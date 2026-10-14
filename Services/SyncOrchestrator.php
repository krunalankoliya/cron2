<?php
declare(strict_types=1);

namespace Jamaat\Cron\Services;

use Jamaat\Cron\Entities\JamaatRow;
use Jamaat\Cron\Entities\RunState;
use Jamaat\Cron\Infrastructure\Logger;

final class SyncOrchestrator
{
    private SoapService $soapService;
    private HashService $hashService;
    private DatabaseService $databaseService;
    private StagingService $stagingService;
    private DiffService $diffService;
    private AuditService $auditService;
    private AdminUserService $adminUserService;
    private RunStateService $runStateService;
    private Logger $logger;

    public function __construct(
        SoapService $soapService,
        HashService $hashService,
        DatabaseService $databaseService,
        StagingService $stagingService,
        DiffService $diffService,
        AuditService $auditService,
        AdminUserService $adminUserService,
        RunStateService $runStateService,
        Logger $logger
    ) {
        $this->soapService = $soapService;
        $this->hashService = $hashService;
        $this->databaseService = $databaseService;
        $this->stagingService = $stagingService;
        $this->diffService = $diffService;
        $this->auditService = $auditService;
        $this->adminUserService = $adminUserService;
        $this->runStateService = $runStateService;
        $this->logger = $logger;
    }

