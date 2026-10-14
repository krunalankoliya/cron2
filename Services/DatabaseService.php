<?php
declare (strict_types = 1);

namespace Jamaat\Cron\Services;

use Jamaat\Cron\Infrastructure\Logger;

final class DatabaseService
{
    /** @var array<string, \mysqli> */
    private array $connections;
    private Logger $logger;

    /**
     * @param array<string, \mysqli> $connections
     */
    public function __construct(array $connections, Logger $logger)
    {
        $this->connections = $connections;
        $this->logger      = $logger;
    }

    /**
     * Get a specific connection by name.
     */
    private function getConnection(string $name): \mysqli
    {
        if (! isset($this->connections[$name])) {
            throw new \RuntimeException("Connection '{$name}' not available");
        }
        return $this->connections[$name];
    }

    /**
     * Fetch all rows from master table by jamaat_id.
     * Returns associative array with row_hash keyed by jamaat_id.
     *
     * @return array<int, array{jamaat_id: int, row_hash: string}>
     */
    public function fetchMasterRows(string $connectionName = 'core'): array
    {
        $conn = $this->getConnection($connectionName);

        $stmt = $conn->prepare('SELECT jamaat_id, row_hash FROM its_jamaat_master ORDER BY jamaat_id ASC');
        if (! $stmt) {
            throw new \RuntimeException("Prepare failed: {$conn->error}");
        }

        if (! $stmt->execute()) {
            throw new \RuntimeException("Execute failed: {$stmt->error}");
        }

        $result = $stmt->get_result();
        $rows   = [];
        while ($row = $result->fetch_assoc()) {
            $rows[(int) $row['jamaat_id']] = [
                'jamaat_id' => (int) $row['jamaat_id'],
                'row_hash'  => $row['row_hash'],
            ];
        }
        $stmt->close();

        return $rows;
    }

    /**
     * Check if a row exists in staging by jamaat_id.
     */
    public function stagingRowExists(int $jamaatId, string $connectionName = 'core'): bool
    {
        $conn = $this->getConnection($connectionName);

        $stmt = $conn->prepare('SELECT 1 FROM its_jamaat_master_staging WHERE jamaat_id = ? LIMIT 1');
        if (! $stmt) {
            throw new \RuntimeException("Prepare failed: {$conn->error}");
        }

        $stmt->bind_param('i', $jamaatId);
        if (! $stmt->execute()) {
            throw new \RuntimeException("Execute failed: {$stmt->error}");
        }

        $result = $stmt->get_result();
        $exists = $result->num_rows > 0;
        $stmt->close();

        return $exists;
    }

    /**
     * Insert a batch of normalized rows via INSERT ... ON DUPLICATE KEY UPDATE.
     * Each row is overwritten if jamaat_id already exists.
     *
     * @param array<int, array<string, mixed>> $rows Array of normalized row arrays
     * @param string $connectionName
     * @return int Number of rows inserted/updated
     */
    public function upsertRows(array $rows, string $connectionName = 'core'): int
    {
        if (empty($rows)) {
            return 0;
        }

        $conn         = $this->getConnection($connectionName);
        $affectedRows = 0;

        // Build placeholders and bind values: each row has 18 fields + row_hash
        $placeholders = [];
        $values       = [];
        foreach ($rows as $row) {
            $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            $values[]       = $row['jamiaat_id'];
            $values[]       = $row['jamiaat'];
            $values[]       = $row['jamaiaat_ar'];
            $values[]       = $row['jamaat_id'];
            $values[]       = $row['jamaat'];
            $values[]       = $row['jamaat_ar'];
            $values[]       = $row['jamaat_type'];
            $values[]       = $row['alvazarat_id'];
            $values[]       = $row['alvazarat_name'];
            $values[]       = $row['aamil_masool_its_id'];
            $values[]       = $row['muavin_its_id'];
            $values[]       = $row['country'];
            $values[]       = $row['city'];
            $values[]       = $row['state'];
            $values[]       = $row['latitude'];
            $values[]       = $row['longitude'];
            $values[]       = $row['primary_email'];
            $values[]       = $row['secondary_email'];
            $values[]       = $row['row_hash'];
        }

        $placeholderString = implode(',', $placeholders);
        $sql               = <<<SQL
INSERT INTO its_jamaat_master (
    jamiaat_id, jamiaat, jamaiaat_ar, jamaat_id, jamaat, jamaat_ar,
    jamaat_type, alvazarat_id, alvazarat_name, aamil_masool_its_id, muavin_its_id,
    country, city, state, latitude, longitude, primary_email, secondary_email, row_hash
) VALUES {$placeholderString}
ON DUPLICATE KEY UPDATE
    jamiaat = VALUES(jamiaat),
    jamaiaat_ar = VALUES(jamaiaat_ar),
    jamaat = VALUES(jamaat),
    jamaat_ar = VALUES(jamaat_ar),
    jamaat_type = VALUES(jamaat_type),
    alvazarat_id = VALUES(alvazarat_id),
    alvazarat_name = VALUES(alvazarat_name),
    aamil_masool_its_id = VALUES(aamil_masool_its_id),
    muavin_its_id = VALUES(muavin_its_id),
    country = VALUES(country),
    city = VALUES(city),
    state = VALUES(state),
    latitude = VALUES(latitude),
    longitude = VALUES(longitude),
    primary_email = VALUES(primary_email),
    secondary_email = VALUES(secondary_email),
    row_hash = VALUES(row_hash)
SQL;

        $stmt = $conn->prepare($sql);
        if (! $stmt) {
            throw new \RuntimeException("Prepare failed: {$conn->error}");
        }

        // Build type string: 19 strings per row, all 's' type
        $typeString = str_repeat('s', count($values));

        // Use spread operator to bind all values at once
        $stmt->bind_param($typeString, ...$values);

        if (! $stmt->execute()) {
            throw new \RuntimeException("Execute failed: {$stmt->error}");
        }

        $affectedRows = $stmt->affected_rows;
        $stmt->close();

        return $affectedRows;
    }

