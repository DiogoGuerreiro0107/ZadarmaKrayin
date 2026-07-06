<?php

namespace Webkul\Zadarma\Services;

use GuzzleHttp\Client;

class ZadarmaClient
{
    protected Client $http;

    public function __construct(
        protected ?string $apiKey = null,
        protected ?string $apiSecret = null,
    ) {
        $this->apiKey ??= (string) system_config()->getConfigData('zadarma.settings.credentials.api_key');
        $this->apiSecret ??= (string) system_config()->getConfigData('zadarma.settings.credentials.api_secret');

        $this->http = new Client([
            'base_uri' => config('zadarma.api_base_url'),
        ]);
    }

    /**
     * Perform a signed request against the Zadarma API.
     *
     * @param  string  $method  API path, e.g. "/v1/statistics/"
     */
    public function request(string $method, array $params = [], string $httpMethod = 'GET'): array
    {
        ksort($params);

        $paramsString = http_build_query($params);

        // Pass the already-built string (not the array) so Guzzle appends it
        // verbatim instead of re-encoding it with its own query aggregator —
        // otherwise the bytes actually sent (e.g. RFC3986 %20 for spaces)
        // can differ from paramsString (RFC1738 '+'), invalidating the
        // signature even with correct credentials.
        $response = $this->http->request($httpMethod, $method, [
            'query' => $httpMethod === 'GET' ? $paramsString : '',
            'body' => $httpMethod !== 'GET' ? $paramsString : null,
            'headers' => [
                'Authorization' => $this->apiKey.':'.$this->sign($method, $paramsString),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
        ]);

        return json_decode((string) $response->getBody(), true) ?? [];
    }

    /**
     * Sign a request per Zadarma's HMAC-SHA1 scheme (matches the official
     * PHP example exactly: hash_hmac() WITHOUT raw-binary output — i.e. the
     * hex digest is what gets base64-encoded, not the raw bytes).
     */
    public function sign(string $method, string $paramsString): string
    {
        $md5Params = md5($paramsString);

        return base64_encode(hash_hmac('sha1', $method.$paramsString.$md5Params, (string) $this->apiSecret));
    }
}
