<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\GenieacsCredential;
use App\Models\GenieacsDeviceMap;
use App\Models\SnmpOlt;
use App\Models\User;
use App\Services\Genieacs\GenieacsDeviceSyncService;
use App\Services\Genieacs\GenieacsManualPinService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Penyematan manual pasangan ONU ↔ device GenieACS.
 *
 * Yang benar-benar diuji di sini bukan "pin tersimpan" melainkan DUA KEADAAN
 * YANG DULU SALAH pada rancangan pertama, yang menyimpan posisi alih-alih
 * identitas:
 *
 *  1. ONU dipindah ke port lain — pin harus ikut berpindah, bukan tertinggal
 *     menunjuk pelanggan yang kini menempati posisi lama.
 *  2. ONU diganti unit baru — pin harus dilepas & ditandai basi, bukan
 *     diwariskan ke ONU asing yang kebetulan menempati posisi yang sama.
 */
class GenieacsManualPinTest extends TestCase
{
    use RefreshDatabase;

    private int $nextIp = 1;

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $ports  "slot_port" => daftar ONU
     */
    private function makeOlt(array $ports, string $name = 'OLT-UJI-PIN'): SnmpOlt
    {
        $portOnus = [];

        foreach ($ports as $key => $onus) {
            [$slot, $port] = array_map('intval', explode('_', $key));

            $portOnus[$key] = [
                'slot' => $slot,
                'port' => $port,
                'count' => count($onus),
                'onus' => array_map(fn (array $onu) => [...$onu, 'slot' => $slot, 'port' => $port], $onus),
            ];
        }

        return SnmpOlt::create([
            'name' => $name,
            'vendor' => 'ZTE C320',
            // Berurutan, bukan acak: dua OLT dalam satu test pernah mendapat IP sama
            // (unik per ip+snmp_port) dan test gagal sesekali.
            'ip' => '10.0.0.'.(++$this->nextIp),
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'last_test_result' => [
                'ok' => true,
                'system' => ['sysDescr' => 'ZTE C320'],
                'port_onus' => $portOnus,
            ],
        ]);
    }

    private function makeDevice(string $deviceId, array $attributes = []): GenieacsDeviceMap
    {
        return GenieacsDeviceMap::create([
            'device_id' => $deviceId,
            'last_inform_at' => now(),
            ...$attributes,
        ]);
    }

    private function pins(): GenieacsManualPinService
    {
        return app(GenieacsManualPinService::class);
    }

    public function test_pin_stores_the_onu_identity_not_its_position(): void
    {
        $olt = $this->makeOlt(['1_1' => [
            ['onu_id' => 4, 'serial_number' => 'ZTEGC1234567', 'online' => true],
        ]]);
        $this->makeDevice('dev-a');

        $result = $this->pins()->pin('dev-a', $olt, 1, 1, 4, null);

        $this->assertTrue($result['ok']);

        $row = GenieacsDeviceMap::where('device_id', 'dev-a')->firstOrFail();
        $this->assertSame(GenieacsDeviceMap::METHOD_MANUAL, $row->match_method);
        $this->assertSame(GenieacsDeviceMap::REF_SERIAL, $row->manual_ref_type);
        $this->assertSame('ZTEGC1234567', $row->manual_ref);
        $this->assertSame(4, $row->onu_id);
        $this->assertFalse($row->manual_stale);
    }

    public function test_pin_falls_back_to_mac_then_position(): void
    {
        // OLT EPON menaruh MAC di kolom serial — itu MAC, bukan serial vendor.
        $olt = $this->makeOlt([
            '1_1' => [['onu_id' => 1, 'serial_number' => 'D0:5F:AF:00:12:E6', 'online' => true]],
            '1_2' => [['onu_id' => 2, 'serial_number' => null, 'mac' => null, 'online' => true]],
        ]);
        $this->makeDevice('dev-mac');
        $this->makeDevice('dev-polos');

        $this->pins()->pin('dev-mac', $olt, 1, 1, 1, null);
        $this->pins()->pin('dev-polos', $olt, 1, 2, 2, null);

        $mac = GenieacsDeviceMap::where('device_id', 'dev-mac')->firstOrFail();
        $this->assertSame(GenieacsDeviceMap::REF_MAC, $mac->manual_ref_type);
        $this->assertSame('d05faf0012e6', $mac->manual_ref);

        // ONU tanpa serial maupun MAC hanya bisa disematkan lewat posisi. Itu
        // memang tidak tahan pindah port — batas yang disadari.
        $polos = GenieacsDeviceMap::where('device_id', 'dev-polos')->firstOrFail();
        $this->assertSame(GenieacsDeviceMap::REF_POSITION, $polos->manual_ref_type);
        $this->assertSame("{$olt->id}.1.2.2", $polos->manual_ref);
    }

