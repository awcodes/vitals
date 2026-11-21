<?php

declare(strict_types=1);

namespace Awcodes\Vitals\Models;

use Awcodes\Vitals\Database\Factories\MonitorCheckFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * One uptime check result. Never holds response bodies or request headers.
 * Pruned by monitors:prune-history once its day has been summarised.
 *
 * @property-read int $id
 * @property int $monitor_id
 * @property CarbonImmutable $checked_at
 * @property bool $succeeded
 * @property ?int $http_status
 * @property ?int $response_time_ms
 * @property ?string $failure_reason
 */
final class MonitorCheck extends Model
{
    /** @use HasFactory<MonitorCheckFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    /** @return BelongsTo<Monitor, $this> */
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    protected static function newFactory(): MonitorCheckFactory
    {
        return MonitorCheckFactory::new();
    }

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'checked_at' => 'immutable_datetime',
            'succeeded' => 'boolean',
            'http_status' => 'integer',
            'response_time_ms' => 'integer',
        ];
    }
}
