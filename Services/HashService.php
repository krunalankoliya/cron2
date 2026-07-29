<?php
declare(strict_types=1);

namespace Jamaat\Cron\Services;

use Jamaat\Cron\Config\Constants;
use Jamaat\Cron\Entities\JamaatRow;

final class HashService
{
    /**
     * Normalizes a row to ensure consistent hashing and DB writes.
     */
    public function normalizeRow(JamaatRow $row): array
    {
        return [
            'jamiaat_id' => $row->jamiaat_id,
            'jamiaat' => trim($row->jamiaat),
            'jamaiaat_ar' => trim($row->jamaiaat_ar),
            'jamaat_id' => $row->jamaat_id,
            'jamaat' => trim($row->jamaat),
            'jamaat_ar' => trim($row->jamaat_ar),
            'jamaat_type' => trim($row->jamaat_type),
            'alvazarat_id' => $row->alvazarat_id,
            'alvazarat_name' => trim($row->alvazarat_name),
            'aamil_masool_its_id' => (int) $row->aamil_masool_its_id,
            'muavin_its_id' => (int) $row->muavin_its_id,
            'country' => trim($row->country),
            'city' => trim($row->city),
            'state' => trim($row->state),
            'latitude' => trim($row->latitude),
            'longitude' => trim($row->longitude),
            'primary_email' => trim($row->primary_email),
            'secondary_email' => trim($row->secondary_email),
        ];
    }

    /**
     * Computes a hash for a normalized row.
     * Hashes each field separately, then the concatenated digests, to avoid delimiter collision.
     */
    public function computeRowHash(array $normalized): string
    {
        $fieldHashes = [];
        foreach ($normalized as $value) {
            $fieldHashes[] = hash(Constants::HASH_ALGORITHM, (string)$value);
        }

        return hash(Constants::HASH_ALGORITHM, implode('', $fieldHashes));
    }

    /**
     * Computes a hash of all rows, ordered by jamaat_id numerically ascending.
     * Payload_hash provides a fast short-circuit: if it matches the last run, skip all diffing.
     *
     * @param JamaatRow[] $rows
     */
    public function computePayloadHash(array $rows): string
    {
        // Sort numerically by jamaat_id ascending to ensure canonical order
        usort($rows, fn(JamaatRow $a, JamaatRow $b) => $a->jamaat_id - $b->jamaat_id);

        $rowHashes = [];
        foreach ($rows as $row) {
            $normalized = $this->normalizeRow($row);
            $rowHashes[] = $this->computeRowHash($normalized);
        }

        return hash(Constants::HASH_ALGORITHM, implode('', $rowHashes));
    }
}