    public function test_pinned_pairing_follows_an_onu_moved_to_another_port(): void
    {
        $olt = $this->makeOlt(['1_1' => [
            ['onu_id' => 4, 'serial_number' => 'ZTEGC1234567', 'online' => true],
        ]]);
        $this->makeDevice('dev-a');
        $this->pins()->pin('dev-a', $olt, 1, 1, 4, null);

        // Teknisi memindahkan ONU ke PON lain; nomor ONU-nya pun berubah.
        $olt->forceFill(['last_test_result' => [
            'ok' => true,
            'system' => ['sysDescr' => 'ZTE C320'],
            'port_onus' => ['1_5' => ['slot' => 1, 'port' => 5, 'count' => 1, 'onus' => [
                ['onu_id' => 12, 'slot' => 1, 'port' => 5, 'serial_number' => 'ZTEGC1234567', 'online' => true],
            ]]],
        ]])->save();

        $result = $this->syncWith([['_id' => 'dev-a', '_deviceId' => ['_SerialNumber' => 'TIDAKDIPAKAI']]]);

        $this->assertSame(1, $result['manual']);
        $this->assertSame(0, $result['manual_stale']);

        $row = GenieacsDeviceMap::where('device_id', 'dev-a')->firstOrFail();
        $this->assertSame(5, $row->port, 'Pin harus ikut ONU ke port barunya.');
        $this->assertSame(12, $row->onu_id);
        $this->assertSame('ZTEGC1234567', $row->manual_ref, 'Identitas yang disematkan tidak boleh ikut berubah.');
    }

    public function test_pin_is_released_and_flagged_when_the_onu_is_replaced(): void
    {
        $olt = $this->makeOlt(['1_1' => [
            ['onu_id' => 4, 'serial_number' => 'ZTEGC1234567', 'online' => true],
        ]]);
        $this->makeDevice('dev-a');
        $this->pins()->pin('dev-a', $olt, 1, 1, 4, null);

        // ONU diganti unit baru: posisinya sama, serialnya berbeda.
        $olt->forceFill(['last_test_result' => [
            'ok' => true,
            'system' => ['sysDescr' => 'ZTE C320'],
            'port_onus' => ['1_1' => ['slot' => 1, 'port' => 1, 'count' => 1, 'onus' => [
                ['onu_id' => 4, 'slot' => 1, 'port' => 1, 'serial_number' => 'ZTEGBARU0001', 'online' => true],
            ]]],
        ]])->save();

        $result = $this->syncWith([['_id' => 'dev-a', '_deviceId' => ['_SerialNumber' => 'TIDAKDIPAKAI']]]);

        $this->assertSame(0, $result['manual']);
        $this->assertSame(1, $result['manual_stale']);

        $row = GenieacsDeviceMap::where('device_id', 'dev-a')->firstOrFail();
        $this->assertNull($row->onu_id, 'Device lama tidak boleh menempel ke ONU pelanggan yang baru.');
        $this->assertTrue($row->manual_stale);
        $this->assertSame('ZTEGC1234567', $row->manual_ref, 'Pin basi disimpan, bukan dihapus diam-diam.');
    }

    public function test_pin_follows_the_only_online_holder_of_a_duplicated_mac(): void
    {
        // Kasus nyata 24 Sep 2026: ONU EPON pindah OLT, registrasi lamanya tertinggal
        // offline di OLT asal → MAC yang sama dipegang dua ONU. Pin manual tak boleh lepas.
        $baru = $this->makeOlt(['2_4' => [['onu_id' => 16, 'serial_number' => null, 'mac' => 'D0:5F:AF:00:34:1E', 'online' => true]]], 'OLT-BARU');
        $this->makeOlt(['1_4' => [['onu_id' => 12, 'serial_number' => null, 'mac' => 'D0:5F:AF:00:34:1E', 'online' => false]]], 'OLT-LAMA');
        $this->makeDevice('dev-a', [
            'match_method' => GenieacsDeviceMap::METHOD_MANUAL,
            'manual_ref_type' => GenieacsDeviceMap::REF_MAC,
            'manual_ref' => 'd05faf00341e',
            'manual_at' => now(),
        ]);

        $result = $this->syncWith([['_id' => 'dev-a', '_deviceId' => ['_SerialNumber' => 'TIDAKDIPAKAI']]]);

        $this->assertSame(1, $result['manual']);
        $row = GenieacsDeviceMap::where('device_id', 'dev-a')->firstOrFail();
        $this->assertSame($baru->id, $row->snmp_olt_id);
        $this->assertSame(16, $row->onu_id);
        $this->assertFalse($row->manual_stale);
    }

