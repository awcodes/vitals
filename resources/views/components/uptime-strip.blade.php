@php
    /** @var list<array{date: \Carbon\CarbonImmutable, state: string, label: string}> $days */
    $days = $getState() ?? [];
    $counts = collect($days)->countBy('state');
    $hasData = $counts->except('none')->isNotEmpty();
    $firstDay = collect($days)->firstWhere('state', '!=', 'none');
    $coverage = match (true) {
        ! $hasData => 'no checks recorded',
        $firstDay['date']->isSameDay($days[0]['date']) => 'full period recorded',
        default => 'checks recorded since '.$firstDay['date']->format('M j'),
    };
@endphp

<div class="px-3 py-2.5">
    <div
        class="flex h-4 w-36 items-stretch gap-px"
        role="img"
        aria-label="{{ count($days) }} day history, {{ $coverage }}: {{ $counts->get('up', 0) }} days all passed, {{ $counts->get('slow', 0) }} slow, {{ $counts->get('degraded', 0) }} with failed checks, {{ $counts->get('down', 0) }} with outages, {{ $counts->get('none', 0) }} without data"
    >
        @foreach ($days as $day)
            <span
                title="{{ $day['label'] }}"
                @class([
                    'flex-1 rounded-[1px]',
                    'bg-success-500/70 dark:bg-success-400/45' => $day['state'] === 'up',
                    'bg-warning-500' => $day['state'] === 'degraded',
                    'bg-warning-500/45 dark:bg-warning-400/35' => $day['state'] === 'slow',
                    'bg-danger-500' => $day['state'] === 'down',
                    'bg-gray-200 dark:bg-white/10' => $day['state'] === 'none',
                ])
            ></span>
        @endforeach
    </div>
    @unless ($hasData)
        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">No data yet</div>
    @endunless
</div>
