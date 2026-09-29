<?php

namespace Tests\Feature;

use App\Models\SnmpOlt;
use App\Models\User;
use App\Services\CData\CDataGponPortService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

/** Fake service: rekam pemanggilan, tak menyentuh telnet. */
class FakeCDataGponPortService extends CDataGponPortService
{
    /** @var list<array<int, mixed>> */
    public array $calls = [];

    public bool $failReads = false;

    public function vlans(SnmpOlt $olt): array
    {
        $this->calls[] = ['vlans'];
        if ($this->failReads) {
            throw new RuntimeException('Koneksi telnet gagal');
        }

        return [
            ['id' => 28, 'description' => 'UJI', 'type' => 'Normal vlan', 'user_bridge' => 'disable', 'tagged' => ['gpon 0/0/1'], 'untagged' => []],
        ];
    }

    public function portDetail(SnmpOlt $olt, string $kind, int $slot, int $port): array
    {
        $this->calls[] = ['detail', $kind, $slot, $port];

        return ['name' => "{$kind} 0/{$slot}/{$port}", 'kind' => $kind, 'slot' => $slot, 'port' => $port, 'info' => ['mode' => 'Trunk'], 'ddm' => ['present' => false], 'stats' => null];
    }

    public function createVlan(SnmpOlt $olt, int $vlanId, ?string $description): array
    {
        $this->calls[] = ['create', $vlanId, $description];

        return ['ok' => true, 'error' => null, 'output' => '', 'vlan' => ['id' => $vlanId]];
    }

    public function tagPortVlan(SnmpOlt $olt, string $kind, int $slot, int $port, int $vlanId): array
    {
        $this->calls[] = ['tag', $kind, $slot, $port, $vlanId];

        return ['ok' => true, 'error' => null, 'output' => '', 'already' => false, 'tagged' => [(string) $vlanId]];
    }
}

class CDataGponPortPagesTest extends TestCase
{
    use RefreshDatabase;

    private FakeCDataGponPortService $fake;

    protected function setUp(): void
    {
        parent::setUp();
        // Halaman Inertia dirender penuh; jangan bergantung pada isi manifest build produksi.
        $this->withoutVite();
        $this->fake = new FakeCDataGponPortService;
        $this->app->instance(CDataGponPortService::class, $this->fake);
    }

    private function olt(bool $v3 = true, string $vendor = 'C-Data GPON 34592', ?string $swVersion = null, string $ip = '10.40.0.1'): SnmpOlt
    {
        return SnmpOlt::create([
            'name' => 'CDATA-GPON-UJI',
            'vendor' => $vendor,
            'ip' => $ip,
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'cli_transport' => 'telnet',
            'cli_username' => 'admin',
            'cli_password' => 'secret',
            'last_test_result' => [
                'cdata' => ['firmware_v3' => $v3],
                'panel' => ['device' => array_filter(['sw_version' => $swVersion]), 'groups' => [
                    ['key' => 'pon-0/0', 'ports' => [['pos' => 1, 'name' => 'gpon 0/0/1', 'status' => 'up']]],
                    ['key' => 'xge', 'ports' => [['pos' => 1, 'name' => 'xge 0/0/1', 'status' => 'up'], ['pos' => 2, 'name' => 'xge 0/0/2', 'status' => 'down']]],
                    ['key' => 'mgmt', 'ports' => [['pos' => 'C', 'name' => 'CONSOLE', 'status' => 'fixed', 'fixed' => true]]],
                ]],
                'port_onus' => ['0_1' => ['onus' => [['onu_id' => 1, 'online' => true], ['onu_id' => 2, 'online' => false]]]],
            ],
        ]);
    }

