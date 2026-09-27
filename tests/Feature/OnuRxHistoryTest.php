<?php

namespace Tests\Feature;

use App\Models\OnuRxHourly;
use App\Models\OnuRxSample;
use App\Models\SnmpOlt;
use App\Models\User;
use App\Services\ZteOnuDetailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnuRxHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function makeOlt(): SnmpOlt
    {
        return SnmpOlt::create([
            'name' => 'PATI-ZTE-C320',
            'vendor' => 'ZTE C320',
            'ip' => '10.20.0.5',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
        ]);
    }

    private function sample(SnmpOlt $olt, float $rx, $polledAt, int $onuId = 1): OnuRxSample
    {
        return OnuRxSample::create([
            'snmp_olt_id' => $olt->id,
            'slot' => 1,
            'port' => 1,
            'onu_id' => $onuId,
            'serial_number' => 'ZTEG'.$onuId,
            'rx_power_dbm' => $rx,
            'polled_at' => $polledAt,
        ]);
    }

    public function test_series_for_filters_by_range_and_orders_ascending(): void
    {
        $olt = $this->makeOlt();
        $this->sample($olt, -19.0, now()->subDays(10)); // di luar rentang
        $this->sample($olt, -20.5, now()->subHours(20));
        $this->sample($olt, -18.0, now()->subHour());
        $this->sample($olt, -25.0, now()->subHour(), onuId: 2); // ONU lain, harus diabaikan

        // Rentang 24 jam dilayani sampel mentah.
        $series = OnuRxSample::seriesFor($olt->id, 1, 1, 1, now()->subDay());

        $this->assertCount(2, $series);
        $this->assertEqualsWithDelta(-20.5, $series[0]['rx_power_dbm'], 0.001);
        $this->assertEqualsWithDelta(-18.0, $series[1]['rx_power_dbm'], 0.001);
    }

    public function test_rentang_panjang_dilayani_ringkasan_per_jam(): void
    {
        $olt = $this->makeOlt();

        // Dua sampel di satu jam yang sama, 20 hari lalu — jauh di luar umur
        // sampel mentah, jadi hanya ringkasannya yang boleh menjawab.
        $jam = now()->subDays(20)->startOfHour();
        $this->sample($olt, -22.0, $jam->copy()->addMinutes(5));
        $this->sample($olt, -24.0, $jam->copy()->addMinutes(35));

        $this->artisan('optical:aggregate-rx', ['--hours' => 24 * 21])->assertSuccessful();

        $ringkasan = OnuRxHourly::query()->where('snmp_olt_id', $olt->id)->first();
        $this->assertNotNull($ringkasan);
        $this->assertSame(2, $ringkasan->sample_count);
        $this->assertEqualsWithDelta(-24.0, $ringkasan->rx_min_dbm, 0.001);
        $this->assertEqualsWithDelta(-23.0, $ringkasan->rx_avg_dbm, 0.001);
        $this->assertEqualsWithDelta(-22.0, $ringkasan->rx_max_dbm, 0.001);

        // Sampel mentahnya dibuang, grafik 30 hari harus tetap berisi.
        OnuRxSample::query()->delete();

        $series = OnuRxSample::seriesFor($olt->id, 1, 1, 1, now()->subDays(30));

        $this->assertCount(1, $series);
        $this->assertEqualsWithDelta(-23.0, $series[0]['rx_power_dbm'], 0.001);
    }

    public function test_agregasi_idempoten_saat_jam_yang_sama_diproses_ulang(): void
    {
        $olt = $this->makeOlt();
        $jam = now()->subDays(3)->startOfHour();
        $this->sample($olt, -20.0, $jam->copy()->addMinutes(10));

        $this->artisan('optical:aggregate-rx', ['--hours' => 24 * 4])->assertSuccessful();
        $this->artisan('optical:aggregate-rx', ['--hours' => 24 * 4])->assertSuccessful();

        $this->assertSame(1, OnuRxHourly::query()->count());
    }

    public function test_prune_dilewati_selama_jamnya_belum_terangkum(): void
    {
        $olt = $this->makeOlt();
        $lama = $this->sample($olt, -19.0, now()->subDays(5));

        // Tanpa ringkasan, membuang sampel mentah berarti menghapus riwayat
        // yang tidak punya salinan di mana pun.
        $this->artisan('optical:prune-rx', ['--days' => 1])->assertSuccessful();

        $this->assertDatabaseHas('onu_rx_samples', ['id' => $lama->id]);
    }

    public function test_prune_command_deletes_samples_older_than_retention(): void
    {
        $olt = $this->makeOlt();
        $old = $this->sample($olt, -19.0, now()->subDays(5));
        $fresh = $this->sample($olt, -18.0, now()->subHours(2));

        // Prune hanya berjalan sejauh agregasi sudah sampai.
        $this->artisan('optical:aggregate-rx', ['--hours' => 24 * 6])->assertSuccessful();
        $this->artisan('optical:prune-rx', ['--days' => 1])->assertSuccessful();

        $this->assertDatabaseMissing('onu_rx_samples', ['id' => $old->id]);
        $this->assertDatabaseHas('onu_rx_samples', ['id' => $fresh->id]);
    }

    public function test_onu_detail_exposes_rx_history(): void
    {
        $olt = $this->makeOlt();
        $this->sample($olt, -20.0, now()->subDays(2));
        $this->sample($olt, -19.0, now()->subHour());

        // Rentang 7d melampaui umur sampel mentah, jadi controller menjawab dari ringkasan per
        // jam (lihat OnuRxSample::needsHourly, sejak 993ed54). Ringkas dulu seperti scheduler
        // di produksi — tanpa ini test hanya menguji tabel yang memang tidak dibaca.
        $this->artisan('optical:aggregate-rx', ['--hours' => 24 * 3])->assertSuccessful();

        // Stub live CLI fetch agar tidak membuka sesi telnet saat test.
        $this->app->instance(ZteOnuDetailService::class, new class extends ZteOnuDetailService
        {
            public function __construct() {}

            public function fetch(SnmpOlt $olt, int $slot, int $port, int $onuId): array
            {
                return [
                    'ok' => false,
                    'groups' => ['identity' => [], 'state' => [], 'optical' => [], 'last_event' => [], 'all' => []],
                    'raw' => '',
                    'error' => null,
                ];
            }
        });

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('smartolt.onu.detail', ['olt' => $olt->id, 'slot' => 1, 'port' => 1, 'onuId' => 1, 'range' => '7d']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('SmartOlt/OnuDetail')
                ->where('range', '7d')
                ->has('rx_history', 2)
            );
    }
}
