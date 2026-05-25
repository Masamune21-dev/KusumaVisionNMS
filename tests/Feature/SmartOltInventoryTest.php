<?php

namespace Tests\Feature;

use App\Models\SnmpOlt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmartOltInventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_smartolt_index_can_be_rendered(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('smartolt.index'));

        $response->assertOk();
    }

    public function test_authenticated_user_can_store_an_olt(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('smartolt.store'), [
            'name' => 'PATI-ZTE-C320',
            'vendor' => 'ZTE C320',
            'ip' => '10.10.10.2',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_write_community' => 'private',
            'snmp_version' => 'v2c',
            'cli_transport' => 'telnet',
            'cli_port' => 23,
            'cli_username' => 'operator',
            'cli_password' => 'secret',
        ]);

        $response->assertRedirect(route('smartolt.index'));

        $olt = SnmpOlt::query()->firstOrFail();

        $this->assertSame('PATI-ZTE-C320', $olt->name);
        $this->assertSame('public', $olt->snmp_read_community);
        $this->assertSame('secret', $olt->cli_password);
    }

    public function test_empty_secret_fields_on_update_preserve_existing_values(): void
    {
        $user = User::factory()->create();
        $olt = SnmpOlt::create([
            'name' => 'OLT Lama',
            'vendor' => 'ZTE C300',
            'ip' => '10.10.10.3',
            'snmp_port' => 161,
            'snmp_read_community' => 'old-public',
            'snmp_write_community' => 'old-private',
            'snmp_version' => 'v2c',
            'cli_transport' => 'ssh',
            'cli_port' => 22,
            'cli_username' => 'admin',
            'cli_password' => 'old-password',
        ]);

        $response = $this->actingAs($user)->put(route('smartolt.update', $olt), [
            'name' => 'OLT Baru',
            'vendor' => 'ZTE C320',
            'ip' => '10.10.10.3',
            'snmp_port' => 161,
            'snmp_read_community' => '',
            'snmp_write_community' => '',
            'snmp_version' => 'v2c',
            'cli_transport' => 'ssh',
            'cli_port' => 22,
            'cli_username' => 'admin2',
            'cli_password' => '',
        ]);

        $response->assertRedirect(route('smartolt.index'));

        $olt->refresh();

        $this->assertSame('OLT Baru', $olt->name);
        $this->assertSame('old-public', $olt->snmp_read_community);
        $this->assertSame('old-private', $olt->snmp_write_community);
        $this->assertSame('old-password', $olt->cli_password);
    }
}
