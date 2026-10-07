<?php

namespace Tests\Feature;

use App\Models\GenieacsCredential;
use App\Models\GenieacsDeviceMap;
use App\Models\SnmpOlt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Ubah SSID & kata sandi WiFi — satu-satunya aksi modul GenieACS yang MENULIS
 * ke perangkat pelanggan.
 *
 * Yang dijaga di sini: jalur passphrase dipilih dari yang benar-benar dimiliki
 * ONU (pada armada ini C-Data memakai `X_CMS_KeyPassphrase`, bukan
 * `PreSharedKey`), aksi ini tertutup bagi peran non-tulis, tercatat di audit,
 * dan kata sandinya TIDAK ikut tercatat.
 */
class GenieacsWifiUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function makeOlt(): SnmpOlt
    {
        return SnmpOlt::create([
            'name' => 'OLT-UJI-WIFI',
            'vendor' => 'ZTE C320',
            'ip' => '10.8.8.8',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'last_test_result' => [
                'ok' => true,
                'system' => ['sysDescr' => 'ZTE C320'],
                'port_onus' => ['1_1' => ['slot' => 1, 'port' => 1, 'count' => 1, 'onus' => [
                    ['onu_id' => 1, 'online' => true, 'serial_number' => 'CDTCAF0012E6'],
                ]]],
            ],
        ]);
    }

    private function linkDevice(SnmpOlt $olt): void
    {
        GenieacsDeviceMap::create([
            'device_id' => 'dev-wifi',
            'serial_number' => 'CDTCAF0012E6',
            'snmp_olt_id' => $olt->id,
            'slot' => 1,
            'port' => 1,
            'onu_id' => 1,
            'match_method' => GenieacsDeviceMap::METHOD_SERIAL,
            'last_inform_at' => now(),
        ]);
    }

    /**
     * Dokumen device C-Data: passphrase ada di `X_CMS_KeyPassphrase`,
     * `PreSharedKey.1.KeyPassphrase` ada tapi kosong.
     *
     * @return array<int, array<string, mixed>>
     */
    private function cdataDoc(): array
    {
        return [[
            '_id' => 'dev-wifi',
            '_deviceId' => ['_SerialNumber' => 'CDTCAF0012E6'],
            'InternetGatewayDevice' => ['LANDevice' => ['1' => ['WLANConfiguration' => ['1' => [
                'SSID' => ['_value' => 'KUSUMANET-LAMA'],
                'BeaconType' => ['_value' => '11i'],
                'Enable' => ['_value' => true],
                'PreSharedKey' => ['1' => ['KeyPassphrase' => ['_value' => '']]],
                'X_CMS_KeyPassphrase' => ['_value' => 'sandilama123'],
            ]]]]],
        ]];
    }

    private function fakeAcs(array $doc): void
    {
        Http::fake([
            '*/tasks*' => Http::response(['name' => 'setParameterValues'], 200),
            '*/devices*' => Http::response($doc, 200),
        ]);
    }

    public function test_writes_the_vendor_passphrase_path_the_onu_actually_has(): void
    {
        $olt = $this->makeOlt();
        $this->linkDevice($olt);
        GenieacsCredential::create(['host' => '192.0.2.10', 'port' => 7557]);
        $this->fakeAcs($this->cdataDoc());

        $this->actingAs(User::factory()->admin()->create())
            ->postJson(route('genieacs.onu.wifi', ['olt' => $olt->id, 'slot' => 1, 'port' => 1, 'onuId' => 1]), [
                'ssid' => 'KUSUMANET-BARU',
                'password' => 'rahasia12345',
                'wlan_index' => 1,
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/tasks')) {
                return false;
            }

            $body = json_encode($request->data());

            // SSID baru dikirim, DAN passphrase ditulis ke jalur vendor C-Data.
            return str_contains($body, 'KUSUMANET-BARU')
                && str_contains($body, 'X_CMS_KeyPassphrase');
        });
    }

    public function test_records_an_audit_row_without_the_password(): void
    {
        $olt = $this->makeOlt();
        $this->linkDevice($olt);
        GenieacsCredential::create(['host' => '192.0.2.10', 'port' => 7557]);
        $this->fakeAcs($this->cdataDoc());

        $this->actingAs(User::factory()->admin()->create())
            ->postJson(route('genieacs.onu.wifi', ['olt' => $olt->id, 'slot' => 1, 'port' => 1, 'onuId' => 1]), [
                'ssid' => 'KUSUMANET-BARU',
                'password' => 'rahasia12345',
                'wlan_index' => 1,
            ])->assertOk();

        $audit = \App\Models\AuditLog::query()->latest('id')->first();

        $this->assertSame('genieacs.wifi.updated', $audit->event);
        $this->assertStringContainsString('KUSUMANET-BARU', (string) $audit->description);
        $this->assertStringNotContainsString('rahasia12345', json_encode($audit->toArray()));
    }

    public function test_rejects_a_password_shorter_than_wpa_allows(): void
    {
        $olt = $this->makeOlt();
        $this->linkDevice($olt);
        GenieacsCredential::create(['host' => '192.0.2.10', 'port' => 7557]);

        $this->actingAs(User::factory()->admin()->create())
            ->postJson(route('genieacs.onu.wifi', ['olt' => $olt->id, 'slot' => 1, 'port' => 1, 'onuId' => 1]), [
                'ssid' => 'KUSUMANET',
                'password' => 'pendek',
                'wlan_index' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        Http::assertNothingSent();
    }

    public function test_unlinked_onu_is_refused_before_touching_the_acs(): void
    {
        $olt = $this->makeOlt();
        GenieacsCredential::create(['host' => '192.0.2.10', 'port' => 7557]);

        $this->actingAs(User::factory()->admin()->create())
            ->postJson(route('genieacs.onu.wifi', ['olt' => $olt->id, 'slot' => 1, 'port' => 1, 'onuId' => 1]), [
                'ssid' => 'KUSUMANET',
                'password' => 'rahasia12345',
                'wlan_index' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'not_linked');

        Http::assertNothingSent();
    }

    public function test_demo_role_cannot_change_customer_wifi(): void
    {
        $olt = $this->makeOlt();
        $this->linkDevice($olt);

        // Peran yang sah di NMS: admin, operator, partner, demo. Hanya tiga
        // pertama yang boleh menulis ke perangkat pelanggan.
        $demo = User::factory()->create(['role' => 'demo']);

        $response = $this->actingAs($demo)
            ->postJson(route('genieacs.onu.wifi', ['olt' => $olt->id, 'slot' => 1, 'port' => 1, 'onuId' => 1]), [
                'ssid' => 'NAKAL',
                'password' => 'rahasia12345',
                'wlan_index' => 1,
            ]);

        // Akun demo ditolak lebih awal lagi: `DemoScope` membuat OLT non-demo
        // tak terlihat sehingga route-model binding menjawab 404 sebelum
        // middleware peran sempat bicara. Yang penting: BUKAN 2xx, dan tak ada
        // satu pun perintah yang sampai ke ACS.
        $this->assertTrue($response->status() >= 400);
        Http::assertNothingSent();
    }

    public function test_the_write_route_is_gated_to_write_roles(): void
    {
        // Aksi ONU lain di NMS hanya ber-`auth`; yang ini sengaja lebih ketat
        // karena menulis ke perangkat pelanggan. Diuji langsung pada definisi
        // rutenya supaya gerbangnya tak bisa hilang diam-diam.
        $middleware = \Illuminate\Support\Facades\Route::getRoutes()
            ->getByName('genieacs.onu.wifi')
            ->gatherMiddleware();

        $this->assertContains('role:admin,operator,partner', $middleware);
    }
}
