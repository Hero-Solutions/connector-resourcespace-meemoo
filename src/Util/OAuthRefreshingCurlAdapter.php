<?php

namespace App\Util;

use Phpoaipmh\Exception\HttpException;
use Phpoaipmh\HttpAdapter\HttpAdapterInterface;

/**
 * Standalone replacement for the library's CurlAdapter: that class calls curl_close()
 * (deprecated since PHP 8.5) and lacks a native return type on request().
 * Adds OAuth bearer-token handling with a one-time refresh/retry on expired tokens.
 */
class OAuthRefreshingCurlAdapter implements HttpAdapterInterface
{
    public function __construct(
        private RestApi $restApi,
        private string $collection,
        private array $baseCurlOpts = []
    ) {
    }

    public function request($url): string
    {
        $this->restApi->ensureValidAccessToken($this->collection);

        try {
            return $this->doRequest($url);
        } catch (HttpException $e) {
            if ($this->isInvalidTokenException($e)) {
                if (!$this->restApi->forceRefreshAccessToken($this->collection)) {
                    echo 'HTTP exception on URL ' . $url . PHP_EOL;
                    throw $e;
                }

                return $this->doRequest($url);
            }

            if (!$this->isEmptyNotFoundException($e)) {
                echo 'HTTP exception on URL ' . $url . PHP_EOL;
            }

            throw $e;
        }
    }

    private function doRequest(string $url): string
    {
        $curlOpts = array_replace([CURLOPT_RETURNTRANSFER => true], $this->baseCurlOpts);
        $curlOpts[CURLOPT_URL] = $url;
        $curlOpts[CURLOPT_HTTPHEADER] = $this->buildHeaders();

        $ch = curl_init();
        curl_setopt_array($ch, $curlOpts);

        $response = curl_exec($ch);
        if ($response === false) {
            throw new HttpException('', 'HTTP Request Failed: ' . curl_error($ch) . ' (errno ' . curl_errno($ch) . ')');
        }

        $httpCode = (string) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        if (!str_starts_with($httpCode, '2')) {
            throw new HttpException($response, sprintf('HTTP Request Failed (code %s): %s', $httpCode, $response), $httpCode);
        }
        if (trim($response) === '') {
            throw new HttpException($response, 'HTTP Response Empty');
        }

        return $response;
    }

    private function buildHeaders(): array
    {
        $headers = ['Accept: application/xml, text/xml;q=0.9, */*;q=0.8'];

        $token = $this->restApi->getRawAccessToken($this->collection);
        if ($token !== null) {
            array_unshift($headers, 'Authorization: Bearer ' . $token);
        }

        return $headers;
    }

    private function isInvalidTokenException(HttpException $e): bool
    {
        if ((int) $e->getCode() !== 401) {
            return false;
        }

        $body = method_exists($e, 'getBody') ? $e->getBody() : '';
        if (!is_string($body) || $body === '') {
            return false;
        }

        $json = json_decode($body, true);
        if (!is_array($json)) {
            return false;
        }

        return ($json['error'] ?? null) === 'invalid_token'
            || str_contains((string) ($json['error_description'] ?? ''), 'expired');
    }

    private function isEmptyNotFoundException(HttpException $e): bool
    {
        return (int) $e->getCode() === 404 && trim($e->getBody()) === '';
    }
}
