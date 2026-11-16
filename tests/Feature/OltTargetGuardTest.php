<?php

namespace Tests\Feature;

use App\Models\SnmpOlt;
use App\Models\User;
use App\Services\ZteCliProvisioningExecutor;
use App\Support\OltTargetGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Penjaga alamat tujuan OLT (NMS-1): server ini tak boleh dipakai sebagai jembatan ke
 * jaringan internal lewat kolom IP OLT. Lihat {@see OltTargetGuard}.
 *
 * Konfigurasi diuji lewat `config('olt.target_guard.*')` seperti yang dibaca dari
 * NMS_OLT_PARTNER_CIDRS / NMS_OLT_BLOCKED_CIDRS.
 */
class OltTargetGuardTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private const PARTNER_RANGE = ['100.64.10.0/24'];

    private function allowPartnerRange(): void
    {
        config(['olt.target_guard.partner_cidrs' => self::PARTNER_RANGE]);
    }

    private function makeOlt(string $ip, ?int $ownerId = null): SnmpOlt
    {
        $olt = SnmpOlt::create([
            'name' => 'OLT-UJI',
            'vendor' => 'ZTE C320',
            'ip' => $ip,
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'cli_transport' => 'telnet',
            'cli_username' => 'admin',
            'cli_password' => 'uji',
        ]);

        if ($ownerId !== null) {
            // owner_user_id sengaja tak fillable (tak bisa dipalsukan dari request).
            $olt->forceFill(['owner_user_id' => $ownerId])->save();
        }

        return $olt->refresh();
    }

    /**
     * Pesan galat dari assertAllowed() tanpa menelan kegagalan assertion PHPUnit
     * (PHPUnit\Framework\Exception juga turunan RuntimeException).
     */
    private function assertAllowedMessage(SnmpOlt $olt): string
    {
        try {
            OltTargetGuard::assertAllowed($olt);
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        $this->fail('alamat OLT ini seharusnya ditolak');
    }

    public function test_olah_alamat_yang_tak_pernah_jadi_olt_ditolak_untuk_semua(): void
    {
        foreach (['0.0.0.0', '0.1.2.3', '127.0.0.1', '169.254.169.254', '224.0.0.1', '240.0.0.1'] as $ip) {
            $this->assertFalse(OltTargetGuard::allowsAddress($ip, null), "{$ip} harus ditolak untuk OLT Pusat");
            $this->assertFalse(OltTargetGuard::allowsAddress($ip, 7), "{$ip} harus ditolak untuk OLT partner");
        }
    }

    public function test_olt_pusat_bebas_di_luar_daftar_terlarang(): void
    {
        foreach (['10.20.30.40', '192.168.10.2', '172.16.5.5', '203.0.113.9'] as $ip) {
            $this->assertTrue(OltTargetGuard::allowsAddress($ip, null), "{$ip} harus boleh untuk OLT Pusat");
        }
    }

    public function test_daftar_terlarang_tambahan_menutup_alamat_infrastruktur(): void
    {
        config(['olt.target_guard.blocked_cidrs' => ['10.99.0.0/16']]);

        $this->assertFalse(OltTargetGuard::allowsAddress('10.99.1.5', null));
        $this->assertTrue(OltTargetGuard::allowsAddress('10.98.1.5', null));
    }

    public function test_olt_partner_ditolak_bila_daftar_jalur_partner_kosong(): void
    {
        config(['olt.target_guard.partner_cidrs' => []]);

        $this->assertFalse(OltTargetGuard::allowsAddress('100.64.10.10', 7));
        $this->assertTrue(OltTargetGuard::allowsAddress('100.64.10.10', null), 'staf Pusat tak ikut dibatasi');
    }

    public function test_olt_partner_hanya_di_dalam_daftar_jalur(): void
    {
        $this->allowPartnerRange();

        $this->assertTrue(OltTargetGuard::allowsAddress('100.64.10.10', 7));
        $this->assertFalse(OltTargetGuard::allowsAddress('100.64.11.10', 7));
        $this->assertFalse(OltTargetGuard::allowsAddress('10.9.9.9', 7));
    }

    public function test_ipv6_loopback_dan_link_local_ditolak(): void
    {
        $this->assertFalse(OltTargetGuard::allowsAddress('::1', null));
        $this->assertFalse(OltTargetGuard::allowsAddress('::', null));
        $this->assertFalse(OltTargetGuard::allowsAddress('fe80::1', null));
        $this->assertFalse(OltTargetGuard::allowsAddress('::ffff:127.0.0.1', null));
        $this->assertTrue(OltTargetGuard::allowsAddress('::ffff:10.0.0.5', null));
        $this->assertFalse(OltTargetGuard::allowsAddress('2001:db8::1', 7), 'OLT partner selalu IPv4');
    }

    public function test_assert_allowed_menahan_baris_lama_yang_tersimpan_sebelum_aturan(): void
    {
        $this->allowPartnerRange();

        $legacy = $this->makeOlt('10.9.9.9', 7);
        $this->assertFalse(OltTargetGuard::allows($legacy));
        $this->expectException(RuntimeException::class);
        OltTargetGuard::assertAllowed($legacy);
    }

    public function test_assert_allowed_pesan_partner_berbeda_dari_pusat(): void
    {
        config(['olt.target_guard.partner_cidrs' => []]);

        $this->assertSame(__('olt.target_not_allowed'), $this->assertAllowedMessage($this->makeOlt('127.0.0.1')));
        $this->assertSame(__('olt.target_partner_range'), $this->assertAllowedMessage($this->makeOlt('10.9.9.9', 7)));
    }

    public function test_klien_cli_menolak_olt_di_luar_rentang_sebelum_membuka_soket(): void
    {
        $olt = $this->makeOlt('127.0.0.1');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(__('olt.target_not_allowed'));

        app(ZteCliProvisioningExecutor::class)->execute($olt, 'show version');
    }

    public function test_form_olt_pusat_menolak_alamat_server_sendiri(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->post(route('smartolt.store'), [
                'name' => 'OLT-LOOPBACK',
                'vendor' => 'ZTE C320',
                'ip' => '127.0.0.1',
                'snmp_port' => 161,
                'snmp_read_community' => 'public',
                'snmp_version' => 'v2c',
            ])
            ->assertSessionHasErrors('ip');

        $this->assertSame(0, SnmpOlt::where('name', 'OLT-LOOPBACK')->count());
    }

    public function test_form_olt_partner_menolak_alamat_di_luar_jalur_partner(): void
    {
        config(['olt.target_guard.partner_cidrs' => []]);

        $partner = User::factory()->partner()->create();

        $this->actingAs($partner)
            ->post(route('smartolt.store'), [
                'name' => 'OLT-PARTNER-LUAR',
                'vendor' => 'ZTE C320',
                'ip' => '10.9.9.9',
                'snmp_port' => 161,
                'snmp_read_community' => 'public',
                'snmp_version' => 'v2c',
            ])
            ->assertSessionHasErrors('ip');

        $this->assertSame(0, SnmpOlt::where('name', 'OLT-PARTNER-LUAR')->count());
    }

    public function test_form_olt_partner_menerima_alamat_di_dalam_jalur_partner(): void
    {
        $this->allowPartnerRange();

        $partner = User::factory()->partner()->create();

        $this->actingAs($partner)
            ->post(route('smartolt.store'), [
                'name' => 'OLT-PARTNER-DALAM',
                'vendor' => 'ZTE C320',
                'ip' => '100.64.10.77',
                'snmp_port' => 161,
                'snmp_read_community' => 'public',
                'snmp_version' => 'v2c',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, SnmpOlt::where('name', 'OLT-PARTNER-DALAM')->count());
    }
}
