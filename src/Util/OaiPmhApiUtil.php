<?php

namespace App\Util;

use Exception;
use Phpoaipmh\Client;
use Phpoaipmh\Endpoint;
use Phpoaipmh\Exception\HttpException;
use Phpoaipmh\Exception\OaipmhException;
use Phpoaipmh\HttpAdapter\CurlAdapter;

class OaiPmhApiUtil
{
    public static function connect($oaiPmhApi, $collection, $overrideCertificateAuthorityFile, $sslCertificateAuthorityFile)
    {
        $oaiPmhEndpoint = null;
        try {
            $curlAdapter = new CurlAdapter();
            $curlOpts = array(
                CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
                CURLOPT_USERPWD => $oaiPmhApi['credentials'][$collection]['username'] . ':' . $oaiPmhApi['credentials'][$collection]['password']
            );
            if ($overrideCertificateAuthorityFile) {
                $curlOpts[CURLOPT_CAINFO] = $sslCertificateAuthorityFile;
                $curlOpts[CURLOPT_CAPATH] = $sslCertificateAuthorityFile;
            }
            $curlAdapter->setCurlOpts($curlOpts);
            $oaiPmhClient = new Client($oaiPmhApi['url'], $curlAdapter);
            $oaiPmhEndpoint = new Endpoint($oaiPmhClient);
        } catch(OaipmhException $e) {
            if($e->getOaiErrorCode() == 'noRecordsMatch') {
                echo 'No records to process, exiting.' . PHP_EOL;
            } else {
                echo 'OAI-PMH error (1) at collection ' . $collection . ': ' . $e . PHP_EOL;
//                $this->logger->error('OAI-PMH error at collection ' . $collection . ': ' . $e);
            }
        }
        catch(HttpException $e) {
            echo 'OAI-PMH error (2) at collection ' . $collection . ': ' . $e . PHP_EOL;
//                $this->logger->error('OAI-PMH error at collection ' . $collection . ': ' . $e);
        }
        catch(Exception $e) {
            echo 'OAI-PMH error (3) at collection ' . $collection . ': ' . $e . PHP_EOL;
//                $this->logger->error('OAI-PMH error at collection ' . $collection . ': ' . $e);
        }
        return $oaiPmhEndpoint;
    }
}
