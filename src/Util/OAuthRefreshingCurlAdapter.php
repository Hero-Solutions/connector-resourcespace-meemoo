<?php

namespace App\Util;

use Phpoaipmh\Exception\HttpException;
use Phpoaipmh\HttpAdapter\CurlAdapter;

class OAuthRefreshingCurlAdapter extends CurlAdapter
{
    public function __construct(
        private RestApi $restApi,
        private string $collection,
        private array $baseCurlOpts = []
    ) {
        parent::__construct();
        $this->applyAccessTokenToCurlOpts();
    }

    public function request($url)
    {
        $this->restApi->ensureValidAccessToken($this->collection);
        $this->applyAccessTokenToCurlOpts();

        try {
            return parent::request($url);
        } catch (HttpException $e) {
            if ($this->isInvalidTokenException($e)) {
                if (!$this->restApi->forceRefreshAccessToken($this->collection)) {
                    throw $e;
                }

                $this->applyAccessTokenToCurlOpts();

                return parent::request($url);
            }

            throw $e;
        }
    }

    private function applyAccessTokenToCurlOpts(): void
    {
        $token = $this->restApi->getRawAccessToken($this->collection);

        if ($token === null) {
            return;
        }

        $curlOpts = $this->baseCurlOpts;
        $curlOpts[CURLOPT_HTTPHEADER] = [
            'Authorization: Bearer ' . $token,
            'Accept: application/xml, text/xml;q=0.9, */*;q=0.8',
        ];

        $this->setCurlOpts($curlOpts, false);
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
}
