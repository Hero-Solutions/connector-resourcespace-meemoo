<?php

namespace App\Util;

use Exception;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class RestApi
{
    private $authUrl;
    private $metadataEditUrl;
    private $exportUrl;
    private $credentials;

    private $overrideCertificateAuthorityFile;
    private $sslCertificateAuthorityFile;

    private $tokens = array();

    public function __construct(ParameterBagInterface $params)
    {
        $restApi = $params->get('rest_api');
        $this->authUrl = $restApi['auth_url'];
        $this->metadataEditUrl = $restApi['metadata_edit_url'];
        $this->exportUrl = $restApi['export_url'];
        $this->credentials = $restApi['credentials'];

        $this->overrideCertificateAuthorityFile = $params->get('override_certificate_authority');
        $this->sslCertificateAuthorityFile = $params->get('ssl_certificate_authority_file');
    }

    public function updateMetadata($collection, $fragmentId, $jsonQuery)
    {
        if(!array_key_exists($collection, $this->tokens)) {
            $this->initializeToken($collection);
        }
        if(!array_key_exists($collection, $this->tokens)) {
            echo 'No valid OAuth token - metadata was not updated!' . PHP_EOL;
            return false;
        }

        // Remove zero width spaces (no idea how they got there)
        $jsonQuery = str_replace( '\u200b', '', $jsonQuery);

        $ch = curl_init();
        if ($this->overrideCertificateAuthorityFile) {
            curl_setopt($ch,CURLOPT_CAINFO, $this->sslCertificateAuthorityFile);
            curl_setopt($ch,CURLOPT_CAPATH, $this->sslCertificateAuthorityFile);
        }
        curl_setopt($ch,CURLOPT_URL, $this->metadataEditUrl . $fragmentId . '?access_token=' . urlencode($this->tokens[$collection]));
        curl_setopt($ch,CURLOPT_POST, true);
        curl_setopt($ch,CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch,CURLOPT_POSTFIELDS, $jsonQuery);

        $result = false;
        $resultJson = curl_exec($ch);
        if (!curl_errno($ch)) {
            switch ($http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE)) {
                case 200:  # OK
                    $result = true;
                    break;
                default:
                    echo 'HTTP error ' .  $http_code . ': ' . json_decode($resultJson)->Message . PHP_EOL;
                    break;
            }
        }
        curl_close($ch);
        return $result;
    }

    public function requestExportJob($collection, $id)
    {
        $resultJson = 'ERROR';
        if(!array_key_exists($collection, $this->tokens)) {
            $resultJson = $this->initializeToken($collection);
        }
        if(!array_key_exists($collection, $this->tokens)) {
            return array(
                "success" => false,
                "message" => 'No valid OAuth token - could not download original. Please report the following error to the museum\'s system administrator: ' . $resultJson
            );
        }

        $jsonQuery = json_encode(array("Records" => array(array("RecordId" => $id))));

        $ch = curl_init();
        if ($this->overrideCertificateAuthorityFile) {
            curl_setopt($ch,CURLOPT_CAINFO, $this->sslCertificateAuthorityFile);
            curl_setopt($ch,CURLOPT_CAPATH, $this->sslCertificateAuthorityFile);
        }
        curl_setopt($ch,CURLOPT_URL, $this->exportUrl . '?access_token=' . urlencode($this->tokens[$collection]));
        curl_setopt($ch,CURLOPT_POST, true);
        curl_setopt($ch,CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch,CURLOPT_POSTFIELDS, $jsonQuery);

        $resultJson = curl_exec($ch);
        $success = false;
        if (!curl_errno($ch)) {
            switch ($http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE)) {
                case 200:  # OK
                case 201:  # OK (created)
                    $success = true;
                    break;
                default:
                    return array(
                        "success" => false,
                        "message" => 'HTTP error ' .  $http_code . ': ' . $resultJson
                    );
                    break;
            }
        }
        curl_close($ch);
        return array(
            "success" => $success,
            "message" => $resultJson
        );
    }

    public function checkExportJobStatus($collection, $jobId)
    {
        $resultJson = 'ERROR';
        if(!array_key_exists($collection, $this->tokens)) {
            $resultJson = $this->initializeToken($collection);
        }
        if(!array_key_exists($collection, $this->tokens)) {
            return array(
                "success" => false,
                "message" => 'No valid OAuth token - could not download original. Please report the following error to the museum\'s system administrator: ' . $resultJson
            );
        }

        $ch = curl_init();
        if ($this->overrideCertificateAuthorityFile) {
            curl_setopt($ch,CURLOPT_CAINFO, $this->sslCertificateAuthorityFile);
            curl_setopt($ch,CURLOPT_CAPATH, $this->sslCertificateAuthorityFile);
        }
        curl_setopt($ch,CURLOPT_URL, $this->exportUrl . urlencode($jobId) . '?access_token=' . urlencode($this->tokens[$collection]));
        curl_setopt($ch,CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));

        $resultJson = curl_exec($ch);
        $success = false;
        if (!curl_errno($ch)) {
            switch ($http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE)) {
                case 200:  # OK
                case 201:  # OK (created)
                    $success = true;
                    break;
                default:
                    return array(
                        "success" => false,
                        "message" => 'HTTP error ' .  $http_code . ': ' . $resultJson
                    );
                    break;
            }
        }
        curl_close($ch);
        return array(
            "success" => $success,
            "message" => $resultJson
        );
    }

    private function initializeToken($collection)
    {
        $ch = curl_init();
        if ($ch === false) {
            return 'Failed to initialize cURL';
        }
        if ($this->overrideCertificateAuthorityFile) {
            curl_setopt($ch,CURLOPT_CAINFO, $this->sslCertificateAuthorityFile);
            curl_setopt($ch,CURLOPT_CAPATH, $this->sslCertificateAuthorityFile);
        }
        curl_setopt($ch,CURLOPT_URL, $this->authUrl);
        curl_setopt($ch,CURLOPT_POST, true);
        curl_setopt($ch,CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch,CURLOPT_POSTFIELDS,
            'username=' . urlencode($this->credentials[$collection]['username'])
            . '&password=' . urlencode($this->credentials[$collection]['password'])
            . '&client_id=' . urlencode($this->credentials[$collection]['client_id'])
            . '&client_secret=' . urlencode($this->credentials[$collection]['client_secret'])
        );

        $resultJson = curl_exec($ch);
        if($resultJson === false) {
            return 'Error: ' . curl_error($ch) . ': ' . curl_errno($ch);
        }

        if (!curl_errno($ch)) {
            $result = json_decode($resultJson);
            if(property_exists($result, 'access_token')) {
                $this->tokens[$collection] = $result->access_token;
            } else {
                switch ($http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE)) {
                    case 200:
                        $resultJson = 'Error: ' . $resultJson . PHP_EOL;
                        break;
                    default:
                        $resultJson = 'HTTP error ' . $http_code . ': ' . $resultJson . PHP_EOL;
                        break;
                }
            }
        }

        curl_close($ch);
        return $resultJson;
    }
}
