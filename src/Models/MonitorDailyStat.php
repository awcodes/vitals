<?php

declare(strict_types=1);

namespace Awcodes\Vitals\Models;

use Awcodes\Vitals\Database\Factories\MonitorDailyStatFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * One monitor's checks for one UTC day, kept after the raw checks are
 * pruned. Response times cover successful checks only.
 *
 * @property-read int $id
 * @property int $monitor_id
 * @property CarbonImmutable $date
 * @property int $total_checks
 * @property int $failed_checks
 * @property ?int $avg_response_ms
 * @property ?int $min_response_ms
 * @property ?int $max_response_ms
 * @property CarbonImmutable $aggregated_at
 */
final class MonitorDailyStat extends Model
{
    /** @use HasFactory<MonitorDailyStatFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    /** @return BelongsTo<Monitor, $this> */
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    protected static function newFactory(): MonitorDailyStatFactory
    {
        return MonitorDailyStatFactory::new();
    }

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'aggregated_at' => 'immutable_datetime',
            'total_checks' => 'integer',
            'failed_checks' => 'integer',
            'avg_response_ms' => 'integer',
            'min_response_ms' => 'integer',
            'max_response_ms' => 'integer',
        ];
    }
}
