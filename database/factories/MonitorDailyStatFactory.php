<?php

declare(strict_types=1);

namespace Awcodes\Vitals\Database\Factories;

use Awcodes\Vitals\Models\Monitor;
use Awcodes\Vitals\Models\MonitorDailyStat;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Date;

/** @extends Factory<MonitorDailyStat> */
final class MonitorDailyStatFactory extends Factory
{
    protected $model = MonitorDailyStat::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'monitor_id' => Monitor::factory(),
            'date' => Date::yesterday(),
            'total_checks' => 96,
            'failed_checks' => 0,
            'avg_response_ms' => 300,
            'min_response_ms' => 150,
            'max_response_ms' => 600,
            'aggregated_at' => Date::now(),
        ];
    }
}
