<?php

namespace Tests\Feature;

use App\Models\OnuZoneLink;
use App\Models\SmartOltOnuRegistration;
use App\Models\SmartOltProfile;
use App\Models\SnmpOlt;
use App\Models\User;
use App\Models\Zone;
use App\Services\ZoneService;
use App\Services\ZteCliProvisioningExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Zona baru boleh terkait ke onu_zone_links HANYA setelah eksekusi CLI benar-benar
 * sukses (status berakhir 'executed') — bukan saat sekadar generate script atau saat
 * eksekusi gagal/exception, supaya tak meninggalkan link "hantu" atau menimpa zona ONU
 * lain yang kebetulan menempati slot/port/onu_id yang sama.
 */
class ZoneRegistrationLinkingTest extends TestCase
{
    use RefreshDatabase;

    private function makeC300Olt(): SnmpOlt
    {
        $olt = SnmpOlt::create([
            'name' => 'PATI-ZTE-C320',
            'vendor' => 'ZTE C320',
            'ip' => '10.30.0.'.random_int(2, 250),
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'cli_transport' => 'telnet',
            'cli_port' => 23,
            'cli_username' => 'admin',
            'cli_password' => 'secret',
        ]);

        foreach (['onu_type' => 'ALL-ONT', 'tcont' => 'SERVER'] as $type => $name) {
            SmartOltProfile::create([
                'snmp_olt_id' => $olt->id, 'profile_type' => $type, 'name' => $name, 'is_active' => true,
            ]);
        }

        return $olt;
    }

    private function zone(): Zone
    {
        return Zone::query()->firstOrCreate(['name' => 'RINCON']);
    }

    private function simplePayload(Zone $zone): array
    {
        return [
            'serial_number' => 'ZTEGCAF11111',
            'slot' => 1, 'port' => 1, 'onu_id' => 6,
            'customer_name' => 'Pak Budi',
            'zone_id' => $zone->id,
            'onu_type' => 'ALL-ONT',
            'tcont_profile' => 'SERVER',
            'vlan' => 100,
            'service_name' => 'ServiceName',
            'wan_mode' => 'pppoe',
        ];
    }

    private function mockExecutor(bool $ok, ?string $error = null): void
    {
        $this->app->instance(ZteCliProvisioningExecutor::class, new class($ok, $error) extends ZteCliProvisioningExecutor
        {
            public function __construct(private bool $ok, private ?string $error) {}

            public function execute(SnmpOlt $olt, string $script, bool $largeOutput = false): array
            {
                return ['ok' => $this->ok, 'error' => $this->error, 'output' => 'BMKV-C320#'];
            }
        });
    }

    /**
     * ZoneService yang assign()-nya selalu melempar — mensimulasikan DB down / zona keburu
     * dihapus user lain tepat setelah CLI sukses. assignQuietly() (yang dipakai jalur
     * provisioning) tetap versi asli, jadi ia yang harus menelan exception ini.
     */
    private function mockFailingZoneService(): void
    {
        $this->app->instance(ZoneService::class, new class extends ZoneService
        {
            public function assign(SnmpOlt $olt, int $slot, int $port, int $onuId, ?string $serial, ?int $zoneId, ?int $userId): void
            {
                throw new RuntimeException('DB zona tidak bisa ditulis');
            }
        });
    }

    // --- storeOnu (mode sederhana C300/C320) ---

    public function test_simple_generated_creates_no_zone_link(): void
    {
        $olt = $this->makeC300Olt();
        $zone = $this->zone();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('smartolt.register.store', $olt), [...$this->simplePayload($zone), 'execute' => false])
            ->assertSessionHas('success');

