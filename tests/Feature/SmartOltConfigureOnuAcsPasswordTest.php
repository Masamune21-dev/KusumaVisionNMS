<?php

namespace Tests\Feature;

use App\Models\AcsSetting;
use App\Models\SnmpOlt;
use App\Models\User;
use App\Services\ZteCliProvisioningExecutor;
use App\Services\ZteOnuRunningConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Halaman Configure ONU (CLI) tak pernah mengirim sandi ACS ke browser — baik dari config hasil
 * parse, teks running-config mentah, maupun skrip/keluaran CLI. Saat baris ACS harus ditulis
 * ulang tanpa sandi, server mengisinya: sandi ACS Pengaturan bila URL-nya sama, atau sandi yang
 * terpasang di ONU bila URL-nya sama dengan running-config.
 */
class SmartOltConfigureOnuAcsPasswordTest extends TestCase
{
    use RefreshDatabase;

    private const SETTINGS_SECRET = 'rahasiaAcs0800';

    private const ONU_SECRET = 'sandiLama0800';

    protected function setUp(): void
    {
        parent::setUp();

        AcsSetting::create([
            'url' => 'http://acs.contoh.test:7547',
            'username' => 'acspusat',
            'password' => self::SETTINGS_SECRET,
        ]);
    }

    private function makeOlt(): SnmpOlt
    {
        return SnmpOlt::create([
            'name' => 'OLT-UJI-C300',
            'vendor' => 'ZTE C300',
            'ip' => '10.30.0.33',
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'cli_transport' => 'telnet',
            'cli_port' => 23,
            'cli_username' => 'admin',
            'cli_password' => 'secret',
        ]);
    }

    private function raw(string $state = 'unlock'): string
    {
        return implode("\n", [
            'interface gpon-onu_1/3/16:51',
            '  name Uji-0800 Warung',
            '!',
            'pon-onu-mng gpon-onu_1/3/16:51',
            "  tr069-mgmt 1 state {$state}",
            '  tr069-mgmt 1 acs http://acs.lama.test:7547 validate basic username lama password '.self::ONU_SECRET,
            '!',
        ]);
    }

    /**
     * @param  list<string>  $raws  running-config berurutan yang "dibaca" dari OLT
     */
    private function fakeOlt(array $raws): object
    {
        $executor = new class extends ZteCliProvisioningExecutor
        {
            /** @var list<string> */
            public array $scripts = [];

            public function execute(SnmpOlt $olt, string $script, bool $largeOutput = false): array
            {
                $this->scripts[] = $script;

                // OLT menggemakan perintah yang diketik — termasuk sandinya.
                return ['ok' => true, 'error' => null, 'output' => "OLT-C300(config)# {$script}"];
            }
        };

        $service = new class($executor, $raws) extends ZteOnuRunningConfigService
        {
            public int $reads = 0;

            public function __construct(ZteCliProvisioningExecutor $executor, private array $raws)
            {
                parent::__construct($executor);
            }

            public function fetch(SnmpOlt $olt, int $slot, int $port, int $onuId): array
            {
                $raw = $this->raws[min($this->reads, count($this->raws) - 1)];
                $this->reads++;

                return ['ok' => true, 'error' => null, 'raw' => $raw, 'config' => $this->parse($raw)];
            }
        };

        $this->app->instance(ZteCliProvisioningExecutor::class, $executor);
        $this->app->instance(ZteOnuRunningConfigService::class, $service);

        return $executor;
    }

    /**
     * Baseline seperti yang diterima browser (sandi sudah dikosongkan server).
     *
     * @return array<string, mixed>
     */
    private function browserBaseline(string $state = 'unlock'): array
    {
        $config = app(ZteOnuRunningConfigService::class)->parse($this->raw($state));

        return [...$config, 'acs_password' => null, 'acs_password_set' => true];
    }

    private function assertNoSecret(string $content): void
    {
        $this->assertStringNotContainsString(self::ONU_SECRET, $content);
        $this->assertStringNotContainsString(self::SETTINGS_SECRET, $content);
    }

