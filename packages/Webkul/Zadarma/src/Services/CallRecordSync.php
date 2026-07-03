<?php

namespace Webkul\Zadarma\Services;

use Webkul\Contact\Models\Person;
use Webkul\Zadarma\Models\CallRecord;

class CallRecordSync
{
    /**
     * Upsert a call record from a normalized payload, matching it to a
     * Person by phone number. Shared between polling (Zadarma statistics
     * API) and webhook (Zadarma call events) discovery paths.
     *
     * @param  array{external_id: string, direction: string, from_number: string, to_number: string, duration: int, disposition: ?string, recording_url: ?string, started_at: ?string}  $call
     */
    public function upsert(array $call): CallRecord
    {
        $matchNumber = $call['direction'] === 'outbound'
            ? ($call['to_number'] ?? null)
            : ($call['from_number'] ?? null);

        return CallRecord::updateOrCreate(
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
            ]
        );
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
