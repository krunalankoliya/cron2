<?php
declare(strict_types=1);

namespace Jamaat\Cron\Infrastructure;

final class FileLock
{
    /** @var resource|null */
    private $handle = null;

    public function acquire(string $lockPath): bool
    {
        $this->handle = @fopen($lockPath, 'c');
        if (!$this->handle) {
            return false;
        }

        if (!flock($this->handle, LOCK_EX | LOCK_NB)) {
            fclose($this->handle);
            $this->handle = null;
            return false;
        }

        return true;
    }

    public function isLocked(): bool
    {
        return $this->handle !== null;
    }

    public function release(): void
    {
        if ($this->handle) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
