<?php
declare(strict_types=1);

namespace Jamaat\Cron\Infrastructure;

final class Logger
{
    private string $logFile;

    public function __construct(string $logDir)
    {
        $logDir = rtrim($logDir, '/');
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $date = date('Ymd');
        $this->logFile = "{$logDir}/outBound_{$date}.log";
    }

    public function log(string $message): void
    {
        $fh = @fopen($this->logFile, 'a');
        if ($fh) {
            fwrite($fh, $message);
            fclose($fh);
            return;
        }

        // Never fail silently: fall back to PHP's own error log if the
        // flat-file target is unwritable (missing dir, bad permissions).
        error_log("[jamaat-cron] Failed to write {$this->logFile}: {$message}");
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
