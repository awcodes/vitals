@php
    /** @var \Awcodes\Vitals\Support\MonitorInspection $inspection */
    $inspection = $getState();
    $days = $inspection->days;
    $measured = collect($days)->pluck('avg_ms')->filter();
    $hasSlowDay = collect($days)->contains('state', 'slow');

    $peak = max((int) $measured->max(), $hasSlowDay ? $inspection->slowThresholdMs : 0);
    $scaleMax = collect([100, 200, 400, 600, 800, 1000, 2000, 4000, 6000, 8000, 10000, 20000, 30000, 60000])
        ->first(fn (int $step): bool => $step >= $peak * 1.1) ?? (int) ceil($peak * 1.1);

    $left = 52;
    $right = 596;
    $top = 6;
    $plotHeight = 92;
    $stripTop = $top + $plotHeight + 14;
    $step = ($right - $left) / max(1, count($days));
    $x = fn (int $index): float => round($left + ($index + 0.5) * $step, 1);
    $y = fn (int $ms): float => round($top + $plotHeight - ($ms / $scaleMax) * $plotHeight, 1);

    $segments = [];
    $current = [];
    foreach ($days as $index => $day) {
        if ($day['avg_ms'] === null) {
            if ($current !== []) {
                $segments[] = $current;
            }
            $current = [];

            continue;
        }

        $current[] = [$x($index), $y($day['avg_ms'])];
    }
    if ($current !== []) {
        $segments[] = $current;
    }

    $stripFill = [
        'up' => 'fill-success-500/70 dark:fill-success-400/45',
        'slow' => 'fill-warning-500/45 dark:fill-warning-400/35',
        'degraded' => 'fill-warning-500',
        'down' => 'fill-danger-500',
        'none' => 'fill-gray-200 dark:fill-white/10',
    ];
    $recentPassed = collect($inspection->recentChecks)->where('succeeded', true)->count();
@endphp

