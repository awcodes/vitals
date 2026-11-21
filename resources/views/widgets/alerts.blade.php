<x-filament-widgets::widget id="vitals-alerts-widget">
    <x-filament::section>
        <x-slot name="heading">
            <div class="flex items-center justify-between">
                <div class="text-base">Monitor Alerts</div>

                <x-filament::link :href="\Awcodes\Vitals\Filament\Pages\VitalsPage::getUrl()" size="sm">
                    View all sites
                </x-filament::link>
            </div>
        </x-slot>

        @forelse ($failingSites as $site)
            <div @class([
                'flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between',
                'border-t border-gray-200 dark:border-white/10' => ! $loop->first,
            ])>
                <div class="text-sm">
                    <div class="font-medium text-gray-950 dark:text-white">{{ $site['name'] }}</div>
                    <a
                        class="text-gray-500 hover:underline focus:underline dark:text-gray-400"
                        href="{{ $site['url'] }}"
                        target="_blank"
                    >{{ $site['url'] }}</a>

                    @if ($site['reason'])
                        <p class="text-danger-600 dark:text-danger-400 mt-1">{{ $site['reason'] }}</p>
                    @endif
                </div>

                <div class="flex shrink-0 items-center gap-2">
                    @if ($site['uptimeFailing'])
                        <x-filament::badge color="danger" icon="heroicon-o-arrow-down-circle"> Down </x-filament::badge>
                    @endif

                    @if ($site['certificateFailing'])
                        <x-filament::badge color="danger" icon="heroicon-o-shield-exclamation">
                            SSL Invalid
                        </x-filament::badge>
                    @endif
                </div>
            </div>
        @empty
            <p class="text-sm text-gray-500 dark:text-gray-400">
                All monitored sites are passing their uptime and SSL checks.
            </p>
        @endforelse
    </x-filament::section>
</x-filament-widgets::widget>
