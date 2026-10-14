<?php
declare (strict_types = 1);

namespace Jamaat\Cron\Services;

use Jamaat\Cron\Infrastructure\Logger;

final class AuditService
{
    private \mysqli $coreConnection;
    private Logger $logger;

    public function __construct(\mysqli $coreConnection, Logger $logger)
    {
        $this->coreConnection = $coreConnection;
        $this->logger         = $logger;
    }

    /**
     * Write an audit event to the database.
     * If database write fails, fall back to flat-file logging.
     */
    public function writeEvent(
        string $runId,
        string $eventType,
        string $entityType,
        ?int $jamaatId = null,
        ?int $jamiaatId = null,
        ?string $fieldName = null,
        ?string $oldValue = null,
        ?string $newValue = null,
        ?string $message = null
    ): void {
        // Sanitize values to avoid truncation issues
        $oldValue = $this->sanitizeValue($oldValue);
        $newValue = $this->sanitizeValue($newValue);

        $stmt = $this->coreConnection->prepare(
            'INSERT INTO jamaat_sync_audit_logs '
            . '(run_id, event_type, entity_type, jamaat_id, jamiaat_id, field_name, old_value, new_value, message, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(6))'
        );

        if (! $stmt) {
            $this->logger->error("Failed to prepare audit insert: {$this->coreConnection->error}");
            $this->logEventFlat($runId, $eventType, $entityType, $jamaatId, $jamiaatId, $fieldName, $oldValue, $newValue, $message);
            return;
        }

        $stmt->bind_param(
            'sssiissss',
            $runId, $eventType, $entityType, $jamaatId, $jamiaatId, $fieldName, $oldValue, $newValue, $message
        );

        if (! $stmt->execute()) {
            $this->logger->error("Failed to insert audit event: {$stmt->error}");
            $this->logEventFlat($runId, $eventType, $entityType, $jamaatId, $jamiaatId, $fieldName, $oldValue, $newValue, $message);
        }

        $stmt->close();
    }

    /**
     * Sanitize a value for audit logging (truncate to TEXT field limit).
     */
    private function sanitizeValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // TEXT field limit in MySQL is 65535 bytes; be conservative
        if (strlen($value) > 2000) {
            return substr($value, 0, 2000) . '... [truncated]';
        }

        return $value;
    }

    /**
     * Fall back to flat-file logging when audit insert fails.
     */
    private function logEventFlat(
        string $runId,
        string $eventType,
        string $entityType,
        ?int $jamaatId,
        ?int $jamiaatId,
        ?string $fieldName,
        ?string $oldValue,
        ?string $newValue,
        ?string $message
    ): void {
        $flatEntry = sprintf(
            "[%s] runId=%s type=%s entity=%s jamaat_id=%s jamiaat_id=%s field=%s old=%s new=%s message=%s",
            date('Y-m-d H:i:s.u'),
            $runId,
            $eventType,
            $entityType,
            $jamaatId ?? 'null',
            $jamiaatId ?? 'null',
            $fieldName ?? 'null',
            $oldValue ?? 'null',
            $newValue ?? 'null',
            $message ?? 'null'
        );

        $this->logger->log($flatEntry . PHP_EOL);
    }

    /**
     * Get count of a specific event type in this run.
     */
    public function getEventCount(string $runId, string $eventType): int
    {
        $stmt = $this->coreConnection->prepare(
            'SELECT COUNT(*) as cnt FROM jamaat_sync_audit_logs WHERE run_id = ? AND event_type = ?'
        );

        if (! $stmt) {
            $this->logger->error("Failed to prepare count query: {$this->coreConnection->error}");
            return 0;
        }

        $stmt->bind_param('ss', $runId, $eventType);
        if (! $stmt->execute()) {
            $this->logger->error("Failed to count audit events: {$stmt->error}");
            $stmt->close();
            return 0;
        }

        $result = $stmt->get_result();
        $row    = $result->fetch_assoc();
        $stmt->close();

        return (int) ($row['cnt'] ?? 0);
    }
}
