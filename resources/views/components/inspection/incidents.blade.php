@php
    /** @var \Awcodes\Vitals\Support\MonitorInspection $inspection */
    $incidents = $getState()->incidents;
@endphp

@if ($incidents === [])
    <p class="text-sm text-gray-500 dark:text-gray-400">No incidents recorded.</p>
@else
    <ol class="divide-y divide-gray-200 dark:divide-white/10">
        @foreach ($incidents as $incident)
            <li class="flex gap-3 py-2.5 first:pt-0 last:pb-0">
                <span
                    @class([
                        'mt-1.5 size-2 shrink-0 rounded-full',
                        'bg-danger-500' => $incident['ongoing'],
                        'bg-gray-400 dark:bg-gray-500' => ! $incident['ongoing'],
                    ])
                ></span>
                <div class="min-w-0 flex-1 text-sm">
                    <div class="flex flex-wrap items-baseline gap-x-2">
                        <span class="font-medium text-gray-950 dark:text-white">{{ $incident['reason']->title }}</span>
                        <span
                            @class([
                                'text-xs font-medium',
                                'text-danger-600 dark:text-danger-400' => $incident['ongoing'],
                                'text-gray-500 dark:text-gray-400' => ! $incident['ongoing'],
                            ])
                        >{{ $incident['ongoing'] ? 'Ongoing' : 'Resolved' }}</span>
                    </div>
                    <p class="text-gray-600 dark:text-gray-400">{{ $incident['reason']->summary }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        {{ $incident['started'] }} → {{ $incident['ended'] ?? 'now' }} · {{ $incident['ongoing'] ? 'ongoing for ' : '' }}{{ $incident['duration'] }}
                    </p>
                    @if ($incident['reason']->raw)
                        <details class="mt-1">
                            <summary class="text-primary-600 dark:text-primary-400 cursor-pointer text-xs">
                                Technical details
                            </summary>
                            <div class="mt-1 rounded-md bg-gray-50 p-2 font-mono text-xs break-words text-gray-700 dark:bg-white/5 dark:text-gray-300">
                                {{ $incident['reason']->raw }}
                            </div>
                        </details>
                    @endif
                </div>
            </li>
        @endforeach
    </ol>
@endif
