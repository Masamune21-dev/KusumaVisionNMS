<?php

namespace Tests\Feature;

use App\Models\AcsSetting;
use App\Models\OltConfigBackup;
use App\Models\SnmpOlt;
use App\Models\User;
use App\Services\Telegram\TelegramNotifier;
use App\Support\Telnet\TelnetTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Regresi pengerasan keamanan: kepemilikan OLT global yang di-assign ke partner,
 * tiket telnet sekali pakai, proxy tepercaya, mode demo di API bertoken, password
 * ACS tak dikirim ke klien, token Telegram tak masuk log, dan logo SVG ditolak.
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function makeOlt(string $name, string $ip, ?int $ownerId = null): SnmpOlt
    {
        $olt = SnmpOlt::create([
            'name' => $name,
            'vendor' => 'ZTE C320',
            'ip' => $ip,
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'cli_transport' => 'telnet',
            'cli_port' => 23,
            'cli_username' => 'zte',
            'cli_password' => 'rahasia-pusat',
        ]);

        // owner_user_id diset controller (bukan mass-assignment) → tiru lewat forceFill.
        if ($ownerId !== null) {
            $olt->forceFill(['owner_user_id' => $ownerId])->save();
        }

        return $olt;
    }

    /**
     * @return array{0: User, 1: SnmpOlt}
     */
    private function partnerWithAssignedGlobalOlt(): array
    {
        $global = $this->makeOlt('OLT-PUSAT', '10.9.0.1');
        $partner = User::factory()->partner()->create();
        $partner->partnerOlts()->sync([$global->id]);

        return [$partner, $global];
    }

    private function oltUpdatePayload(SnmpOlt $olt, array $override = []): array
    {
        return array_merge([
            'name' => $olt->name, 'vendor' => 'ZTE C320', 'ip' => $olt->ip,
            'snmp_port' => 161, 'snmp_version' => 'v2c', 'cli_transport' => 'telnet', 'cli_port' => 23,
            'cli_username' => 'zte',
        ], $override);
    }

    public function test_partner_cannot_change_ip_or_credentials_of_assigned_global_olt(): void
    {
        [$partner, $global] = $this->partnerWithAssignedGlobalOlt();

        $this->actingAs($partner)
            ->put(route('smartolt.update', $global), $this->oltUpdatePayload($global, ['ip' => '203.0.113.9']))
            ->assertStatus(403);

        $this->actingAs($partner)
            ->put(route('smartolt.update', $global), $this->oltUpdatePayload($global, ['cli_password' => 'milik-partner']))
            ->assertStatus(403);

        $this->actingAs($partner)
            ->put(route('smartolt.update', $global), $this->oltUpdatePayload($global, ['cli_port' => 2323]))
            ->assertStatus(403);

        $fresh = $global->fresh();
        $this->assertSame('10.9.0.1', $fresh->ip);
        $this->assertSame(23, (int) $fresh->cli_port);
        $this->assertSame('rahasia-pusat', $fresh->cli_password);

        // Kolom non-koneksi tetap boleh (nama) — IP/port sama seperti tersimpan.
        $this->actingAs($partner)
            ->put(route('smartolt.update', $global), $this->oltUpdatePayload($global, ['name' => 'OLT-PUSAT-RENAMED']))
            ->assertRedirect();
        $this->assertDatabaseHas('snmp_olts', ['id' => $global->id, 'name' => 'OLT-PUSAT-RENAMED', 'ip' => '10.9.0.1']);
    }

    public function test_partner_can_change_ip_of_own_private_olt_and_admin_unrestricted(): void
    {
        $partner = User::factory()->partner()->create();
        $own = $this->makeOlt('OLT-MITRA', '10.9.5.1', $partner->id);

        $this->actingAs($partner)
            ->put(route('smartolt.update', $own), $this->oltUpdatePayload($own, ['ip' => '10.9.5.2']))
            ->assertRedirect();
        $this->assertDatabaseHas('snmp_olts', ['id' => $own->id, 'ip' => '10.9.5.2']);

        $global = $this->makeOlt('OLT-PUSAT', '10.9.0.1');
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)
            ->put(route('smartolt.update', $global), $this->oltUpdatePayload($global, ['ip' => '10.9.0.99']))
            ->assertRedirect();
        $this->assertDatabaseHas('snmp_olts', ['id' => $global->id, 'ip' => '10.9.0.99']);
    }

    public function test_partner_cannot_test_telnet_or_read_backup_of_assigned_global_olt(): void
    {
        [$partner, $global] = $this->partnerWithAssignedGlobalOlt();

        $this->actingAs($partner)->post(route('smartolt.test', $global))->assertStatus(403);
        $this->actingAs($partner)->postJson(route('smartolt.telnet.token', $global))->assertStatus(403);

        $backup = OltConfigBackup::create([
            'snmp_olt_id' => $global->id,
            'content' => "enable\nsecret pusat\n",
            'size_bytes' => 20,
            'sha256' => hash('sha256', 'x'),
            'trigger' => 'manual',
            'status' => 'ok',
            'captured_at' => now(),
        ]);

        $this->actingAs($partner)->getJson(route('smartolt.config-backups.content', [$global, $backup]))->assertStatus(403);
        $this->actingAs($partner)->get(route('smartolt.config-backups.download', [$global, $backup]))->assertStatus(403);

        // Daftar riwayat (tanpa isi) tetap boleh dilihat.
        $this->actingAs($partner)->get(route('smartolt.config-backups.index', $global))->assertOk();

        // Staf Pusat tetap boleh.
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->getJson(route('smartolt.config-backups.content', [$global, $backup]))->assertOk();
    }

    public function test_telnet_ticket_is_single_use_and_bound_to_cache(): void
    {
        $token = TelnetTicket::issue(7, 3);

        $this->assertSame(['u' => 7, 'o' => 3], array_intersect_key(TelnetTicket::verify($token), ['u' => 1, 'o' => 1]));
        $this->assertNotNull(TelnetTicket::consume($token));
        $this->assertNull(TelnetTicket::consume($token), 'tiket harus hangus setelah dikonsumsi');
        $this->assertNull(TelnetTicket::verify($token));
    }

    public function test_x_forwarded_for_is_ignored_unless_from_trusted_proxy(): void
    {
        Route::get('/_test/ip', fn (Request $r) => $r->ip());

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->withHeader('X-Forwarded-For', '1.2.3.4')
            ->get('/_test/ip')
            ->assertOk()
            ->assertSee('203.0.113.7');

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->withHeader('X-Forwarded-For', '1.2.3.4')
            ->get('/_test/ip')
            ->assertOk()
            ->assertSee('1.2.3.4');
    }

    public function test_trusted_proxies_are_configurable(): void
    {
        // Mis. Cloudflare / load balancer di host lain yang diisi lewat TRUSTED_PROXIES.
        config()->set('trustedproxy.proxies', '127.0.0.1,198.51.100.0/24');
        Route::get('/_test/ip', fn (Request $r) => $r->ip());

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->withHeader('X-Forwarded-For', '1.2.3.4')
            ->get('/_test/ip')
            ->assertOk()
            ->assertSee('1.2.3.4');
    }

    public function test_demo_user_cannot_write_via_token_api(): void
    {
        $demo = User::factory()->demo()->create();
        $this->withToken($demo->createToken('apk')->plainTextToken);

        $this->postJson('/api/v1/devices', ['token' => 'fcm-token-demo'])
            ->assertStatus(403)
            ->assertJsonFragment(['message' => 'Mode demo bersifat read-only.']);
        $this->assertDatabaseMissing('fcm_device_tokens', ['token' => 'fcm-token-demo']);

        // Baca tetap boleh; logout tetap boleh.
        $this->getJson('/api/v1/olts')->assertOk();
        $this->postJson('/api/v1/auth/logout')->assertOk();
    }

    public function test_acs_password_is_filled_by_server_not_sent_to_client(): void
    {
        config()->set('services.acs.password', 'sandi-acs-server');

        // Form mengirim kosong → server mengisi dari Pengaturan ACS.
        $this->assertSame('sandi-acs-server', AcsSetting::fillPassword(['acs_password' => ''])['acs_password']);
        // Operator sengaja mengetik password lain → dipakai apa adanya.
        $this->assertSame('ketik-sendiri', AcsSetting::fillPassword(['acs_password' => 'ketik-sendiri'])['acs_password']);
    }

    public function test_telegram_bot_token_is_redacted_from_error_messages(): void
    {
        $message = 'cURL error 28: Connection timed out for https://api.telegram.org/bot123456:AAHk-9_xYz/sendMessage';

        $redacted = TelegramNotifier::redactToken($message);

        $this->assertStringNotContainsString('AAHk-9_xYz', $redacted);
        $this->assertStringContainsString('bot<token-disensor>/sendMessage', $redacted);
    }

    public function test_svg_logo_upload_is_rejected(): void
    {
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('settings.general.update'), [
                'app_name' => 'NMS',
                'app_version' => '1.0',
                'logo' => $svg,
            ])
            ->assertSessionHasErrors('logo');
    }
}
