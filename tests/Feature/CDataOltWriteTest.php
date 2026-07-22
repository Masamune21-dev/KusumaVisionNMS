<?php

namespace Tests\Feature;

use App\Models\SnmpOlt;
use App\Models\User;
use App\Services\CData\CDataCliWriteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Fake writer: rekam pemanggilan, tak menyentuh telnet. */
class FakeCDataCliWriteService extends CDataCliWriteService
{
    /** @var array<int, array<int, mixed>> */
    public array $calls = [];

    public function reboot(SnmpOlt $olt, string $iface, int $slot, int $port, int $onuId): array
    {
        $this->calls[] = ['reboot', $iface, $slot, $port, $onuId];

        return ['ok' => true, 'output' => '', 'error' => null];
    }

    public function setDescription(SnmpOlt $olt, string $iface, int $slot, int $port, int $onuId, ?string $text): array
    {
        $this->calls[] = ['desc', $iface, $slot, $port, $onuId, $text];

        return ['ok' => true, 'output' => '', 'error' => null];
    }

    public function delete(SnmpOlt $olt, string $iface, int $slot, int $port, int $onuId): array
    {
        $this->calls[] = ['delete', $iface, $slot, $port, $onuId];

        return ['ok' => true, 'output' => '', 'error' => null];
    }

    public function setRemoteAccess(SnmpOlt $olt, string $iface, int $slot, int $port, int $onuId, bool $enable): array
    {
        $this->calls[] = ['remote', $iface, $slot, $port, $onuId, $enable];

        return ['ok' => true, 'output' => '', 'error' => null];
    }
}

class CDataOltWriteTest extends TestCase
{
    use RefreshDatabase;

    private function eponOltWithOnu(): SnmpOlt
    {
        return SnmpOlt::create([
            'name' => 'CDATA-EPON-W',
            'vendor' => 'C-Data EPON 17409',
            'ip' => '10.30.0.1',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'cli_transport' => 'telnet',
            'cli_username' => 'admin',
            'cli_password' => 'secret',
            'last_test_result' => [
                'port_onus' => [
                    '1_1' => [
                        'slot' => 1,
                        'port' => 1,
                        'count' => 1,
                        'onus' => [
                            ['onu_key' => '1.1.5', 'slot' => 1, 'port' => 1, 'onu_id' => 5, 'name' => 'Old', 'interface' => 'epon 0/1/1 onu 5'],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function test_rename_calls_cli_and_updates_cache(): void
    {
        $user = User::factory()->create();
        $fake = new FakeCDataCliWriteService;
        $this->app->instance(CDataCliWriteService::class, $fake);

        $olt = $this->eponOltWithOnu();

        $this->actingAs($user)
            ->post(route('cdata-olt.onu.info', [$olt, 1, 1, 5]), ['name' => 'Baru'])
            ->assertRedirect(route('cdata-olt.port-onus', [$olt, 1, 1]))
            ->assertSessionHas('success');

        $this->assertSame([['desc', 'epon', 1, 1, 5, 'Baru']], $fake->calls);

        $olt->refresh();
        $this->assertSame('Baru', data_get($olt->last_test_result, 'port_onus.1_1.onus.0.name'));
    }

    public function test_reboot_calls_cli_with_epon_keyword(): void
    {
        $user = User::factory()->create();
        $fake = new FakeCDataCliWriteService;
        $this->app->instance(CDataCliWriteService::class, $fake);

        $olt = $this->eponOltWithOnu();

        $this->actingAs($user)
            ->post(route('cdata-olt.onu.reboot', [$olt, 1, 1, 5]))
            ->assertRedirect(route('cdata-olt.port-onus', [$olt, 1, 1]))
            ->assertSessionHas('success');

        $this->assertSame([['reboot', 'epon', 1, 1, 5]], $fake->calls);
    }

    public function test_delete_calls_cli_and_removes_from_cache(): void
    {
        $user = User::factory()->create();
        $fake = new FakeCDataCliWriteService;
        $this->app->instance(CDataCliWriteService::class, $fake);

        $olt = $this->eponOltWithOnu();

        $this->actingAs($user)
            ->delete(route('cdata-olt.onu.delete', [$olt, 1, 1, 5]))
            ->assertRedirect(route('cdata-olt.port-onus', [$olt, 1, 1]))
            ->assertSessionHas('success');

        $this->assertSame([['delete', 'epon', 1, 1, 5]], $fake->calls);

        $olt->refresh();
        $this->assertSame([], data_get($olt->last_test_result, 'port_onus.1_1.onus'));
        $this->assertSame(0, data_get($olt->last_test_result, 'port_onus.1_1.count'));
    }

    /** OLT GPON FlashV3 (satu-satunya family dengan `ont security-mgmt` terverifikasi). */
    private function gponV3OltWithOnu(): SnmpOlt
    {
        return SnmpOlt::create([
            'name' => 'CDATA-GPON-W',
            'vendor' => 'C-Data GPON 34592',
            'ip' => '10.30.0.2',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'cli_transport' => 'telnet',
            'cli_username' => 'admin',
            'cli_password' => 'secret',
            'last_test_result' => [
                'cdata' => ['firmware_v3' => true],
                'port_onus' => [
                    '0_1' => [
                        'slot' => 0,
                        'port' => 1,
                        'count' => 1,
                        'onus' => [
                            ['onu_key' => '0.1.2', 'slot' => 0, 'port' => 1, 'onu_id' => 2, 'name' => 'Pak Muh', 'interface' => 'gpon 0/0/1:2'],
                        ],
                    ],
                ],
            ],
        ]);
    }

    public function test_remote_access_calls_cli_with_gpon_keyword_and_updates_cache(): void
    {
        $user = User::factory()->create();
        $fake = new FakeCDataCliWriteService;
        $this->app->instance(CDataCliWriteService::class, $fake);

        $olt = $this->gponV3OltWithOnu();

        $this->actingAs($user)
            ->post(route('cdata-olt.onu.remote-access', [$olt, 0, 1, 2]), ['enable' => true])
            ->assertRedirect(route('cdata-olt.port-onus', [$olt, 0, 1]))
            ->assertSessionHas('success');

        $this->assertSame([['remote', 'gpon', 0, 1, 2, true]], $fake->calls);

        $olt->refresh();
        $this->assertTrue((bool) data_get($olt->last_test_result, 'port_onus.0_1.onus.0.remote_web'));
    }

    public function test_remote_access_rejected_on_epon_family(): void
    {
        $user = User::factory()->create();
        $fake = new FakeCDataCliWriteService;
        $this->app->instance(CDataCliWriteService::class, $fake);

        $olt = $this->eponOltWithOnu();

        $this->actingAs($user)
            ->post(route('cdata-olt.onu.remote-access', [$olt, 1, 1, 5]), ['enable' => true])
            ->assertForbidden();

        $this->assertSame([], $fake->calls);
    }
}
