<?php
declare(strict_types=1);

namespace Jamaat\Cron\Services;

use Jamaat\Cron\Config\Constants;
use Jamaat\Cron\Infrastructure\Logger;

final class StagingService
{
    private \mysqli $coreConnection;
    private Logger $logger;
    private HashService $hashService;

    public function __construct(\mysqli $coreConnection, Logger $logger, HashService $hashService)
    {
        $this->coreConnection = $coreConnection;
        $this->logger = $logger;
        $this->hashService = $hashService;
    }

    /**
     * Rebuild the staging table atomically.
     * 1. Create a temp table with the same schema as staging
     * 2. Populate it with normalized rows and hashes
     * 3. Rename the temp table to staging (atomic, never leaves partial state)
     * 4. Remove the old staging table (if RENAME succeeded)
     *
     * @param array<int, array<string, mixed>> $normalizedRows
     * @param string $runId UUID for this run
     * @return int Number of rows inserted into staging
     */
    public function reloadStagingTable(array $normalizedRows, string $runId): int
    {
        $tempTableName = 'its_jamaat_master_staging_tmp_' . time();
        $stagingTableName = 'its_jamaat_master_staging';

        // Create temp table with identical schema to staging
        $createTempSql = <<<SQL
CREATE TABLE {$tempTableName} (
    id INT AUTO_INCREMENT PRIMARY KEY,
    jamaat_id INT NOT NULL UNIQUE,
    jamiaat_id INT,
    jamiaat VARCHAR(255),
    jamaiaat_ar VARCHAR(255),
    jamaat VARCHAR(255),
    jamaat_ar VARCHAR(255),
    jamaat_type VARCHAR(100),
    alvazarat_id INT,
    alvazarat_name VARCHAR(255),
    aamil_masool_its_id INT,
    muavin_its_id INT,
    country VARCHAR(100),
    city VARCHAR(100),
    state VARCHAR(100),
    latitude VARCHAR(50),
    longitude VARCHAR(50),
    primary_email VARCHAR(255),
    secondary_email VARCHAR(255),
    row_hash CHAR(64) NOT NULL,
    run_id CHAR(36) NOT NULL,
    INDEX idx_jamaat_id (jamaat_id),
    INDEX idx_row_hash (row_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

        if (!$this->coreConnection->query($createTempSql)) {
            throw new \RuntimeException("Failed to create temp staging table: {$this->coreConnection->error}");
        }

        try {
            $rowCount = $this->insertStagingRows($tempTableName, $normalizedRows, $runId);

            // Atomic RENAME: old staging -> backup, temp -> staging
            if (!$this->coreConnection->query("RENAME TABLE {$stagingTableName} TO {$stagingTableName}_old, {$tempTableName} TO {$stagingTableName}")) {
                throw new \RuntimeException("Failed to rename staging tables: {$this->coreConnection->error}");
            }

            // Drop the old staging table (best-effort; if it fails, it's not catastrophic)
            $this->coreConnection->query("DROP TABLE IF EXISTS {$stagingTableName}_old");

            return $rowCount;
        } catch (\Exception $e) {
            // If anything fails, clean up the temp table
            $this->coreConnection->query("DROP TABLE IF EXISTS {$tempTableName}");
            throw $e;
        }
    }

    /**
     * Insert normalized rows into the staging temp table.
     * Uses chunked INSERT...VALUES to avoid exceeding max_allowed_packet.
     *
     * @param array<int, array<string, mixed>> $normalizedRows
     * @return int Total rows inserted
     */
    private function insertStagingRows(string $tableName, array $normalizedRows, string $runId): int
    {
        $totalInserted = 0;
        $chunkSize = Constants::CHUNK_SIZE;

        for ($i = 0; $i < count($normalizedRows); $i += $chunkSize) {
            $chunk = array_slice($normalizedRows, $i, $chunkSize);

            $rowCount = $this->insertStagingChunk($tableName, $chunk, $runId);
            $totalInserted += $rowCount;
        }

        return $totalInserted;
    }

    /**
     * Insert a chunk of normalized rows into staging.
     */
    private function insertStagingChunk(string $tableName, array $chunk, string $runId): int
    {
        $placeholders = [];
        $values = [];

        foreach ($chunk as $row) {
            $rowHash = $this->hashService->computeRowHash($row);
            $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';

            $values[] = $row['jamaat_id'];
            $values[] = $row['jamiaat_id'];
            $values[] = $row['jamiaat'];
            $values[] = $row['jamaiaat_ar'];
            $values[] = $row['jamaat'];
            $values[] = $row['jamaat_ar'];
            $values[] = $row['jamaat_type'];
            $values[] = $row['alvazarat_id'];
            $values[] = $row['alvazarat_name'];
            $values[] = $row['aamil_masool_its_id'];
            $values[] = $row['muavin_its_id'];
            $values[] = $row['country'];
            $values[] = $row['city'];
            $values[] = $row['state'];
            $values[] = $row['latitude'];
            $values[] = $row['longitude'];
            $values[] = $row['primary_email'];
            $values[] = $row['secondary_email'];
            $values[] = $rowHash;
            $values[] = $runId;
        }

        $placeholderString = implode(',', $placeholders);
        $sql = <<<SQL
INSERT INTO {$tableName} (
    jamaat_id, jamiaat_id, jamiaat, jamaiaat_ar, jamaat, jamaat_ar, jamaat_type,
    alvazarat_id, alvazarat_name, aamil_masool_its_id, muavin_its_id,
    country, city, state, latitude, longitude, primary_email, secondary_email, row_hash, run_id
) VALUES {$placeholderString}
SQL;

        $stmt = $this->coreConnection->prepare($sql);
        if (!$stmt) {
            throw new \RuntimeException("Prepare failed: {$this->coreConnection->error}");
        }

        $typeString = str_repeat('s', count($values));
        $stmt->bind_param($typeString, ...$values);

        if (!$stmt->execute()) {
            throw new \RuntimeException("Insert staging chunk failed: {$stmt->error}");
        }

        $affected = $stmt->affected_rows;
        $stmt->close();

        return $affected;
    }

    /**
     * Truncate the staging table (use sparingly; prefer rebuild).
     */
    public function truncateStagingTable(): void
    {
        if (!$this->coreConnection->query('TRUNCATE TABLE its_jamaat_master_staging')) {
            throw new \RuntimeException("Failed to truncate staging: {$this->coreConnection->error}");
        }
    }

    /**
     * Get row count in staging table.
     */
    public function getStagingRowCount(): int
    {
        $result = $this->coreConnection->query('SELECT COUNT(*) as cnt FROM its_jamaat_master_staging');
        if (!$result) {
            throw new \RuntimeException("Failed to count staging rows: {$this->coreConnection->error}");
        }

        $row = $result->fetch_assoc();
        return (int)$row['cnt'];
    }
}
