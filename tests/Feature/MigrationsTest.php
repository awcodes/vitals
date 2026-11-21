<?php

declare(strict_types=1);

use Awcodes\Vitals\VitalsServiceProvider;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function vitalsMigration(string $name): Migration
{
    return require __DIR__ . "/../../database/migrations/{$name}.php.stub";
}

function runVitalsMigrations(): void
{
    foreach (VitalsServiceProvider::MIGRATIONS as $name) {
        vitalsMigration($name)->up();
    }
}

/** @return array<string, list<string>> */
function vitalsSchema(): array
{
    return collect(['monitors', 'monitor_checks', 'monitor_incidents', 'monitor_daily_stats'])
        ->mapWithKeys(fn (string $table): array => [$table => Schema::hasTable($table) ? Schema::getColumnListing($table) : []])
        ->all();
}

it('is a no-op on a schema that already has every table and column', function (): void {
    DB::table('monitors')->insert(['url' => 'https://example.com', 'name' => 'Example', 'created_at' => now(), 'updated_at' => now()]);
    $before = vitalsSchema();

    runVitalsMigrations();

    expect(vitalsSchema())->toBe($before)
        ->and(DB::table('monitors')->value('name'))->toBe('Example');
});

it('adds the tables and columns to a monitors table that has none of them', function (): void {
    foreach (array_reverse(VitalsServiceProvider::MIGRATIONS) as $name) {
        vitalsMigration($name)->down();
    }

    expect(Schema::hasTable('monitor_checks'))->toBeFalse()
        ->and(Schema::hasColumn('monitors', 'paused_at'))->toBeFalse();

    runVitalsMigrations();

    expect(Schema::hasTable('monitor_checks'))->toBeTrue()
        ->and(Schema::hasTable('monitor_incidents'))->toBeTrue()
        ->and(Schema::hasTable('monitor_daily_stats'))->toBeTrue()
        ->and(Schema::hasColumns('monitors', ['name', 'server_url', 'paused_at', 'paused_until', 'pause_reason']))->toBeTrue();
});

it('adds only the columns a partially migrated monitors table is missing', function (): void {
    vitalsMigration('add_vitals_columns_to_monitors_table')->down();
    Schema::table('monitors', fn ($table) => $table->string('name')->nullable());
    DB::table('monitors')->insert(['url' => 'https://example.com', 'name' => 'Kept', 'created_at' => now(), 'updated_at' => now()]);

    runVitalsMigrations();

    expect(Schema::hasColumns('monitors', ['name', 'server_url', 'paused_at', 'paused_until', 'pause_reason']))->toBeTrue()
        ->and(DB::table('monitors')->value('name'))->toBe('Kept');
});
