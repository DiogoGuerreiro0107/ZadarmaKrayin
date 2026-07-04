<?php

namespace Webkul\Zadarma\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webkul\Zadarma\Services\ZadarmaClient;

class CallController
{
    public function __construct(
        protected ZadarmaClient $client,
    ) {}

    /**
     * Place a click-to-call request: Zadarma rings the configured caller
     * extension first, and only connects it to the target number once
     * answered.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'to' => ['required', 'string'],
        ]);

        if (! system_config()->getConfigData('zadarma.settings.credentials.active')) {
            return response()->json([
                'message' => trans('zadarma::app.call.not-active'),
            ], 422);
        }

        $extension = system_config()->getConfigData('zadarma.settings.credentials.caller_extension');

        try {
            $response = $this->client->request('/v1/request/callback/', [
                'from' => $extension,
                'to' => $request->input('to'),
            ]);
        } catch (Throwable $exception) {
            Log::error('Zadarma click-to-call request failed.', ['exception' => $exception->getMessage()]);

            return response()->json([
                'message' => trans('zadarma::app.call.failed'),
            ], 500);
        }

        if (($response['status'] ?? null) !== 'success') {
            return response()->json([
                'message' => $response['message'] ?? trans('zadarma::app.call.failed'),
            ], 422);
        }

        return response()->json([
            'message' => trans('zadarma::app.call.requested'),
        ]);
    }
}
