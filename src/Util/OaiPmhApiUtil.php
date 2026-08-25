<?php

namespace App\Util;

use Exception;
use Phpoaipmh\Client;
use Phpoaipmh\Endpoint;
use Phpoaipmh\Exception\HttpException;
use Phpoaipmh\Exception\OaipmhException;
use Phpoaipmh\Granularity;

class OaiPmhApiUtil
{
    public static function connect(
        $restApi,
        $oaiPmhApi,
        $collection,
        $overrideCertificateAuthorityFile,
        $sslCertificateAuthorityFile,
        &$granularityOutput = null
    ): ?Endpoint
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
            $autoGranularityEndpoint = new Endpoint($oaiPmhClient);
            $identifyResponse = $autoGranularityEndpoint->identify();
            $granularity = isset($identifyResponse->Identify->granularity)
                ? (string) $identifyResponse->Identify->granularity
                : Granularity::DATE;
            if (!in_array($granularity, [Granularity::DATE, Granularity::DATE_AND_TIME], true)) {
                throw new \UnexpectedValueException(
                    'Unsupported OAI-PMH granularity returned by Identify: ' . $granularity
                );
            }
            $granularityOutput = $granularity;

            // Supplying the discovered granularity avoids a hidden Identify request for every
            // subsequent daily ListRecords window.
            return new Endpoint($oaiPmhClient, $granularity);
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
