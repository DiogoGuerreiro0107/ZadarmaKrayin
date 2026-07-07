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
    protected $description = 'Pull call history from the Zadarma statistics API (polling mode) and upsert it into call_records.';

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

        $end = now();
        $start = $this->resolveWindowStart($end);

        $synced = 0;
        $skip = 0;

        try {
            do {
                $response = $client->request('/v1/statistics/', [
                    'start' => $start->format('Y-m-d H:i:s'),
                    'end' => $end->format('Y-m-d H:i:s'),
                    'skip' => $skip,
                    'limit' => self::PAGE_SIZE,
                ]);

                $calls = $response['stats'] ?? [];

                foreach ($calls as $call) {
                    $record = $callRecordSync->upsert($this->normalize($call));

                    // A call with no talk time was never answered, so it
                    // can't have a recording — skip the extra API round
                    // trip for those.
                    if ($record && ! $record->recording_url && $record->duration > 0) {
                        $link = $client->getRecordingLink($record->external_id);

                        if ($link) {
                            $record->update(['recording_url' => $link]);
                        }
                    }

                    $synced++;
                }

                $skip += self::PAGE_SIZE;
            } while (count($calls) === self::PAGE_SIZE);
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

        $start = $lastSyncedAt ? Carbon::parse($lastSyncedAt) : $end->copy()->subDay();

        $earliestAllowed = $end->copy()->subDays(self::MAX_WINDOW_DAYS);

        return $start->lessThan($earliestAllowed) ? $earliestAllowed : $start;
    }

    /**
     * Map a raw /v1/statistics/ call record to the shape CallRecordSync expects.
     *
     * The exact field names below (particularly a direction indicator) are
     * best-effort based on Zadarma's published docs and have not yet been
     * verified against a live account — confirm during Fase 7.
     */
    protected function normalize(array $call): array
    {
        return [
            'external_id' => (string) ($call['id'] ?? ''),
            'direction' => $call['call_type'] ?? $call['direction'] ?? 'unknown',
            'from_number' => (string) ($call['from'] ?? ''),
            'to_number' => (string) ($call['to'] ?? ''),
            'duration' => (int) ($call['billseconds'] ?? 0),
            'disposition' => $call['disposition'] ?? null,
            'recording_url' => null,
            'started_at' => $call['callstart'] ?? null,
            'sip' => $call['sip'] ?? null,
        ];
    }
}