<div class="space-y-4">
    <dl class="grid grid-cols-3 gap-4 text-sm">
        <div>
            <dt class="text-gray-500 dark:text-gray-400">Uptime, {{ count($days) }} days</dt>
            <dd class="mt-0.5 text-lg font-semibold text-gray-950 tabular-nums dark:text-white">
                {{ $inspection->uptime === null ? '—' : ($inspection->uptime === 100.0 ? '100%' : number_format($inspection->uptime, 2).'%') }}
            </dd>
            @if ($inspection->uptime === null)
                <dd class="text-xs text-gray-500 dark:text-gray-400">No checks recorded</dd>
            @endif
        </div>
        <div>
            <dt class="text-gray-500 dark:text-gray-400">
                {{ $inspection->health === \Awcodes\Vitals\Enums\MonitorHealth::Paused ? 'Before pausing' : 'Latest response' }}
            </dt>
            @if ($inspection->latestFailed)
                <dd class="text-danger-600 dark:text-danger-400 mt-0.5 text-lg font-semibold">Failed</dd>
                <dd class="text-xs text-gray-500 dark:text-gray-400">
                    {{ $inspection->lastSuccessMs !== null ? 'Last success '.number_format($inspection->lastSuccessMs).' ms, '.$inspection->lastSuccessAgo : 'No successful check recorded' }}
                </dd>
            @else
                <dd @class([
                    'mt-0.5 text-lg font-semibold tabular-nums',
                    'text-warning-600 dark:text-warning-400' => $inspection->latestMs !== null && $inspection->latestMs > $inspection->slowThresholdMs,
                    'text-gray-950 dark:text-white' => $inspection->latestMs === null || $inspection->latestMs <= $inspection->slowThresholdMs,
                ])>
                    {{ $inspection->latestMs === null ? '—' : number_format($inspection->latestMs).' ms' }}
                </dd>
            @endif
        </div>
        <div>
            <dt class="text-gray-500 dark:text-gray-400">Average response</dt>
            <dd class="mt-0.5 text-lg font-semibold text-gray-950 tabular-nums dark:text-white">
                {{ $inspection->averageMs === null ? '—' : number_format($inspection->averageMs).' ms' }}
            </dd>
            <dd class="text-xs text-gray-500 dark:text-gray-400">Successful checks only</dd>
        </div>
    </dl>

    @if ($measured->isEmpty() && collect($days)->every(fn (array $day): bool => $day['state'] === 'none'))
        <p class="text-sm text-gray-500 dark:text-gray-400">No monitoring history recorded yet.</p>
    @else
        <figure>
            <svg
                viewBox="0 0 600 138"
                class="w-full overflow-visible text-gray-500 dark:text-gray-400"
                role="img"
                aria-label="Daily average response time and availability over {{ count($days) }} days. Response scale 0 to {{ number_format($scaleMax) }} milliseconds."
            >
                @foreach ([0, $scaleMax / 2, $scaleMax] as $tick)
                    <line
                        x1="{{ $left }}"
                        x2="{{ $right }}"
                        y1="{{ $y((int) $tick) }}"
                        y2="{{ $y((int) $tick) }}"
                        class="stroke-gray-200 dark:stroke-white/10"
                        stroke-width="1"
                    />
                    <text
                        x="{{ $left - 6 }}"
                        y="{{ $y((int) $tick) + 3 }}"
                        text-anchor="end"
                        class="fill-current text-[10px]"
                    >{{ number_format($tick) }} ms</text>
                @endforeach

                @if ($hasSlowDay && $inspection->slowThresholdMs <= $scaleMax)
                    <line
                        x1="{{ $left }}"
                        x2="{{ $right }}"
                        y1="{{ $y($inspection->slowThresholdMs) }}"
                        y2="{{ $y($inspection->slowThresholdMs) }}"
                        class="stroke-warning-500/70"
                        stroke-width="1"
                        stroke-dasharray="4 3"
                    />
                    <text
                        x="{{ $right }}"
                        y="{{ $y($inspection->slowThresholdMs) - 3 }}"
                        text-anchor="end"
                        class="fill-warning-600 dark:fill-warning-400 text-[10px]"
                    >slow</text>
                @endif

                @foreach ($segments as $segment)
                    @if (count($segment) === 1)
                        <circle cx="{{ $segment[0][0] }}" cy="{{ $segment[0][1] }}" r="2.5" class="fill-primary-500" />
                    @else
                        <polyline
                            points="{{ collect($segment)->map(fn (array $point): string => implode(',', $point))->implode(' ') }}"
                            fill="none"
                            class="stroke-primary-500"
                            stroke-width="1.75"
                            stroke-linejoin="round"
                        />
                    @endif
                @endforeach

                @foreach ($days as $index => $day)
                    <rect
                        x="{{ round($left + $index * $step + 0.5, 1) }}"
                        y="{{ $stripTop }}"
                        width="{{ round($step - 1, 1) }}"
                        height="8"
                        rx="1"
                        class="{{ $stripFill[$day['state']] }}"
                    />
                    <rect
                        x="{{ round($left + $index * $step, 1) }}"
                        y="{{ $top }}"
                        width="{{ round($step, 1) }}"
                        height="{{ $stripTop + 8 - $top }}"
                        fill="transparent"
                    >
                        <title>
                            {{ $day['label'] }}{{ $day['avg_ms'] !== null ? ' · average '.number_format($day['avg_ms']).' ms' : '' }}
                        </title>
                    </rect>
                @endforeach

                <text x="{{ $left - 6 }}" y="{{ $stripTop + 7 }}" text-anchor="end" class="fill-current text-[10px]">
                    checks
                </text>
                <text x="{{ $left }}" y="136" class="fill-current text-[10px]">
                    {{ $days[0]['date']->format('M j') }}
                </text>
                <text x="{{ ($left + $right) / 2 }}" y="136" text-anchor="middle" class="fill-current text-[10px]">
                    {{ $days[intdiv(count($days), 2)]['date']->format('M j') }}
                </text>
                <text x="{{ $right }}" y="136" text-anchor="end" class="fill-current text-[10px]">Today</text>
            </svg>

            <figcaption class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
                <span class="inline-flex items-center gap-1"><span class="bg-primary-500 h-0.5 w-3"></span> Daily average response</span>
                <span class="inline-flex items-center gap-1"><span class="bg-danger-500 size-2 rounded-sm"></span> Outage</span>
                <span class="inline-flex items-center gap-1"><span class="bg-warning-500 size-2 rounded-sm"></span> Failed checks</span>
                <span class="inline-flex items-center gap-1"><span class="bg-warning-500/45 dark:bg-warning-400/35 size-2 rounded-sm"></span> Slow</span>
                <span class="inline-flex items-center gap-1"><span class="size-2 rounded-sm bg-gray-200 dark:bg-white/10"></span> No data</span>
            </figcaption>

            <div class="sr-only">
                <table>
                    <caption>
                        Daily monitoring history
                    </caption>
                    <thead>
                        <tr>
                            <th>Day</th>
                            <th>Checks</th>
                            <th>Average response</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($days as $day)
                            <tr>
                                <td>{{ $day['date']->format('M j') }}</td>
                                <td>{{ $day['label'] }}</td>
                                <td>
                                    {{ $day['avg_ms'] === null ? 'No measurement' : number_format($day['avg_ms']).' ms' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </figure>
    @endif

    @if ($inspection->recentChecks !== [])
        <div>
            <div class="flex items-baseline justify-between text-xs text-gray-500 dark:text-gray-400">
                <span>Latest {{ count($inspection->recentChecks) }} checks</span>
                <span>
                    {{ $recentPassed }} passed · {{ count($inspection->recentChecks) - $recentPassed }} failed
                    @if ($recentSlow = collect($inspection->recentChecks)->where('slow', true)->count())
                        · {{ $recentSlow }} slow
                    @endif
                </span>
            </div>
            <ol
                class="mt-1 flex h-3 gap-0.5"
                aria-label="Latest {{ count($inspection->recentChecks) }} checks, oldest first"
            >
                @foreach ($inspection->recentChecks as $check)
                    <li
                        title="{{ $check['label'] }}"
                        @class([
                            'flex-1 rounded-sm',
                            'bg-success-500/70 dark:bg-success-400/45' => $check['succeeded'] && ! $check['slow'],
                            'bg-warning-500/45 dark:bg-warning-400/35' => $check['succeeded'] && $check['slow'],
                            'bg-danger-500' => ! $check['succeeded'],
                        ])
                    >
                        <span class="sr-only">{{ $check['label'] }}</span>
                    </li>
                @endforeach
            </ol>
            <div class="mt-0.5 flex justify-between text-[11px] text-gray-500 dark:text-gray-400">
                <span>{{ $inspection->recentChecks[0]['at'] }}</span>
                <span>Latest</span>
            </div>
        </div>
    @endif
</div>
