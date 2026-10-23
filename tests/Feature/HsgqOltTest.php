<?php

namespace Tests\Feature;

use App\Models\AlarmEvent;
use App\Models\AuditLog;
use App\Models\SnmpOlt;
use App\Models\User;
use App\Services\AlarmEvaluator;
use App\Services\CData\CDataOltScanner;
use App\Services\Hsgq\HsgqSnmpService;
use App\Services\Hsgq\HsgqWebService;
use App\Services\ZteRemoteOnuService;
use App\Support\SmartOltSupport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * OLT HSGQ asli (HS Fiber 50224) — halaman `hsgq-olt.*`, aksi lewat API web JSON, dan pengaman
 * driver yang belum terverifikasi. Semua data fiktif; tak ada SNMP/HTTP nyata (Http::fake).
 */
class HsgqOltTest extends TestCase
{
    use RefreshDatabase;

    private const OLT_IP = '10.30.0.77';

    private function hsgqOlt(array $overrides = []): SnmpOlt
    {
        return SnmpOlt::create(array_merge([
            'name' => 'OLT-HSGQ-UJI',
            'vendor' => 'HSGQ 50224',
            'ip' => self::OLT_IP,
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'web_port' => 8080,
            'cli_username' => 'root',
            'cli_password' => 'admin',
            'last_test_result' => [
                'onu_scanned_at' => now()->toIso8601String(),
                'system' => ['sys_descr' => 'HSGQ-G02ID', 'pon_type' => 'gpon'],
                'ports' => [['slot' => 1, 'port' => 1, 'name' => 'PON01', 'oper_status' => 'up']],
                'port_onus' => [
                    '1_1' => [
                        'ok' => true, 'slot' => 1, 'port' => 1, 'count' => 2,
                        'onus' => [
                            ['onu_key' => '1.0', 'slot' => 1, 'port' => 1, 'onu_id' => 0, 'interface' => 'PON01/0', 'serial_number' => 'HWTC00080001', 'name' => 'uji0800-a', 'online' => true],
                            ['onu_key' => '1.1', 'slot' => 1, 'port' => 1, 'onu_id' => 1, 'interface' => 'PON01/1', 'serial_number' => 'ZTEG00080002', 'name' => 'uji0800-b', 'online' => true],
                        ],
                    ],
                ],
            ],
        ], $overrides));
    }

    private function base(): string
    {
        return 'http://'.self::OLT_IP.':8080';
    }

    /** Web OLT palsu: login → X-Token, tabel ONU GPON, aksi tulis sukses. */
    private function fakeGponWeb(array $extra = []): void
    {
        Http::fake(array_merge([
            $this->base().'/userlogin*' => Http::response(['code' => 1, 'message' => 'success'], 200, ['X-Token' => 'tok-0800']),
            $this->base().'/ontinfo_table' => Http::response(['code' => 1, 'message' => 'success', 'data' => [
                ['ont_sn' => 'HWTC-00080001', 'ont_name' => 'uji0800-a', 'ont_description' => 'Tiang 3', 'port_id' => 1, 'ont_id' => 0, 'identifier' => 4097],
                ['ont_sn' => '5A54454700080002', 'ont_name' => 'uji0800-b', 'ont_description' => '', 'port_id' => 1, 'ont_id' => 1, 'identifier' => 4098],
            ]]),
            $this->base().'/gponont_mgmt*' => Http::response(['code' => 1, 'message' => 'success']),
            $this->base().'/system_save' => Http::response(['code' => 1, 'message' => 'success']),
        ], $extra));
    }

    public function test_index_partitions_hsgq_olts_into_their_own_tab(): void
    {
        $this->hsgqOlt();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('smartolt.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('SmartOlt/Index')
                ->has('hsgqOlts', 1)
                ->has('olts', 0)
                ->has('cdataOlts', 0)
                ->where('hsgqOlts.0.driver', SmartOltSupport::DRIVER_HSGQ)
                ->where('hsgqOlts.0.web_port', 8080));
    }

