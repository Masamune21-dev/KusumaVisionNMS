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

    public function test_offline_onu_raises_then_clears_alarm(): void
    {
        $olt = $this->makeOlt($this->snapshotWithOnu([
            'slot' => 1, 'port' => 1, 'onu_id' => 5, 'interface' => 'gpon-onu_1/1/1:5',
            'serial_number' => 'ZTEGAAAA0001', 'admin_state' => 'active',
            'phase_state' => 'Offline', 'online' => false, 'last_down_cause' => 'LOSi',
        ]));

        $evaluator = new AlarmEvaluator();
        $result = $evaluator->evaluate($olt);

        $this->assertSame(1, $result['raised']);
        $this->assertDatabaseHas('alarm_events', [
            'snmp_olt_id' => $olt->id,
            'type' => 'onu_offline',
            'severity' => 'minor',
            'status' => 'active',
            'serial_number' => 'ZTEGAAAA0001',
        ]);

        // ONU back online -> alarm should clear, not duplicate.
        $olt->forceFill(['last_test_result' => $this->snapshotWithOnu([
            'slot' => 1, 'port' => 1, 'onu_id' => 5, 'interface' => 'gpon-onu_1/1/1:5',
            'serial_number' => 'ZTEGAAAA0001', 'admin_state' => 'active',
            'phase_state' => 'Working', 'online' => true, 'last_down_cause' => 'Normal',
        ])])->save();

        $result = $evaluator->evaluate($olt);

        $this->assertSame(1, $result['cleared']);
        $this->assertSame(0, AlarmEvent::where('status', 'active')->count());
        $this->assertSame(1, AlarmEvent::where('status', 'cleared')->count());
    }

    public function test_repeated_evaluation_does_not_duplicate_active_alarm(): void
    {
        $olt = $this->makeOlt($this->snapshotWithOnu([
            'slot' => 1, 'port' => 1, 'onu_id' => 5, 'interface' => 'gpon-onu_1/1/1:5',
            'serial_number' => 'ZTEGAAAA0002', 'admin_state' => 'active',
            'phase_state' => 'DyingGasp', 'online' => false, 'last_down_cause' => 'DyingGasp',
        ]));

        $evaluator = new AlarmEvaluator();
        $evaluator->evaluate($olt);
        $evaluator->evaluate($olt);

        $this->assertSame(1, AlarmEvent::where('snmp_olt_id', $olt->id)->where('type', 'dying_gasp')->count());
    }

    public function test_disabled_onu_does_not_raise_offline_alarm(): void
    {
        $olt = $this->makeOlt($this->snapshotWithOnu([
            'slot' => 1, 'port' => 1, 'onu_id' => 6, 'interface' => 'gpon-onu_1/1/1:6',
            'serial_number' => 'ZTEGAAAA0003', 'admin_state' => 'disabled',
            'phase_state' => 'Offline', 'online' => false, 'last_down_cause' => 'Manual',
        ]));

        (new AlarmEvaluator())->evaluate($olt);

        $this->assertSame(0, AlarmEvent::count());
    }

    public function test_unreachable_olt_raises_critical_alarm(): void
    {
        $olt = $this->makeOlt(['ok' => false, 'error' => 'SNMP timeout']);

        (new AlarmEvaluator())->evaluate($olt);

        $this->assertDatabaseHas('alarm_events', [
            'snmp_olt_id' => $olt->id,
            'type' => 'olt_unreachable',
            'severity' => 'critical',
            'status' => 'active',
        ]);
    }

    public function test_high_rx_attenuation_raises_warning(): void
    {
        $olt = $this->makeOlt($this->snapshotWithOnu([
            'slot' => 1, 'port' => 1, 'onu_id' => 7, 'interface' => 'gpon-onu_1/1/1:7',
            'serial_number' => 'ZTEGAAAA0004', 'admin_state' => 'active',
            'phase_state' => 'Working', 'online' => true, 'last_down_cause' => 'Normal',
            'rx_power_dbm' => -29.5,
        ]));

        (new AlarmEvaluator())->evaluate($olt);

        $this->assertDatabaseHas('alarm_events', [
            'snmp_olt_id' => $olt->id,
            'type' => 'high_rx_attenuation',
            'severity' => 'warning',
            'status' => 'active',
        ]);
    }

    public function test_alarms_page_can_be_rendered(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('alarms.index'));

        $response->assertOk();
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
