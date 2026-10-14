<?php
declare (strict_types = 1);

namespace Jamaat\Cron;

use Jamaat\Cron\Config\Constants;
use Jamaat\Cron\Infrastructure\Connections;
use Jamaat\Cron\Infrastructure\EnvironmentLoader;
use Jamaat\Cron\Infrastructure\Logger;
use Jamaat\Cron\Services\AdminUserService;
use Jamaat\Cron\Services\AuditService;
use Jamaat\Cron\Services\DatabaseService;
use Jamaat\Cron\Services\DiffService;
use Jamaat\Cron\Services\HashService;
use Jamaat\Cron\Services\MailService;
use Jamaat\Cron\Services\RunStateService;
use Jamaat\Cron\Services\SoapService;
use Jamaat\Cron\Services\StagingService;
use Jamaat\Cron\Services\SyncOrchestrator;

/**
 * Bootstrap the DI container and return configured services.
 *
 * @return array<string, mixed>
 */
    function createContainer(string $envPath): array
{
        // Load environment variables
        $env = EnvironmentLoader::load($envPath);

        // Validate required env vars
        $required = ['SOAP_KEY', 'CORE_HOST', 'CORE_USER', 'CORE_PASS', 'CORE_DB',
            'FANEW_HOST', 'FANEW_USER', 'FANEW_PASS', 'FANEW_DB',
            'ARAIZ_HOST', 'ARAIZ_USER', 'ARAIZ_PASS', 'ARAIZ_DB',
            'IMTIHAAN_HOST', 'IMTIHAAN_USER', 'IMTIHAAN_PASS', 'IMTIHAAN_DB',
            'INFRA_HOST', 'INFRA_USER', 'INFRA_PASS', 'INFRA_DB'];

        foreach ($required as $key) {
            if (! isset($env[$key]) || $env[$key] === '') {
                throw new \RuntimeException("Required env var missing: {$key}");
            }
        }

        // Create logger
        $logger = new Logger(Constants::LOG_DIR);

        // Establish connections
        $connManager = new Connections($logger);
        $configs     = [
            'core' => ['host' => $env['CORE_HOST'], 'user' => $env['CORE_USER'], 'pass' => $env['CORE_PASS'], 'db' => $env['CORE_DB']],
            'fanew' => ['host' => $env['FANEW_HOST'], 'user' => $env['FANEW_USER'], 'pass' => $env['FANEW_PASS'], 'db' => $env['FANEW_DB']],
            'araiz' => ['host' => $env['ARAIZ_HOST'], 'user' => $env['ARAIZ_USER'], 'pass' => $env['ARAIZ_PASS'], 'db' => $env['ARAIZ_DB']],
            'imtihaan' => ['host' => $env['IMTIHAAN_HOST'], 'user' => $env['IMTIHAAN_USER'], 'pass' => $env['IMTIHAAN_PASS'], 'db' => $env['IMTIHAAN_DB']],
            'infra' => ['host' => $env['INFRA_HOST'], 'user' => $env['INFRA_USER'], 'pass' => $env['INFRA_PASS'], 'db' => $env['INFRA_DB']],
        ];
        $connections = $connManager->establish($configs);

        // Create services
        $soapService      = new SoapService(outboundInterface: $env['SOAP_OUTBOUND_IP'] ?? null);
        $hashService      = new HashService();
        $databaseService  = new DatabaseService($connections, $logger);
        $stagingService   = new StagingService($connections['core'], $logger, $hashService);
        $diffService      = new DiffService();
        $auditService     = new AuditService($connections['core'], $logger);
        $adminUserService = new AdminUserService($connections, $logger);
        $runStateService  = new RunStateService($connections['core'], $logger);
        $mailService      = new MailService($env, $logger);

        $orchestrator = new SyncOrchestrator(
            $soapService,
            $hashService,
            $databaseService,
            $stagingService,
            $diffService,
            $auditService,
            $adminUserService,
            $runStateService,
            $mailService,
            $logger
        );

        return [
            'env'              => $env,
            'logger'           => $logger,
            'connections'      => $connections,
            'connManager'      => $connManager,
            'soapService'      => $soapService,
            'hashService'      => $hashService,
            'databaseService'  => $databaseService,
            'stagingService'   => $stagingService,
            'diffService'      => $diffService,
            'auditService'     => $auditService,
            'adminUserService' => $adminUserService,
            'runStateService'  => $runStateService,
            'mailService'      => $mailService,
            'orchestrator'     => $orchestrator,
        ];
    }