    public function test_create_store_and_pages_render(): void
    {
        $admin = User::factory()->admin()->create();
        $scanner = Mockery::mock(CDataOltScanner::class);
        $scanner->shouldReceive('scan')->once()->andReturn(0);
        $this->app->instance(CDataOltScanner::class, $scanner);

        $this->actingAs($admin)->get(route('hsgq-olt.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Hsgq/Create')->where('defaults.web_port', 80));

        $this->actingAs($admin)->post(route('hsgq-olt.store'), [
            'name' => 'OLT-HSGQ-BARU',
            'vendor' => 'HSGQ 50224',
            'ip' => '10.30.0.78',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'web_port' => 80,
            'cli_username' => 'root',
            'cli_password' => 'admin',
        ])->assertRedirect(route('smartolt.index', ['tab' => 'hsgq']));

        $olt = SnmpOlt::where('ip', '10.30.0.78')->firstOrFail();
        $this->assertSame(80, $olt->web_port);
        $this->assertSame(SmartOltSupport::DRIVER_HSGQ, SmartOltSupport::driverKey($olt));

        $existing = $this->hsgqOlt();
        $this->actingAs($admin)->get(route('hsgq-olt.detail', $existing))
            ->assertOk()->assertInertia(fn ($page) => $page->component('Hsgq/Detail')->where('olt.capabilities.hsgq_unverified', true));
        $this->actingAs($admin)->get(route('hsgq-olt.port-onus', [$existing, 1, 1]))
            ->assertOk()->assertInertia(fn ($page) => $page->component('Hsgq/PortOnus')->where('snapshot.onus.0.onu_id', 0));
        $this->actingAs($admin)->get(route('hsgq-olt.edit', $existing))
            ->assertOk()->assertInertia(fn ($page) => $page->component('Hsgq/Edit'));
        $this->actingAs($admin)->get(route('hsgq-olt.pon-ports', $existing))
            ->assertOk()->assertInertia(fn ($page) => $page->component('SmartOlt/PonPorts')->where('route_prefix', 'hsgq-olt'));
    }

