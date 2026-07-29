<?php
declare(strict_types=1);

namespace Jamaat\Cron\Services;

final class DiffService
{
    /**
     * Diff staging vs master to find changed/new rows.
     * Returns jamaat_id => row_hash pairs for rows that differ or are new.
     *
     * @param array<int, array{jamaat_id: int, row_hash: string}> $masterRows
     * @param array<int, array{jamaat_id: int, row_hash: string}> $stagingRows
     * @return array<int, string> Changed/new rows: jamaat_id => staging_row_hash
     */
    public function findChangedRows(array $masterRows, array $stagingRows): array
    {
        $changed = [];

        foreach ($stagingRows as $jamaatId => $stagingRow) {
            // New row: doesn't exist in master
            if (!isset($masterRows[$jamaatId])) {
                $changed[$jamaatId] = $stagingRow['row_hash'];
                continue;
            }

            // Existing row: check if hash differs
            $masterHash = $masterRows[$jamaatId]['row_hash'];
            $stagingHash = $stagingRow['row_hash'];

            if ($masterHash !== $stagingHash) {
                $changed[$jamaatId] = $stagingHash;
            }
        }

        return $changed;
    }

    /**
     * Find rows that exist in master but not in staging (deleted).
     *
     * @param array<int, array{jamaat_id: int, row_hash: string}> $masterRows
     * @param array<int, array{jamaat_id: int, row_hash: string}> $stagingRows
     * @return int[] Deleted jamaat_ids
     */
    public function findDeletedRows(array $masterRows, array $stagingRows): array
    {
        $deleted = [];

        foreach ($masterRows as $jamaatId => $masterRow) {
            if (!isset($stagingRows[$jamaatId])) {
                $deleted[] = $jamaatId;
            }
        }

        return $deleted;
    }
}