    public function test_pin_is_still_released_when_a_duplicated_identity_is_online_twice(): void
    {
        $this->makeOlt(['1_1' => [['onu_id' => 1, 'serial_number' => null, 'mac' => 'D0:5F:AF:00:34:1E', 'online' => true]]], 'OLT-A');
        $this->makeOlt(['1_1' => [['onu_id' => 2, 'serial_number' => null, 'mac' => 'D0:5F:AF:00:34:1E', 'online' => true]]], 'OLT-B');
        $this->makeDevice('dev-a', [
            'match_method' => GenieacsDeviceMap::METHOD_MANUAL,
            'manual_ref_type' => GenieacsDeviceMap::REF_MAC,
            'manual_ref' => 'd05faf00341e',
            'manual_at' => now(),
        ]);

        $result = $this->syncWith([['_id' => 'dev-a', '_deviceId' => ['_SerialNumber' => 'TIDAKDIPAKAI']]]);

        $this->assertSame(1, $result['manual_stale'], 'Dua pemegang online = tak bisa dipastikan, jangan menebak.');
    }

    public function test_pinning_releases_another_device_holding_the_same_onu(): void
    {
        $olt = $this->makeOlt(['1_1' => [
            ['onu_id' => 4, 'serial_number' => 'ZTEGC1234567', 'online' => true],
        ]]);

        // Pencocokan otomatis sebelumnya meleset ke device lain — justru itu
        // alasan operator menyemat manual.
        $this->makeDevice('dev-salah', [
            'snmp_olt_id' => $olt->id, 'slot' => 1, 'port' => 1, 'onu_id' => 4,
            'match_method' => GenieacsDeviceMap::METHOD_MAC, 'matched_at' => now(),
        ]);
        $this->makeDevice('dev-benar');

        $this->pins()->pin('dev-benar', $olt, 1, 1, 4, null);

        $this->assertNull(GenieacsDeviceMap::where('device_id', 'dev-salah')->firstOrFail()->onu_id);
        $this->assertSame(4, GenieacsDeviceMap::where('device_id', 'dev-benar')->firstOrFail()->onu_id);
    }

    public function test_unpin_returns_the_device_to_automatic_matching(): void
    {
        $olt = $this->makeOlt(['1_1' => [
            ['onu_id' => 4, 'serial_number' => 'ZTEGC1234567', 'online' => true],
        ]]);
        $this->makeDevice('dev-a', ['serial_number' => 'ZTEGC1234567']);
        $this->pins()->pin('dev-a', $olt, 1, 1, 4, null);

        $result = $this->pins()->unpin($olt->id, 1, 1, 4);

        $this->assertTrue($result['ok']);
        // Serialnya sama, jadi pencocokan otomatis langsung memasangkannya lagi —
        // operator perlu diberi tahu itu, bukan dibiarkan mengira pelepasannya gagal.
        $this->assertSame('serial', $result['rematch_method']);

        $row = GenieacsDeviceMap::where('device_id', 'dev-a')->firstOrFail();
        $this->assertSame(GenieacsDeviceMap::METHOD_SERIAL, $row->match_method);
        $this->assertNull($row->manual_ref);
        $this->assertNull($row->manual_at);
    }

    public function test_unpin_leaves_the_device_unpaired_when_nothing_matches(): void
    {
        $olt = $this->makeOlt(['1_1' => [
            ['onu_id' => 4, 'serial_number' => 'ZTEGC1234567', 'online' => true],
        ]]);
        $this->makeDevice('dev-a', ['serial_number' => 'SERIALASING1']);
        $this->pins()->pin('dev-a', $olt, 1, 1, 4, null);

        $result = $this->pins()->unpin($olt->id, 1, 1, 4);

        $this->assertNull($result['rematch_method']);
        $this->assertNull(GenieacsDeviceMap::where('device_id', 'dev-a')->firstOrFail()->onu_id);
    }

