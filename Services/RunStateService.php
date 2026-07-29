<?php
declare(strict_types=1);

namespace Jamaat\Cron\Services;

use Jamaat\Cron\Entities\RunState;
use Jamaat\Cron\Infrastructure\Logger;

final class RunStateService
{
    private \mysqli $coreConnection;
    private Logger $logger;

    public function __construct(\mysqli $coreConnection, Logger $logger)
    {
        $this->coreConnection = $coreConnection;
        $this->logger = $logger;
    }

    /**
     * Load the last run state (payload_hash, row_count).
     * Returns null if no prior run exists (first-time deploy).
     */
    public function loadRunState(): ?RunState
    {
        $stmt = $this->coreConnection->prepare(
            'SELECT payload_hash, row_count FROM jamaat_sync_run_state LIMIT 1'
        );

        if (!$stmt) {
            $this->logger->error("Failed to prepare run_state query: {$this->coreConnection->error}");
            return null;
        }

        if (!$stmt->execute()) {
            $this->logger->error("Failed to execute run_state query: {$stmt->error}");
            $stmt->close();
            return null;
        }

        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            $stmt->close();
            return null;
        }

        $row = $result->fetch_assoc();
        $stmt->close();

        return RunState::fromDatabase($row['payload_hash'], (int)$row['row_count']);
    }

    /**
     * Save the run state (called only after a fully clean run with no db_stmt_failure events).
     */
    public function saveRunState(string $payloadHash, int $rowCount): void
    {
        // Delete existing state and insert new (since there's only one row)
        $stmt = $this->coreConnection->prepare(
            'DELETE FROM jamaat_sync_run_state'
        );

        if (!$stmt) {
            $this->logger->error("Failed to prepare delete: {$this->coreConnection->error}");
            return;
        }

        if (!$stmt->execute()) {
            $this->logger->error("Failed to delete old run_state: {$stmt->error}");
            $stmt->close();
            return;
        }

        $stmt->close();

        // Insert new state
        $insertStmt = $this->coreConnection->prepare(
            'INSERT INTO jamaat_sync_run_state (payload_hash, row_count) VALUES (?, ?)'
        );

        if (!$insertStmt) {
            $this->logger->error("Failed to prepare insert: {$this->coreConnection->error}");
            return;
        }

        $insertStmt->bind_param('si', $payloadHash, $rowCount);

        if (!$insertStmt->execute()) {
            $this->logger->error("Failed to insert new run_state: {$insertStmt->error}");
        }

        $insertStmt->close();
    }
}
