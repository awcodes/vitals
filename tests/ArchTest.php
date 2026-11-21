<?php

declare(strict_types=1);

arch('source files declare strict types')
    ->expect('Awcodes\Vitals')
    ->toUseStrictTypes();

arch('no debugging functions are left in source')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die'])
    ->not->toBeUsed();

arch('env() is only read from config files')
    ->expect('Awcodes\Vitals')
    ->not->toUse('env');

arch('history models are final')
    ->expect(['Awcodes\Vitals\Models\MonitorCheck', 'Awcodes\Vitals\Models\MonitorIncident', 'Awcodes\Vitals\Models\MonitorDailyStat'])
    ->toBeFinal();

// Monitor and TimedUptimeCheckRequest stay open: apps that already customise
// Spatie's monitor model or request action extend them instead.
arch('the Spatie extension points can be extended')
    ->expect(['Awcodes\Vitals\Models\Monitor', 'Awcodes\Vitals\Support\TimedUptimeCheckRequest'])
    ->not->toBeFinal();
