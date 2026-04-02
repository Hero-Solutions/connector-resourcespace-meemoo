<?php

namespace App\Util;

use Exception;
use Phpoaipmh\Client;
use Phpoaipmh\Endpoint;
use Phpoaipmh\Exception\HttpException;
use Phpoaipmh\Exception\OaipmhException;

class OaiPmhApiUtil
{
    public static function connect($restApi, $oaiPmhApi, $collection, $overrideCertificateAuthorityFile, $sslCertificateAuthorityFile): ?Endpoint
    {
        try {
            if (!$restApi->ensureValidAccessToken($collection)) {
                echo 'No valid OAuth token generated!' . PHP_EOL;
                return null;
            }

            $curlOpts = [
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_RETURNTRANSFER => true,
            ];

            if ($overrideCertificateAuthorityFile) {
                $curlOpts[CURLOPT_CAINFO] = $sslCertificateAuthorityFile;
            }

            $curlAdapter = new OAuthRefreshingCurlAdapter(
                $restApi,
                $collection,
                $curlOpts
            );

            $oaiPmhClient = new Client($oaiPmhApi['url'], $curlAdapter);

            return new Endpoint($oaiPmhClient);
        } catch (OaipmhException $e) {
            if ($e->getOaiErrorCode() === 'noRecordsMatch') {
                echo 'No records to process, exiting.' . PHP_EOL;
            } else {
                echo 'OAI-PMH error (1) at collection ' . $collection . ': ' . $e . PHP_EOL;
            }
        } catch (HttpException $e) {
            echo 'OAI-PMH error (2) at collection ' . $collection . ': ' . $e . PHP_EOL;
        } catch (Exception $e) {
            echo 'OAI-PMH error (3) at collection ' . $collection . ': ' . $e . PHP_EOL;
        }

        return null;
    }
}
