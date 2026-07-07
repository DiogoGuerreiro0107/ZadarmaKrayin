<?php

namespace Webkul\Zadarma\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Webkul\Zadarma\Models\CallRecord;
use Webkul\Zadarma\Services\CallRecordSync;
use Webkul\Zadarma\Services\WebhookSignatureVerifier;
use Webkul\Zadarma\Services\ZadarmaClient;

/**
 * Receives Zadarma PBX call event notifications (NOTIFY_START, NOTIFY_INTERNAL,
 * NOTIFY_ANSWER, NOTIFY_END, NOTIFY_OUT_START, NOTIFY_OUT_END, NOTIFY_RECORD,
 * NOTIFY_IVR — confirmed event names, see CLAUDE.md 8.4).
 *
 * This is a public, unauthenticated route: never trust its payload without a
 * valid signature, and never let a malformed/unrecognized payload throw.
 */
class WebhookController
{
    public function __construct(
        protected WebhookSignatureVerifier $verifier,
        protected CallRecordSync $callRecordSync,
        protected ZadarmaClient $client,
    ) {}

    public function handle(Request $request): Response
    {
        if (! $this->verifier->verify($request)) {
            Log::warning('Zadarma webhook rejected: invalid or missing signature.', [
                'ip' => $request->ip(),
            ]);

            return response('Invalid signature.', 403);
        }

        $event = strtoupper((string) $request->input('event', $request->input('event_type', '')));

        Log::info('Zadarma webhook received.', [
            'event' => $event,
            'payload' => $request->except('signature'),
        ]);

        match ($event) {
            'NOTIFY_END', 'NOTIFY_OUT_END' => $this->handleCallEnd($request, $event),
            'NOTIFY_RECORD' => $this->handleRecordReady($request),
            default => null,
        };

        return response('', 200);
    }

    /**
     * Field names below are best-effort (Zadarma's published docs do not
     * detail the exact webhook payload) — confirm and adjust during Fase 7
     * live testing, using the raw payload logged above.
     */
    protected function handleCallEnd(Request $request, string $event): void
    {
        $this->callRecordSync->upsert([
            'external_id' => (string) $request->input('pbx_call_id', $request->input('call_id', '')),
            'direction' => str_contains($event, 'OUT') ? 'outbound' : 'inbound',
            'from_number' => (string) $request->input('caller_id', ''),
            'to_number' => (string) $request->input('called_did', ''),
            'duration' => (int) $request->input('duration', 0),
            'disposition' => $request->input('disposition'),
            'recording_url' => null,
            // Best-effort assumption (see class docblock): treated as UTC,
            // same as the polling path — see SyncCallsCommand::normalize().
            'started_at' => $request->input('call_start')
                ? Carbon::parse($request->input('call_start'), 'UTC')->setTimezone(config('app.timezone'))
                : null,
            'sip' => $request->input('internal', $request->input('sip')),
        ]);
    }

    /**
     * NOTIFY_RECORD fires once a call recording has finished processing —
     * fetch its download link now rather than at NOTIFY_END, since the
     * recording likely isn't ready yet at that point.
     */
    protected function handleRecordReady(Request $request): void
    {
        $externalId = (string) $request->input('pbx_call_id', $request->input('call_id', ''));

        if ($externalId === '') {
            return;
        }

        $record = CallRecord::where('external_id', $externalId)->first();

        if (! $record || $record->recording_url) {
            return;
        }

        $link = $this->client->getRecordingLink($externalId);

        if ($link) {
            $record->update(['recording_url' => $link]);
        }
    }
}
