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

        $response = $this->http->request($httpMethod, $method, [
            'query' => $httpMethod === 'GET' ? $params : [],
            'form_params' => $httpMethod !== 'GET' ? $params : [],
            'headers' => [
                'Authorization' => $this->apiKey.':'.$this->sign($method, $paramsString),
            ],
        ]);

        return json_decode((string) $response->getBody(), true) ?? [];
    }

    /**
     * Sign a request per Zadarma's HMAC-SHA1 scheme:
     * base64(hmac_sha1(method + sorted_params_string + md5(sorted_params_string), apiSecret)).
     */
    public function sign(string $method, string $paramsString): string
    {
        $md5Params = md5($paramsString);

        return base64_encode(hash_hmac('sha1', $method.$paramsString.$md5Params, (string) $this->apiSecret, true));
    }
}