    public function test_search_matches_pppoe_username_regardless_of_case(): void
    {
        $this->makeDevice('dev-a', ['pppoe_username' => 'uji0800budi', 'serial_number' => 'ZTEGC1234567']);
        $this->makeDevice('dev-b', ['pppoe_username' => 'uji0800sari']);

        $hits = $this->pins()->search('BUDI');

        $this->assertCount(1, $hits);
        $this->assertSame('dev-a', $hits[0]['device_id']);
    }

    public function test_search_puts_unpaired_devices_first(): void
    {
        $olt = $this->makeOlt(['1_1' => [['onu_id' => 1, 'serial_number' => 'ZTEGC1234567', 'online' => true]]]);

        $this->makeDevice('dev-terpakai', [
            'pppoe_username' => 'warga01',
            'snmp_olt_id' => $olt->id, 'slot' => 1, 'port' => 1, 'onu_id' => 1,
            'match_method' => GenieacsDeviceMap::METHOD_SERIAL,
        ]);
        $this->makeDevice('dev-bebas', ['pppoe_username' => 'warga02']);

        $hits = $this->pins()->search('warga');

        $this->assertSame('dev-bebas', $hits[0]['device_id']);
        // Yang sudah dipakai tetap muncul, lengkap dengan peringatan sedang dipakai siapa.
        $this->assertSame($olt->name, $hits[1]['linked_to']['olt_name']);
    }

    public function test_search_ignores_a_mac_fragment_that_is_too_short(): void
    {
        $this->makeDevice('dev-a', ['pon_mac' => 'd05faf0012e6']);

        // "d0" akan mencocokkan hampir seluruh tabel kalau dibiarkan lolos.
        $this->assertCount(0, $this->pins()->search('d0'));
        $this->assertCount(1, $this->pins()->search('D0:5F:AF'));
    }

    public function test_sync_stores_pppoe_username_and_management_ip(): void
    {
        $this->makeOlt(['1_1' => [['onu_id' => 1, 'serial_number' => 'ZTEGC1234567', 'online' => true]]]);

        $this->syncWith([[
            '_id' => 'dev-a',
            '_deviceId' => ['_SerialNumber' => 'ZTEGC1234567'],
            'VirtualParameters' => [
                'pppoeUsername' => ['_value' => 'uji0800budi'],
                'IPTR069' => ['_value' => '10.255.0.128'],
            ],
        ]]);

        $row = GenieacsDeviceMap::where('device_id', 'dev-a')->firstOrFail();
        $this->assertSame('uji0800budi', $row->pppoe_username);
        $this->assertSame('10.255.0.128', $row->tr069_ip);
    }

    public function test_pin_endpoint_is_closed_to_read_only_roles(): void
    {
        $olt = $this->makeOlt(['1_1' => [['onu_id' => 1, 'serial_number' => 'ZTEGC1234567', 'online' => true]]]);
        $this->makeDevice('dev-a');

        // `demo` satu-satunya peran sah di luar daftar tulis.
        $response = $this->actingAs(User::factory()->create(['role' => 'demo']))
            ->postJson(route('genieacs.onu.pin', [
                'olt' => $olt->id, 'slot' => 1, 'port' => 1, 'onuId' => 1,
            ]), ['device_id' => 'dev-a']);

        $this->assertTrue($response->status() >= 400, 'Peran baca-saja tidak boleh mengubah pasangan ACS.');
        $this->assertNull(GenieacsDeviceMap::where('device_id', 'dev-a')->firstOrFail()->onu_id);
    }

    public function test_pin_endpoint_records_an_audit_entry(): void
    {
        $olt = $this->makeOlt(['1_1' => [['onu_id' => 1, 'serial_number' => 'ZTEGC1234567', 'online' => true]]]);
        $this->makeDevice('dev-a');

        $this->actingAs(User::factory()->admin()->create())
            ->postJson(route('genieacs.onu.pin', [
                'olt' => $olt->id, 'slot' => 1, 'port' => 1, 'onuId' => 1,
            ]), ['device_id' => 'dev-a'])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $audit = AuditLog::query()->latest('id')->firstOrFail();
        $this->assertSame('genieacs.pin.created', $audit->event);
        $this->assertSame('serial', $audit->properties['manual_ref_type'] ?? null);
    }