        $this->assertSame(0, OnuZoneLink::count());
        $this->assertSame($zone->id, SmartOltOnuRegistration::withoutGlobalScopes()->firstOrFail()->zone_id);
    }

    public function test_simple_execute_failure_creates_no_zone_link(): void
    {
        $olt = $this->makeC300Olt();
        $zone = $this->zone();
        $admin = User::factory()->admin()->create();
        $this->mockExecutor(false, 'Command rejected');

        $this->actingAs($admin)
            ->post(route('smartolt.register.store', $olt), [...$this->simplePayload($zone), 'execute' => true]);

        $this->assertSame(0, OnuZoneLink::count());
        $this->assertSame('failed', SmartOltOnuRegistration::withoutGlobalScopes()->firstOrFail()->status);
    }

    public function test_simple_execute_success_creates_zone_link(): void
    {
        $olt = $this->makeC300Olt();
        $zone = $this->zone();
        $admin = User::factory()->admin()->create();
        $this->mockExecutor(true);

        $this->actingAs($admin)
            ->post(route('smartolt.register.store', $olt), [...$this->simplePayload($zone), 'execute' => true]);

        $this->assertDatabaseHas('onu_zone_links', [
            'snmp_olt_id' => $olt->id, 'slot' => 1, 'port' => 1, 'onu_id' => 6, 'zone_id' => $zone->id,
        ]);
    }

    public function test_simple_execute_failure_does_not_overwrite_existing_link(): void
    {
        $olt = $this->makeC300Olt();
        $originalZone = Zone::query()->create(['name' => 'PALMARITO']);
        $newZone = $this->zone();
        OnuZoneLink::create([
            'zone_id' => $originalZone->id, 'snmp_olt_id' => $olt->id,
            'slot' => 1, 'port' => 1, 'onu_id' => 6, 'serial_number' => 'ZTEG-OLD',
        ]);
        $admin = User::factory()->admin()->create();
        $this->mockExecutor(false, 'Command rejected');

        $this->actingAs($admin)
            ->post(route('smartolt.register.store', $olt), [...$this->simplePayload($newZone), 'execute' => true]);

        $this->assertSame($originalZone->id, OnuZoneLink::first()->zone_id);
    }

    // --- storeOnuAdvanced (mode Lanjutan C300/C320) ---

    private function advancedPayload(Zone $zone): array
    {
        return [
            'serial_number' => 'ZTEGCAF22222',
            'slot' => 1, 'port' => 2, 'onu_id' => 7,
            'onu_type' => 'ALL-ONT',
            'zone_id' => $zone->id,
            'config' => [
                'name' => 'Pelanggan Lanjutan',
                'tconts' => [['id' => 1, 'name' => '1', 'profile' => 'SERVER', 'gap' => 'mode0']],
                'gemports' => [['id' => 1, 'name' => '1', 'tcont' => 1]],
                'service_ports' => [['id' => 1, 'vport' => 1, 'user_vlan' => 100, 'vlan' => 100]],
                'services' => [['name' => 'ServiceName', 'mode' => 'vlanpri', 'gem' => 1, 'cos' => 0, 'vlan' => 100]],
                'wan_ips' => [['id' => 1, 'mode' => 'pppoe', 'pppoe_username' => 'u', 'pppoe_password' => 'p']],
                'tr069' => false,
                'remote_ont' => false,
            ],
        ];
    }

    public function test_advanced_generated_creates_no_zone_link(): void
    {
        $olt = $this->makeC300Olt();
        $zone = $this->zone();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('smartolt.register.advanced.store', $olt), [...$this->advancedPayload($zone), 'execute' => false])
            ->assertSessionHas('success');

        $this->assertSame(0, OnuZoneLink::count());
        $this->assertSame($zone->id, SmartOltOnuRegistration::withoutGlobalScopes()->firstOrFail()->zone_id);
    }

    public function test_advanced_execute_success_creates_zone_link(): void
    {
        $olt = $this->makeC300Olt();
        $zone = $this->zone();
        $admin = User::factory()->admin()->create();
        $this->mockExecutor(true);

        $this->actingAs($admin)
            ->post(route('smartolt.register.advanced.store', $olt), [...$this->advancedPayload($zone), 'execute' => true]);

        $this->assertDatabaseHas('onu_zone_links', [
            'snmp_olt_id' => $olt->id, 'slot' => 1, 'port' => 2, 'onu_id' => 7, 'zone_id' => $zone->id,
        ]);
    }

    // --- OnuRegistrationService::register() via cabang C600 (dipakai juga oleh API mobile) ---

    private function makeC600Olt(): SnmpOlt
    {
        return SnmpOlt::create([
            'name' => 'OLT-C600-TEST',
            'vendor' => 'ZTE C600',
            'ip' => '10.31.0.'.random_int(2, 250),
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'cli_transport' => 'telnet',
            'cli_port' => 23,
            'cli_username' => 'admin',
            'cli_password' => 'secret',
        ]);
    }

    private function c600Payload(Zone $zone): array
    {
        return [
            'serial_number' => 'ZTEGCAF33333',
            'slot' => 1, 'port' => 1, 'onu_id' => 8,
            'customer_name' => 'Pak Budi C600',
            'zone_id' => $zone->id,
            'onu_type' => 'ZTE-F660',
            'internet_vlan' => 200,
            'internet_tcont_profile' => '10MB',
            'mgmt_vlan' => 601,
            'mgmt_tcont_profile' => 'SMARTOLT-VOIPMNG-10M',
            'mgmt_ip' => '10.99.0.5',
            'mgmt_mask' => '255.255.240.0',
            'mgmt_gateway' => '10.99.0.1',
            'acs_url' => 'http://acs.example.test:7547',
            'acs_username' => 'acsuser',
            'acs_password' => 'acspass',
        ];
    }

    public function test_c600_generated_creates_no_zone_link(): void
    {
        $olt = $this->makeC600Olt();
        $zone = $this->zone();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('smartolt.register.store', $olt), [...$this->c600Payload($zone), 'execute' => false]);

        $this->assertSame(0, OnuZoneLink::count());
        $this->assertSame($zone->id, SmartOltOnuRegistration::withoutGlobalScopes()->firstOrFail()->zone_id);
    }

    public function test_c600_execute_failure_creates_no_zone_link(): void
    {
        $olt = $this->makeC600Olt();
        $zone = $this->zone();
        $admin = User::factory()->admin()->create();
        $this->mockExecutor(false, 'Command rejected');

        $this->actingAs($admin)
            ->post(route('smartolt.register.store', $olt), [...$this->c600Payload($zone), 'execute' => true]);

        $this->assertSame(0, OnuZoneLink::count());
    }

    public function test_c600_execute_success_creates_zone_link(): void
    {
        $olt = $this->makeC600Olt();
        $zone = $this->zone();
        $admin = User::factory()->admin()->create();
        $this->mockExecutor(true);

        $this->actingAs($admin)
            ->post(route('smartolt.register.store', $olt), [...$this->c600Payload($zone), 'execute' => true]);

        $this->assertDatabaseHas('onu_zone_links', [
            'snmp_olt_id' => $olt->id, 'slot' => 1, 'port' => 1, 'onu_id' => 8, 'zone_id' => $zone->id,
        ]);
    }

    // --- executeRegistration (alur "generate sekarang, eksekusi nanti") ---

    public function test_deferred_execute_success_creates_zone_link(): void
    {
        $olt = $this->makeC300Olt();
        $zone = $this->zone();
        $admin = User::factory()->admin()->create();

        // Generate dulu (execute=false) — hanya menyimpan zone_id di baris registrasi.
        $this->actingAs($admin)
            ->post(route('smartolt.register.store', $olt), [...$this->simplePayload($zone), 'execute' => false]);

        $registration = SmartOltOnuRegistration::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(0, OnuZoneLink::count());

        // Eksekusi nanti dari daftar registrasi — baru sekarang link dibuat.
        $this->mockExecutor(true);
        $this->actingAs($admin)
            ->post(route('smartolt.registrations.execute', [$olt, $registration]));

        $this->assertDatabaseHas('onu_zone_links', [
            'snmp_olt_id' => $olt->id, 'slot' => 1, 'port' => 1, 'onu_id' => 6, 'zone_id' => $zone->id,
        ]);
    }

    // --- Kegagalan simpan zona TIDAK boleh membatalkan registrasi yang sudah sukses di OLT ---
    //
    // ONU sudah nyata teregister lewat Telnet; error saat menyimpan link zona tak boleh
    // menghasilkan baris audit 'failed' kedua (atau menurunkan status yang sudah 'executed'),
    // karena itu akan memberi tahu operator bahwa provisioning gagal padahal tidak.

    public function test_zone_failure_keeps_simple_registration_executed(): void
    {
        $olt = $this->makeC300Olt();
        $zone = $this->zone();
        $admin = User::factory()->admin()->create();
        $this->mockExecutor(true);
        $this->mockFailingZoneService();

        $this->actingAs($admin)
            ->post(route('smartolt.register.store', $olt), [...$this->simplePayload($zone), 'execute' => true])
            ->assertSessionHas('success');

        $this->assertSame(1, SmartOltOnuRegistration::withoutGlobalScopes()->count());
        $this->assertSame('executed', SmartOltOnuRegistration::withoutGlobalScopes()->firstOrFail()->status);
        $this->assertSame(0, OnuZoneLink::count());
    }

    public function test_zone_failure_keeps_advanced_registration_executed(): void
    {
        $olt = $this->makeC300Olt();
        $zone = $this->zone();
        $admin = User::factory()->admin()->create();
        $this->mockExecutor(true);
        $this->mockFailingZoneService();

        $this->actingAs($admin)
            ->post(route('smartolt.register.advanced.store', $olt), [...$this->advancedPayload($zone), 'execute' => true])
            ->assertSessionHas('success');

        $this->assertSame(1, SmartOltOnuRegistration::withoutGlobalScopes()->count());
        $this->assertSame('executed', SmartOltOnuRegistration::withoutGlobalScopes()->firstOrFail()->status);
        $this->assertSame(0, OnuZoneLink::count());
    }

    public function test_zone_failure_keeps_c600_registration_executed(): void
    {
        $olt = $this->makeC600Olt();
        $zone = $this->zone();
        $admin = User::factory()->admin()->create();
        $this->mockExecutor(true);
        $this->mockFailingZoneService();

        $this->actingAs($admin)
            ->post(route('smartolt.register.store', $olt), [...$this->c600Payload($zone), 'execute' => true])
            ->assertSessionHas('success');

        $this->assertSame(1, SmartOltOnuRegistration::withoutGlobalScopes()->count());
        $this->assertSame('executed', SmartOltOnuRegistration::withoutGlobalScopes()->firstOrFail()->status);
        $this->assertSame(0, OnuZoneLink::count());
    }

    public function test_zone_failure_keeps_deferred_registration_executed(): void
    {
        $olt = $this->makeC300Olt();
        $zone = $this->zone();
        $admin = User::factory()->admin()->create();

        // Generate dulu (zona belum dikaitkan, hanya tersimpan di baris registrasi).
        $this->actingAs($admin)
            ->post(route('smartolt.register.store', $olt), [...$this->simplePayload($zone), 'execute' => false]);

        $registration = SmartOltOnuRegistration::withoutGlobalScopes()->firstOrFail();

        // Eksekusi nanti: CLI sukses tapi simpan zona gagal — status harus TETAP executed
        // (di jalur ini baris di-update, jadi kalau tak dijaga record sukses justru hilang).
        $this->mockExecutor(true);
        $this->mockFailingZoneService();
        $this->actingAs($admin)
            ->post(route('smartolt.registrations.execute', [$olt, $registration]))
            ->assertSessionHas('success');

        $this->assertSame(1, SmartOltOnuRegistration::withoutGlobalScopes()->count());
        $this->assertSame('executed', $registration->fresh()->status);
        $this->assertSame(0, OnuZoneLink::count());
    }

    public function test_deferred_execute_failure_creates_no_zone_link(): void
    {
        $olt = $this->makeC300Olt();
        $zone = $this->zone();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('smartolt.register.store', $olt), [...$this->simplePayload($zone), 'execute' => false]);

        $registration = SmartOltOnuRegistration::withoutGlobalScopes()->firstOrFail();

        $this->mockExecutor(false, 'Command rejected');
        $this->actingAs($admin)
            ->post(route('smartolt.registrations.execute', [$olt, $registration]));

        $this->assertSame(0, OnuZoneLink::count());
        $this->assertSame('failed', $registration->fresh()->status);
    }
}
