<?php

namespace Tests\Feature;

use App\Models\SnmpOlt;
use App\Models\User;
use App\Services\ZteCliProvisioningExecutor;
use App\Services\ZteOnuRunningConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Editor ONU per-bagian (gaya NetNumen): satu perubahan → langsung ke OLT → config dibaca ulang.
 * Plus pelepasan onu-profile C300 yang harus memulihkan layanan persis seperti semula.
 */
class SmartOltConfigureOnuItemTest extends TestCase
{
    use RefreshDatabase;

    private function makeOlt(): SnmpOlt
    {
        return SnmpOlt::create([
            'name' => 'OLT-UJI-C300',
            'vendor' => 'ZTE C300',
            'ip' => '10.30.0.32',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'cli_transport' => 'telnet',
            'cli_port' => 23,
            'cli_username' => 'admin',
            'cli_password' => 'secret',
        ]);
    }

    /**
     * @param  list<string>  $raws  running-config berurutan yang "dibaca" dari OLT
     */
    private function fakeOlt(array $raws): object
    {
        $executor = new class extends ZteCliProvisioningExecutor
        {
            /** @var list<string> */
            public array $scripts = [];

            public function execute(SnmpOlt $olt, string $script, bool $largeOutput = false): array
            {
                $this->scripts[] = $script;

                return ['ok' => true, 'error' => null, 'output' => 'OLT-C300#'];
            }
        };

        $service = new class($executor, $raws) extends ZteOnuRunningConfigService
        {
            public int $reads = 0;

            public function __construct(ZteCliProvisioningExecutor $executor, private array $raws)
            {
                parent::__construct($executor);
            }

            public function fetch(SnmpOlt $olt, int $slot, int $port, int $onuId): array
            {
                $raw = $this->raws[min($this->reads, count($this->raws) - 1)];
                $this->reads++;

                return ['ok' => true, 'error' => null, 'raw' => $raw, 'config' => $this->parse($raw)];
            }
        };

        $this->app->instance(ZteCliProvisioningExecutor::class, $executor);
        $this->app->instance(ZteOnuRunningConfigService::class, $service);

        return $executor;
    }

    private function profiledRaw(): string
    {
        return implode("\n", [
            'interface gpon-onu_1/3/16:51',
            '  name Uji-0800 Warung',
            '  ==Configured by profile: VLAN2100== ',
            '  tcont 1 name 1 profile SERVER',
            '  tcont 1 gap mode0',
            '  gemport 1 name 1 tcont 1',
            '  ==End== ',
            '  service-port 1 vport 1 user-vlan 2100 vlan 2100 ',
            '!',
            'pon-onu-mng gpon-onu_1/3/16:51',
            '  ==Configured by profile: VLAN2100==',
            '  service ServiceName gemport 1 cos 0 vlan 2100',
            '  ==End==',
            '  wan-ip 1 mode pppoe username uji0800 password uji0800 vlan-profile VLAN2100-NEW host 1',
            '!',
        ]);
    }

    /** Config yang sama tanpa penanda profile (keadaan OLT setelah dilepas & ditulis ulang). */
    private function manualRaw(): string
    {
        return str_replace(["  ==Configured by profile: VLAN2100== \n", "  ==Configured by profile: VLAN2100==\n", "  ==End== \n", "  ==End==\n"], '', $this->profiledRaw());
    }

    public function test_item_sends_one_change_and_returns_the_reread_config(): void
    {
        $executor = $this->fakeOlt([$this->manualRaw()]);
        $baseline = app(ZteOnuRunningConfigService::class)->parse($this->manualRaw());
        $target = [...$baseline, 'service_ports' => [['id' => 1, 'vport' => 1, 'user_vlan' => 2101, 'vlan' => 2101]]];

        $this->actingAs(User::factory()->admin()->create())
            ->postJson(route('smartolt.onu.configure.item', [$this->makeOlt(), 3, 16, 51]), ['baseline' => $baseline, 'config' => $target])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('fetch_ok', true)
            ->assertJsonPath('config.name', 'Uji-0800 Warung');

        $this->assertCount(1, $executor->scripts);
        $this->assertStringContainsString('service-port 1 vport 1 user-vlan 2101 vlan 2101', $executor->scripts[0]);
        $this->assertDatabaseHas('smartolt_onu_registrations', ['onu_id' => 51, 'status' => 'reconfigured']);
    }

