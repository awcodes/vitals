@php
    /** @var \Awcodes\Vitals\Support\MonitorInspection $inspection */
    $certificate = $getState()->certificate;
@endphp

<div class="space-y-3 text-sm">
    <div>
        <div class="flex items-center gap-2">
            <x-filament::badge :color="$certificate['health']->getColor()">{{ $certificate['badge'] }}</x-filament::badge>
            <span class="font-semibold text-gray-950 dark:text-white">{{ $certificate['headline'] }}</span>
        </div>
        @if ($certificate['detail'])
            <p class="mt-1 text-gray-500 dark:text-gray-400">{{ $certificate['detail'] }}</p>
        @endif
    </div>

    @if ($certificate['expires'] || $certificate['issuer'])
        <dl class="grid grid-cols-2 gap-x-4 gap-y-2">
            @if ($certificate['expires'])
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">Expires</dt>
                    <dd class="text-gray-950 dark:text-white">{{ $certificate['expires'] }}</dd>
                </div>
            @endif
            @if ($certificate['issuer'])
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">Issuer</dt>
                    <dd class="text-gray-950 dark:text-white">{{ $certificate['issuer'] }}</dd>
                </div>
            @endif
        </dl>
    @endif

    @if ($certificate['raw'])
        <details>
            <summary class="cursor-pointer text-gray-700 dark:text-gray-300">Validation details</summary>
            <div class="mt-2 rounded-md bg-gray-50 p-2 font-mono text-xs break-words text-gray-700 dark:bg-white/5 dark:text-gray-300">
                {{ $certificate['raw'] }}
            </div>
        </details>
    @endif
</div>
