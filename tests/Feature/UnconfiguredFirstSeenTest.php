<?php

namespace Tests\Feature;

use App\Models\SnmpOlt;
use App\Models\User;
use App\Services\Snmp\OltSnmpClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnconfiguredFirstSeenTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_refresh_records_first_seen_and_puts_the_newest_onu_on_top(): void
    {
        $user = User::factory()->create();
        $olt = $this->makeOlt();

        $this->fakeDiscovery(['ZTEG0800AAAA'], ['ZTEG0800AAAA', 'ZTEG0800BBBB']);

        $this->travelTo(now()->setDateTime(2026, 9, 30, 8, 0));
        $this->actingAs($user)->post(route('smartolt.unconfigured.refresh', $olt))->assertRedirect();

        $this->travelTo(now()->setDateTime(2026, 9, 30, 10, 30));
        $this->actingAs($user)->post(route('smartolt.unconfigured.refresh', $olt))->assertRedirect();

        $this->actingAs($user)
            ->get(route('smartolt.unconfigured-all', ['olt_id' => $olt->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('snapshot.onus.0.serial_number', 'ZTEG0800BBBB')
                ->where('snapshot.onus.0.first_seen_baseline', false)
                ->where('snapshot.onus.0.first_seen_at', fn ($at) => str_starts_with($at, '2026-09-30T10:30'))
                ->where('snapshot.onus.1.serial_number', 'ZTEG0800AAAA')
                ->where('snapshot.onus.1.first_seen_baseline', true)
                ->where('snapshot.onus.1.first_seen_at', fn ($at) => str_starts_with($at, '2026-09-30T08:00')));
    }

    public function test_api_refresh_shares_the_same_first_seen_record(): void
    {
        $user = User::factory()->create();
        $olt = $this->makeOlt();

        $this->fakeDiscovery(['ZTEG0800AAAA'], ['ZTEG0800AAAA', 'ZTEG0800BBBB']);

        $this->travelTo(now()->setDateTime(2026, 9, 30, 8, 0));
        $this->actingAs($user)->post(route('smartolt.unconfigured.refresh', $olt))->assertRedirect();

        $this->travelTo(now()->setDateTime(2026, 9, 30, 10, 30));
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/olts/{$olt->id}/unconfigured/refresh")
            ->assertOk()
            ->assertJsonPath('data.onus.0.serial_number', 'ZTEG0800BBBB')
            ->assertJsonPath('data.onus.1.first_seen_baseline', true);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/v1/olts/{$olt->id}/unconfigured")
            ->assertOk()
            ->assertJsonPath('data.0.first_seen_baseline', false);
    }

    /**
     * Tiap panggilan discovery mengembalikan daftar SN berikutnya.
     */
    private function fakeDiscovery(array ...$rounds): void
    {
        $snapshots = array_map(fn (array $serials) => [
            'ok' => true,
            'count' => count($serials),
            'onus' => array_map(fn (string $sn) => [
                'serial_number' => $sn,
                'slot' => 3,
                'port' => 1,
                'oid_index' => '268632320.1',
                'suggested_onu_id' => 1,
            ], $serials),
            'latency_ms' => 12,
            'error' => null,
        ], $rounds);

        $this->mock(OltSnmpClient::class)
            ->shouldReceive('unconfiguredOnusSnapshot')
            ->andReturn(...$snapshots);
    }

    private function makeOlt(): SnmpOlt
    {
        return SnmpOlt::create([
            'name' => 'OLT-UJI',
            'vendor' => 'ZTE C300',
            'ip' => '10.10.10.6',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
        ]);
    }
}
