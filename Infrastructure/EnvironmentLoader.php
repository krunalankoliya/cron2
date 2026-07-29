<?php
declare(strict_types=1);

namespace Jamaat\Cron\Infrastructure;

final class EnvironmentLoader
{
    /**
     * @return array<string, string>
     */
    public static function load(string $envPath): array
    {
        if (!file_exists($envPath)) {
            throw new \RuntimeException("Environment file not found: {$envPath}");
        }

        $env = [];
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) {
            return $env;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $env[trim($parts[0])] = trim($parts[1]);
            }
        }

        return $env;
    }
}