    public function test_pin_rejects_an_onu_missing_from_the_snapshot(): void
    {
        $olt = $this->makeOlt(['1_1' => [['onu_id' => 1, 'serial_number' => 'ZTEGC1234567', 'online' => true]]]);
        $this->makeDevice('dev-a');

        $result = $this->pins()->pin('dev-a', $olt, 1, 1, 99, null);

        $this->assertFalse($result['ok']);
        $this->assertSame('onu_not_found', $result['error']);
    }

    public function test_refresh_endpoint_pulls_a_device_that_just_enabled_tr069(): void
    {
        // Kasus nyata: serial TR-069 berbeda dari serial GPON dan PonMac kosong,
        // jadi device ini baru bisa dipasangkan manual — tapi harus bisa dicari dulu.
        $this->makeOlt(['1_1' => [['onu_id' => 10, 'serial_number' => 'RTEGC0800001', 'online' => true]]]);
        Http::fake(['*/devices*' => Http::response([[
            '_id' => '689FF0-F609-RTEEQ0800001',
            '_deviceId' => ['_SerialNumber' => 'RTEEQ0800001'],
            'VirtualParameters' => ['pppoeUsername' => ['_value' => 'uji0800desa']],
        ]], 200)]);
        GenieacsCredential::create(['host' => '192.0.2.10', 'port' => 7557]);

        $this->actingAs(User::factory()->admin()->create())
            ->postJson(route('genieacs.devices.refresh'))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('devices', 1);

        $this->assertSame('689FF0-F609-RTEEQ0800001', $this->pins()->search('uji0800')[0]['device_id'] ?? null);

        // Tekan kedua dalam jeda 20 detik tidak memanggil ACS lagi.
        $this->actingAs(User::factory()->admin()->create())
            ->postJson(route('genieacs.devices.refresh'))
            ->assertOk()
            ->assertJsonPath('skipped', true);
        Http::assertSentCount(1);
    }

    public function test_refresh_endpoint_reports_an_unreachable_acs(): void
    {
        Http::fake(['*' => Http::response('down', 500)]);
        GenieacsCredential::create(['host' => '192.0.2.10', 'port' => 7557]);

        $this->actingAs(User::factory()->admin()->create())
            ->postJson(route('genieacs.devices.refresh'))
            ->assertStatus(502)
            ->assertJsonPath('error', 'sync_failed');
    }

    public function test_refresh_endpoint_is_closed_to_read_only_roles(): void
    {
        $response = $this->actingAs(User::factory()->create(['role' => 'demo']))
            ->postJson(route('genieacs.devices.refresh'));

        $this->assertTrue($response->status() >= 400);
    }

    /**
     * OLT privat milik partner, lengkap dengan pivot penugasannya.
     */
    private function makePartnerOlt(User $partner, array $ports): SnmpOlt
    {
        $olt = $this->makeOlt($ports, 'OLT-MITRA-UJI');
        $olt->forceFill(['owner_user_id' => $partner->id])->save();
        $olt->partners()->syncWithoutDetaching([$partner->id]);

        return $olt;
    }

    public function test_partner_cannot_use_the_acs_catalog_even_on_an_assigned_olt(): void
    {
        // Katalog ACS memuat device seluruh pelanggan, jadi hanya staf Pusat.
        // Penugasan OLT global ke partner tidak membukanya.
        $olt = $this->makeOlt(['1_1' => [['onu_id' => 1, 'serial_number' => 'ZTEGC1234567', 'online' => true]]]);
        $partner = User::factory()->partner()->create();
        $olt->partners()->syncWithoutDetaching([$partner->id]);
        $this->makeDevice('dev-satu', ['pppoe_username' => 'pelanggansatu']);

        $this->actingAs($partner)
            ->getJson(route('genieacs.devices.search', ['q' => 'pelanggan']))
            ->assertForbidden();

        $this->actingAs($partner)->postJson(route('genieacs.onu.pin', [
            'olt' => $olt->id, 'slot' => 1, 'port' => 1, 'onuId' => 1,
        ]), ['device_id' => 'dev-satu'])->assertForbidden();

        $this->actingAs($partner)
            ->postJson(route('genieacs.devices.refresh'))
            ->assertForbidden();

        $this->assertNull(GenieacsDeviceMap::where('device_id', 'dev-satu')->firstOrFail()->snmp_olt_id);
    }

