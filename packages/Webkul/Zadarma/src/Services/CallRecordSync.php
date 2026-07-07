<?php

namespace Webkul\Zadarma\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Webkul\Activity\Models\Activity;
use Webkul\Contact\Models\Person;
use Webkul\Zadarma\Models\CallRecord;
use Webkul\Zadarma\Models\UserExtension;

class CallRecordSync
{
    /**
     * Upsert a call record from a normalized payload, matching it to a
     * Person by phone number. Shared between polling (Zadarma statistics
     * API) and webhook (Zadarma call events) discovery paths.
     *
     * Returns null (without writing anything) if the payload has no usable
     * external_id, since call_records.external_id is unique and every call
     * without one would otherwise collide on the same row.
     *
     * @param  array{external_id: string, direction: string, from_number: string, to_number: string, duration: int, disposition: ?string, recording_url: ?string, started_at: ?string, sip: ?string}  $call
     */
    public function upsert(array $call): ?CallRecord
    {
        if (($call['external_id'] ?? '') === '') {
            Log::warning('Zadarma: skipping call record with no external_id.', $call);

            return null;
        }

        $matchNumber = $call['direction'] === 'outbound'
            ? ($call['to_number'] ?? null)
            : ($call['from_number'] ?? null);

        $record = CallRecord::updateOrCreate(
            ['external_id' => $call['external_id']],
            [
                'direction' => $call['direction'] ?? 'unknown',
                'from_number' => $call['from_number'] ?? '',
                'to_number' => $call['to_number'] ?? '',
                'duration' => $call['duration'] ?? 0,
                'disposition' => $call['disposition'] ?? null,
                'recording_url' => $call['recording_url'] ?? null,
                'started_at' => $call['started_at'] ?? null,
                'person_id' => $matchNumber ? $this->matchPerson($matchNumber) : null,
                'user_id' => ! empty($call['sip']) ? $this->matchUser($call['sip']) : null,
            ]
        );

        $this->logActivity($record);

        return $record;
    }

    /**
     * Log the call as a Krayin Activity (type=call) attached to the matched
     * Person, so it shows up alongside notes/meetings in the native
     * Activities feature — not just our own Call History section.
     *
     * Only attached to the Person (never to a Lead): a person can have
     * several leads and call_records only resolves person_id, so there is
     * no reliable way to pick "the" lead a call belongs to.
     *
     * Idempotent via call_records.activity_id: re-processing the same call
     * (e.g. a repeated webhook notification, or an overlapping polling
     * window) updates the existing Activity instead of creating a duplicate.
     */
    protected function logActivity(CallRecord $record): void
    {
        if (! $record->person_id) {
            return;
        }

        $startedAt = $record->started_at ? Carbon::parse($record->started_at) : now();
        $endedAt = $startedAt->copy()->addSeconds($record->duration ?: 0);

        $number = $record->direction === 'outbound' ? $record->to_number : $record->from_number;

        $comment = sprintf(
            '%s call with %s — duration %s, disposition: %s',
            ucfirst($record->direction),
            $number,
            gmdate('H:i:s', $record->duration ?: 0),
            $record->disposition ?? 'unknown'
        );

        if ($record->activity_id) {
            Activity::whereKey($record->activity_id)->update([
                'comment' => $comment,
                'schedule_to' => $endedAt,
                'is_done' => true,
            ]);

            return;
        }

        $activity = Activity::create([
            'type' => 'call',
            'title' => ucfirst($record->direction).' call with '.$number,
            'comment' => $comment,
            'schedule_from' => $startedAt,
            'schedule_to' => $endedAt,
            'is_done' => true,
            'user_id' => $record->user_id,
        ]);

        $activity->persons()->attach($record->person_id);

        $record->update(['activity_id' => $activity->id]);
    }

    /**
     * Find the Krayin user whose personal extension matches the SIP/extension
     * a call was handled on.
     */
    public function matchUser(string $sip): ?int
    {
        return UserExtension::where('extension', $sip)->value('user_id');
    }

    /**
     * Find a Person whose contact_numbers contains a number matching the
     * last 9 digits of the given number (tolerant of country code/formatting
     * differences), mirroring the matching logic already validated in
     * OpenCRM.
     */
    public function matchPerson(string $number): ?int
    {
        $target = substr(preg_replace('/\D/', '', $number), -9);

        if ($target === '') {
            return null;
        }

        $person = Person::query()
            ->whereNotNull('contact_numbers')
            ->get(['id', 'contact_numbers'])
            ->first(function (Person $person) use ($target) {
                foreach ($person->contact_numbers ?? [] as $entry) {
                    $candidate = is_array($entry) ? ($entry['value'] ?? '') : $entry;

                    if (substr(preg_replace('/\D/', '', (string) $candidate), -9) === $target) {
                        return true;
                    }
                }

                return false;
            });

        return $person?->id;
    }
}
