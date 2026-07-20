<?php

namespace Webkul\Zadarma\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Webkul\Zadarma\Models\CallRecord;
use Webkul\Zadarma\Services\CallRecordSync;
use Webkul\Zadarma\Services\ZadarmaClient;

class BackfillCallUsersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'zadarma:backfill-users';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Re-resolve user_id on existing call_records whose Zadarma extension was only configured after the calls were originally synced (sync only resolves user_id at insert time, never retroactively).';

    protected const PAGE_SIZE = 1000;

    public function handle(ZadarmaClient $client, CallRecordSync $callRecordSync): int
    {
        $oldest = CallRecord::whereNull('user_id')->min('started_at');

        if (! $oldest) {
            $this->info('No call_records with a missing user_id. Nothing to backfill.');

            return self::SUCCESS;
        }

        $end = now()->utc();
        $start = Carbon::parse($oldest, config('app.timezone'))->utc();

        $sipsByCallId = [];

        foreach (['in', 'out'] as $callType) {
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
                    $callId = (string) ($call['call_id'] ?? '');

                    if ($callId !== '' && ! empty($call['sip'])) {
                        $sipsByCallId[$callId] = (string) $call['sip'];
                    }
                }

                $skip += self::PAGE_SIZE;
            } while (count($calls) === self::PAGE_SIZE);
        }

        $updated = 0;

        CallRecord::whereNull('user_id')->chunkById(200, function ($records) use ($sipsByCallId, $callRecordSync, &$updated) {
            foreach ($records as $record) {
                $sip = $sipsByCallId[$record->external_id] ?? null;

                if (! $sip) {
                    continue;
                }

                $userId = $callRecordSync->matchUser($sip);

                if ($userId) {
                    $record->update(['user_id' => $userId]);
                    $updated++;
                }
            }
        });

        $this->info("Backfilled user_id on {$updated} call record(s).");

        return self::SUCCESS;
    }
}
