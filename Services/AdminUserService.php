<?php
declare(strict_types=1);

namespace Jamaat\Cron\Services;

use Jamaat\Cron\Infrastructure\Logger;

final class AdminUserService
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
        $this->logger = $logger;
    }

    /**
     * Sync admin_users from fanew (source) to all other connections.
     * This runs always (not skipped on payload-hash match) since admin_users can drift independently.
     *
     * @return array<string, int> Number of rows synced per connection
     */
    public function syncAdminUsers(): array
    {
        if (!isset($this->connections['fanew'])) {
            $this->logger->error('fanew connection not available for admin_users sync');
            return [];
        }

        // Read from fanew
        $sourceRows = $this->fetchAdminUsers('fanew');
        if (empty($sourceRows)) {
            $this->logger->info('No admin_users to sync from fanew');
            return [];
        }

        $syncResults = [];

        // Write to all other connections
        $targets = ['core', 'araiz', 'imtihaan', 'infra'];
        foreach ($targets as $targetName) {
            if (!isset($this->connections[$targetName])) {
                $this->logger->error("Target connection '{$targetName}' not available for admin_users sync");
                continue;
            }

            try {
                $rowCount = $this->upsertAdminUsers($targetName, $sourceRows);
                $syncResults[$targetName] = $rowCount;
                $this->logger->info("Synced {$rowCount} admin_users to {$targetName}");
            } catch (\Exception $e) {
                $this->logger->error("Failed to sync admin_users to {$targetName}: {$e->getMessage()}");
                $syncResults[$targetName] = 0;
            }
        }

        return $syncResults;
    }

    /**
     * Fetch all admin_users from a connection.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchAdminUsers(string $connectionName): array
    {
        $conn = $this->connections[$connectionName];

        $result = $conn->query('SELECT * FROM admin_users');
        if (!$result) {
            throw new \RuntimeException("Failed to fetch admin_users from {$connectionName}: {$conn->error}");
        }

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Upsert admin_users into a target connection via INSERT...ON DUPLICATE KEY UPDATE.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function upsertAdminUsers(string $connectionName, array $rows): int
    {
        $conn = $this->connections[$connectionName];

        if (empty($rows)) {
            return 0;
        }

        // Get column info from first row
        $columns = array_keys($rows[0]);
        $columnList = implode(', ', array_map(fn($c) => "`{$c}`", $columns));

        // Build placeholders and bind values
        $placeholders = [];
        $values = [];
        $types = '';

        foreach ($rows as $row) {
            $placeholders[] = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
            foreach ($columns as $col) {
                $values[] = $row[$col];
                $types .= 's'; // Treat all as strings for simplicity
            }
        }

        $placeholderString = implode(',', $placeholders);

        // Build UPDATE clause for all columns except primary key (assume 'id' is PK, or similar)
        $updateParts = [];
        foreach ($columns as $col) {
            if (strtolower($col) !== 'id') {
                $updateParts[] = "`{$col}` = VALUES(`{$col}`)";
            }
        }
        $updateClause = implode(', ', $updateParts);

        $sql = "INSERT INTO admin_users ({$columnList}) VALUES {$placeholderString} ON DUPLICATE KEY UPDATE {$updateClause}";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new \RuntimeException("Prepare failed: {$conn->error}");
        }

        $stmt->bind_param($types, ...$values);

        if (!$stmt->execute()) {
            throw new \RuntimeException("Execute failed: {$stmt->error}");
        }

        $affectedRows = $stmt->affected_rows;
        $stmt->close();

        return $affectedRows;
    }
}