    public function test_gpon_reboot_finds_onu_by_serial_and_keeps_name(): void
    {
        $this->fakeGponWeb();
        $olt = $this->hsgqOlt();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('hsgq-olt.onu.reboot', [$olt, 1, 1, 0]))
            ->assertRedirect(route('hsgq-olt.port-onus', [$olt, 1, 1]))
            ->assertSessionHas('success');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/userlogin')
            && $r['param']['key'] === md5('root:admin')
            && $r['param']['value'] === base64_encode('admin'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/gponont_mgmt?form=info')
            && $r->hasHeader('X-Token', 'tok-0800')
            && $r['param'] === ['identifier' => 4097, 'flags' => 4, 'ont_name' => 'uji0800-a', 'ont_description' => 'Tiang 3']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'onu.rebooted']);
    }

    public function test_gpon_rename_matches_hex_serial_and_updates_cache(): void
    {
        $this->fakeGponWeb();
        $olt = $this->hsgqOlt();

        // ONU 1: SN di web dalam bentuk 16 hex (5A544547… = ZTEG…).
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('hsgq-olt.onu.info', [$olt, 1, 1, 1]), ['name' => 'uji0800 baru!', 'description' => null])
            ->assertSessionHas('success');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/gponont_mgmt?form=info')
            && $r['param'] === ['identifier' => 4098, 'flags' => 8, 'ont_name' => 'uji0800 baru_', 'ont_description' => '']);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/system_save'));
        $this->assertSame('uji0800 baru_', data_get($olt->fresh()->last_test_result, 'port_onus.1_1.onus.1.name'));
    }

    public function test_epon_reboot_echoes_current_fec_mode(): void
    {
        Http::fake([
            $this->base().'/userlogin*' => Http::response(['code' => 1], 200, ['X-Token' => 'tok-0800']),
            $this->base().'/onutable' => Http::response(['code' => 1, 'data' => [
                ['macaddr' => '00:00:5e:00:53:11', 'onu_name' => 'uji0800-e', 'onu_desc' => '', 'port_id' => 1, 'onu_id' => 1],
            ]]),
            $this->base().'/onumgmt?form=base-info*' => Http::response(['code' => 1, 'data' => ['fec_mode' => 0]]),
            $this->base().'/onumgmt?form=config' => Http::response(['code' => 1, 'message' => 'success']),
        ]);
        $olt = $this->hsgqOlt(['last_test_result' => [
            'system' => ['sys_descr' => 'HSGQ-XE04R', 'pon_type' => 'epon'],
            'port_onus' => ['1_1' => ['onus' => [
                ['onu_key' => '1.1', 'slot' => 1, 'port' => 1, 'onu_id' => 1, 'serial_number' => '00:00:5E:00:53:11', 'mac' => '00:00:5E:00:53:11'],
            ]]],
        ]]);

        $result = app(HsgqWebService::class)->reboot($olt, 1, 1, 1);

        $this->assertTrue($result['ok'], (string) $result['error']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/onumgmt?form=config')
            && $r['param']['flags'] === 1 && $r['param']['fec_mode'] === 0 && $r['param']['port_id'] === 1);
    }

    public function test_expired_token_relogs_once(): void
    {
        $this->fakeGponWeb([
            $this->base().'/ontinfo_table' => Http::sequence()
                ->push(['code' => -1, 'message' => 'Token Check Failed'])
                ->push(['code' => 1, 'data' => [['ont_sn' => 'HWTC00080001', 'ont_name' => 'a', 'ont_description' => '', 'identifier' => 1]]]),
        ]);

        $result = app(HsgqWebService::class)->reboot($this->hsgqOlt(), 1, 1, 0);

        $this->assertTrue($result['ok'], (string) $result['error']);
        Http::assertSentCount(5); // login, tabel (basi), login ulang, tabel, reboot
    }

    public function test_login_failures_are_reported_without_secrets(): void
    {
        Http::fake([$this->base().'/userlogin*' => Http::response(['code' => 0, 'message' => 'captcha error'])]);
        $result = app(HsgqWebService::class)->reboot($this->hsgqOlt(), 1, 1, 0);

        $this->assertFalse($result['ok']);
        $this->assertSame(__('hsgq.web_captcha_required'), $result['error']);
        $audit = AuditLog::where('event', 'onu.reboot_failed')->firstOrFail();
        $this->assertStringNotContainsString('admin', json_encode($audit->properties));
    }

    public function test_onu_missing_on_web_is_refused(): void
    {
        $this->fakeGponWeb([
            $this->base().'/ontinfo_table' => Http::response(['code' => 1, 'data' => []]),
        ]);

        $result = app(HsgqWebService::class)->reboot($this->hsgqOlt(), 1, 1, 0);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('HWTC00080001', (string) $result['error']);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/gponont_mgmt'));
    }

    public function test_web_client_refuses_blocked_targets_and_missing_port(): void
    {
        Http::fake();

        $loopback = $this->hsgqOlt(['ip' => '127.0.0.1']);
        $this->assertFalse(app(HsgqWebService::class)->reboot($loopback, 1, 1, 0)['ok']);

        $noPort = $this->hsgqOlt(['ip' => '10.30.0.79', 'web_port' => null]);
        $result = app(HsgqWebService::class)->reboot($noPort, 1, 1, 0);
        $this->assertSame(__('hsgq.web_port_missing'), $result['error']);

        Http::assertNothingSent();
    }

    public function test_save_config_posts_system_save(): void
    {
        $this->fakeGponWeb();
        $olt = $this->hsgqOlt();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('hsgq-olt.config.save', $olt))
            ->assertSessionHas('success');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/system_save') && $r['method'] === 'set');
        $this->assertDatabaseHas('audit_logs', ['event' => 'olt.config_saved']);
    }

    public function test_demo_user_cannot_reboot(): void
    {
        Http::fake();
        $olt = $this->hsgqOlt(['is_demo' => true]);

        $this->actingAs(User::factory()->demo()->create())
            ->post(route('hsgq-olt.onu.reboot', [$olt, 1, 1, 0]))
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_zte_routes_and_other_channels_never_run_zte_code_on_hsgq(): void
    {
        $this->fakeGponWeb();
        $olt = $this->hsgqOlt();
        $admin = User::factory()->admin()->create();
        $zte = Mockery::mock(ZteRemoteOnuService::class);
        $zte->shouldNotReceive('reboot');
        $zte->shouldNotReceive('setInfo');
        $this->app->instance(ZteRemoteOnuService::class, $zte);

        // Rute ZTE dengan id OLT HSGQ (URL rakitan) → ditolak, bukan menjalankan CLI ZTE.
        $this->actingAs($admin)
            ->post(route('smartolt.onu.reboot', [$olt, 1, 1, 0]))
            ->assertForbidden();

        // API v1 (aplikasi Android) → cabang HSGQ lewat web.
        Sanctum::actingAs($admin, ['*']);
        $this->postJson(route('api.olts.onu.reboot', [$olt, 1, 1, 0]))->assertOk();
        $this->postJson(route('api.olts.onu.name', [$olt, 1, 1, 1]), ['name' => 'uji0800-api'])->assertOk();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/gponont_mgmt') && $r['param']['flags'] === 4);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/gponont_mgmt') && $r['param']['flags'] === 8);
    }

    public function test_test_button_stores_diagnostic_report(): void
    {
        $olt = $this->hsgqOlt();
        $snmp = Mockery::mock(HsgqSnmpService::class);
        $snmp->shouldReceive('diagnose')->once()->andReturn(['snmp_ok' => true, 'onu_count' => 2, 'errors' => []]);
        $snmp->shouldReceive('getSystemInfo')->once()->andReturn(['sys_descr' => 'HSGQ-G02ID', 'pon_type' => 'gpon']);
        $web = Mockery::mock(HsgqWebService::class);
        $web->shouldReceive('diagnose')->once()->andReturn(['configured' => true, 'login' => true]);
        $this->app->instance(HsgqSnmpService::class, $snmp);
        $this->app->instance(HsgqWebService::class, $web);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('hsgq-olt.test', $olt))
            ->assertRedirect(route('hsgq-olt.detail', $olt))
            ->assertSessionHas('success');

        $snapshot = $olt->fresh()->last_test_result;
        $this->assertTrue($snapshot['ok']);
        $this->assertTrue(data_get($snapshot, 'hsgq_diag.web.login'));
        $this->assertSame(2, data_get($snapshot, 'hsgq_diag.snmp.onu_count'));
        $this->assertSame('HSGQ-G02ID', data_get($snapshot, 'system.sys_descr'));
        $this->assertSame(2, data_get($snapshot, 'port_onus.1_1.count'), 'Test tak boleh menghapus inventori');
    }

    public function test_unverified_status_holds_onu_offline_alarms_but_verified_raises(): void
    {
        $onu = fn (bool $online, bool $verified) => [
            'onu_key' => '1.0', 'slot' => 1, 'port' => 1, 'onu_id' => 0, 'interface' => 'PON01/0',
            'serial_number' => 'HWTC00080001', 'online' => $online, 'phase_state' => $online ? 'Online' : 'Offline',
            'status_verified' => $verified, 'rx_power_dbm' => $online ? -20.0 : null,
        ];
        $snap = fn (bool $online, bool $verified) => [
            'ok' => true,
            'ports' => [['slot' => 1, 'port' => 1, 'name' => 'PON01', 'oper_status' => 'up']],
            'port_onus' => ['1_1' => ['onus' => [$onu($online, $verified)]]],
        ];

        $olt = $this->hsgqOlt(['last_test_result' => $snap(false, false)]);
        (new AlarmEvaluator)->evaluate($olt, $snap(true, false));
        $this->assertSame(0, AlarmEvent::count(), 'status belum terverifikasi → tanpa alarm offline');

        $olt->forceFill(['last_test_result' => $snap(false, true)])->save();
        (new AlarmEvaluator)->evaluate($olt, $snap(true, true));
        $this->assertSame(1, AlarmEvent::where('type', AlarmEvent::TYPE_ONU_OFFLINE)->count());
    }
}
