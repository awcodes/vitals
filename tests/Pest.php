<?php

declare(strict_types=1);

use Awcodes\Vitals\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature', 'Unit');

pest()->extend(Awcodes\Vitals\Tests\InstallTestCase::class)->in('Install');
