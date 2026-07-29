<?php
declare(strict_types=1);

namespace Jamaat\Cron\Entities;

final readonly class RunState
{
    public function __construct(
        public string $payload_hash,
        public int $row_count,
    ) {
    }

    public static function fromDatabase(array $row): self
    {
        return new self(
            payload_hash: $row['payload_hash'],
            row_count: (int)$row['row_count'],
        );
    }
}
