<?php

namespace Webkul\Zadarma\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webkul\Core\Models\CoreConfig;
use Webkul\Zadarma\Services\CallRecordSync;
use Webkul\Zadarma\Services\ZadarmaClient;

class SyncCallsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'zadarma:sync-calls';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Pull call history from the Zadarma PBX statistics API (polling mode) and upsert it into call_records.';

    /**
     * Zadarma allows a maximum query window of 30 days per request.
     */
    protected const MAX_WINDOW_DAYS = 30;

    /**
     * Zadarma allows a maximum of 1000 lines per request.
     */
    protected const PAGE_SIZE = 1000;

    protected const LAST_SYNCED_AT_CODE = 'zadarma.settings.last_synced_at';

    /**
     * Handle.
     */
    public function handle(ZadarmaClient $client, CallRecordSync $callRecordSync): int
    {
        if (! system_config()->getConfigData('zadarma.settings.credentials.active')) {
            $this->info('Zadarma integration is not active. Skipping.');

            return self::SUCCESS;
        }

        // Zadarma's API (and the callstart timestamps it returns) is treated
        // as UTC here, regardless of the Krayin installation's own
        // APP_TIMEZONE — this app defaults to Asia/Kolkata, and sending
        // bare app-timezone timestamps to Zadarma silently shifted every
        // query window by the UTC offset, making the narrow recurring sync
        // window miss real calls entirely (only caught because a manual
        // resync happened to use a wide enough window to still overlap).
        $end = now()->utc();
        $start = $this->resolveWindowStart($end);

        $synced = 0;

        try {
            // /v1/statistics/pbx/ — not /v1/statistics/ — is the endpoint that
            // actually reflects calls routed through the PBX/extensions
            // (i.e. everything our click-to-call button and normal office
            // phone use produce). Confirmed for real: a call placed through
            // this package showed up here but never in /v1/statistics/.
            // call_type ('in'/'out') is a query filter, not a returned field,
            // so it's queried twice to get a reliable direction per call —
            // resolving the "unknown direction" limitation from Fase 3.
            foreach (['in' => 'inbound', 'out' => 'outbound'] as $callType => $direction) {
                $skip = 0;

                do {
                    $response = $client->request('/v1/statistics/pbx/', [
                        'start' => $start->format('Y-m-d H:i:s'),
                        'end' => $end->format('Y-m-d H:i:s'),
                        'call_type' => $callType,
                        'skip' => $skip,
                        'limit' => self::PAGE_SIZE,
                    ]);

                    $calls = $response['stats'] ?? [];

                    foreach ($calls as $call) {
                        $record = $callRecordSync->upsert($this->normalize($call, $direction));

                        if ($record && ! $record->recording_url && ! empty($call['is_recorded'])) {
                            $callId = (string) ($call['pbx_call_id'] ?? $call['call_id'] ?? '');

                            $link = $callId !== '' ? $client->getRecordingLink($callId) : null;

                            if ($link) {
                                $record->update(['recording_url' => $link]);
                            }
                        }

                        $synced++;
                    }

                    $skip += self::PAGE_SIZE;
                } while (count($calls) === self::PAGE_SIZE);
            }
        } catch (Throwable $exception) {
            Log::error('Zadarma call sync failed.', ['exception' => $exception->getMessage()]);

            $this->error('Zadarma call sync failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        // Only advance the watermark once the window has synced successfully,
        // so a failed run retries the same window next time.
        CoreConfig::updateOrCreate(
            ['code' => self::LAST_SYNCED_AT_CODE],
            ['value' => $end->toDateTimeString()]
        );

        $this->info("Synced {$synced} call(s).");

        return self::SUCCESS;
    }

    /**
     * Resolve the start of the sync window from the last successful sync,
     * clamped to Zadarma's maximum 30-day query window.
     */
    protected function resolveWindowStart(Carbon $end): Carbon
    {
        $lastSyncedAt = CoreConfig::where('code', self::LAST_SYNCED_AT_CODE)->value('value');

        // last_synced_at is always stored as a UTC timestamp (see handle()),
        // so it must be parsed back as UTC too, not the app's local timezone.
        $start = $lastSyncedAt ? Carbon::parse($lastSyncedAt, 'UTC') : $end->copy()->subDay();

        $earliestAllowed = $end->copy()->subDays(self::MAX_WINDOW_DAYS);

        return $start->lessThan($earliestAllowed) ? $earliestAllowed : $start;
    }

    /**
     * Map a raw /v1/statistics/pbx/ call record to the shape CallRecordSync
     * expects. Field names confirmed for real against a live account
     * (2026-07-07): call_id, sip, callstart, clid, destination, disposition,
     * seconds, is_recorded, pbx_call_id.
     */
    protected function normalize(array $call, string $direction): array
    {
        return [
            'external_id' => (string) ($call['call_id'] ?? ''),
            'direction' => $direction,
            'from_number' => (string) ($call['clid'] ?? ''),
            'to_number' => (string) ($call['destination'] ?? ''),
            'duration' => (int) ($call['seconds'] ?? 0),
            'disposition' => $call['disposition'] ?? null,
            'recording_url' => null,
            // Zadarma returns callstart as a bare "Y-m-d H:i:s" string in UTC
            // (best-effort assumption, consistent with the query window
            // above) — convert it to the app's own timezone explicitly so it
            // doesn't get silently misinterpreted as being in that timezone
            // already.
            'started_at' => $call['callstart']
                ? Carbon::parse($call['callstart'], 'UTC')->setTimezone(config('app.timezone'))
                : null,
            'sip' => $call['sip'] ?? null,
        ];
    }
}
