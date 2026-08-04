<?php

namespace Tests\Feature;

use App\Models\AlarmEvent;
use App\Models\AlarmSetting;
use App\Models\Odp;
use App\Models\OnuOdpLink;
use App\Models\SnmpOlt;
use App\Models\TelegramSetting;
use App\Services\AlarmEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Korelasi root-cause: kalau induknya yang mati (port PON atau ODP), penerima cukup dapat SATU
 * pesan induk — bukan puluhan pesan per pelanggan. Ini yang diminta owner setelah lapangan
 * kebanjiran notifikasi ONU tiap satu ODP putus.
 */
class AlarmOdpCorrelationTest extends TestCase
{
    use RefreshDatabase;

    private function makeOlt(array $lastTestResult): SnmpOlt
    {
        return SnmpOlt::create([
            'name' => 'PATI-ZTE-C320',
            'vendor' => 'ZTE C320',
            'ip' => '10.30.0.'.random_int(2, 250),
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'last_test_result' => $lastTestResult,
        ]);
    }

    /**
     * Snapshot 1 port PON berisi 3 ONU; `$onlineIds` menentukan siapa yang masih online.
     *
     * @param  array<int, int>  $onlineIds
     * @return array<string, mixed>
     */
    private function snapshot(array $onlineIds, string $portStatus = 'up'): array
    {
        $onus = [];

        foreach ([1, 2, 3] as $id) {
            $online = in_array($id, $onlineIds, true);
            $onus[] = [
                'slot' => 1, 'port' => 1, 'onu_id' => $id, 'interface' => "gpon-onu_1/1/1:{$id}",
                'serial_number' => "ZTEGODP000{$id}", 'admin_state' => 'active',
                'name' => "Pelanggan {$id}",
                'phase_state' => $online ? 'Working' : 'LOS',
                'online' => $online,
                'last_down_cause' => $online ? 'Normal' : 'LOS',
                'rx_power_dbm' => $online ? -22.0 : null,
            ];
        }

        return [
            'ok' => true,
            'ports' => [['if_index' => 1, 'name' => 'gpon-olt_1/1/1', 'slot' => 1, 'port' => 1, 'oper_status' => $portStatus]],
            'port_onus' => ['1_1' => ['onus' => $onus]],
        ];
    }

    /**
     * Kaitkan ketiga ONU ke satu ODP (seperti hasil pemetaan lapangan di halaman ODP/peta).
     */
    private function linkOnusToOdp(SnmpOlt $olt, string $name = 'ODP-KETANEN'): Odp
    {
        $odp = Odp::create([
            'snmp_olt_id' => $olt->id,
            'name' => $name,
            'slot' => 1,
            'port' => 1,
            'latitude' => -6.75,
            'longitude' => 111.03,
        ]);

        foreach ([1, 2, 3] as $id) {
            OnuOdpLink::create([
                'odp_id' => $odp->id,
                'snmp_olt_id' => $olt->id,
                'slot' => 1,
                'port' => 1,
                'onu_id' => $id,
                'serial_number' => "ZTEGODP000{$id}",
            ]);
        }

        return $odp;
    }

    public function test_all_onus_of_one_odp_down_raises_single_odp_alarm_without_child_alarms(): void
    {
        $healthy = $this->snapshot([1, 2, 3]);
        $allDown = $this->snapshot([]);

        $olt = $this->makeOlt($allDown);
        $this->linkOnusToOdp($olt);

        $evaluator = new AlarmEvaluator;
        $evaluator->evaluate($olt, $healthy);           // poll 1: odp_down PENDING
        $result = $evaluator->evaluate($olt, $allDown); // poll 2: konfirmasi

        $this->assertSame(1, $result['raised'], 'Hanya satu alarm ODP yang dikirim');

        $alarm = AlarmEvent::where('type', AlarmEvent::TYPE_ODP_DOWN)->sole();
        $this->assertSame(AlarmEvent::STATUS_ACTIVE, $alarm->status);
        $this->assertSame('odp', $alarm->scope);
        $this->assertStringContainsString('ODP-KETANEN', $alarm->message);
        $this->assertStringContainsString('semua 3 ONU offline', $alarm->message);
        $this->assertSame(3, data_get($alarm->meta, 'affected_onus'));

        $this->assertSame(
            0,
            AlarmEvent::whereIn('type', ['onu_offline', 'los', 'dying_gasp'])->count(),
            'ONU anggota ODP tak boleh membuat alarm sendiri',
        );
    }

