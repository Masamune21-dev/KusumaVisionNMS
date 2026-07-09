<?php

namespace Tests\Feature;

use App\Models\AlarmEvent;
use App\Models\SnmpOlt;
use App\Models\User;
use App\Services\AlarmEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlarmEngineTest extends TestCase
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

    private function snapshotWithOnu(array $onu, array $portOverrides = []): array
    {
        return [
            'ok' => true,
            'ports' => [
                array_merge(['if_index' => 268501760, 'name' => 'gpon-olt_1/1/1', 'slot' => 1, 'port' => 1, 'oper_status' => 'up'], $portOverrides),
            ],
            'port_onus' => [
                '1_1' => ['onus' => [$onu]],
            ],
        ];
    }

    private function onuSnapshot(bool $online, array $over = []): array
    {
        return $this->snapshotWithOnu(array_merge([
            'slot' => 1, 'port' => 1, 'onu_id' => 5, 'interface' => 'gpon-onu_1/1/1:5',
            'serial_number' => 'ZTEGAAAA0001', 'admin_state' => 'active',
            'phase_state' => $online ? 'Working' : 'Offline',
            'online' => $online,
            'last_down_cause' => $online ? 'Normal' : 'LOSi',
            'rx_power_dbm' => $online ? -22.0 : null,
        ], $over));
    }

    /**
     * Debounce anti-flap 2 poll: poll pertama sebuah fault hanya membuat baris PENDING (belum dikirim,
     * tak tampil di UI). Helper ini menjalankan poll konfirmasi kedua (fault MASIH ada) sehingga alarm
     * dipromosikan ke ACTIVE & terhitung 'raised'. Mengasumsikan `$olt->last_test_result` = snapshot
     * fault (mis. dari makeOlt). Mengembalikan hasil poll kedua.
     *
     * @return array{active:int, raised:int, cleared:int}
     */
    private function evaluateConfirmed(AlarmEvaluator $evaluator, SnmpOlt $olt, array $faultSnap, array $priorSnap): array
    {
        $evaluator->evaluate($olt, $priorSnap);        // poll 1: transisi sehat→fault → PENDING (raised 0)

        return $evaluator->evaluate($olt, $faultSnap); // poll 2: fault masih ada → promote ke ACTIVE (raised 1)
    }

    public function test_offline_onu_raises_after_confirmation_then_clears_alarm(): void
    {
        $onlineSnap = $this->onuSnapshot(true);
        $offlineSnap = $this->onuSnapshot(false);

        // Poll 1: ONU online -> offline. Debounce 2 poll: hanya PENDING (belum dikirim, tak tampil di UI).
        $olt = $this->makeOlt($offlineSnap);
        $evaluator = new AlarmEvaluator;
        $result = $evaluator->evaluate($olt, $onlineSnap);

        $this->assertSame(0, $result['raised'], 'Poll pertama tak boleh mengirim alarm (masih pending)');
        $this->assertSame(0, AlarmEvent::where('status', 'active')->count());
        $this->assertSame(1, AlarmEvent::where('status', 'pending')->count());

        // Poll 2: masih offline -> konfirmasi -> promote ke ACTIVE + dikirim.
        $result = $evaluator->evaluate($olt, $offlineSnap);

        $this->assertSame(1, $result['raised']);
        $this->assertDatabaseHas('alarm_events', [
            'snmp_olt_id' => $olt->id,
            'type' => 'onu_offline',
            'severity' => 'minor',
            'status' => 'active',
            'serial_number' => 'ZTEGAAAA0001',
        ]);

        // ONU back online -> alarm should clear, not duplicate.
        $olt->forceFill(['last_test_result' => $onlineSnap])->save();
        $result = $evaluator->evaluate($olt, $offlineSnap);

        $this->assertSame(1, $result['cleared']);
        $this->assertSame(0, AlarmEvent::where('status', 'active')->count());
        $this->assertSame(1, AlarmEvent::where('status', 'cleared')->count());

        // Cleared alarm reports the recovered state (online + latest RX), not the fault text.
        $cleared = AlarmEvent::where('status', 'cleared')->first();
        $recovery = data_get($cleared->meta, 'recovery.message');
        $this->assertStringContainsString('kembali online', $recovery);
        $this->assertStringContainsString('-22', $recovery);
    }

    public function test_transient_fault_recovers_before_confirmation_is_not_alarmed(): void
    {
        // Inti permintaan user: poll 1 down, poll 2 sudah online lagi -> alarm TAK dikirim sama sekali
        // (baris pending dihapus diam-diam, tak ada notifikasi down maupun clear).
        $onlineSnap = $this->onuSnapshot(true);
        $offlineSnap = $this->onuSnapshot(false);

        $olt = $this->makeOlt($offlineSnap);
        $evaluator = new AlarmEvaluator;

        // Poll 1: online -> offline (transien) => pending saja.
        $result = $evaluator->evaluate($olt, $onlineSnap);
        $this->assertSame(0, $result['raised']);
        $this->assertSame(1, AlarmEvent::where('status', 'pending')->count());

        // Poll 2: sudah online lagi sebelum konfirmasi => pending dibuang, tak ada alarm.
        $olt->forceFill(['last_test_result' => $onlineSnap])->save();
        $result = $evaluator->evaluate($olt, $offlineSnap);

        $this->assertSame(0, $result['raised']);
        $this->assertSame(0, $result['cleared'], 'Tak ada notifikasi clear untuk fault yang tak pernah dikirim');
        $this->assertSame(0, AlarmEvent::count(), 'Baris pending harus terhapus, tak menyisakan jejak');
    }

    public function test_already_offline_onu_is_not_alarmed(): void
    {
        // ONU was already offline last poll (never seen online) -> no transition, no alarm.
        $offlineSnap = $this->onuSnapshot(false);
        $olt = $this->makeOlt($offlineSnap);

        $result = (new AlarmEvaluator)->evaluate($olt, $offlineSnap);

        $this->assertSame(0, $result['raised']);
        $this->assertSame(0, AlarmEvent::count());
    }

    public function test_repeated_evaluation_does_not_duplicate_active_alarm(): void
    {
        $onlineSnap = $this->snapshotWithOnu([
            'slot' => 1, 'port' => 1, 'onu_id' => 5, 'interface' => 'gpon-onu_1/1/1:5',
            'serial_number' => 'ZTEGAAAA0002', 'admin_state' => 'active',
            'phase_state' => 'Working', 'online' => true, 'last_down_cause' => 'Normal',
        ]);
        $dyingSnap = $this->snapshotWithOnu([
            'slot' => 1, 'port' => 1, 'onu_id' => 5, 'interface' => 'gpon-onu_1/1/1:5',
            'serial_number' => 'ZTEGAAAA0002', 'admin_state' => 'active',
            'phase_state' => 'DyingGasp', 'online' => false, 'last_down_cause' => 'DyingGasp',
        ]);

        $olt = $this->makeOlt($dyingSnap);
        $evaluator = new AlarmEvaluator;
        $evaluator->evaluate($olt, $onlineSnap); // online -> dying: raise
        $evaluator->evaluate($olt, $dyingSnap);  // still dying: keep, no duplicate

        $this->assertSame(1, AlarmEvent::where('snmp_olt_id', $olt->id)->where('type', 'dying_gasp')->count());
    }

    public function test_alarms_disabled_olt_still_records_events(): void
    {
        // Saklar alarm OLT off HANYA menggerbang pengiriman notifikasi (Telegram/FCM);
        // evaluasi tetap jalan sehingga event tercatat & alarm dashboard tetap akurat.
        $onlineSnap = $this->onuSnapshot(true);
        $offlineSnap = $this->onuSnapshot(false);

        $olt = $this->makeOlt($offlineSnap);
        $olt->forceFill(['alarms_enabled' => false])->save();

        $result = $this->evaluateConfirmed(new AlarmEvaluator, $olt, $offlineSnap, $onlineSnap);

        $this->assertSame(1, $result['raised']);
        $this->assertSame(1, AlarmEvent::where('status', 'active')->count());
    }

    public function test_disabled_onu_does_not_raise_offline_alarm(): void
    {
        $olt = $this->makeOlt($this->snapshotWithOnu([
            'slot' => 1, 'port' => 1, 'onu_id' => 6, 'interface' => 'gpon-onu_1/1/1:6',
            'serial_number' => 'ZTEGAAAA0003', 'admin_state' => 'disabled',
            'phase_state' => 'Offline', 'online' => false, 'last_down_cause' => 'Manual',
        ]));

        (new AlarmEvaluator)->evaluate($olt);

        $this->assertSame(0, AlarmEvent::count());
    }

    public function test_unreachable_olt_raises_critical_alarm(): void
    {
        $olt = $this->makeOlt(['ok' => false, 'error' => 'SNMP timeout']);

        // OLT reachable -> unreachable. Debounce 2 poll: poll 1 pending, poll 2 (masih unreachable) raise.
        $unreachable = ['ok' => false, 'error' => 'SNMP timeout'];
        $result = $this->evaluateConfirmed(new AlarmEvaluator, $olt, $unreachable, ['ok' => true]);

        $this->assertSame(1, $result['raised']);
        $this->assertDatabaseHas('alarm_events', [
            'snmp_olt_id' => $olt->id,
            'type' => 'olt_unreachable',
            'severity' => 'critical',
            'status' => 'active',
        ]);
    }

    public function test_high_rx_attenuation_raises_warning(): void
    {
        $rxOnu = fn (float $rx) => $this->snapshotWithOnu([
            'slot' => 1, 'port' => 1, 'onu_id' => 7, 'interface' => 'gpon-onu_1/1/1:7',
            'name' => 'Customer RX',
            'description' => '7$$Customer RX$$',
            'serial_number' => 'ZTEGAAAA0004', 'admin_state' => 'active',
            'phase_state' => 'Working', 'online' => true, 'last_down_cause' => 'Normal',
            'rx_power_dbm' => $rx,
        ]);

        // RX healthy -> below -28. Debounce 2 poll: poll 1 pending, poll 2 (masih di luar rentang) raise.
        $olt = $this->makeOlt($rxOnu(-29.5));
        $result = $this->evaluateConfirmed(new AlarmEvaluator, $olt, $rxOnu(-29.5), $rxOnu(-22.0));

        $this->assertSame(1, $result['raised']);
        $this->assertDatabaseHas('alarm_events', [
            'snmp_olt_id' => $olt->id,
            'type' => 'high_rx_attenuation',
            'severity' => 'warning',
            'status' => 'active',
        ]);
        $this->assertSame('Customer RX', data_get(AlarmEvent::where('status', 'active')->first()?->meta, 'customer_name'));
    }

    public function test_rx_already_out_of_range_is_not_alarmed(): void
    {
        // RX was already below -28 last poll -> no "touch" transition, no alarm.
        $rxOnu = fn (float $rx) => $this->snapshotWithOnu([
            'slot' => 1, 'port' => 1, 'onu_id' => 7, 'interface' => 'gpon-onu_1/1/1:7',
            'serial_number' => 'ZTEGAAAA0004', 'admin_state' => 'active',
            'phase_state' => 'Working', 'online' => true, 'last_down_cause' => 'Normal',
            'rx_power_dbm' => $rx,
        ]);

        $olt = $this->makeOlt($rxOnu(-29.0));
        $result = (new AlarmEvaluator)->evaluate($olt, $rxOnu(-29.0));

        $this->assertSame(0, $result['raised']);
        $this->assertSame(0, AlarmEvent::count());
    }

    public function test_online_onu_does_not_raise_alarm_from_historical_down_cause(): void
    {
        // ONU is back online (phase Working) but its last_down_cause is still DyingGasp.
        // That historical cause must not raise an alarm.
        $olt = $this->makeOlt($this->snapshotWithOnu([
            'slot' => 1, 'port' => 1, 'onu_id' => 9, 'interface' => 'gpon-onu_1/1/1:9',
            'serial_number' => 'ZTEGAAAA0009', 'admin_state' => 'active',
            'phase_state' => 'Working', 'online' => true, 'last_down_cause' => 'DyingGasp',
        ]));

        $result = (new AlarmEvaluator)->evaluate($olt);

        $this->assertSame(0, $result['raised']);
        $this->assertSame(0, AlarmEvent::where('status', 'active')->count());
    }

    public function test_rx_alarm_uses_hysteresis_to_avoid_flapping(): void
    {
        $onu = fn (float $rx) => $this->snapshotWithOnu([
            'slot' => 1, 'port' => 1, 'onu_id' => 10, 'interface' => 'gpon-onu_1/1/1:10',
            'serial_number' => 'ZTEGAAAA0010', 'admin_state' => 'active',
            'phase_state' => 'Working', 'online' => true, 'last_down_cause' => 'Normal',
            'rx_power_dbm' => $rx,
        ]);
        $evaluator = new AlarmEvaluator;

        // Healthy (-22) then below -28 dBm -> raise setelah konfirmasi 2 poll (poll 1 pending, poll 2 raise).
        $olt = $this->makeOlt($onu(-28.4));
        $this->assertSame(0, $evaluator->evaluate($olt, $onu(-22.0))['raised']);
        $this->assertSame(1, $evaluator->evaluate($olt, $onu(-28.4))['raised']);

        // Recovers into the deadband (-27.5, still worse than -26) -> stays active, no flap.
        $olt->forceFill(['last_test_result' => $onu(-27.5)])->save();
        $this->assertSame(0, $evaluator->evaluate($olt, $onu(-28.4))['cleared']);
        $this->assertSame(1, AlarmEvent::where('type', 'high_rx_attenuation')->where('status', 'active')->count());

        // Recovers to -26 dBm -> cleared.
        $olt->forceFill(['last_test_result' => $onu(-26.0)])->save();
        $this->assertSame(1, $evaluator->evaluate($olt, $onu(-27.5))['cleared']);
        $this->assertSame(0, AlarmEvent::where('type', 'high_rx_attenuation')->where('status', 'active')->count());
    }

    public function test_port_down_raises_only_on_transition(): void
    {
        $portSnap = fn (string $status) => [
            'ok' => true,
            'ports' => [
                ['if_index' => 1, 'name' => 'gpon-olt_1/1/1', 'slot' => 1, 'port' => 1, 'oper_status' => $status],
            ],
            'port_onus' => [],
        ];

        // Port up -> down: raise setelah konfirmasi 2 poll (poll 1 pending, poll 2 masih down -> raise).
        $olt = $this->makeOlt($portSnap('down'));
        $result = $this->evaluateConfirmed(new AlarmEvaluator, $olt, $portSnap('down'), $portSnap('up'));
        $this->assertSame(1, $result['raised']);
        $this->assertDatabaseHas('alarm_events', ['snmp_olt_id' => $olt->id, 'type' => 'port_down', 'status' => 'active']);

        // A different port that was already down at baseline: no transition, no alarm (bahkan pending).
        $olt2 = $this->makeOlt($portSnap('down'));
        $result = (new AlarmEvaluator)->evaluate($olt2, $portSnap('down'));
        $this->assertSame(0, $result['raised']);
        $this->assertSame(0, AlarmEvent::where('snmp_olt_id', $olt2->id)->count());
    }

    public function test_alarms_page_can_be_rendered(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('alarms.index'));

        $response->assertOk();
    }

    public function test_alarms_page_filters_results(): void
    {
        $user = User::factory()->create();
        $oltA = $this->makeOlt(['ok' => true]);
        $oltB = $this->makeOlt(['ok' => true]);
        $oltA->update(['name' => 'OLT-A']);
        $oltB->update(['name' => 'OLT-B']);

        AlarmEvent::create([
            'snmp_olt_id' => $oltA->id,
            'signature' => 'olt:unreachable',
            'type' => 'olt_unreachable',
            'severity' => 'critical',
            'status' => 'active',
            'scope' => 'olt',
            'message' => 'SNMP timeout on OLT-A.',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
        AlarmEvent::create([
            'snmp_olt_id' => $oltA->id,
            'signature' => 'onu:ZTEGSEARCH:offline',
            'type' => 'onu_offline',
            'severity' => 'minor',
            'status' => 'active',
            'scope' => 'onu',
            'serial_number' => 'ZTEGSEARCH',
            'message' => 'ONU ZTEGSEARCH offline.',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
        AlarmEvent::create([
            'snmp_olt_id' => $oltB->id,
            'signature' => 'onu:OTHER:los',
            'type' => 'los',
            'severity' => 'major',
            'status' => 'cleared',
            'scope' => 'onu',
            'serial_number' => 'OTHER',
            'message' => 'ONU OTHER LOS.',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'cleared_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('alarms.index', [
            'severity' => 'critical',
            'olt_id' => $oltA->id,
            'scope' => 'olt',
            'q' => 'timeout',
        ]));

        $response->assertInertia(fn ($page) => $page
            ->component('SmartOlt/Alarms')
            ->where('alarms.total', 1)
            ->where('alarms.data.0.type', 'olt_unreachable')
            ->where('filter.severity', 'critical')
            ->where('filter.olt_id', $oltA->id)
            ->where('filter.scope', 'olt')
            ->where('filter.q', 'timeout')
            ->has('filterOptions.olts', 2)
            ->has('filterOptions.types', 3)
        );
    }

    public function test_alarms_page_includes_customer_name_from_poll_snapshot(): void
    {
        $user = User::factory()->create();
        $olt = $this->makeOlt($this->snapshotWithOnu([
            'slot' => 2, 'port' => 2, 'onu_id' => 5, 'interface' => 'gpon-onu_1/2/2:5',
            'name' => 'Jefri Alugoro',
            'description' => '5$$Jefri Alugoro$$',
            'serial_number' => 'ELWGC09C61E1',
            'admin_state' => 'active',
            'phase_state' => 'Working',
            'online' => true,
            'last_down_cause' => 'LOFi',
            'rx_power_dbm' => -28.54,
        ]));

        AlarmEvent::create([
            'snmp_olt_id' => $olt->id,
            'signature' => 'onu:ELWGC09C61E1:high_rx_attenuation',
            'type' => 'high_rx_attenuation',
            'severity' => 'warning',
            'status' => 'active',
            'scope' => 'onu',
            'slot' => 2,
            'port' => 2,
            'onu_id' => 5,
            'serial_number' => 'ELWGC09C61E1',
            'message' => 'ONU gpon-onu_1/2/2:5 RX -28.54 dBm di luar rentang sehat.',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('alarms.index'));

        $response->assertInertia(fn ($page) => $page
            ->component('SmartOlt/Alarms')
            ->where('alarms.data.0.customer_name', 'Jefri Alugoro')
        );
    }

    public function test_alarms_are_paginated(): void
    {
        $user = User::factory()->create();
        $olt = $this->makeOlt(['ok' => true]);

        for ($i = 1; $i <= 25; $i++) {
            AlarmEvent::create([
                'snmp_olt_id' => $olt->id,
                'signature' => "onu:SN{$i}:onu_offline",
                'type' => 'onu_offline',
                'severity' => 'minor',
                'status' => 'active',
                'scope' => 'onu',
                'serial_number' => "SN{$i}",
                'message' => "ONU SN{$i} offline.",
                'first_seen_at' => now(),
                'last_seen_at' => now()->subSeconds($i),
            ]);
        }

        $response = $this->actingAs($user)->get(route('alarms.index'));

        $response->assertInertia(fn ($page) => $page
            ->component('SmartOlt/Alarms')
            ->where('alarms.total', 25)
            ->where('alarms.per_page', 20)
            ->has('alarms.data', 20)
        );
    }
}
