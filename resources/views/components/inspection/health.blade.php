@php
    /** @var \Awcodes\Vitals\Support\MonitorInspection $inspection */
    $inspection = $getState();
@endphp

<div class="space-y-1.5">
    <div class="flex flex-wrap items-center gap-1.5">
        @foreach ($inspection->conditions as $condition)
            <x-filament::badge :color="$condition['color']">{{ $condition['label'] }}</x-filament::badge>
        @endforeach
    </div>

    <p class="text-base font-semibold text-gray-950 dark:text-white">{{ $inspection->headline }}</p>

    @if ($inspection->pauseReason)
        <p class="text-sm text-gray-700 dark:text-gray-300">{{ $inspection->pauseReason }}</p>
    @endif

    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $inspection->checkTimes }}</p>

    @if ($inspection->activeIncident)
        <p class="text-danger-600 dark:text-danger-400 text-sm">
            Outage ongoing since {{ $inspection->activeIncident['since'] }} · {{ $inspection->activeIncident['duration'] }}
        </p>
    @endif

    @if ($inspection->latestFailure)
        <details class="pt-1 text-sm">
            <summary class="cursor-pointer text-gray-700 dark:text-gray-300">
                Latest check:
                <span class="font-medium text-gray-950 dark:text-white">{{ $inspection->latestFailure->title }}</span>
                <span class="text-gray-500 dark:text-gray-400">{{ $inspection->latestFailure->summary }}</span>
            </summary>
            <div class="mt-2 rounded-md bg-gray-50 p-2 font-mono text-xs break-words text-gray-700 dark:bg-white/5 dark:text-gray-300">
                {{ $inspection->latestFailure->raw ?? 'No error text was recorded.' }}
                @if ($inspection->latestFailureStatus)
                    <div class="mt-1 text-gray-500 dark:text-gray-400">
                        HTTP status {{ $inspection->latestFailureStatus }}
                    </div>
                @endif
            </div>
        </details>
    @endif
</div>
