<?php
declare(strict_types=1);

namespace Jamaat\Cron\Services;

use Jamaat\Cron\Config\Constants;
use Jamaat\Cron\Entities\JamaatRow;

final class SoapService
{
    private string $soapUrl;
    private string $soapAction;
    private ?string $outboundInterface;

    public function __construct(
        string $soapUrl = Constants::SOAP_URL,
        string $soapAction = Constants::SOAP_ACTION,
        ?string $outboundInterface = null
    ) {
        $this->soapUrl = $soapUrl;
        $this->soapAction = $soapAction;
        $this->outboundInterface = $outboundInterface ?: null;
    }

    /**
     * @return string XML response body
     * @throws \RuntimeException
     */
    public function fetchPayload(string $key): string
    {
        $soapBody = $this->buildSoapBody($key);
        $headers = $this->buildHeaders($soapBody);

        for ($attempt = 0; $attempt <= Constants::SOAP_MAX_RETRIES; $attempt++) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->soapUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $soapBody);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            if ($this->outboundInterface !== null) {
                curl_setopt($ch, CURLOPT_INTERFACE, $this->outboundInterface);
            }
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, Constants::SOAP_CONNECT_TIMEOUT);
            curl_setopt($ch, CURLOPT_TIMEOUT, Constants::SOAP_TIMEOUT);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

            $response = curl_exec($ch);
            $curlErr = curl_error($ch);
            $curlErrNo = curl_errno($ch);
            curl_close($ch);

            if ($response === false) {
                // Retry on transient errors; fail immediately on deterministic rejection
                $isTransient = in_array($curlErrNo, [CURLE_OPERATION_TIMEDOUT, CURLE_COULDNT_CONNECT, CURLE_COULDNT_RESOLVE_HOST], true);
                if ($isTransient && $attempt < Constants::SOAP_MAX_RETRIES) {
                    usleep((2 ** $attempt) * 1_000_000);
                    continue;
                }
                throw new \RuntimeException("cURL error [{$curlErrNo}]: {$curlErr}");
            }

            return $response;
        }

        throw new \RuntimeException('SOAP request failed after ' . (Constants::SOAP_MAX_RETRIES + 1) . ' attempts');
    }

    /**
     * @return JamaatRow[]
     */
    public function parseResponse(string $response): array
    {
        $xml = simplexml_load_string($response);
        if ($xml === false) {
            throw new \RuntimeException('Failed to parse SOAP XML response');
        }

        $jamaat_data = $xml->xpath('//Table') ?: [];

        if (empty($jamaat_data)) {
            $resultNodes = $xml->xpath('//*[contains(local-name(), "Result")]');
            if ($resultNodes) {
                $innerXmlString = trim((string)$resultNodes[0]);
                if ($innerXmlString !== '') {
                    $innerXml = simplexml_load_string($innerXmlString);
                    if ($innerXml !== false) {
                        $jamaat_data = $innerXml->xpath('//Table') ?: [];
                    }
                }
            }
        }

        $rows = [];
        foreach ($jamaat_data as $row) {
            $rows[] = new JamaatRow(
                jamiaat_id: (int)$row->Jamiaat_ID,
                jamiaat: trim((string)$row->Jamiaat),
                jamaiaat_ar: trim((string)$row->Jamaiaat_AR),
                jamaat_id: (int)$row->Jamaat_ID,
                jamaat: trim((string)$row->Jamaat),
                jamaat_ar: trim((string)$row->Jamaat_AR),
                jamaat_type: trim((string)$row->Jamaat_Type),
                alvazarat_id: (int)$row->Alvazarat_ID,
                alvazarat_name: trim((string)$row->Alvazarat_Name),
                aamil_masool_its_id: (int)$row->Aamil_Masool_ITS_ID,
                muavin_its_id: (int)$row->Muavin_ITS_ID,
                country: trim((string)$row->Country),
                city: trim((string)$row->City),
                state: trim((string)$row->State),
                latitude: trim((string)$row->Latitude),
                longitude: trim((string)$row->Longitude),
                primary_email: trim((string)$row->Primary_Email),
                secondary_email: trim((string)$row->Secondary_Email),
            );
        }

        return $rows;
    }

    private function buildSoapBody(string $key): string
    {
        return <<<XML
<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
<soap:Body>
<Get_Jamaat_Jamiaat_Details xmlns="http://localhost/eJAS/EjamaatServices">
<strKey>{$key}</strKey>
</Get_Jamaat_Jamiaat_Details>
</soap:Body>
</soap:Envelope>
XML;
    }

    /**
     * @return string[]
     */
    private function buildHeaders(string $soapBody): array
    {
        return [
            'Content-type: text/xml;charset="utf-8"',
            'Accept: text/xml',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
            "SOAPAction: {$this->soapAction}",
            'Content-length: ' . strlen($soapBody),
        ];
    }
}