    /**
     * Execute the full sync orchestration.
     */
    public function execute(string $soapKey): void
    {
        $runId = $this->generateRunId();
        $startTime = microtime(true);

        try {
            $this->auditService->writeEvent(
                $runId,
                'run_start',
                'jamaat_sync',
                message: 'Sync run started'
            );

            // 1. Fetch payload from SOAP API
            $payload = $this->soapService->fetchPayload($soapKey);
            $rows = $this->soapService->parseResponse($payload);

            if (empty($rows)) {
                // No <Table> nodes parsed out — could mean a genuinely empty
                // result set, or ITS rejected the request (bad key, IP not
                // whitelisted, etc). Log the FULL raw body: the XSD schema
                // block alone (one <xs:element> per column) can run past
                // 2000 chars before the actual <diffgr:diffgram> data starts,
                // so a truncated excerpt can look empty when it isn't.
                $rawResponse = trim($payload);
                $this->logger->error(
                    'Empty payload from SOAP API. Response length: ' . strlen($rawResponse)
                    . ' bytes. Raw response: ' . $rawResponse
                );

                $this->auditService->writeEvent(
                    $runId,
                    'no_data',
                    'jamaat_sync',
                    message: 'Empty payload from SOAP API (' . strlen($rawResponse) . ' bytes, see log for full body)'
                );
                $this->logRunSummary($runId, 0, 0, 0, 0, $startTime);
                return;
            }

            // Normalize all rows
            $normalizedRows = array_map(
                fn(JamaatRow $row) => $this->hashService->normalizeRow($row),
                $rows
            );

            // 2. Compute payload hash for short-circuit check
            $payloadHash = $this->hashService->computePayloadHash($rows);
            $lastRunState = $this->runStateService->loadRunState();

            if ($lastRunState && $lastRunState->payload_hash === $payloadHash && $lastRunState->row_count === count($rows)) {
                // Payload hasn't changed; skip all diffing for jamaat
                $this->logger->info("Payload hash unchanged; skipping jamaat diffing");
                $this->logRunSummary($runId, 0, 0, 0, count($rows), $startTime);
            } else {
                // Payload changed; do full diff and update
                $this->syncJamaatData($runId, $normalizedRows, $rows);
                $this->runStateService->saveRunState($payloadHash, count($rows));
                $this->logRunSummary($runId, $this->auditService->getEventCount($runId, 'jamaat_added'), $this->auditService->getEventCount($runId, 'jamaat_name_changed'), $this->auditService->getEventCount($runId, 'jamaat_deleted'), count($rows), $startTime);
            }

            // 3. Sync admin_users (always runs)
            $adminSyncResults = $this->adminUserService->syncAdminUsers();

            $this->auditService->writeEvent(
                $runId,
                'run_summary',
                'jamaat_sync',
                message: 'Sync run completed successfully'
            );
        } catch (\Exception $e) {
            $this->auditService->writeEvent(
                $runId,
                'runtime_error',
                'jamaat_sync',
                message: $e->getMessage()
            );
            $this->logger->error("Sync run failed: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Sync jamaat data (diff, upsert, audit).
     */
    private function syncJamaatData(string $runId, array $normalizedRows, array $rows): void
    {
        // Reload staging table
        $stagingRowCount = $this->stagingService->reloadStagingTable($normalizedRows, $runId);
        $this->logger->info("Staging table reloaded with {$stagingRowCount} rows");

        // Load master and staging hashes
        $masterRows = $this->databaseService->fetchMasterRows('core');
        $stagingRows = $this->databaseService->fetchStagingRows('core');

        // Diff to find changes
        $changedRows = $this->diffService->findChangedRows($masterRows, $stagingRows);
        $deletedRows = $this->diffService->findDeletedRows($masterRows, $stagingRows);

        // Upsert changed rows
        if (!empty($changedRows)) {
            $rowsToUpsert = [];
            foreach ($normalizedRows as $row) {
                if (isset($changedRows[$row['jamaat_id']])) {
                    $row['row_hash'] = $changedRows[$row['jamaat_id']];
                    $rowsToUpsert[] = $row;
                }
            }

            // Upsert to all connections
            $connections = ['core', 'fanew', 'araiz', 'imtihaan', 'infra'];
            foreach ($connections as $connName) {
                try {
                    $this->databaseService->upsertRows($rowsToUpsert, $connName);
                    $this->logger->info("Upserted " . count($rowsToUpsert) . " rows to {$connName}");
                } catch (\Exception $e) {
                    $this->auditService->writeEvent(
                        $runId,
                        'db_stmt_failure',
                        'jamaat_sync',
                        fieldName: $connName,
                        message: $e->getMessage()
                    );
                }
            }

            // Generate audit events for each changed row
            foreach ($changedRows as $jamaatId => $newHash) {
                $this->auditChangeEvent($runId, $jamaatId, $normalizedRows);
            }
        }

        // Audit deleted rows
        foreach ($deletedRows as $jamaatId) {
            $this->auditService->writeEvent(
                $runId,
                'jamaat_deleted',
                'jamaat',
                jamaatId: $jamaatId
            );
        }
    }

    /**
     * Audit a changed row by comparing old vs new.
     */
    private function auditChangeEvent(string $runId, int $jamaatId, array $normalizedRows): void
    {
        $newRow = null;
        foreach ($normalizedRows as $row) {
            if ($row['jamaat_id'] === $jamaatId) {
                $newRow = $row;
                break;
            }
        }

        if (!$newRow) {
            return;
        }

        // Fetch old row from master
        $oldRow = $this->databaseService->fetchMasterRow($jamaatId, 'core');

        if (!$oldRow) {
            // New row
            $this->auditService->writeEvent(
                $runId,
                'jamaat_added',
                'jamaat',
                jamaatId: $jamaatId,
                jamiaatId: $newRow['jamiaat_id']
            );
            return;
        }

        // Compare key fields and generate specific events
        if ((int)$oldRow['aamil_masool_its_id'] !== $newRow['aamil_masool_its_id']) {
            $this->auditService->writeEvent(
                $runId,
                'aamil_changed',
                'jamaat',
                jamaatId: $jamaatId,
                fieldName: 'aamil_masool_its_id',
                oldValue: (string)$oldRow['aamil_masool_its_id'],
                newValue: (string)$newRow['aamil_masool_its_id']
            );
        }

        if ($oldRow['jamaat'] !== $newRow['jamaat'] || $oldRow['jamaat_ar'] !== $newRow['jamaat_ar']) {
            $this->auditService->writeEvent(
                $runId,
                'jamaat_name_changed',
                'jamaat',
                jamaatId: $jamaatId,
                fieldName: 'jamaat',
                oldValue: $oldRow['jamaat'],
                newValue: $newRow['jamaat']
            );
        }

        // Catch-all for other field changes
        foreach ($newRow as $field => $newValue) {
            if (in_array($field, ['jamaat_id', 'jamiaat_id', 'jamaat', 'jamaat_ar', 'aamil_masool_its_id'], true)) {
                continue;
            }
            if (isset($oldRow[$field]) && (string)$oldRow[$field] !== (string)$newValue) {
                $this->auditService->writeEvent(
                    $runId,
                    'jamaat_field_changed',
                    'jamaat',
                    jamaatId: $jamaatId,
                    fieldName: $field,
                    oldValue: (string)$oldRow[$field],
                    newValue: (string)$newValue
                );
            }
        }
    }

    /**
     * Log run summary.
     */
    private function logRunSummary(string $runId, int $added, int $changed, int $deleted, int $totalRows, float $startTime): void
    {
        $duration = microtime(true) - $startTime;
        $summary = sprintf(
            "Run %s completed in %.2fs. Added=%d, Changed=%d, Deleted=%d, Total=%d",
            $runId, $duration, $added, $changed, $deleted, $totalRows
        );
        $this->logger->info($summary);
    }

    /**
     * Generate a unique run ID (UUIDv4).
     */
    private function generateRunId(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff), random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff)
        );
    }
}
