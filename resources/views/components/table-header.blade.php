@php
    /**
     * @var list<array{state: ?string, label: string, count: int, color: string}> $segments
     * @var ?string $activeState
     * @var int $certificateCount
     * @var bool $certificateFilterActive
     * @var list<array{key: int, name: string, message: string, color: string, more: int}> $issues
     * @var int $issueCount
     */
    $dots = [
        'success' => 'bg-success-500',
        'danger' => 'bg-danger-500',
        'warning' => 'bg-warning-500',
        'gray' => 'bg-gray-400 dark:bg-gray-500',
        'none' => 'hidden',
    ];
@endphp

<div class="min-w-0 divide-y divide-gray-200 dark:divide-white/10">
    <div class="flex flex-wrap items-center gap-1 px-3 py-2 sm:px-5">
        @foreach ($segments as $segment)
            @php($isActive = $segment['state'] === $activeState)
            <button
                type="button"
                wire:click="filterMonitorsByState(@js($segment['state']))"
                aria-pressed="{{ $isActive ? 'true' : 'false' }}"
                @class([
                    'inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-sm transition',
                    'bg-gray-100 text-gray-950 dark:bg-white/10 dark:text-white' => $isActive,
                    'text-gray-600 hover:bg-gray-50 hover:text-gray-950 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-white' => ! $isActive,
                ])
            >
                <span @class(['size-1.5 rounded-full', $dots[$segment['count'] > 0 ? $segment['color'] : 'gray']])></span>
                {{ $segment['label'] }}
                <span
                    @class([
                        'font-semibold tabular-nums',
                        'text-danger-600 dark:text-danger-400' => $segment['color'] === 'danger' && $segment['count'] > 0,
                    ])
                >{{ $segment['count'] }}</span>
            </button>
        @endforeach

        <button
            type="button"
            wire:click="filterMonitorsByCertificate"
            aria-pressed="{{ $certificateFilterActive ? 'true' : 'false' }}"
            @class([
                'ms-auto inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-sm transition',
                'bg-gray-100 text-gray-950 dark:bg-white/10 dark:text-white' => $certificateFilterActive,
                'text-gray-500 hover:bg-gray-50 hover:text-gray-950 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-white' => ! $certificateFilterActive,
            ])
        >
            <x-filament::icon
                icon="heroicon-m-lock-closed"
                @class([
                    'size-4',
                    'text-warning-500' => $certificateCount > 0,
                    'text-gray-400 dark:text-gray-500' => $certificateCount === 0,
                ])
            />
            SSL issues
            <span class="font-semibold tabular-nums">{{ $certificateCount }}</span>
        </button>
    </div>

    @if ($issues !== [])
        <div class="px-3 py-2 sm:px-5">
            <div class="flex items-center justify-between px-2">
                <h3 class="text-xs font-medium text-gray-500 dark:text-gray-400">Needs attention</h3>

                @if ($issueCount > count($issues))
                    <x-filament::link tag="button" size="sm" wire:click="filterMonitorsByState('attention')">
                        View all {{ $issueCount }}
                    </x-filament::link>
                @endif
            </div>

            <ul class="mt-1">
                @foreach ($issues as $issue)
                    <li wire:key="monitor-issue-{{ $issue['key'] }}">
                        <button
                            type="button"
                            wire:click="mountTableAction('inspect', '{{ $issue['key'] }}')"
                            class="group flex w-full items-center gap-2.5 rounded-md px-2 py-1 text-start text-sm hover:bg-gray-50 dark:hover:bg-white/5"
                        >
                            <span @class(['size-1.5 shrink-0 rounded-full', $dots[$issue['color']]])></span>
                            <span class="max-w-[50%] shrink-0 truncate font-medium text-gray-950 dark:text-white">{{ $issue['name'] }}</span>
                            <span class="min-w-0 truncate text-gray-600 dark:text-gray-400">{{ $issue['message'] }}</span>
                            @if ($issue['more'] > 0)
                                <span class="hidden shrink-0 text-xs text-gray-500 sm:inline dark:text-gray-400">+{{ $issue['more'] }} more</span>
                            @endif
                            <span class="text-primary-600 dark:text-primary-400 ms-auto hidden shrink-0 text-xs opacity-0 transition group-hover:opacity-100 group-focus-visible:opacity-100 sm:inline">
                                Inspect
                            </span>
                        </button>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
