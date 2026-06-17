<?php

namespace Tests\Feature;

use App\Models\SnmpOlt;
use App\Models\User;
use App\Services\ZteCliProvisioningExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmartOltDeleteOnuTest extends TestCase
{
    use RefreshDatabase;

    public function test_delete_onu_runs_no_onu_and_drops_it_from_cache(): void
    {
        $user = User::factory()->create();
        $olt = $this->makeOlt();

        $executor = new class extends ZteCliProvisioningExecutor
        {
            public string $script = '';

            public function execute(SnmpOlt $olt, string $script): array
            {
                $this->script = $script;

                return ['ok' => true, 'error' => null, 'output' => 'BMKV-C300#'];
            }
        };
        $this->app->instance(ZteCliProvisioningExecutor::class, $executor);

        $response = $this->actingAs($user)
            ->post(route('smartolt.onu.delete', [$olt, 4, 9, 1]));

        $response
            ->assertRedirect(route('smartolt.port-onus', [$olt, 4, 9]))
            ->assertSessionHas('success');

        $this->assertStringContainsString('interface gpon-olt_1/4/9', $executor->script);
        $this->assertStringContainsString('no onu 1', $executor->script);

        $olt->refresh();
        $remaining = data_get($olt->last_test_result, 'port_onus.4_9.onus');
        $this->assertSame([2], collect($remaining)->pluck('onu_id')->all());
        $this->assertSame(1, data_get($olt->last_test_result, 'port_onus.4_9.count'));
    }

    private function makeOlt(array $overrides = []): SnmpOlt
    {
        return SnmpOlt::create(array_merge([
            'name' => 'BMKV-C300',
            'vendor' => 'ZTE C300',
            'ip' => '10.30.0.30',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'cli_transport' => 'telnet',
            'cli_port' => 23,
            'cli_username' => 'admin',
            'cli_password' => 'secret',
            'last_test_result' => [
                'port_onus' => [
                    '4_9' => [
                        'ok' => true,
                        'count' => 2,
                        'onus' => [
                            ['onu_id' => 1, 'serial_number' => 'ZTEGC9140803', 'type_name' => 'ALL-ONT'],
                            ['onu_id' => 2, 'serial_number' => 'ZTEG22222222', 'type_name' => 'F660'],
                        ],
                    ],
                ],
            ],
        ], $overrides));
    }
}
