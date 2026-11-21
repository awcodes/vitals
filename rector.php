<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

try {
    return RectorConfig::configure()
        ->withPaths([
            __DIR__ . '/src',
            // Dev-only, but hand-written — refactored on the same terms as src.
            __DIR__ . '/workbench',
            __DIR__ . '/config',
            __DIR__ . '/database',
        ])
        // Compiled Blade under workbench/storage is gitignored but present locally
        // once the workbench app has been run, and it is not valid standalone PHP.
        // Unlike withPaths(), withSkip() tolerates a path that is not there.
        ->withSkip([
            __DIR__ . '/workbench/storage',
        ])
        ->withPreparedSets(
            deadCode: true,
            codeQuality: true,
            typeDeclarations: true,
            privatization: true,
            earlyReturn: true,
        )
        // Pinned to the package's PHP floor: an unpinned set follows the running PHP
        // version and would rewrite code into 8.4-only syntax that breaks on 8.3.
        ->withPhpSets(php83: true);
} catch (Rector\Exception\Configuration\InvalidConfigurationException $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