    /**
     * Fetch rows from staging table.
     *
     * @return array<int, array{jamaat_id: int, row_hash: string}>
     */
    public function fetchStagingRows(string $connectionName = 'core'): array
    {
        $conn = $this->getConnection($connectionName);

        $stmt = $conn->prepare('SELECT jamaat_id, row_hash FROM its_jamaat_master_staging ORDER BY jamaat_id ASC');
        if (! $stmt) {
            throw new \RuntimeException("Prepare failed: {$conn->error}");
        }

        if (! $stmt->execute()) {
            throw new \RuntimeException("Execute failed: {$stmt->error}");
        }

        $result = $stmt->get_result();
        $rows   = [];
        while ($row = $result->fetch_assoc()) {
            $rows[(int) $row['jamaat_id']] = [
                'jamaat_id' => (int) $row['jamaat_id'],
                'row_hash'  => $row['row_hash'],
            ];
        }
        $stmt->close();

        return $rows;
    }

    /**
     * Find jamaat_id values that exist in master but not in staging (deleted rows).
     *
     * @return int[]
     */
    public function findDeletedRows(string $connectionName = 'core'): array
    {
        $conn = $this->getConnection($connectionName);

        $stmt = $conn->prepare(
            'SELECT m.jamaat_id FROM its_jamaat_master m '
            . 'LEFT JOIN its_jamaat_master_staging s ON m.jamaat_id = s.jamaat_id '
            . 'WHERE s.jamaat_id IS NULL '
            . 'ORDER BY m.jamaat_id ASC'
        );
        if (! $stmt) {
            throw new \RuntimeException("Prepare failed: {$conn->error}");
        }

        if (! $stmt->execute()) {
            throw new \RuntimeException("Execute failed: {$stmt->error}");
        }

        $result     = $stmt->get_result();
        $deletedIds = [];
        while ($row = $result->fetch_assoc()) {
            $deletedIds[] = (int) $row['jamaat_id'];
        }
        $stmt->close();

        return $deletedIds;
    }

    /**
     * Fetch a master row by jamaat_id for audit context.
     *
     * @return array<string, mixed>|null
     */
    public function fetchMasterRow(int $jamaatId, string $connectionName = 'core'): ?array
    {
        $conn = $this->getConnection($connectionName);

        $stmt = $conn->prepare(
            'SELECT jamiaat_id, jamaat, jamaiaat_ar, aamil_masool_its_id, muavin_its_id, '
            . 'alvazarat_id, alvazarat_name, country, city, state, primary_email, secondary_email '
            . 'FROM its_jamaat_master WHERE jamaat_id = ? LIMIT 1'
        );
        if (! $stmt) {
            throw new \RuntimeException("Prepare failed: {$conn->error}");
        }

        $stmt->bind_param('i', $jamaatId);
        if (! $stmt->execute()) {
            throw new \RuntimeException("Execute failed: {$stmt->error}");
        }

        $result = $stmt->get_result();
        $row    = $result->fetch_assoc();
        $stmt->close();

        return $row;
    }

    /**
     * Update row_hash on a master row.
     */
    public function updateRowHash(int $jamaatId, string $newHash, string $connectionName = 'core'): void
    {
        $conn = $this->getConnection($connectionName);

        $stmt = $conn->prepare('UPDATE its_jamaat_master SET row_hash = ? WHERE jamaat_id = ? LIMIT 1');
        if (! $stmt) {
            throw new \RuntimeException("Prepare failed: {$conn->error}");
        }

        $stmt->bind_param('si', $newHash, $jamaatId);
        if (! $stmt->execute()) {
            throw new \RuntimeException("Execute failed: {$stmt->error}");
        }

        $stmt->close();
    }
}