    public function test_vlan_page_loads_vlans_as_deferred_prop(): void
    {
        $olt = $this->olt();

        $this->actingAs(User::factory()->create())
            ->get(route('cdata-olt.vlans', $olt))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('CDataOlt/Vlans')
                ->where('tag_ports', ['xge 0/0/1', 'xge 0/0/2'])
                ->where('can_write', true)
                ->missing('vlan_data')
                ->loadDeferredProps(fn (Assert $reload) => $reload
                    ->where('vlan_data.ok', true)
                    ->where('vlan_data.vlans.0.id', 28)));
    }

    public function test_vlan_page_falls_back_to_last_good_read_when_olt_unreachable(): void
    {
        $olt = $this->olt();
        $user = User::factory()->create();

        // Baca sukses dulu → tersimpan di cache.
        $this->actingAs($user)->get(route('cdata-olt.vlans', $olt))
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $r) => $r->where('vlan_data.ok', true)));

        $this->fake->failReads = true;

        $this->actingAs($user)->get(route('cdata-olt.vlans', $olt))
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $r) => $r
                ->where('vlan_data.ok', false)
                ->where('vlan_data.stale', true)
                ->where('vlan_data.error', 'Koneksi telnet gagal')
                ->where('vlan_data.vlans.0.id', 28)));
    }

    public function test_pages_are_hidden_for_olts_without_cli_support(): void
    {
        $nonV3 = $this->olt(v3: false);
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('cdata-olt.vlans', $nonV3))->assertNotFound();
        $this->actingAs($user)->get(route('cdata-olt.port.detail', [$nonV3, 'xge', 0, 1]))->assertNotFound();
        $this->actingAs($user)->post(route('cdata-olt.vlans.store', $nonV3), ['vlan_id' => 28])->assertNotFound();
        $this->assertSame([], $this->fake->calls);
    }

    public function test_compact_gpon_without_v3_snmp_table_but_v3_firmware_gets_pages(): void
    {
        // FD1601S/FD1602S: tabel SNMP `34592…18.12` tak ada (is_v3=false), firmware & CLI tetap V3.x.
        $olt = $this->olt(v3: false, swVersion: 'V3.2.5_251111');
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('cdata-olt.vlans', $olt))->assertOk();
        $this->actingAs($user)->get(route('cdata-olt.port.detail', [$olt, 'ge', 0, 1]))->assertOk();

        $legacy = $this->olt(v3: false, swVersion: 'V2.1.0', ip: '10.40.0.2');
        $this->actingAs($user)->get(route('cdata-olt.vlans', $legacy))->assertNotFound();
    }

    public function test_port_detail_page_for_gpon_includes_onu_summary(): void
    {
        $olt = $this->olt();

        $this->actingAs(User::factory()->create())
            ->get(route('cdata-olt.port.detail', [$olt, 'gpon', 0, 1]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('CDataOlt/PortDetail')
                ->where('name', 'gpon 0/0/1')
                ->where('onu_summary', ['total' => 2, 'online' => 1])
                ->loadDeferredProps(fn (Assert $r) => $r->where('detail.info.mode', 'Trunk')));

        $this->assertSame([['detail', 'gpon', 0, 1]], $this->fake->calls);
    }

    public function test_unknown_port_kind_is_not_routed(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/cdata-olt/'.$this->olt()->id.'/port/lan/0/1')
            ->assertNotFound();
    }

    public function test_store_vlan_creates_and_tags_selected_uplink(): void
    {
        $olt = $this->olt();

        $this->actingAs(User::factory()->create())
            ->post(route('cdata-olt.vlans.store', $olt), ['vlan_id' => 29, 'description' => 'UJI-2', 'ports' => ['xge 0/0/1']])
            ->assertRedirect(route('cdata-olt.vlans', $olt))
            ->assertSessionHas('success');

        $this->assertSame([['create', 29, 'UJI-2'], ['tag', 'xge', 0, 1, 29]], $this->fake->calls);
        $this->assertDatabaseHas('audit_logs', ['auditable_id' => $olt->id]);
    }

    public function test_store_vlan_validates_description_and_uplink(): void
    {
        $olt = $this->olt();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('cdata-olt.vlans.store', $olt), ['vlan_id' => 29, 'description' => 'dua kata'])
            ->assertSessionHasErrors('description');
        $this->actingAs($user)->post(route('cdata-olt.vlans.store', $olt), ['vlan_id' => 29, 'ports' => ['ge 0/0/9']])
            ->assertSessionHasErrors('ports.0');
        // Port GPON tak ditawarkan untuk di-tag (otomatis ikut VLAN baru).
        $this->actingAs($user)->post(route('cdata-olt.vlans.store', $olt), ['vlan_id' => 29, 'ports' => ['gpon 0/0/1']])
            ->assertSessionHasErrors('ports.0');
        $this->actingAs($user)->post(route('cdata-olt.vlans.store', $olt), ['vlan_id' => 4095])
            ->assertSessionHasErrors('vlan_id');

        $this->assertSame([], $this->fake->calls);
    }

    public function test_tag_route_only_for_uplink_ports(): void
    {
        $olt = $this->olt();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('cdata-olt.port.vlan', [$olt, 'xge', 0, 1]), ['vlan_id' => 28])
            ->assertRedirect(route('cdata-olt.port.detail', [$olt, 'xge', 0, 1]))
            ->assertSessionHas('success');
        $this->actingAs($user)->post('/cdata-olt/'.$olt->id.'/port/gpon/0/1/vlan', ['vlan_id' => 28])
            ->assertNotFound();

        $this->assertSame([['tag', 'xge', 0, 1, 28]], $this->fake->calls);
    }

    public function test_epon_olt_offers_pon_ports_and_tags_each_selected_port(): void
    {
        $olt = SnmpOlt::create([
            'name' => 'CDATA-EPON-UJI',
            'vendor' => 'C-Data EPON 17409',
            'ip' => '10.40.0.3',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'cli_transport' => 'telnet',
            'cli_username' => 'admin',
            'cli_password' => 'secret',
            'last_test_result' => [
                'panel' => ['device' => ['model' => 'FD1304E', 'sw_version' => 'V3.4.53_260130'], 'groups' => [
                    ['key' => 'pon-0/2', 'ports' => [['pos' => 1, 'name' => 'epon 0/2/1', 'status' => 'up']]],
                    ['key' => 'pon-0/1', 'ports' => [['pos' => 1, 'name' => 'epon 0/1/1', 'status' => 'up']]],
                    ['key' => 'ge', 'ports' => [['pos' => 1, 'name' => 'ge 0/0/1', 'status' => 'up']]],
                    ['key' => 'xge', 'ports' => [['pos' => 1, 'name' => 'xge 0/0/1', 'status' => 'up']]],
                ]],
                'port_onus' => ['2_1' => ['onus' => [['onu_id' => 1, 'online' => true]]]],
            ],
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('cdata-olt.vlans', $olt))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tag_ports', ['xge 0/0/1', 'ge 0/0/1', 'epon 0/1/1', 'epon 0/2/1'])
                ->where('olt.capabilities.pon_label', 'EPON'));

        $this->actingAs($user)->get(route('cdata-olt.port.detail', [$olt, 'epon', 2, 1]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('onu_summary', ['total' => 1, 'online' => 1]));

        $this->actingAs($user)
            ->post(route('cdata-olt.vlans.store', $olt), ['vlan_id' => 30, 'ports' => ['xge 0/0/1', 'epon 0/1/1', 'epon 0/2/1']])
            ->assertSessionHas('success');
        $this->actingAs($user)->post(route('cdata-olt.port.vlan', [$olt, 'epon', 2, 1]), ['vlan_id' => 31])
            ->assertSessionHas('success');

        // Detail port = deferred prop → tak dibaca pada GET biasa, jadi tak ada panggilan 'detail'.
        $this->assertSame([
            ['create', 30, null],
            ['tag', 'xge', 0, 1, 30],
            ['tag', 'epon', 1, 1, 30],
            ['tag', 'epon', 2, 1, 30],
            ['tag', 'epon', 2, 1, 31],
        ], array_values(array_filter($this->fake->calls, fn ($c) => $c[0] !== 'vlans')));
    }

    public function test_partner_on_assigned_global_olt_can_view_but_not_write(): void
    {
        $olt = $this->olt();
        $partner = User::factory()->partner()->create();
        $partner->partnerOlts()->attach($olt->id);

        $this->actingAs($partner)->get(route('cdata-olt.vlans', $olt))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('can_write', false));

        $this->actingAs($partner)->post(route('cdata-olt.vlans.store', $olt), ['vlan_id' => 29])->assertForbidden();
        $this->actingAs($partner)->post(route('cdata-olt.port.vlan', [$olt, 'xge', 0, 1]), ['vlan_id' => 29])->assertForbidden();

        $this->assertSame([], $this->fake->calls);
    }

    public function test_demo_user_cannot_write(): void
    {
        // OLT demo — demo user hanya melihat OLT ber-is_demo (DemoScope), jadi yang diuji benar
        // penjaga tulisnya, bukan 404 dari route binding.
        $olt = $this->olt();
        $olt->forceFill(['is_demo' => true])->save();
        $demo = User::factory()->demo()->create();

        $this->actingAs($demo)->get(route('cdata-olt.vlans', $olt))->assertOk();
        $this->actingAs($demo)
            ->post(route('cdata-olt.vlans.store', $olt), ['vlan_id' => 29])
            ->assertForbidden();

        $this->assertSame([], $this->fake->calls);
    }
}
