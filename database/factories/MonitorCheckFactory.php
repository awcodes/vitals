<?php

declare(strict_types=1);

namespace Awcodes\Vitals\Database\Factories;

use Awcodes\Vitals\Models\Monitor;
use Awcodes\Vitals\Models\MonitorCheck;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Date;

/** @extends Factory<MonitorCheck> */
final class MonitorCheckFactory extends Factory
{
    protected $model = MonitorCheck::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'monitor_id' => Monitor::factory(),
            'checked_at' => Date::now(),
            'succeeded' => true,
            'http_status' => 200,
            'response_time_ms' => $this->faker->numberBetween(80, 900),
            'failure_reason' => null,
        ];
    }

    public function failed(string $reason = 'Connection timed out'): self
    {
        return $this->state(fn (): array => [
            'succeeded' => false,
            'http_status' => null,
            'response_time_ms' => null,
            'failure_reason' => $reason,
        ]);
    }
}
