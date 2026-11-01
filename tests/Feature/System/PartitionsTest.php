<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Src\Shared\Models\OutboxEvent;
use Src\Shared\Services\PartitionManager;

/*
 * SQLite no particiona: lo que depende de MySQL está en `scripts/partition-check.php`.
 */
function managerWith(array $partitions): PartitionManager
{
    return new class($partitions) extends PartitionManager
    {
        public function __construct(private readonly array $names) {}

        public function partitions(string $table): array
        {
            return $this->names;
        }
    };
}

it('solo da por vencidos los meses fuera de la retención', function () {
    $now = CarbonImmutable::create(2026, 11, 15);
    $manager = managerWith(['p2024_10', 'p2024_11', 'p2024_12', 'p2026_11', 'p2026_12', 'pmax']);

    expect($manager->expired('tracking_events', 24, $now))->toBe(['p2024_10']);
});

it('nunca da por vencido el mes actual, un mes futuro ni pmax', function () {
    $now = CarbonImmutable::create(2026, 11, 15);
    $manager = managerWith(['p2026_10', 'p2026_11', 'p2026_12', 'pmax', 'raro']);

    expect($manager->expired('tracking_events', 0, $now))->toBe([]);
});

it('cuenta los meses futuros que faltan', function () {
    $now = CarbonImmutable::create(2026, 11, 15);

    expect(managerWith(['p2026_11', 'p2026_12', 'pmax'])->missingAhead('t', 3, $now))->toBe(2);
});

it('define las particiones hasta los meses de margen y añade pmax', function () {
    $definition = (new PartitionManager)->definition(
        CarbonImmutable::create(2026, 10, 20),
        1,
        CarbonImmutable::create(2026, 11, 15),
    );

    expect($definition)
        ->toContain("PARTITION `p2026_10` VALUES LESS THAN (TO_DAYS('2026-11-01'))")
        ->toContain("PARTITION `p2026_12` VALUES LESS THAN (TO_DAYS('2027-01-01'))")
        ->toContain('PARTITION `pmax` VALUES LESS THAN MAXVALUE')
        ->not->toContain('p2027_01');
});

it('archiva solo los eventos procesados y antiguos', function () {
    $event = fn (string $status, ?CarbonImmutable $processedAt) => DB::table('outbox_events')->insertGetId([
        'event_type' => 'test.event',
        'payload' => '{}',
        'status' => $status,
        'processed_at' => $processedAt,
        'created_at' => now()->subMonths(5),
        'updated_at' => now(),
    ]);

    $old = $event(OutboxEvent::STATUS_PROCESSED, CarbonImmutable::now()->subMonths(4));
    $recent = $event(OutboxEvent::STATUS_PROCESSED, CarbonImmutable::now()->subMonth());
    $failed = $event(OutboxEvent::STATUS_FAILED, null);

    $this->artisan('outbox:archive')->assertSuccessful();

    expect(DB::table('outbox_events_archive')->pluck('id')->all())->toBe([$old])
        ->and(DB::table('outbox_events')->pluck('id')->all())->toBe([$recent, $failed]);
});

it('deja el consumidor al daemon cuando está activo', function () {
    $consumer = fn () => collect(Schedule::events())
        ->first(fn ($event) => str_contains((string) $event->command, 'outbox:consume'));

    config(['outbox.daemon' => false]);
    expect($consumer()->filtersPass(app()))->toBeTrue();

    config(['outbox.daemon' => true]);
    expect($consumer()->filtersPass(app()))->toBeFalse();
});
