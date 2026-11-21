<?php

declare(strict_types=1);

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Enums\Agent;

/**
 * boost-core configuration. Edit `.ai/` for repository guidance, then run
 * `vendor/bin/boost sync`. Docs: https://github.com/sandermuller/boost-core
 */
return BoostConfig::configure()
    ->withAgents([Agent::CLAUDE_CODE])
    ->withAllowedVendors([
        'sandermuller/package-boost-laravel',
        'sandermuller/package-boost-php',
    ])
    ->withRemoteSkills([]);
