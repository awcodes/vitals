<?php

declare(strict_types=1);

namespace Awcodes\Vitals\Tests;

use Illuminate\Filesystem\Filesystem;

/**
 * Runs against a private copy of the Testbench skeleton. The Workbench
 * materializes the same migrations and config files into the shared skeleton,
 * and package-tools fixes publish targets when it boots, so the install tests
 * need an application whose database/ and config/ only they write to.
 */
abstract class InstallTestCase extends TestCase
{
    private static ?string $skeleton = null;

    protected function setUp(): void
    {
        self::removeInstalledFiles();

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        self::removeInstalledFiles();
    }

    /** @return list<string> */
    public static function installedFiles(): array
    {
        $base = self::skeleton();

        return array_values(array_merge(
            self::files()->glob("{$base}/database/migrations/*_create_monitors_table.php"),
            self::files()->glob("{$base}/database/migrations/*vitals*.php"),
            self::files()->glob("{$base}/config/{vitals,uptime-monitor}.php", GLOB_BRACE),
        ));
    }

    public static function removeInstalledFiles(): void
    {
        self::files()->delete(self::installedFiles());
    }

    /**
     * A fresh app: no monitors schema, only the copy's own migrations.
     */
    protected function defineDatabaseMigrations(): void {}

    protected function getApplicationBasePath()
    {
        return self::skeleton();
    }

    // Used before and after the application exists, so not the File facade.
    private static function files(): Filesystem
    {
        return new Filesystem;
    }

    private static function skeleton(): string
    {
        if (self::$skeleton !== null) {
            return self::$skeleton;
        }

        $source = dirname(__DIR__) . '/vendor/orchestra/testbench-core/laravel';
        $target = sys_get_temp_dir() . '/vitals-install-skeleton-' . getmypid();

        self::files()->deleteDirectory($target);

        foreach (['app', 'bootstrap', 'config', 'database', 'lang', 'migrations', 'resources', 'routes', 'storage'] as $directory) {
            self::files()->copyDirectory("{$source}/{$directory}", "{$target}/{$directory}");
        }

        self::files()->delete(self::files()->glob("{$target}/database/*.sqlite"));
        self::files()->delete(self::files()->glob("{$target}/bootstrap/cache/*.php"));

        register_shutdown_function(static fn () => (new Filesystem)->deleteDirectory($target));

        return self::$skeleton = $target;
    }
}
