<?php

namespace Tests\Feature;

use App\Models\AlarmEvent;
use App\Models\SmartOltOnuRegistration;
use App\Models\SnmpOlt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mengunci isolasi data demo vs nyata di instance yang sama (pendekatan is_demo + DemoScope).
 * Jika ada query baru yang melewati scope, test ini akan gagal.
 */
class DemoIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function makeOlt(string $name, string $ip, bool $isDemo): SnmpOlt
    {
        return SnmpOlt::create([
            'name' => $name,
            'vendor' => 'ZTE C320',
            'ip' => $ip,
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'is_demo' => $isDemo,
            'last_test_result' => [
                'ok' => true,
                'system' => ['sysDescr' => 'ZTE C320'],
                'ports' => [['name' => 'gpon-olt_1/1/1', 'slot' => 1, 'port' => 1, 'oper_status' => 'up']],
                'port_onus' => [
                    '1_1' => [
                        'slot' => 1, 'port' => 1, 'count' => 1,
                        'onus' => [['onu_id' => 1, 'online' => true, 'serial_number' => $name.'-SN1']],
                    ],
                ],
            ],
        ]);
    }

    private function makeAlarm(SnmpOlt $olt, bool $isDemo): void
    {
        AlarmEvent::create([
            'snmp_olt_id' => $olt->id,
            'signature' => $olt->name.'-sig',
            'type' => 'port_down',
            'severity' => 'critical',
            'status' => 'active',
            'scope' => 'port',
            'message' => $olt->name.' alarm',
            'is_demo' => $isDemo,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    private function makeRegistration(SnmpOlt $olt, bool $isDemo): void
    {
        SmartOltOnuRegistration::create([
            'snmp_olt_id' => $olt->id,
            'serial_number' => $olt->name.'-REG',
            'slot' => 1, 'port' => 1, 'pon_port' => '1/1/1', 'onu_id' => 1,
            'vlan' => 100,
            'customer_name' => $olt->name.' Customer',
            'status' => 'executed',
            'cli_script' => '! demo',
            'is_demo' => $isDemo,
        ]);
    }

    private function seedBoth(): array
    {
        $real = $this->makeOlt('OLT-REAL', '10.50.0.1', false);
        $demo = $this->makeOlt('OLT-DEMO', '10.60.0.1', true);
        $this->makeAlarm($real, false);
        $this->makeAlarm($demo, true);
        $this->makeRegistration($real, false);
        $this->makeRegistration($demo, true);

        return [$real, $demo];
    }

    public function test_real_user_only_sees_real_data(): void
    {
        [$real, $demo] = $this->seedBoth();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('cards.olt.total', 1));

        $this->actingAs($admin)->get(route('smartolt.index'))
            ->assertInertia(fn ($page) => $page->has('olts', 1));

        $this->actingAs($admin)->get(route('reports.index', ['type' => 'onu']))
            ->assertInertia(fn ($page) => $page->has('report.rows', 1));

        $this->actingAs($admin)->get(route('reports.index', ['type' => 'alarm']))
            ->assertInertia(fn ($page) => $page->has('report.rows', 1));

        // OLT demo tidak bisa diakses user nyata.
        $this->actingAs($admin)->get(route('smartolt.detail', $demo))->assertNotFound();
    }

    public function test_demo_user_only_sees_demo_data(): void
    {
        [$real, $demo] = $this->seedBoth();
        $demoUser = User::factory()->demo()->create();

        $this->actingAs($demoUser)->get(route('dashboard'))
            ->assertInertia(fn ($page) => $page->where('cards.olt.total', 1));

        $this->actingAs($demoUser)->get(route('smartolt.index'))
            ->assertInertia(fn ($page) => $page->has('olts', 1));

        $this->actingAs($demoUser)->get(route('reports.index', ['type' => 'onu']))
            ->assertInertia(fn ($page) => $page
                ->has('report.rows', 1)
                ->where('report.rows.0.serial_number', 'OLT-DEMO-SN1')
            );

        // OLT nyata tidak bisa diakses user demo.
        $this->actingAs($demoUser)->get(route('smartolt.detail', $real))->assertNotFound();
    }
}