    public function test_child_alarms_opened_before_odp_went_fully_down_are_not_notified(): void
    {
        // Skenario lapangan: ONU jatuh bertahap. Sebagian sempat jadi episode pending, lalu
        // sisanya menyusul sampai satu ODP mati total — inilah celah yang dulu bocor ke Telegram.
        $healthy = $this->snapshot([1, 2, 3]);
        $partial = $this->snapshot([3]);   // ONU 1 & 2 down
        $allDown = $this->snapshot([]);    // semuanya down

        $olt = $this->makeOlt($partial);
        $this->linkOnusToOdp($olt);
        $evaluator = new AlarmEvaluator;

        // Poll 1: 2 ONU down → pending (belum dikirim).
        $this->assertSame(0, $evaluator->evaluate($olt, $healthy)['raised']);
        $this->assertSame(2, AlarmEvent::where('status', AlarmEvent::STATUS_PENDING)->count());

        // Poll 2: ODP mati total → alarm anak dipromosikan DIAM-DIAM, odp_down masih pending.
        $olt->forceFill(['last_test_result' => $allDown])->save();
        $this->assertSame(0, $evaluator->evaluate($olt, $partial)['raised'], 'Alarm ONU anak tak boleh dikirim');

        // Poll 3: konfirmasi → yang dikirim HANYA alarm ODP.
        $result = $evaluator->evaluate($olt, $allDown);
        $this->assertSame(1, $result['raised']);
        $this->assertSame(
            AlarmEvent::TYPE_ODP_DOWN,
            AlarmEvent::where('status', AlarmEvent::STATUS_ACTIVE)->orderByDesc('id')->first()->type,
        );

        // Alarm anak tetap tercatat untuk UI/riwayat, tapi ditandai tak pernah dinotifikasikan.
        $child = AlarmEvent::where('type', 'los')->where('serial_number', 'ZTEGODP0001')->sole();
        $this->assertSame(AlarmEvent::STATUS_ACTIVE, $child->status);
        $this->assertFalse(data_get($child->meta, 'notified'));
    }

    public function test_recovery_only_notifies_the_parent_alarm(): void
    {
        $healthy = $this->snapshot([1, 2, 3]);
        $partial = $this->snapshot([3]);
        $allDown = $this->snapshot([]);

        $olt = $this->makeOlt($partial);
        $this->linkOnusToOdp($olt);
        $evaluator = new AlarmEvaluator;

        $evaluator->evaluate($olt, $healthy);
        $olt->forceFill(['last_test_result' => $allDown])->save();
        $evaluator->evaluate($olt, $partial);
        $evaluator->evaluate($olt, $allDown); // odp_down ACTIVE, 2 alarm anak ACTIVE tapi silent

        // Semua ONU kembali online.
        $olt->forceFill(['last_test_result' => $healthy])->save();
        $result = $evaluator->evaluate($olt, $allDown);

        $this->assertSame(1, $result['cleared'], 'Hanya alarm ODP yang mengirim pesan pemulihan');
        $this->assertSame(0, AlarmEvent::where('status', AlarmEvent::STATUS_ACTIVE)->count());
        $this->assertSame(3, AlarmEvent::where('status', AlarmEvent::STATUS_CLEARED)->count());
    }