    public function test_form_never_sends_the_acs_password_to_the_browser(): void
    {
        $this->fakeOlt([$this->raw()]);

        $response = $this->actingAs(User::factory()->admin()->create())
            ->get(route('smartolt.onu.configure', [$this->makeOlt(), 3, 16, 51]));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('SmartOlt/ConfigureOnu')
            ->where('config.acs_url', 'http://acs.lama.test:7547')
            ->where('config.acs_password', null)
            ->where('config.acs_password_set', true)
            ->where('raw', fn ($raw) => str_contains($raw, 'password ********')));
        $this->assertNoSecret($response->getContent());
    }

    public function test_switching_to_the_settings_acs_fills_its_password_on_the_server(): void
    {
        $executor = $this->fakeOlt([$this->raw()]);
        $baseline = $this->browserBaseline();

        $response = $this->actingAs(User::factory()->admin()->create())
            ->postJson(route('smartolt.onu.configure.item', [$this->makeOlt(), 3, 16, 51]), [
                'baseline' => $baseline,
                'config' => [...$baseline, 'acs_url' => 'http://acs.contoh.test:7547', 'acs_username' => 'acspusat', 'acs_password' => ''],
            ]);

        $response->assertOk()->assertJsonPath('ok', true)->assertJsonPath('config.acs_password_set', true);
        $this->assertNoSecret($response->getContent());
        $this->assertStringContainsString('password ********', (string) $response->json('script'));

        $this->assertCount(1, $executor->scripts);
        $this->assertStringContainsString(
            'tr069-mgmt 1 acs http://acs.contoh.test:7547 validate basic username acspusat password '.self::SETTINGS_SECRET,
            $executor->scripts[0],
        );
        $this->assertDatabaseHas('smartolt_onu_registrations', ['onu_id' => 51, 'status' => 'reconfigured']);
    }

    public function test_enabling_tr069_again_keeps_the_password_already_on_the_onu(): void
    {
        $executor = $this->fakeOlt([$this->raw('lock')]);
        $baseline = $this->browserBaseline('lock');

        $response = $this->actingAs(User::factory()->admin()->create())
            ->postJson(route('smartolt.onu.configure.item', [$this->makeOlt(), 3, 16, 51]), [
                'baseline' => $baseline,
                'config' => [...$baseline, 'tr069' => true],
            ]);

        $response->assertOk()->assertJsonPath('ok', true);
        $this->assertNoSecret($response->getContent());
        $this->assertStringContainsString('password '.self::ONU_SECRET, $executor->scripts[0]);
    }

    public function test_an_unknown_acs_url_without_a_password_is_refused(): void
    {
        $executor = $this->fakeOlt([$this->raw()]);
        $baseline = $this->browserBaseline();

        $this->actingAs(User::factory()->admin()->create())
            ->postJson(route('smartolt.onu.configure.item', [$this->makeOlt(), 3, 16, 51]), [
                'baseline' => $baseline,
                'config' => [...$baseline, 'acs_url' => 'http://acs.lain.test:7547', 'acs_password' => ''],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'acs_password_required');

        $this->assertSame([], $executor->scripts);
    }

    public function test_a_typed_password_is_used_but_never_echoed(): void
    {
        $executor = $this->fakeOlt([$this->raw()]);
        $baseline = $this->browserBaseline();

        $response = $this->actingAs(User::factory()->admin()->create())
            ->postJson(route('smartolt.onu.configure.item', [$this->makeOlt(), 3, 16, 51]), [
                'baseline' => $baseline,
                'config' => [...$baseline, 'acs_url' => 'http://acs.lain.test:7547', 'acs_password' => 'sandiBaru0800'],
            ]);

        $response->assertOk();
        $this->assertStringNotContainsString('sandiBaru0800', $response->getContent());
        $this->assertStringContainsString('password sandiBaru0800', $executor->scripts[0]);
    }

    public function test_full_form_apply_fills_the_password_or_refuses(): void
    {
        $executor = $this->fakeOlt([$this->raw()]);
        $baseline = $this->browserBaseline();
        $olt = $this->makeOlt();
        $admin = User::factory()->admin()->create();
        $route = route('smartolt.onu.configure.apply', [$olt, 3, 16, 51]);

        $this->actingAs($admin)
            ->post($route, ['baseline' => $baseline, 'config' => [...$baseline, 'acs_url' => 'http://acs.lain.test:7547', 'acs_password' => '']])
            ->assertRedirect()
            ->assertSessionHas('error', __('flash.acs_password_required'));
        $this->assertSame([], $executor->scripts);

        $this->actingAs($admin)
            ->post($route, ['baseline' => $baseline, 'config' => [...$baseline, 'acs_url' => 'http://acs.contoh.test:7547', 'acs_password' => '']])
            ->assertRedirect()
            ->assertSessionHas('success');
        $this->assertStringContainsString('password '.self::SETTINGS_SECRET, $executor->scripts[0]);
    }

    public function test_preview_masks_the_password(): void
    {
        $this->fakeOlt([$this->raw()]);
        $baseline = $this->browserBaseline();

        $response = $this->actingAs(User::factory()->admin()->create())
            ->postJson(route('smartolt.onu.configure.preview', [$this->makeOlt(), 3, 16, 51]), [
                'baseline' => $baseline,
                'config' => [...$baseline, 'acs_url' => 'http://acs.contoh.test:7547', 'acs_password' => ''],
            ]);

        $response->assertOk();
        $this->assertStringContainsString('password ********', (string) $response->json('script'));
        $this->assertNoSecret($response->getContent());
    }

    public function test_unchanged_acs_writes_nothing_even_with_an_empty_password(): void
    {
        $executor = $this->fakeOlt([$this->raw()]);
        $baseline = $this->browserBaseline();

        $this->actingAs(User::factory()->admin()->create())
            ->postJson(route('smartolt.onu.configure.item', [$this->makeOlt(), 3, 16, 51]), [
                'baseline' => $baseline,
                'config' => [...$baseline, 'name' => 'Uji-0800 Toko'],
            ])
            ->assertOk();

        $this->assertCount(1, $executor->scripts);
        $this->assertStringNotContainsString('tr069-mgmt', $executor->scripts[0]);
    }
}
