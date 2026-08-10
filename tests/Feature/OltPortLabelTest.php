<?php

namespace Tests\Feature;

use App\Contracts\SmartOltSnmpDriver;
use App\Models\OltPortLabel;
use App\Models\SnmpOlt;
use App\Models\User;
use App\Services\SmartOltSnmpServiceResolver;
use App\Support\SmartOltSupport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Label port PON sisi-NMS untuk family non-ZTE (C-Data, HiOSO, HsAirPo).
 *
 * Label ini murni milik NMS (tabel `olt_port_labels`) — tak pernah ditulis ke OLT. ZTE tetap
 * memakai deskripsi port di perangkat (`smartolt.port.description`) dan ditolak endpoint ini.
 */
class OltPortLabelTest extends TestCase
{
    use RefreshDatabase;

    /** Driver SNMP palsu supaya auto-scan halaman Detail/PortOnus tak menyentuh perangkat. */
    private function fakeScanDriver(): void
    {
        $driver = new class implements SmartOltSnmpDriver
        {
            public function ping(SnmpOlt $olt): bool
            {
                return true;
            }

            public function getSystemInfo(SnmpOlt $olt): array
            {
                return ['sys_name' => 'CDATA-FAKE'];
            }

            public function getPorts(SnmpOlt $olt): array
            {
                return [['slot' => 0, 'port' => 1, 'name' => 'epon 0/0/1']];
            }

            public function getRegisteredOnus(SnmpOlt $olt): array
            {
                return [];
            }

            public function getRegisteredOnusByPort(SnmpOlt $olt, int $slot, int $port): array
            {
                return [];
            }

            public function getPortRxMap(SnmpOlt $olt): array
            {
                return [];
            }

            public function countRegisteredOnus(SnmpOlt $olt): int
            {
                return 0;
            }

            public function getUnconfiguredOnus(SnmpOlt $olt): array
            {
                return [];
            }
        };

        $resolver = new class($driver) extends SmartOltSnmpServiceResolver
        {
            public function __construct(private SmartOltSnmpDriver $driver) {}

            public function resolve(SnmpOlt $olt): SmartOltSnmpDriver
            {
                return $this->driver;
            }
        };

        $this->app->instance(SmartOltSnmpServiceResolver::class, $resolver);
    }

    private function cdataOlt(): SnmpOlt
    {
        return SnmpOlt::create([
            'name' => 'CDATA-EPON-LABEL',
            'vendor' => 'C-Data EPON 17409',
            'ip' => '10.20.0.31',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
        ]);
    }

    public function test_non_zte_families_expose_the_port_label_capability_but_zte_does_not(): void
    {
        $cdata = $this->cdataOlt();
        $zte = SnmpOlt::create([
            'name' => 'ZTE-C320-LABEL',
            'vendor' => 'ZTE C320',
            'ip' => '10.10.0.31',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
        ]);

        $this->assertTrue(SmartOltSupport::capabilities(SmartOltSupport::driverKey($cdata), $cdata)['supports_port_label']);
        $this->assertArrayNotHasKey(
            'supports_port_label',
            SmartOltSupport::capabilities(SmartOltSupport::driverKey($zte), $zte),
        );
    }

    public function test_operator_can_save_and_clear_a_port_label(): void
    {
        $user = User::factory()->create();
        $olt = $this->cdataOlt();

        $this->actingAs($user)
            ->post(route('olt.port-label.store', $olt), ['slot' => 0, 'port' => 1, 'label' => '  Jalur   Timur '])
            ->assertRedirect();

        // Spasi ganda dirapatkan, ujung dipangkas.
        $this->assertSame('Jalur Timur', OltPortLabel::query()
            ->where('snmp_olt_id', $olt->id)->where('slot', 0)->where('port', 1)->value('label'));

        $this->actingAs($user)
            ->post(route('olt.port-label.store', $olt), ['slot' => 0, 'port' => 1, 'label' => ''])
            ->assertRedirect();

        $this->assertSame(0, OltPortLabel::query()->where('snmp_olt_id', $olt->id)->count());
    }

    public function test_saving_twice_updates_the_same_row(): void
    {
        $user = User::factory()->create();
        $olt = $this->cdataOlt();

        foreach (['Awal', 'Ganti'] as $label) {
            $this->actingAs($user)
                ->post(route('olt.port-label.store', $olt), ['slot' => 0, 'port' => 1, 'label' => $label])
                ->assertRedirect();
        }

        $this->assertSame(1, OltPortLabel::query()->where('snmp_olt_id', $olt->id)->count());
        $this->assertSame('Ganti', OltPortLabel::query()->where('snmp_olt_id', $olt->id)->value('label'));
    }

    public function test_zte_olt_is_rejected_because_it_labels_ports_on_the_device(): void
    {
        $user = User::factory()->create();
        $zte = SnmpOlt::create([
            'name' => 'ZTE-C320-REJECT',
            'vendor' => 'ZTE C320',
            'ip' => '10.10.0.32',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
        ]);

        $this->actingAs($user)
            ->post(route('olt.port-label.store', $zte), ['slot' => 1, 'port' => 1, 'label' => 'Coba'])
            ->assertForbidden();

        $this->assertSame(0, OltPortLabel::query()->count());
    }

    public function test_demo_user_cannot_write_labels(): void
    {
        $demo = User::factory()->demo()->create();
        $olt = $this->cdataOlt();

        // OLT sungguhan tak terlihat oleh user demo (DemoScope) → binding gagal sebelum controller.
        $this->actingAs($demo)
            ->post(route('olt.port-label.store', $olt), ['slot' => 0, 'port' => 1, 'label' => 'Coba'])
            ->assertNotFound();

        $this->assertSame(0, OltPortLabel::query()->withoutGlobalScopes()->count());
    }

    public function test_detail_and_port_onus_pages_receive_the_labels(): void
    {
        $user = User::factory()->create();
        $this->fakeScanDriver();
        $olt = $this->cdataOlt();

        $this->actingAs($user)
            ->post(route('olt.port-label.store', $olt), ['slot' => 0, 'port' => 1, 'label' => 'Perum Griya'])
            ->assertRedirect();

        $this->actingAs($user)
            ->get(route('cdata-olt.detail', $olt))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('CDataOlt/Detail')
                ->where('port_labels.0_1', 'Perum Griya'));

        $this->actingAs($user)
            ->get(route('cdata-olt.port-onus', [$olt, 0, 1]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('CDataOlt/PortOnus')
                ->where('port_labels.0_1', 'Perum Griya'));
    }

    public function test_partner_cannot_label_ports_of_an_olt_outside_its_scope(): void
    {
        $partner = User::factory()->partner()->create();
        $olt = $this->cdataOlt();

        // OLT global tak ter-assign → PartnerOltScope menyembunyikannya dari route-model binding.
        $this->actingAs($partner)
            ->post(route('olt.port-label.store', $olt), ['slot' => 0, 'port' => 1, 'label' => 'Coba'])
            ->assertNotFound();

        $this->assertSame(0, OltPortLabel::query()->withoutGlobalScopes()->count());
    }
}
