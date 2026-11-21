<?php

declare(strict_types=1);

namespace Awcodes\Vitals\Models;

use Awcodes\Vitals\Database\Factories\MonitorIncidentFactory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Date;
use Override;

/**
 * A confirmed outage: opened once a monitor reaches the consecutive failure
 * threshold, resolved by the first passing check afterwards.
 *
 * @property-read int $id
 * @property int $monitor_id
 * @property CarbonImmutable $started_at
 * @property ?CarbonImmutable $resolved_at
 * @property ?string $failure_reason
 * @property-read CarbonImmutable $created_at
 * @property-read CarbonImmutable $updated_at
 *
 * @method static Builder<static> open()
 */
final class MonitorIncident extends Model
{
    /** @use HasFactory<MonitorIncidentFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @return BelongsTo<Monitor, $this> */
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    /**
     * @param  Builder<MonitorIncident>  $query
     * @return Builder<MonitorIncident>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    public function durationInMinutes(): int
    {
        return (int) $this->started_at->diffInMinutes($this->resolved_at ?? Date::now(), absolute: true);
    }

    protected static function newFactory(): MonitorIncidentFactory
    {
        return MonitorIncidentFactory::new();
    }

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