    public function test_item_refuses_a_change_locked_by_the_onu_profile(): void
    {
        $executor = $this->fakeOlt([$this->profiledRaw()]);
        $baseline = app(ZteOnuRunningConfigService::class)->parse($this->profiledRaw());
        $target = [...$baseline, 'services' => [['name' => 'ServiceName', 'gem' => 1, 'cos' => 0, 'vlan' => 2101, 'mode' => 'vlanpri']]];

        $this->actingAs(User::factory()->admin()->create())
            ->postJson(route('smartolt.onu.configure.item', [$this->makeOlt(), 3, 16, 51]), ['baseline' => $baseline, 'config' => $target])
            ->assertStatus(422)
            ->assertJsonPath('error', 'profile_locked');

        $this->assertSame([], $executor->scripts);
    }

    public function test_unbind_detaches_with_profile_keyword_and_rewrites_the_profile_lines(): void
    {
        // Baca 1: sebelum; baca 2: sesudah sesi lepas+tulis ulang; baca 3: laporan akhir.
        $executor = $this->fakeOlt([$this->profiledRaw(), $this->manualRaw(), $this->manualRaw()]);

        $this->actingAs(User::factory()->admin()->create())
            ->postJson(route('smartolt.onu.configure.unbind-profile', [$this->makeOlt(), 3, 16, 51]))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('config.onu_profile', null);

        $this->assertCount(1, $executor->scripts, 'Tak ada selisih → tak perlu sesi pemulihan kedua.');
        $script = $executor->scripts[0];
        $this->assertStringContainsString("interface gpon-olt_1/3/16\nno onu 51 profile\nexit", $script);
        $this->assertDoesNotMatchRegularExpression('/^no onu 51$/m', $script, '`no onu 51` tanpa `profile` menghapus ONU.');
        $this->assertStringContainsString("interface gpon-onu_1/3/16:51\ntcont 1 name 1 profile SERVER", $script);
        $this->assertStringContainsString("pon-onu-mng gpon-onu_1/3/16:51\nservice ServiceName gemport 1 cos 0 vlan 2100", $script);
    }

    public function test_unbind_restores_lines_the_olt_dropped_with_the_profile(): void
    {
        // Setelah lepas, OLT ternyata juga membuang service-port → harus dipulihkan di sesi kedua.
        $dropped = str_replace("  service-port 1 vport 1 user-vlan 2100 vlan 2100 \n", '', $this->manualRaw());
        $executor = $this->fakeOlt([$this->profiledRaw(), $dropped, $this->manualRaw()]);

        $this->actingAs(User::factory()->admin()->create())
            ->postJson(route('smartolt.onu.configure.unbind-profile', [$this->makeOlt(), 3, 16, 51]))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertCount(2, $executor->scripts);
        $this->assertStringContainsString('service-port 1 vport 1 user-vlan 2100 vlan 2100', $executor->scripts[1]);
    }

    public function test_unbind_refuses_an_onu_without_profile(): void
    {
        $executor = $this->fakeOlt([$this->manualRaw()]);

        $this->actingAs(User::factory()->admin()->create())
            ->postJson(route('smartolt.onu.configure.unbind-profile', [$this->makeOlt(), 3, 16, 51]))
            ->assertStatus(422)
            ->assertJsonPath('error', 'no_profile');

        $this->assertSame([], $executor->scripts);
    }
}
