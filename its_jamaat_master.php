<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/bootstrap.php';

use Jamaat\Cron\Config\Constants;
use Jamaat\Cron\Infrastructure\FileLock;

// Determine .env path
$envPath = __DIR__ . '/.env';

try {
    // Create DI container
    $container = \Jamaat\Cron\createContainer($envPath);
    $logger = $container['logger'];
    $orchestrator = $container['orchestrator'];
    $connManager = $container['connManager'];
    $env = $container['env'];

    // Acquire lock to prevent concurrent runs
    $lock = new FileLock();
    if (!$lock->acquire(Constants::LOCK_FILE)) {
        $logger->info('Sync run skipped: another instance is running');
        $container['auditService']->writeEvent(
            bin2hex(random_bytes(16)),
            'run_skipped_locked',
            'jamaat_sync',
            message: 'Skipped due to concurrent run'
        );
        exit(0);
    }

    // Execute the sync
    $orchestrator->execute($env['SOAP_KEY']);

    // Release lock
    $lock->release();

    exit(0);
} catch (\Throwable $e) {
    $message = "Fatal error: {$e->getMessage()} at {$e->getFile()}:{$e->getLine()}";

    // $logger may not exist yet if createContainer() itself failed (e.g.
    // missing .env, bad DB creds) — always surface the error regardless.
    if (isset($logger)) {
        $logger->error($message);
    } else {
        error_log("[jamaat-cron] {$message}");
    }
    @file_put_contents('php://stderr', $message . PHP_EOL);

    // Attempt cleanup
    if (isset($connManager)) {
        $connManager->closeAll();
    }

    exit(1);
}
