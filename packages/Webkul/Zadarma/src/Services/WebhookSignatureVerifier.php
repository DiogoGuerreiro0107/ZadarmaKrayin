<?php

namespace Webkul\Zadarma\Services;

use Illuminate\Http\Request;

class WebhookSignatureVerifier
{
    public function __construct(
        protected ?string $apiSecret = null,
    ) {
        $this->apiSecret ??= (string) system_config()->getConfigData('zadarma.settings.credentials.api_secret');
    }

    /**
     * Verify that a webhook request was genuinely sent by Zadarma.
     *
     * Best-effort implementation mirroring Zadarma's documented outbound API
     * signing scheme (HMAC-SHA1 of the sorted, url-encoded params, base64
     * encoded) applied to the notification params themselves. Zadarma's
     * public docs do not document a webhook-specific signing scheme, so this
     * has not been verified against a real notification — confirm and adjust
     * during Fase 7 live testing.
     */
    public function verify(Request $request): bool
    {
        $signature = $request->input('signature');

        if (! $signature || ! $this->apiSecret) {
            return false;
        }

        $params = $request->except('signature');

        ksort($params);

        $paramsString = http_build_query($params);

        $expected = base64_encode(hash_hmac('sha1', $paramsString, $this->apiSecret));

        return hash_equals($expected, $signature);
    }
}
