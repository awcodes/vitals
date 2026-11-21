<?php

declare(strict_types=1);

namespace Awcodes\Vitals\Database\Factories;

use Awcodes\Vitals\Models\Monitor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Date;
use Spatie\UptimeMonitor\Models\Enums\CertificateStatus;
use Spatie\UptimeMonitor\Models\Enums\UptimeStatus;

/**
 * @extends Factory<Monitor>
 */
final class MonitorFactory extends Factory
{
    protected $model = Monitor::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'url' => 'https://' . $this->faker->unique()->domainName(),
            'uptime_check_enabled' => true,
            'uptime_status' => UptimeStatus::UP,
            'certificate_check_enabled' => true,
            'certificate_status' => CertificateStatus::VALID,
        ];
    }

    public function down(string $reason = 'Connection timed out'): self
    {
        return $this->state(fn (): array => [
            'uptime_status' => UptimeStatus::DOWN,
            'uptime_check_failure_reason' => $reason,
        ]);
    }

    public function paused(?string $reason = 'Planned maintenance'): self
    {
        return $this->state(fn (): array => [
            'paused_at' => Date::now()->subHour(),
            'pause_reason' => $reason,
        ]);
    }

    public function invalidCertificate(string $reason = 'The certificate has expired'): self
    {
        return $this->state(fn (): array => [
            'certificate_status' => CertificateStatus::INVALID,
            'certificate_check_failure_reason' => $reason,
        ]);
    }
}
