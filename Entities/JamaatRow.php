<?php
declare(strict_types=1);

namespace Jamaat\Cron\Entities;

final readonly class JamaatRow
{
    public function __construct(
        public int $jamiaat_id,
        public string $jamiaat,
        public string $jamaiaat_ar,
        public int $jamaat_id,
        public string $jamaat,
        public string $jamaat_ar,
        public string $jamaat_type,
        public int $alvazarat_id,
        public string $alvazarat_name,
        public int $aamil_masool_its_id,
        public int $muavin_its_id,
        public string $country,
        public string $city,
        public string $state,
        public string $latitude,
        public string $longitude,
        public string $primary_email,
        public string $secondary_email,
    ) {
    }
}
