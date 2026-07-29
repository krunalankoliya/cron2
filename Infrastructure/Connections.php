<?php
declare(strict_types=1);

namespace Jamaat\Cron\Infrastructure;

final class Connections
{
    /**
     * @var array<string, \mysqli>
     */
    private array $connections = [];
    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param array<string, array{host: string, user: string, pass: string, db: string}> $configs
     * @return array<string, \mysqli>
     */
    public function establish(array $configs): array
    {
        foreach ($configs as $name => $config) {
            $conn = new \mysqli($config['host'], $config['user'], $config['pass'], $config['db']);
            if ($conn->connect_error) {
                if ($name === 'core') {
                    throw new \RuntimeException("Failed to connect to core database: {$conn->connect_error}");
                }
                $this->logger->error("Failed to connect to {$name}: {$conn->connect_error}");
                continue;
            }
            $conn->set_charset('utf8');
            $this->connections[$name] = $conn;
        }

        if (!isset($this->connections['core'])) {
            throw new \RuntimeException('Core database connection unavailable');
        }

        return $this->connections;
    }

    public function closeAll(): void
    {
        foreach ($this->connections as $conn) {
            if ($conn instanceof \mysqli) {
                $conn->close();
            }
        }
        $this->connections = [];
    }
}
