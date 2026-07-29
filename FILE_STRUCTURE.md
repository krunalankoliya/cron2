# Refactored Cron Structure

Monolithic script refactored into a service-based architecture with clear separation of concerns.

## Directory Layout

```
cron/
├── Config/
│   └── Constants.php          Central configuration (timeouts, URLs, limits)
├── Entities/
│   ├── JamaatRow.php          DTO for normalized jamaat row data
│   └── RunState.php           DTO for payload hash + row count baseline
├── Infrastructure/
│   ├── Connections.php        MySQLi connection pool manager
│   ├── EnvironmentLoader.php  .env file parser
│   ├── FileLock.php           flock wrapper for cron overlap protection
│   └── Logger.php             Flat-file logging service
├── Services/
│   ├── SoapService.php        SOAP API interaction (fetch + parse)
│   ├── HashService.php        Row/payload hashing & normalization
│   ├── DatabaseService.php    All database queries (prepared statements)
│   ├── StagingService.php     Staging table rebuild (atomic RENAME)
│   ├── DiffService.php        Hash-based diff: staging vs master
│   ├── AuditService.php       Audit event logging (DB + flat-file fallback)
│   ├── AdminUserService.php   admin_users sync across connections
│   ├── RunStateService.php    Payload hash state persistence
│   └── SyncOrchestrator.php   Main orchestration (calls all services)
├── bootstrap.php              DI container factory
└── its_jamaat_master.php      Entry point (lock + execute)
```

## Key Principles

- **Immutable DTOs:** JamaatRow and RunState are read-only value objects.
- **Single responsibility:** Each service owns one concern (SOAP, hashing, DB, etc.).
- **Constructor injection:** All dependencies passed in; no global state or service locator.
- **Prepared statements:** All SQL uses parameterized queries (no string injection).
- **Error fallback:** Audit failures fall back to flat-file logging automatically.
- **No over-commenting:** Comments only explain WHY, never WHAT (code is self-documenting).

## Execution Flow

1. `its_jamaat_master.php` loads `.env` and creates DI container
2. Acquires `flock` on `/tmp/jamaat_sync.lock` (prevents concurrent runs)
3. Calls `SyncOrchestrator::execute($soapKey)`
4. Orchestrator:
   - Fetches + parses SOAP payload (with retry on transient errors)
   - Computes payload hash; compares to last run (short-circuit if unchanged)
   - Reloads staging table via atomic RENAME (build-new + swap, never truncate)
   - Diffs staging vs master to find changed/added/deleted rows
   - Upserts changed rows to all 5 DBs (chunked, per-connection isolation)
   - Generates audit events for each change type
   - Saves run state only after fully clean run (no DB failures)
   - Syncs admin_users from fanew to all other connections (always runs)
5. Releases lock, exits

## Testing Checklist

- [ ] .env file exists at project root with all 15 credentials
- [ ] `its_jamaat_master_staging` table exists with atomic RENAME atomicity
- [ ] `jamaat_sync_run_state` table exists (stores last payload_hash + row_count)
- [ ] `jamaat_sync_audit_log` table exists with TIMESTAMP(6) for microsecond precision
- [ ] `row_hash` column added to `its_jamaat_master` (backfilled on first deploy)
- [ ] Cron logs appear in `/home/talabulilm/public_html/logFiles/outBound_YYYYMMDD.log`
- [ ] Audit events appear in `jamaat_sync_audit_log` for each change
- [ ] Concurrent cron invocation is blocked with `run_skipped_locked` log entry
- [ ] SOAP timeout (if SOAP endpoint is down) produces `soap_failure` event within ~15s
- [ ] Partial DB failure (e.g., fanew down) is logged but run continues to other connections
