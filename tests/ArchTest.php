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
