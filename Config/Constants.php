<?php
declare(strict_types=1);

namespace Jamaat\Cron\Config;

final class Constants
{
    public const HASH_ALGORITHM = 'sha256';
    public const CHUNK_SIZE = 300;
    public const SOAP_CONNECT_TIMEOUT = 10;
    public const SOAP_TIMEOUT = 45;
    public const SOAP_MAX_RETRIES = 2;
    public const LOCK_FILE = '/tmp/jamaat_sync.lock';
    public const SOAP_URL = 'https://ejas.its52.com/ejamaatservices.asmx';
    public const SOAP_ACTION = 'http://localhost/eJAS/EjamaatServices/Get_Jamaat_Jamiaat_Details';
    public const LOG_DIR = '/home/talabulilm/public_html/logFiles';
    public const FEED_NAME = 'jamaat_jamiaat';

    private function __construct()
    {
    }
}
