<?php
declare(strict_types=1);

namespace Jamaat\Cron\Infrastructure;

final class Logger
{
    private string $logFile;

    public function __construct(string $logDir)
    {
        $date = date('Ymd');
        $this->logFile = rtrim($logDir, '/') . "/outBound_{$date}.log";
    }

    public function log(string $message): void
    {
        $fh = @fopen($this->logFile, 'a');
        if ($fh) {
            fwrite($fh, $message);
            fclose($fh);
        }
    }

    public function info(string $message): void
    {
        $this->log(date('Y-m-d H:i:s') . " - {$message}" . PHP_EOL);
    }

    public function error(string $message): void
    {
        $this->log(date('Y-m-d H:i:s') . " - Error: {$message}" . PHP_EOL);
    }
}
