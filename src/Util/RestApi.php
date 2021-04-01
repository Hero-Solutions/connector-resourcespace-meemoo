<?php

namespace App\Util;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class RestApi
{
    private $authUrl;
    private $metadataEditUrl;
    private $credentials;

    private $overrideCertificateAuthorityFile;
    private $sslCertificateAuthorityFile;

    private $tokens = array();

    public function __construct(ParameterBagInterface $params)
    {
        $restApi = $params->get('rest_api');
        $this->authUrl = $restApi['auth_url'];
        $this->metadataEditUrl = $restApi['metadata_edit_url'];
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

    private function initializeToken($collection)
    {
        $ch = curl_init();
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
        if (!curl_errno($ch)) {
            $result = json_decode($resultJson);
            if(property_exists($result, 'access_token')) {
                $this->tokens[$collection] = $result->access_token;
            } else {
                switch ($http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE)) {
                    case 200:
                        echo 'Error: ' . $resultJson . PHP_EOL;
                        break;
                    default:
                        echo 'HTTP error ' . $http_code . ': ' . $resultJson . PHP_EOL;
                        break;
                }
            }
        }

        curl_close($ch);

    }
}
