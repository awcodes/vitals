<?php

declare(strict_types=1);

namespace Awcodes\Vitals\Database\Factories;

use Awcodes\Vitals\Models\Monitor;
use Awcodes\Vitals\Models\MonitorIncident;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Date;

/** @extends Factory<MonitorIncident> */
final class MonitorIncidentFactory extends Factory
{
    protected $model = MonitorIncident::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'monitor_id' => Monitor::factory(),
            'started_at' => Date::now()->subHour(),
            'resolved_at' => null,
            'failure_reason' => 'Connection timed out',
        ];
    }

    public function resolved(): self
    {
        return $this->state(fn (array $attributes): array => [
            'resolved_at' => Date::now(),
        ]);
    }
}
