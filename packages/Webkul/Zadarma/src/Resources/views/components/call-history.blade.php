@php
    $zadarmaHistoryPerson = $person ?? ($lead->person ?? null);

    $zadarmaCallRecords = $zadarmaHistoryPerson
        ? app(\Webkul\Zadarma\Repositories\CallRecordRepository::class)->findRecentForPerson($zadarmaHistoryPerson->id)
        : collect();
@endphp

@if ($zadarmaCallRecords->isNotEmpty())
    <div class="flex w-full flex-col gap-2.5 border-b border-gray-300 p-4 dark:border-gray-800">
        <p class="font-semibold dark:text-white">
            @lang('zadarma::app.history.title')
        </p>

        <div class="flex flex-col gap-2">
            @foreach ($zadarmaCallRecords as $zadarmaCallRecord)
                <div class="flex items-center justify-between gap-2 rounded border border-gray-200 p-2 text-sm dark:border-gray-800">
                    <div class="flex flex-col">
                        <span class="flex items-center gap-1 font-medium dark:text-white">
                            <i class="icon-call text-brandColor"></i>

                            {{ $zadarmaCallRecord->direction === 'outbound' ? $zadarmaCallRecord->to_number : $zadarmaCallRecord->from_number }}

                            <span class="text-xs text-gray-500 dark:text-gray-300">
                                ({{ $zadarmaCallRecord->direction }})
                            </span>
                        </span>

                        <span class="text-gray-500 dark:text-gray-300">
                            {{ $zadarmaCallRecord->started_at?->format('Y-m-d H:i') }}
                            &middot;
                            {{ gmdate('i:s', $zadarmaCallRecord->duration) }}
                            &middot;
                            {{ $zadarmaCallRecord->disposition ? ucfirst($zadarmaCallRecord->disposition) : '—' }}
                        </span>
                    </div>

                    @if ($zadarmaCallRecord->recording_url)
                        <a
                            href="{{ $zadarmaCallRecord->recording_url }}"
                            target="_blank"
                            class="icon-download rounded p-1 text-lg text-brandColor hover:bg-gray-100 dark:hover:bg-gray-950"
                            title="@lang('zadarma::app.history.recording')"
                        ></a>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
@endif
