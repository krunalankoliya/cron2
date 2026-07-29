<?php

// $database = new Matrix\talabulilm\MySQLiDB();
date_default_timezone_set("Asia/Kolkata");

$startTime = microtime(true);

$date    = date('Ymd');
$logFile = "/home/talabulilm/public_html/logFiles/outBound_$date.log";

function writeLog($logFile, $message)
{
    $fh = @fopen($logFile, 'a');
    if ($fh) {
        fwrite($fh, $message);
        fclose($fh);
    }
}

try {

    $key = 'Talabulilm562138';

    $soapUrl = "https://ejas.its52.com/ejamaatservices.asmx";

    $xml_post_string = '<?xml version="1.0" encoding="utf-8"?>
    <soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
    <soap:Body>
    <Get_Jamaat_Jamiaat_Details xmlns="http://localhost/eJAS/EjamaatServices">
    <strKey>' . $key . '</strKey>
    </Get_Jamaat_Jamiaat_Details>
    </soap:Body>
    </soap:Envelope>';

    $headers = [
        "Content-type: text/xml;charset=\"utf-8\"",
        "Accept: text/xml",
        "Cache-Control: no-cache",
        "Pragma: no-cache",
        "SOAPAction: http://localhost/eJAS/EjamaatServices/Get_Jamaat_Jamiaat_Details",
        "Content-length: " . strlen($xml_post_string),
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $soapUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $xml_post_string); // the SOAP request
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_INTERFACE, '66.85.132.227'); // force the whitelisted outbound IP

    // Log the start time and SOAP request
    writeLog($logFile, date('Y-m-d H:i:s') . ' - Start Time: ' . date('Y-m-d H:i:s', (int) $startTime) . PHP_EOL);
    writeLog($logFile, 'SOAP Request = Get_Jamaat_Jamiaat_Details' . PHP_EOL);
    writeLog($logFile, 'caller = its_jamaat_master' . PHP_EOL);

    $response = curl_exec($ch);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        writeLog($logFile, date('Y-m-d H:i:s') . ' - cURL error: ' . $curlErr . PHP_EOL);
        throw new Exception('cURL error: ' . $curlErr);
    }

    $xml = simplexml_load_string($response);
    if ($xml === false) {
        writeLog($logFile, date('Y-m-d H:i:s') . ' - Failed to parse SOAP XML response' . PHP_EOL);
        throw new Exception('Failed to parse SOAP XML response');
    }

    // First try: Table nodes directly under the envelope.
    $jamaat_data = $xml->xpath('//Table') ?: [];

    // Fallback: some ASMX responses return the dataset as an XML-escaped
    // STRING inside a "..._Result" element rather than as real XML nodes.
    if (empty($jamaat_data)) {
        $resultNodes = $xml->xpath('//*[contains(local-name(), "Result")]');
        if ($resultNodes) {
            $innerXmlString = trim((string) $resultNodes[0]);
            if ($innerXmlString !== '') {
                $innerXml = simplexml_load_string($innerXmlString);
                if ($innerXml !== false) {
                    $jamaat_data = $innerXml->xpath('//Table') ?: [];
                }
            }
        }
    }

    // If the API rejected the request (e.g. IP not whitelisted), the body
    // won't contain any Table nodes — log the raw response so it's visible.
    if (empty($jamaat_data)) {
        writeLog($logFile, date('Y-m-d H:i:s') . ' - No jamaat data in response. Raw response: ' . trim($response) . PHP_EOL);
    }

    // Calculate and log the total runtime
    $endTime      = microtime(true);
    $totalRuntime = $endTime - $startTime;
    writeLog($logFile, 'Total Runtime: ' . $totalRuntime . ' seconds' . PHP_EOL . PHP_EOL);

    if ($jamaat_data) {

        // Open every DB connection ONCE, outside the loop.
        $mysqli      = new mysqli("localhost", "talabulilm_core", "core@515253", "talabulilm_core");
        $mysqlifa    = new mysqli("localhost", "talabulilm_fanew", "new@515253", "talabulilm_fanew");
        $mysqliaraiz = new mysqli("localhost", "talabulilm_araiz", "araiz@515253", "talabulilm_araiz");
        // $mysqliprofile = new mysqli("localhost", "talabulilm", "0tRXgIXtyh0w", "talabulilm_profile");
        $mysqliimtihaan = new mysqli("localhost", "talabulilm_joker", "tlb@515253", "talabulilm_imtihaan");
        $mysqliinfra    = new mysqli("localhost", "talabulilm_core", "core@515253", "talabulilm_infra");

        $connections = [
            'core'     => $mysqli,
            'fanew'    => $mysqlifa,
            'araiz'    => $mysqliaraiz,
            // 'profile'  => $mysqliprofile,
            'imtihaan' => $mysqliimtihaan,
            'infra'    => $mysqliinfra,
        ];

        foreach ($connections as $conn) {
            $conn->set_charset("utf8");
        }

        $sql = "INSERT INTO `its_jamaat_master`
            (`jamiaat_id`, `jamiaat`, `jamaiaat_ar`, `jamaat_id`, `jamaat`, `jamaat_ar`,
             `jamaat_type`, `alvazarat_id`, `alvazarat_name`, `aamil_masool_its_id`, `muavin_its_id`,
             `country`, `city`, `state`, `latitude`, `longitude`,
             `primary_email`, `secondary_email`, `update_ts`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
             `jamiaat` = VALUES(`jamiaat`), `jamaiaat_ar` = VALUES(`jamaiaat_ar`),
             `jamaat` = VALUES(`jamaat`), `jamaat_ar` = VALUES(`jamaat_ar`),
             `jamaat_type` = VALUES(`jamaat_type`), `alvazarat_id` = VALUES(`alvazarat_id`),
             `alvazarat_name` = VALUES(`alvazarat_name`), `aamil_masool_its_id` = VALUES(`aamil_masool_its_id`),
             `muavin_its_id` = VALUES(`muavin_its_id`), `country` = VALUES(`country`),
             `city` = VALUES(`city`), `state` = VALUES(`state`),
             `latitude` = VALUES(`latitude`), `longitude` = VALUES(`longitude`),
             `primary_email` = VALUES(`primary_email`), `secondary_email` = VALUES(`secondary_email`),
             `update_ts` = VALUES(`update_ts`)";

        foreach ($jamaat_data as $jdata) {
            set_time_limit(30);

            $jamiaat_id          = (string) $jdata->Jamiaat_ID;
            $jamiaat             = (string) $jdata->Jamiaat;
            $jamaiaat_ar         = (string) $jdata->Jamaiaat_AR;
            $jamaat_id           = (string) $jdata->Jamaat_ID;
            $jamaat              = (string) $jdata->Jamaat;
            $jamaat_ar           = (string) $jdata->Jamaat_AR;
            $jamaat_type         = (string) $jdata->Jamaat_Type;
            $alvazarat_id        = (string) $jdata->Alvazarat_ID;
            $alvazarat_name      = (string) $jdata->Alvazarat_Name;
            $aamil_masool_its_id = (string) $jdata->Aamil_Masool_ITS_ID;
            $muavin_its_id       = (string) $jdata->Muavin_ITS_ID;
            $country             = (string) $jdata->Country;
            $city                = (string) $jdata->City;
            $state               = (string) $jdata->State;
            $latitude            = (string) $jdata->Latitude;
            $longitude           = (string) $jdata->Longitude;
            $primary_email       = (string) $jdata->Primary_Email;
            $secondary_email     = (string) $jdata->Secondary_Email;
            $update_ts           = date('Y-m-d H:i:s');

            foreach ($connections as $name => $conn) {
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $stmt->bind_param(
                        'sssssssssssssssssss', // 19 columns, all bound as strings
                        $jamiaat_id, $jamiaat, $jamaiaat_ar,
                        $jamaat_id, $jamaat, $jamaat_ar,
                        $jamaat_type, $alvazarat_id, $alvazarat_name,
                        $aamil_masool_its_id, $muavin_its_id, $country,
                        $city, $state, $latitude, $longitude,
                        $primary_email, $secondary_email, $update_ts
                    );
                    $stmt->execute();
                    $stmt->close();
                } else {
                    writeLog($logFile, date('Y-m-d H:i:s') . " - Prepare failed on [$name]: " . $conn->error . PHP_EOL);
                }
            }
        }

        foreach ($connections as $conn) {
            $conn->close();
        }
    }

    // ---- Sync admin_users from aamil/muavin ITS IDs ----
    $mysqlifa = new mysqli("localhost", "talabulilm_fanew", "new@515253", "talabulilm_fanew");
    $mysqlifa->set_charset("utf8");

    $roleQueries = [
        'aamil_masool_its_id' => "SELECT `i`.`aamil_masool_its_id` FROM `its_jamaat_master` `i`
            WHERE `i`.`aamil_masool_its_id` != 0
            AND `i`.`aamil_masool_its_id` NOT IN (SELECT `a`.`its_id` FROM `admin_users` `a`)",
        'muavin_its_id'       => "SELECT `i`.`muavin_its_id` FROM `its_jamaat_master` `i`
            WHERE `i`.`muavin_its_id` != 0
            AND `i`.`muavin_its_id` NOT IN (SELECT `a`.`its_id` FROM `admin_users` `a`)",
    ];

    $upsertSql = "INSERT INTO `admin_users` (`its_id`, `name`, `password`, `admin_role`, `expertise`, `is_active`)
                  VALUES (?, '', '', '3', '', '1')
                  ON DUPLICATE KEY UPDATE `admin_role` = '3', `is_active` = '1'";

    foreach ($roleQueries as $column => $query) {
        $result = $mysqlifa->query($query);
        if ($result) {
            $rows = $result->fetch_all(MYSQLI_ASSOC);
            foreach ($rows as $row) {
                $itsID = (int) $row[$column];
                $stmt  = $mysqlifa->prepare($upsertSql);
                if ($stmt) {
                    $stmt->bind_param('i', $itsID);
                    $stmt->execute();
                    $stmt->close();
                } else {
                    writeLog($logFile, date('Y-m-d H:i:s') . " - Prepare failed for admin_users [$column]: " . $mysqlifa->error . PHP_EOL);
                }
            }
        }
    }

    $mysqlifa->close();

} catch (\Exception $e) {
    writeLog($logFile, date('Y-m-d H:i:s') . ' - Error: ' . $e->getMessage() . PHP_EOL);
    echo "Error: " . $e->getMessage();
}
