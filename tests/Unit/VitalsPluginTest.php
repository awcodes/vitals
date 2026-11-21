<?php

declare(strict_types=1);

use Awcodes\Vitals\VitalsPlugin;

it('has a stable plugin id', function (): void {
    expect(VitalsPlugin::make()->getId())->toBe('vitals');
});
