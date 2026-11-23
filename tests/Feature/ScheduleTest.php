<?php

declare(strict_types=1);

use Awcodes\Vitals\Vitals;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

function scheduledEvent(Schedule $schedule, string $command): ?Event
{
    return collect($schedule->events())->first(fn (Event $event): bool => str_contains((string) $event->command, $command));
}

it('registers nothing on its own', function (): void {
    expect(collect(resolve(Schedule::class)->events())->filter(
        fn (Event $event): bool => str_contains((string) $event->command, 'monitor'),
    ))->toBeEmpty();
});

it('registers on the application schedule when given none', function (): void {
    Vitals::schedule();

    expect(scheduledEvent(resolve(Schedule::class), 'monitors:prune-history'))->not->toBeNull();
});

it('schedules the four Vitals commands', function (string $command, string $expression, bool $overlapping, ?int $expiresAfterMinutes): void {
    $schedule = new Schedule;
    Vitals::schedule($schedule);

    $event = scheduledEvent($schedule, $command);

    expect($schedule->events())->toHaveCount(4)
        ->and($event)->not->toBeNull()
        ->and($event->expression)->toBe($expression)
        ->and($event->withoutOverlapping)->toBe($overlapping);

    if ($expiresAfterMinutes !== null) {
        expect($event->expiresAt)->toBe($expiresAfterMinutes);
    }
})->with([
    'resume expired pauses' => ['monitors:resume-expired-pauses', '*/5 * * * *', false, null],
    'uptime checks' => ['monitor:check-uptime', '*/15 * * * *', true, 14],
    'certificate checks' => ['monitor:check-certificate', '0 0 * * *', true, 120],
    'history pruning' => ['monitors:prune-history', '30 3 * * *', true, 60],
]);