    public function test_port_down_suppresses_onu_alarms_opened_in_an_earlier_poll(): void
    {
        // Kasus yang dilaporkan owner: ONU jatuh lebih dulu (jadi pending), status port baru
        // terbaca down di poll berikutnya → dulu alarm ONU tetap terkirim. Kini tidak.
        $healthy = $this->snapshot([1, 2, 3]);
        $onusDownPortUp = $this->snapshot([]);
        $portDown = $this->snapshot([], 'down');

        $olt = $this->makeOlt($onusDownPortUp);
        $evaluator = new AlarmEvaluator;

        $this->assertSame(0, $evaluator->evaluate($olt, $healthy)['raised']);
        $this->assertSame(3, AlarmEvent::where('status', AlarmEvent::STATUS_PENDING)->count());

        // Port terbaca down: alarm ONU anak jadi diam, port_down masuk fase pending.
        $olt->forceFill(['last_test_result' => $portDown])->save();
        $this->assertSame(0, $evaluator->evaluate($olt, $onusDownPortUp)['raised']);

        $result = $evaluator->evaluate($olt, $portDown);
        $this->assertSame(1, $result['raised']);
        $this->assertSame(AlarmEvent::TYPE_PORT_DOWN, AlarmEvent::where('status', AlarmEvent::STATUS_ACTIVE)
            ->where('scope', 'port')->sole()->type);
        $this->assertSame(3, AlarmEvent::where('scope', 'onu')->where('status', AlarmEvent::STATUS_ACTIVE)->count());
        $this->assertSame(0, AlarmEvent::where('scope', 'onu')->get()
            ->filter(fn (AlarmEvent $a) => data_get($a->meta, 'notified') !== false)->count());
    }

    public function test_onu_still_down_after_parent_recovers_is_notified_then(): void
    {
        // Port pulih tapi satu ONU tetap mati → sekarang gangguan mandiri, baru dikirim.
        $healthy = $this->snapshot([1, 2, 3]);
        $portDown = $this->snapshot([], 'down');
        $portUpOneDown = $this->snapshot([2, 3]); // hanya ONU 1 yang masih mati

        $olt = $this->makeOlt($portDown);
        $evaluator = new AlarmEvaluator;

        $evaluator->evaluate($olt, $healthy);
        $evaluator->evaluate($olt, $portDown); // port_down ACTIVE, alarm ONU silent

        // Port pulih: alarm port di-clear, ONU yang masih mati masuk fase pending (debounce 2 poll).
        $olt->forceFill(['last_test_result' => $portUpOneDown])->save();
        $recovery = $evaluator->evaluate($olt, $portDown);

        $this->assertSame(1, $recovery['cleared'], 'Alarm port yang pulih dikirim');
        $this->assertSame(0, $recovery['raised']);
        $this->assertSame(1, AlarmEvent::where('status', AlarmEvent::STATUS_PENDING)->count());

        // Poll konfirmasi: ONU itu tetap mati → kini dikirim sebagai gangguan mandiri.
        $result = $evaluator->evaluate($olt, $portUpOneDown);

        $this->assertSame(1, $result['raised'], 'ONU yang masih mati setelah port pulih kini dikirim');

        $survivor = AlarmEvent::where('serial_number', 'ZTEGODP0001')->sole();
        $this->assertSame(AlarmEvent::STATUS_ACTIVE, $survivor->status);
        $this->assertNull(data_get($survivor->meta, 'notified'), 'Tanda diam dilepas saat alarm dikirim');
    }

