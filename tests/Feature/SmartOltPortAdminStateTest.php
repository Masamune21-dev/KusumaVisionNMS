<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AlarmEvent;
use App\Models\AuditLog;
use App\Models\SnmpOlt;
use App\Models\User;
use App\Services\AlarmEvaluator;
use App\Services\ZteCliProvisioningExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmartOltPortAdminStateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Tak boleh ada pesan Telegram sungguhan dari test.
        Http::fake();
    }

    public function test_admin_disables_port_with_shutdown_and_one_marker_alarm(): void
    {
        $executor = $this->fakeExecutor();
        $olt = $this->makeOlt();
        $admin = $this->user(UserRole::Admin);

        $this->actingAs($admin)
            ->postJson(route('smartolt.port.admin-state', $olt), ['slot' => 4, 'port' => 1, 'enabled' => false])
            ->assertOk()
            ->assertJson(['ok' => true, 'disabled' => true]);

        $this->assertStringContainsString("configure terminal\ninterface gpon-olt_1/4/1\nshutdown\nexit\nend", $executor->scripts[0]);
        $this->assertStringNotContainsString('write', $executor->scripts[0]);

        $alarm = AlarmEvent::where('type', AlarmEvent::TYPE_PORT_DISABLED)->sole();
        $this->assertSame(AlarmEvent::STATUS_ACTIVE, $alarm->status);
        $this->assertSame('port:4/1:port_disabled', $alarm->signature);
        $this->assertStringContainsString('2 ONU terputus', $alarm->message);
        $this->assertTrue(AuditLog::where('event', 'port.disabled')->exists());

        // Mematikan lagi port yang sudah bertanda tak menggandakan alarm.
        $this->actingAs($admin)
            ->postJson(route('smartolt.port.admin-state', $olt), ['slot' => 4, 'port' => 1, 'enabled' => false])
            ->assertOk();
        $this->assertSame(1, AlarmEvent::where('type', AlarmEvent::TYPE_PORT_DISABLED)->count());

        $this->actingAs($admin)
            ->get(route('smartolt.port.detail', ['olt' => $olt, 'interface' => 'gpon-olt_1/4/1']))
            ->assertInertia(fn ($page) => $page->where('port_disabled', true)->where('can_set_admin_state', true));
    }

    public function test_enabling_runs_no_shutdown_and_notifies_once(): void
    {
        $executor = $this->fakeExecutor();
        $olt = $this->makeOlt();
        app(AlarmEvaluator::class)->raisePortDisabled($olt, 'gpon-olt_1/4/1', 4, 1, 2, 'Admin');

        $this->actingAs($this->user(UserRole::Admin))
            ->postJson(route('smartolt.port.admin-state', $olt), ['slot' => 4, 'port' => 1, 'enabled' => true])
            ->assertOk()
            ->assertJson(['ok' => true, 'disabled' => false]);

        $this->assertStringContainsString("interface gpon-olt_1/4/1\nno shutdown\n", $executor->scripts[0]);

        $alarm = AlarmEvent::where('type', AlarmEvent::TYPE_PORT_DISABLED)->sole();
        $this->assertSame(AlarmEvent::STATUS_CLEARED, $alarm->status);
        $this->assertFalse((bool) data_get($alarm->meta, 'recovery.silent'));
        $this->assertStringContainsString('dinyalakan lagi dari NMS oleh', (string) data_get($alarm->meta, 'recovery.message'));
        $this->assertTrue(AuditLog::where('event', 'port.enabled')->exists());
    }

    public function test_only_admin_or_owning_partner_may_toggle(): void
    {
        $this->fakeExecutor();
        $olt = $this->makeOlt();
        $payload = ['slot' => 4, 'port' => 1, 'enabled' => false];

        $this->actingAs($this->user(UserRole::Operator))
            ->postJson(route('smartolt.port.admin-state', $olt), $payload)
            ->assertForbidden();

        // Partner yang sekadar di-assign ke OLT global: bukan pemilik.
        $assigned = $this->user(UserRole::Partner);
        $olt->partners()->syncWithoutDetaching([$assigned->id => ['alarms_enabled' => true]]);
        $this->actingAs($assigned)
            ->postJson(route('smartolt.port.admin-state', $olt), $payload)
            ->assertForbidden();

        $owner = $this->user(UserRole::Partner);
        $private = $this->makeOlt();
        $private->forceFill(['owner_user_id' => $owner->id])->save();
        $private->partners()->syncWithoutDetaching([$owner->id => ['alarms_enabled' => true]]);

        $this->actingAs($owner)
            ->postJson(route('smartolt.port.admin-state', $private), $payload)
            ->assertOk();
    }

    public function test_cli_error_leaves_no_marker(): void
    {
        $this->fakeExecutor(false);
        $olt = $this->makeOlt();

        $this->actingAs($this->user(UserRole::Admin))
            ->postJson(route('smartolt.port.admin-state', $olt), ['slot' => 4, 'port' => 1, 'enabled' => false])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);

        $this->assertSame(0, AlarmEvent::count());
        $this->assertTrue(AuditLog::where('event', 'port.disable_failed')->exists());
    }

    public function test_disabled_port_holds_port_down_and_onu_alarms(): void
    {
        $olt = $this->makeOlt([], $this->snapshot(portUp: false, onuOnline: false));
        $evaluator = new AlarmEvaluator;
        $evaluator->raisePortDisabled($olt, 'gpon-olt_1/4/1', 4, 1, 2, 'Admin');

        // Dua poll (debounce) setelah port mati: tak ada port_down maupun alarm ONU yang dikirim.
        $first = $evaluator->evaluate($olt, $this->snapshot(portUp: true, onuOnline: true));
        $second = $evaluator->evaluate($olt, $this->snapshot(portUp: false, onuOnline: false));

        $this->assertSame(0, $first['raised']);
        $this->assertSame(0, $second['raised']);
        $this->assertSame(0, AlarmEvent::where('type', AlarmEvent::TYPE_PORT_DOWN)->count());
        $this->assertSame(
            AlarmEvent::STATUS_ACTIVE,
            AlarmEvent::where('type', AlarmEvent::TYPE_PORT_DISABLED)->sole()->status,
        );
    }

    public function test_marker_is_released_when_port_reads_up_after_grace(): void
    {
        $olt = $this->makeOlt([], $this->snapshot(portUp: true, onuOnline: true));
        $evaluator = new AlarmEvaluator;
        $evaluator->raisePortDisabled($olt, 'gpon-olt_1/4/1', 4, 1, 2, 'Admin');
        $down = $this->snapshot(portUp: false, onuOnline: false);

        // Masih dalam tenggang: pembacaan UP (jeda SNMP) tak melepas penanda.
        $this->travel(2)->minutes();
        $evaluator->evaluate($olt, $down);
        $this->assertSame(AlarmEvent::STATUS_ACTIVE, AlarmEvent::where('type', AlarmEvent::TYPE_PORT_DISABLED)->sole()->status);

        // Lewat tenggang & port UP = dinyalakan di luar NMS → ditutup dengan satu notifikasi pulih.
        $this->travel(10)->minutes();
        $result = $evaluator->evaluate($olt, $down);

        $this->assertSame(AlarmEvent::STATUS_CLEARED, AlarmEvent::where('type', AlarmEvent::TYPE_PORT_DISABLED)->sole()->status);
        $this->assertSame(1, $result['cleared']);
    }

    private function fakeExecutor(bool $ok = true): ZteCliProvisioningExecutor
    {
        $executor = new class($ok) extends ZteCliProvisioningExecutor
        {
            /** @var array<int, string> */
            public array $scripts = [];

            public function __construct(private bool $ok) {}

            public function execute(SnmpOlt $olt, string $script, bool $largeOutput = false): array
            {
                $this->scripts[] = $script;

                return $this->ok
                    ? ['ok' => true, 'error' => null, 'output' => 'OLT-UJI#']
                    : ['ok' => false, 'error' => '%Error 20202: Invalid input detected', 'output' => ''];
            }
        };

        $this->app->instance(ZteCliProvisioningExecutor::class, $executor);

        return $executor;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function user(UserRole $role, array $attributes = []): User
    {
        return User::factory()->create(['role' => $role, ...$attributes]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<string, mixed>|null  $snapshot
     */
    private function makeOlt(array $overrides = [], ?array $snapshot = null): SnmpOlt
    {
        return SnmpOlt::create([
            'name' => 'OLT-UJI-C300',
            'vendor' => 'ZTE C300',
            'ip' => '10.30.0.'.random_int(2, 250),
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'cli_transport' => 'telnet',
            'cli_port' => 23,
            'cli_username' => 'admin',
            'cli_password' => 'secret',
            'last_test_result' => $snapshot ?? $this->snapshot(portUp: true, onuOnline: true),
            ...$overrides,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(bool $portUp, bool $onuOnline): array
    {
        $onu = fn (int $id) => [
            'slot' => 4, 'port' => 1, 'onu_id' => $id, 'interface' => "gpon-onu_1/4/1:{$id}",
            'serial_number' => sprintf('ZTEG0800%04d', $id), 'admin_state' => 'active',
            'phase_state' => $onuOnline ? 'Working' : 'Offline', 'online' => $onuOnline,
            'last_down_cause' => $onuOnline ? 'Normal' : 'LOSi', 'rx_power_dbm' => $onuOnline ? -21.5 : null,
        ];

        return [
            'ok' => true,
            'ports' => [[
                'if_index' => 268501760, 'name' => 'gpon-olt_1/4/1', 'slot' => 4, 'port' => 1,
                'oper_status' => $portUp ? 'up' : 'down',
            ]],
            'port_onus' => ['4_1' => ['onus' => [$onu(1), $onu(2)]]],
        ];
    }
}