    public function test_demo_has_no_acs_catalog(): void
    {
        $this->actingAs(User::factory()->demo()->create())
            ->getJson(route('genieacs.devices.search'))
            ->assertForbidden();
    }

    public function test_pin_refuses_a_partner_owned_olt(): void
    {
        $olt = $this->makePartnerOlt(User::factory()->partner()->create(), [
            '1_1' => [['onu_id' => 1, 'serial_number' => 'ZTEGC1234567', 'online' => true]],
        ]);
        $this->makeDevice('dev-satu');

        $result = $this->pins()->pin('dev-satu', $olt, 1, 1, 1, null);

        $this->assertFalse($result['ok']);
        $this->assertSame('olt_not_eligible', $result['error']);
        $this->assertNull(GenieacsDeviceMap::where('device_id', 'dev-satu')->firstOrFail()->snmp_olt_id);
    }

    public function test_assigned_operator_cannot_see_or_take_devices_of_other_olts(): void
    {
        $mine = $this->makeOlt(['1_1' => [['onu_id' => 1, 'serial_number' => 'ZTEGC0000001', 'online' => true]]], 'OLT-MILIKKU');
        $other = $this->makeOlt(['1_1' => [['onu_id' => 5, 'serial_number' => 'ZTEGC0000005', 'online' => true]]], 'OLT-LAIN');
        $operator = User::factory()->create(['role' => 'operator']);
        $mine->partners()->syncWithoutDetaching([$operator->id]);

        $this->makeDevice('dev-lain', [
            'pppoe_username' => 'wargalain',
            'snmp_olt_id' => $other->id, 'slot' => 1, 'port' => 1, 'onu_id' => 5,
            'match_method' => GenieacsDeviceMap::METHOD_SERIAL,
        ]);
        $this->makeDevice('dev-bebas', ['pppoe_username' => 'wargabebas']);

        $this->actingAs($operator);

        $ids = array_column($this->pins()->search('warga'), 'device_id');
        $this->assertSame(['dev-bebas'], $ids);

        $result = $this->pins()->pin('dev-lain', $mine, 1, 1, 1, $operator->id);
        $this->assertFalse($result['ok']);
        $this->assertSame($other->id, GenieacsDeviceMap::where('device_id', 'dev-lain')->firstOrFail()->snmp_olt_id);
    }

    public function test_sync_triggered_by_a_scoped_user_keeps_global_pairings(): void
    {
        // Dulu indeks dibangun dari OLT yang terlihat oleh pemicu sinkronisasi,
        // sehingga refresh dari pengguna ter-scope melepas semua pasangan OLT lain.
        $global = $this->makeOlt(['1_1' => [['onu_id' => 3, 'serial_number' => 'ZTEGC0000003', 'online' => true]]], 'OLT-GLOBAL');
        $partner = User::factory()->partner()->create();
        $this->makePartnerOlt($partner, ['1_1' => [['onu_id' => 9, 'serial_number' => 'ZTEGC0000009', 'online' => true]]]);

        $this->actingAs($partner);
        $this->syncWith([
            ['_id' => 'dev-global', '_deviceId' => ['_SerialNumber' => 'ZTEGC0000003']],
            // ONU di OLT privat partner yang serialnya dikenal ACS: tetap tidak dipasangkan.
            ['_id' => 'dev-di-mitra', '_deviceId' => ['_SerialNumber' => 'ZTEGC0000009']],
        ]);

        $this->assertSame($global->id, GenieacsDeviceMap::where('device_id', 'dev-global')->firstOrFail()->snmp_olt_id);
        $this->assertNull(GenieacsDeviceMap::where('device_id', 'dev-di-mitra')->firstOrFail()->snmp_olt_id);
    }

    /**
     * Jalankan sinkronisasi dengan katalog ACS palsu.
     *
     * @param  array<int, array<string, mixed>>  $devices
     * @return array<string, mixed>
     */
    private function syncWith(array $devices): array
    {
        Http::fake(['*/devices*' => Http::response($devices, 200)]);
        GenieacsCredential::firstOrCreate([], ['host' => '192.0.2.10', 'port' => 7557]);

        return app(GenieacsDeviceSyncService::class)->sync();
    }
}