    public function test_existing_per_onu_episodes_are_lifted_into_one_odp_alarm(): void
    {
        // Kondisi produksi saat fitur ini dipasang: ODP sudah mati total dan gangguannya sudah
        // terlanjur tercatat sebagai alarm per-ONU. Sekali saja diangkat jadi satu alarm ODP,
        // supaya pemulihannya nanti punya induk yang melapor dan alarm ONU-nya berhenti berisik.
        $allDown = $this->snapshot([]);
        $olt = $this->makeOlt($allDown);
        $this->linkOnusToOdp($olt);

        foreach ([1, 2, 3] as $id) {
            AlarmEvent::create([
                'snmp_olt_id' => $olt->id,
                'signature' => "onu:ZTEGODP000{$id}:los",
                'type' => AlarmEvent::TYPE_LOS,
                'severity' => AlarmEvent::SEVERITY_MAJOR,
                'status' => AlarmEvent::STATUS_ACTIVE,
                'scope' => 'onu',
                'slot' => 1, 'port' => 1, 'onu_id' => $id,
                'serial_number' => "ZTEGODP000{$id}",
                'message' => 'ONU offline',
                'first_seen_at' => now()->subDay(),
                'last_seen_at' => now()->subDay(),
            ]);
        }

        $evaluator = new AlarmEvaluator;
        // Snapshot sebelumnya juga sudah mati total (bukan transisi) — tanpa aturan ini, diam saja.
        $evaluator->evaluate($olt, $allDown);
        $result = $evaluator->evaluate($olt, $allDown);

        $this->assertSame(1, $result['raised']);
        $this->assertSame(AlarmEvent::TYPE_ODP_DOWN, AlarmEvent::where('scope', 'odp')->sole()->type);
        $this->assertSame(3, AlarmEvent::where('scope', 'onu')->get()
            ->filter(fn (AlarmEvent $a) => data_get($a->meta, 'notified') === false)->count());
    }

    public function test_correlation_can_be_disabled_from_settings(): void
    {
        AlarmSetting::create(['suppress_child_alarms' => false]);

        $healthy = $this->snapshot([1, 2, 3]);
        $allDown = $this->snapshot([]);

        $olt = $this->makeOlt($allDown);
        $this->linkOnusToOdp($olt);

        $evaluator = new AlarmEvaluator;
        $evaluator->evaluate($olt, $healthy);
        $result = $evaluator->evaluate($olt, $allDown);

        // 1 alarm ODP + 3 alarm ONU (perilaku lama, per pelanggan).
        $this->assertSame(4, $result['raised']);
        $this->assertSame(3, AlarmEvent::where('scope', 'onu')->where('status', AlarmEvent::STATUS_ACTIVE)->count());
    }

    public function test_single_customer_odp_still_reports_per_onu(): void
    {
        // ODP berisi 1 ONU = pelanggan tunggal → tetap alarm ONU biasa, bukan "ODP down".
        $healthy = $this->snapshot([1, 2, 3]);
        $oneDown = $this->snapshot([2, 3]);

        $olt = $this->makeOlt($oneDown);
        $odp = Odp::create([
            'snmp_olt_id' => $olt->id, 'name' => 'ODP-SOLO',
            'slot' => 1, 'port' => 1, 'latitude' => -6.75, 'longitude' => 111.03,
        ]);
        OnuOdpLink::create([
            'odp_id' => $odp->id, 'snmp_olt_id' => $olt->id,
            'slot' => 1, 'port' => 1, 'onu_id' => 1, 'serial_number' => 'ZTEGODP0001',
        ]);

        $evaluator = new AlarmEvaluator;
        $evaluator->evaluate($olt, $healthy);
        $result = $evaluator->evaluate($olt, $oneDown);

        $this->assertSame(1, $result['raised']);
        $this->assertSame(0, AlarmEvent::where('type', AlarmEvent::TYPE_ODP_DOWN)->count());
        $this->assertSame('los', AlarmEvent::where('status', AlarmEvent::STATUS_ACTIVE)->sole()->type);
    }

    public function test_telegram_receives_one_odp_message_not_one_per_customer(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        TelegramSetting::create([
            'enabled' => true,
            'bot_token' => '123:ABC',
            'chat_id' => '-1009',
        ]);

        $healthy = $this->snapshot([1, 2, 3]);
        $allDown = $this->snapshot([]);

        $olt = $this->makeOlt($allDown);
        $this->linkOnusToOdp($olt);

        $evaluator = new AlarmEvaluator;
        $evaluator->evaluate($olt, $healthy);
        $evaluator->evaluate($olt, $allDown);

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            $text = (string) $request['text'];

            return str_contains($request->url(), '/sendMessage')
                && str_contains($text, 'ODP-KETANEN')
                && ! str_contains($text, 'gpon-onu_1/1/1:1');
        });
    }
}
