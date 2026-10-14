<?php

declare(strict_types=1);

namespace Tests\Feature\Radar;

use App\Jobs\PurgeRadarItemsJob;
use App\Models\RadarItem;
use App\Models\RadarRun;
use App\Models\RadarTopic;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Radar (R1): tudo o que veio dos feeds é apagado ao fim de 30 dias; o resto fica. */
class RadarPurgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['radar.enabled' => true]); // RADAR_ENABLED (desligado por omissão)
        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-11-20 03:20:00'));
    }

    private function seedRadar(int $daysAgo, string $suffix): void
    {
        $at = now()->subDays($daysAgo);
        $topic = RadarTopic::create([
            'kind' => 'trend', 'title' => "tendência {$suffix}", 'normalized_key' => "trend:{$suffix}", 'first_seen_at' => $at, 'last_seen_at' => $at,
            'window_until' => $at->copy()->addHours(48), 'expires_at' => $at->copy()->addDays(30), 'filter_verdict' => 'passed',
        ]);
        RadarItem::create([
            'source_key' => 'google_trends', 'external_key' => sha1($suffix), 'title' => "tendência {$suffix}", 'url' => null,
            'source_name' => 'Google Trends', 'published_at' => $at, 'fetched_at' => $at, 'filter_verdict' => 'passed',
            'topic_id' => $topic->id, 'expires_at' => $at->copy()->addDays(30),
        ]);
        RadarRun::create(['source_key' => 'google_trends', 'started_at' => $at, 'finished_at' => $at, 'status' => 'ok', 'requests' => 1]);
    }

    public function test_items_older_than_30_days_go_and_recent_ones_stay(): void
    {
        $this->seedRadar(31, 'antiga');
        $this->seedRadar(10, 'recente');

        $stats = (new PurgeRadarItemsJob())->handle();

        $this->assertSame(['itens' => 1, 'tendencias' => 1, 'recolhas' => 1], $stats);
        $this->assertSame(['tendência recente'], RadarItem::pluck('title')->all());
        $this->assertSame(['tendência recente'], RadarTopic::pluck('title')->all());
        $this->assertSame(1, RadarRun::count());
        $this->assertNotNull(RadarItem::first()->topic_id);

        // Uma segunda execução não tem nada para apagar.
        $this->assertSame(['itens' => 0, 'tendencias' => 0, 'recolhas' => 0], (new PurgeRadarItemsJob())->handle());
        $this->assertSame(30, PurgeRadarItemsJob::RETENTION_DAYS);
    }

    public function test_it_deletes_in_batches(): void
    {
        $at = now()->subDays(40);
        $rows = [];
        for ($i = 0; $i < PurgeRadarItemsJob::BATCH + 5; $i++) {
            $rows[] = ['source_key' => 'jornal_eco', 'external_key' => sha1("i{$i}"), 'title' => "t{$i}", 'source_name' => 'ECO', 'fetched_at' => $at,
                'filter_verdict' => 'passed', 'expires_at' => $at->copy()->addDays(30), 'created_at' => $at, 'updated_at' => $at];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            RadarItem::insert($chunk);
        }

        $this->assertSame(PurgeRadarItemsJob::BATCH + 5, (new PurgeRadarItemsJob())->handle()['itens']);
        $this->assertSame(0, RadarItem::count());
    }
}
