<?php

namespace Tests\Feature;

use App\Models\AcsSetting;
use App\Models\SmartOltProfile;
use App\Models\SnmpOlt;
use App\Models\User;
use App\Services\Zte\OnuRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Password CWMP dari Pengaturan tidak boleh sampai ke partner.
 *
 * Server mengisikan password tersimpan bila form mengirimnya kosong ke script yang
 * DIEKSEKUSI, tapi setiap script yang dikirim ke browser (preview, riwayat registrasi,
 * pesan galat) menyamarkannya jadi `********` ({@see AcsSetting::maskScript()}). ACS di
 * Pengaturan hanya melayani OLT global non-demo ({@see AcsSetting::servesOlt()}): OLT
 * privat partner dan OLT demo tak pernah menerima URL/password-nya.
 */
class AcsPasswordPartnerTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'rahasiaAcs0800';

    protected function setUp(): void
    {
        parent::setUp();

        AcsSetting::create([
            'url' => 'http://acs.contoh.test:7547',
            'username' => 'acspusat',
            'password' => self::SECRET,
        ]);
    }

    private function makeOlt(?User $owner = null, bool $demo = false): SnmpOlt
    {
        $olt = SnmpOlt::create([
            'name' => $owner ? 'OLT-MITRA-ACS' : ($demo ? 'OLT-DEMO-ACS' : 'OLT-GLOBAL-ACS'),
            'vendor' => 'ZTE C320',
            'ip' => $owner ? '10.41.0.3' : ($demo ? '10.41.0.4' : '10.41.0.2'),
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'is_demo' => $demo,
            'last_test_result' => [
                'ok' => true,
                'system' => ['sys_descr' => 'ZTE ZXA10 C320'],
                'port_onus' => ['1_1' => ['slot' => 1, 'port' => 1, 'onus' => []]],
            ],
        ]);

        if ($owner) {
            $olt->forceFill(['owner_user_id' => $owner->id])->save();
            $olt->partners()->syncWithoutDetaching([$owner->id]);
        }

        foreach (['onu_type' => 'ALL-ONT', 'tcont' => 'SERVER'] as $type => $name) {
            SmartOltProfile::create([
                'snmp_olt_id' => $olt->id, 'profile_type' => $type, 'name' => $name, 'is_active' => true,
            ]);
        }

        return $olt;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'serial_number' => 'ZTEGC0800001',
            'slot' => 1, 'port' => 1, 'onu_id' => 6,
            'customer_name' => 'Uji',
            'onu_type' => 'ALL-ONT',
            'tcont_profile' => 'SERVER',
            'vlan' => 100,
            'service_name' => 'ServiceName',
            'wan_mode' => 'pppoe',
            'tr069_enabled' => true,
            'acs_url' => 'http://acs.contoh.test:7547',
            'acs_username' => 'acspusat',
            'acs_password' => '',
        ];
    }

    public function test_global_olt_script_is_filled_with_the_stored_password_but_preview_masks_it(): void
    {
        $olt = $this->makeOlt();

        $this->actingAs(User::factory()->admin()->create(), 'sanctum')
            ->postJson("/api/v1/olts/{$olt->id}/register/preview", $this->payload())
            ->assertOk()
            ->assertJsonPath('data.script', fn ($s) => str_contains($s, 'password ********') && ! str_contains($s, self::SECRET));

        // Script yang dieksekusi tetap memakai password asli dari server.
        $this->assertStringContainsString(self::SECRET, app(OnuRegistrationService::class)->buildScript($olt, $this->payload()));
    }

    public function test_partner_preview_on_its_own_olt_never_receives_the_stored_password(): void
    {
        $partner = User::factory()->partner()->create();
        $olt = $this->makeOlt($partner);

        // API: tanpa password tersimpan, form partner wajib membawa password ACS-nya sendiri.
        $api = $this->actingAs($partner, 'sanctum')
            ->postJson("/api/v1/olts/{$olt->id}/register/preview", $this->payload());
        $api->assertStatus(422);
        $this->assertStringNotContainsString(self::SECRET, $api->getContent());

        // Web: preview toleran form parsial — tetap membangun script, tapi tanpa password Pengaturan.
        $web = $this->actingAs($partner)
            ->postJson(route('smartolt.register.preview', $olt), $this->payload());
        $web->assertOk();
        $this->assertStringNotContainsString(self::SECRET, $web->getContent());

        // Script yang dieksekusi pun tak memuatnya.
        $this->assertStringNotContainsString(self::SECRET, app(OnuRegistrationService::class)->buildScript($olt, [
            ...$this->payload(),
            'acs_password' => 'sandiMitra0800',
        ]));
    }

    public function test_partner_assigned_to_a_global_olt_only_sees_a_masked_password(): void
    {
        $olt = $this->makeOlt();
        $partner = User::factory()->partner()->create();
        $olt->partners()->syncWithoutDetaching([$partner->id]);

        $response = $this->actingAs($partner)
            ->postJson(route('smartolt.register.preview', $olt), $this->payload());

        $response->assertOk();
        $this->assertStringContainsString('password ********', (string) $response->json('script'));
        $this->assertStringNotContainsString(self::SECRET, $response->getContent());
    }

    public function test_acs_target_is_empty_for_partner_and_demo_olts(): void
    {
        $partnerOlt = $this->makeOlt(User::factory()->partner()->create());
        $demoOlt = $this->makeOlt(demo: true);
        $globalOlt = $this->makeOlt();

        $empty = ['url' => '', 'username' => '', 'password' => ''];
        $this->assertSame($empty, AcsSetting::resolved($partnerOlt));
        $this->assertSame($empty, AcsSetting::resolved($demoOlt));
        $this->assertSame('http://acs.contoh.test:7547', AcsSetting::resolved($globalOlt)['url']);
    }

    public function test_partner_gets_no_acs_target_without_an_olt(): void
    {
        $this->actingAs(User::factory()->partner()->create());

        $this->assertSame(['url' => '', 'username' => '', 'password' => ''], AcsSetting::resolved());
    }

    public function test_tr069_bulk_refuses_an_olt_without_a_target(): void
    {
        $partner = User::factory()->partner()->create();
        $olt = $this->makeOlt($partner);

        // OLT privat partner tak dilayani ACS Pengaturan — TR069 Massal tak punya target.
        $this->actingAs($partner)
            ->postJson(route('smartolt.tr069-bulk', ['olt' => $olt->id, 'slot' => 1, 'port' => 1]), ['execute' => false])
            ->assertStatus(422);
    }

    public function test_partner_can_register_with_its_own_acs_typed_in_the_form(): void
    {
        $partner = User::factory()->partner()->create();
        $olt = $this->makeOlt($partner);

        $response = $this->actingAs($partner, 'sanctum')
            ->postJson("/api/v1/olts/{$olt->id}/register/preview", [
                ...$this->payload(),
                'acs_url' => 'http://acs.mitra.test:7547',
                'acs_username' => 'mitra',
                'acs_password' => 'sandiMitra0800',
            ]);

        $response->assertOk();
        $this->assertStringContainsString('password ********', (string) $response->json('data.script'));
        $this->assertStringNotContainsString('sandiMitra0800', $response->getContent());
        $this->assertStringNotContainsString(self::SECRET, $response->getContent());
    }

    public function test_mask_keeps_an_empty_password_from_swallowing_the_next_token(): void
    {
        $this->assertSame(
            'tr069-mgmt 1 acs http://a validate basic username u password ********',
            AcsSetting::maskScript('tr069-mgmt 1 acs http://a validate basic username u password s3cret'),
        );
        $this->assertSame(
            'acs http://a validate basic username u password  tag pri 0',
            AcsSetting::maskScript('acs http://a validate basic username u password  tag pri 0'),
        );
    }
}
