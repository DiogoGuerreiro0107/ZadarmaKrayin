<?php

namespace Webkul\Zadarma\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webkul\Zadarma\Models\UserExtension;
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

        // The user's own extension/prefix take priority; fall back to the
        // shared extension (Configuration > Zadarma) and the app-wide
        // default prefix when they haven't set personal ones.
        $userExtension = UserExtension::where('user_id', auth()->id())->first();

        $extension = $userExtension?->extension
            ?? system_config()->getConfigData('zadarma.settings.credentials.caller_extension');

        $outboundPrefix = $userExtension?->outbound_prefix
            ?? config('zadarma.outbound_prefix');

        // Prepend the outbound routing prefix so the call goes out showing
        // the company's main caller ID, mirroring how calls are already
        // dialed manually from the mobile app.
        $to = $outboundPrefix.$request->input('to');

        try {
            $response = $this->client->request('/v1/request/callback/', [
                'from' => $extension,
                'to' => $to,
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
