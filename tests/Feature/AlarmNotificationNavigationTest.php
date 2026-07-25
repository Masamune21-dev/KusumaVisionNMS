<?php

namespace Tests\Feature;

use App\Models\AlarmEvent;
use App\Models\AlarmNotificationRead;
use App\Models\SnmpOlt;
use App\Models\User;
use App\Services\Alarm\AlarmNotificationService;
use App\Services\Alarm\AlarmNotificationTargetResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Navegación contextual desde la campana de notificaciones.
 *
 * El destino lo resuelve el SERVIDOR a partir de columnas estructuradas de `alarm_events`
 * (nunca del texto de `message`), verificando permiso en el momento del clic y siguiendo
 * a la ONU si cambió de puerto.
 */
class AlarmNotificationNavigationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<int, array<string, mixed>>  $onus
     */
    private function makeOlt(string $vendor, string $sysDescr, array $onus = [], string $slotPortKey = '1_1'): SnmpOlt
    {
        return SnmpOlt::create([
            'name' => 'OLT-'.$vendor,
            'vendor' => $vendor,
            'ip' => '10.60.0.'.random_int(2, 250),
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'last_test_result' => [
                'ok' => true,
                'system' => ['sys_descr' => $sysDescr],
                'port_onus' => [$slotPortKey => ['onus' => $onus]],
            ],
        ]);
    }

    private function onu(int $slot, int $port, int $onuId, string $serial): array
    {
        return [
            'slot' => $slot, 'port' => $port, 'onu_id' => $onuId,
            'serial_number' => $serial, 'online' => false,
        ];
    }

    private function alarm(SnmpOlt $olt, array $over = []): AlarmEvent
    {
        return AlarmEvent::create(array_merge([
            'snmp_olt_id' => $olt->id,
            'signature' => 'sig-'.random_int(1000, 999999),
            'type' => AlarmEvent::TYPE_ONU_OFFLINE,
            'severity' => AlarmEvent::SEVERITY_MAJOR,
            'status' => AlarmEvent::STATUS_ACTIVE,
            'scope' => 'onu',
            'slot' => 1, 'port' => 1, 'onu_id' => 5,
            'serial_number' => 'ZTEGC0001',
            'message' => 'texto localizado que NUNCA debe parsearse',
            'first_seen_at' => now()->subHour(),
            'last_seen_at' => now(),
        ], $over));
    }

    private function resolver(): AlarmNotificationTargetResolver
    {
        return app(AlarmNotificationTargetResolver::class);
    }

    // ---------- Resolver: ZTE ----------

    public function test_zte_onu_alarm_resolves_to_onu_detail(): void
    {
        $olt = $this->makeOlt('ZTE C320', 'ZTE ZXA10 C320', [$this->onu(1, 1, 5, 'ZTEGC0001')]);
        $alarm = $this->alarm($olt);

        $target = $this->resolver()->resolve($alarm);

        $this->assertSame("/smartolt/{$olt->id}/ports/1/1/onus/5/detail", $target['url']);
        $this->assertNull($target['reason']);
    }

    public function test_zte_c600_onu_alarm_resolves_to_onu_detail(): void
    {
        // C600 también es DRIVER_ZTE → supports_cli_onu_detail = true.
        $olt = $this->makeOlt('ZTE C600', 'ZTE ZXA10 C600', [$this->onu(3, 13, 37, 'ZTEGC1F9E618')], '3_13');
        $alarm = $this->alarm($olt, ['slot' => 3, 'port' => 13, 'onu_id' => 37, 'serial_number' => 'ZTEGC1F9E618']);

        $target = $this->resolver()->resolve($alarm);

        $this->assertSame("/smartolt/{$olt->id}/ports/3/13/onus/37/detail", $target['url']);
    }

    // ---------- Resolver: no-ZTE (sin página de detalle individual) ----------

    public function test_cdata_onu_alarm_resolves_to_port_page_with_focus(): void
    {
        $olt = $this->makeOlt('C-Data FD1208S', 'EPON OLT', [$this->onu(1, 1, 5, 'CD0001')]);
        $alarm = $this->alarm($olt, ['serial_number' => 'CD0001']);

        $target = $this->resolver()->resolve($alarm);

        $this->assertStringContainsString("/cdata-olt/{$olt->id}/ports/1/1/onus", $target['url']);
        $this->assertStringContainsString('focus=5', $target['url']);
    }

    public function test_hioso_onu_alarm_resolves_to_port_page_with_focus(): void
    {
        $olt = $this->makeOlt('HIOSO HA7304', 'HIOSO OLT HA7304', [$this->onu(1, 1, 5, 'HS0001')]);
        $alarm = $this->alarm($olt, ['serial_number' => 'HS0001']);

        $target = $this->resolver()->resolve($alarm);

        $this->assertStringContainsString("/hioso-olt/{$olt->id}/ports/1/1/onus", $target['url']);
        $this->assertStringContainsString('focus=5', $target['url']);
    }

    // ---------- Resolver: scope port / olt ----------

    public function test_port_alarm_resolves_to_port_page(): void
    {
        $olt = $this->makeOlt('ZTE C320', 'ZTE ZXA10 C320');
        $alarm = $this->alarm($olt, ['scope' => 'port', 'type' => AlarmEvent::TYPE_PORT_DOWN, 'onu_id' => null, 'serial_number' => null]);

        $target = $this->resolver()->resolve($alarm);

        $this->assertSame("/smartolt/{$olt->id}/ports/1/1/onus", $target['url']);
    }

    public function test_olt_alarm_resolves_to_olt_detail(): void
    {
        $olt = $this->makeOlt('ZTE C320', 'ZTE ZXA10 C320');
        $alarm = $this->alarm($olt, [
            'scope' => 'olt', 'type' => AlarmEvent::TYPE_OLT_UNREACHABLE,
            'slot' => null, 'port' => null, 'onu_id' => null, 'serial_number' => null,
        ]);

        $target = $this->resolver()->resolve($alarm);

        $this->assertSame("/smartolt/{$olt->id}/detail", $target['url']);
    }

    // ---------- Resolver: ONU movida / borrada / posición reusada ----------

    public function test_moved_onu_resolves_to_its_current_position_by_serial(): void
    {
        // La alarma quedó en 1/1/5, pero la ONU (mismo serial) vive ahora en 2/4/9.
        $olt = $this->makeOlt('ZTE C320', 'ZTE ZXA10 C320', [$this->onu(2, 4, 9, 'ZTEGC0001')], '2_4');
        $alarm = $this->alarm($olt);

        $target = $this->resolver()->resolve($alarm);

        $this->assertSame("/smartolt/{$olt->id}/ports/2/4/onus/9/detail", $target['url']);
        $this->assertSame(AlarmNotificationTargetResolver::REASON_ONU_MOVED, $target['reason']);
    }

    public function test_deleted_onu_falls_back_without_url(): void
    {
        $olt = $this->makeOlt('ZTE C320', 'ZTE ZXA10 C320'); // snapshot sin ONU
        $alarm = $this->alarm($olt);

        $target = $this->resolver()->resolve($alarm);

        $this->assertNull($target['url']);
        $this->assertSame(AlarmNotificationTargetResolver::REASON_ONU_NOT_FOUND, $target['reason']);
        $this->assertStringContainsString('/alarms', $target['fallback_url']);
    }

    public function test_reused_position_is_not_opened(): void
    {
        // Otra ONU (serial distinto) heredó 1/1/5 → abrirla mostraría el cliente equivocado.
        $olt = $this->makeOlt('ZTE C320', 'ZTE ZXA10 C320', [$this->onu(1, 1, 5, 'OTRA-ONU-9999')]);
        $alarm = $this->alarm($olt, ['serial_number' => 'ZTEGC0001']);

        $target = $this->resolver()->resolve($alarm);

        $this->assertNull($target['url']);
        $this->assertSame(AlarmNotificationTargetResolver::REASON_POSITION_REUSED, $target['reason']);
    }

    public function test_alarm_without_serial_uses_historic_position_when_present(): void
    {
        $olt = $this->makeOlt('ZTE C320', 'ZTE ZXA10 C320', [$this->onu(1, 1, 5, 'CUALQUIERA')]);
        $alarm = $this->alarm($olt, ['serial_number' => null]);

        $target = $this->resolver()->resolve($alarm);

        $this->assertSame("/smartolt/{$olt->id}/ports/1/1/onus/5/detail", $target['url']);
    }

    public function test_unknown_driver_onu_alarm_falls_back_to_port_page(): void
    {
        // Driver desconocido → sin capability de detalle → página de puerto (prefijo smartolt).
        $olt = $this->makeOlt('Marca Rara XYZ', 'algo no reconocido', [$this->onu(1, 1, 5, 'X1')]);
        $alarm = $this->alarm($olt, ['serial_number' => 'X1']);

        $target = $this->resolver()->resolve($alarm);

        $this->assertStringContainsString('focus=5', $target['url']);
        $this->assertStringContainsString("/smartolt/{$olt->id}/ports/1/1/onus", $target['url']);
    }

    // ---------- Endpoint open ----------

    public function test_open_endpoint_returns_target_and_marks_only_that_alarm_read(): void
    {
        $olt = $this->makeOlt('ZTE C320', 'ZTE ZXA10 C320', [$this->onu(1, 1, 5, 'ZTEGC0001')]);
        $alarm = $this->alarm($olt);
        $other = $this->alarm($olt, ['signature' => 'otra', 'onu_id' => 6, 'serial_number' => 'ZTEGC0002']);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->postJson(route('notifications.alarms.open', $alarm))
            ->assertOk()
            ->assertJsonPath('data.target_url', "/smartolt/{$olt->id}/ports/1/1/onus/5/detail")
            ->assertJsonPath('data.reason', null);

        // Solo esa alarma queda leída — la otra sigue sin leer.
        $this->assertDatabaseHas('alarm_notification_reads', [
            'user_id' => $admin->id, 'alarm_event_id' => $alarm->id,
        ]);
        $this->assertDatabaseMissing('alarm_notification_reads', [
            'user_id' => $admin->id, 'alarm_event_id' => $other->id,
        ]);

        // La alarma leída desaparece de /alarms; la otra permanece.
        $this->actingAs($admin)->get(route('alarms.index'))
            ->assertInertia(fn ($page) => $page
                ->where('alarms.total', 1)
                ->where('alarms.data.0.id', $other->id));
    }

    public function test_open_endpoint_reports_message_when_target_missing(): void
    {
        $olt = $this->makeOlt('ZTE C320', 'ZTE ZXA10 C320'); // ONU ya no existe
        $alarm = $this->alarm($olt);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->postJson(route('notifications.alarms.open', $alarm))
            ->assertOk()
            ->assertJsonPath('data.target_url', null)
            ->assertJsonPath('data.reason', AlarmNotificationTargetResolver::REASON_ONU_NOT_FOUND)
            ->assertJsonPath('data.message', __('flash.notif_onu_not_found'));
    }

    public function test_partner_cannot_open_alarm_of_foreign_olt(): void
    {
        $olt = $this->makeOlt('ZTE C320', 'ZTE ZXA10 C320', [$this->onu(1, 1, 5, 'ZTEGC0001')]);
        $alarm = $this->alarm($olt);
        $partner = User::factory()->partner()->create();

        // Route-model binding aplica PartnerOltScope → la alarma no existe para él.
        $this->actingAs($partner)
            ->postJson(route('notifications.alarms.open', $alarm))
            ->assertNotFound();

        $this->assertSame(0, AlarmNotificationRead::count());
    }

    // ---------- Lectura individual y contador ----------

    public function test_read_endpoint_marks_single_alarm(): void
    {
        $olt = $this->makeOlt('ZTE C320', 'ZTE ZXA10 C320');
        $alarm = $this->alarm($olt);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->postJson(route('notifications.alarms.read', $alarm))
            ->assertOk();

        $this->assertDatabaseHas('alarm_notification_reads', [
            'user_id' => $admin->id, 'alarm_event_id' => $alarm->id,
        ]);
    }

    public function test_unread_count_covers_all_active_alarms_not_just_the_bell_page(): void
    {
        $olt = $this->makeOlt('ZTE C320', 'ZTE ZXA10 C320');
        // 12 activas > las 8 que muestra la campana: el contador antes se quedaba en 8.
        for ($i = 1; $i <= 12; $i++) {
            $this->alarm($olt, ['signature' => "s{$i}", 'onu_id' => $i]);
        }
        $admin = User::factory()->admin()->create();

        $payload = app(AlarmNotificationService::class)->payloadFor($admin);

        $this->assertCount(8, $payload['items']);
        $this->assertSame(12, $payload['unread_count']);
    }

    public function test_mark_all_read_survives_the_poller_refreshing_last_seen_at(): void
    {
        // AlarmEvaluator refresca `last_seen_at` de toda alarma que sigue ACTIVA en cada poll
        // (~5 min). Con solo el timestamp global, el badge reaparecía a los minutos con las
        // mismas alarmas mientras la avería durara.
        $olt = $this->makeOlt('ZTE C320', 'ZTE ZXA10 C320');
        $a = $this->alarm($olt, ['signature' => 'a', 'onu_id' => 1]);
        $b = $this->alarm($olt, ['signature' => 'b', 'onu_id' => 2]);
        $admin = User::factory()->admin()->create();
        $service = app(AlarmNotificationService::class);

        $this->actingAs($admin)->post(route('notifications.read-all'))->assertRedirect();
        $this->assertSame(0, $service->unreadCountFor($admin->fresh()));

        // Cada alarma activa quedó con su propia fila (no solo el timestamp).
        $this->assertDatabaseHas('alarm_notification_reads', ['user_id' => $admin->id, 'alarm_event_id' => $a->id]);
        $this->assertDatabaseHas('alarm_notification_reads', ['user_id' => $admin->id, 'alarm_event_id' => $b->id]);

        // Simular el siguiente poll: la avería persiste → se refresca last_seen_at.
        $this->travel(6)->minutes();
        $a->forceFill(['last_seen_at' => now()])->save();
        $b->forceFill(['last_seen_at' => now()])->save();

        // Debe SEGUIR en cero: antes volvía a 2.
        $this->assertSame(0, $service->unreadCountFor($admin->fresh()));

        $payload = $service->payloadFor($admin->fresh());
        $this->assertSame(0, $payload['unread_count']);
        $this->assertTrue(collect($payload['items'])->every(fn ($i) => $i['is_read'] === true));
    }

    public function test_mark_all_read_only_covers_alarms_the_user_can_see(): void
    {
        // El upsert masivo no debe filtrar alarmas de OLT ajenos a un partner.
        $olt = $this->makeOlt('ZTE C320', 'ZTE ZXA10 C320');
        $this->alarm($olt, ['signature' => 'ajena', 'onu_id' => 1]);
        $partner = User::factory()->partner()->create();

        $this->actingAs($partner)->post(route('notifications.read-all'))->assertRedirect();

        $this->assertSame(0, AlarmNotificationRead::count());
    }

    public function test_individual_read_decrements_count_and_read_all_still_works(): void
    {
        $olt = $this->makeOlt('ZTE C320', 'ZTE ZXA10 C320');
        $first = $this->alarm($olt, ['signature' => 'a', 'onu_id' => 1]);
        $this->alarm($olt, ['signature' => 'b', 'onu_id' => 2]);
        $this->alarm($olt, ['signature' => 'c', 'onu_id' => 3]);
        $admin = User::factory()->admin()->create();
        $service = app(AlarmNotificationService::class);

        $this->assertSame(3, $service->unreadCountFor($admin));

        $service->markRead($first, $admin);
        $this->assertSame(2, $service->unreadCountFor($admin->fresh()));

        // La acción masiva histórica sigue funcionando.
        $this->actingAs($admin)->post(route('notifications.read-all'))->assertRedirect();
        $this->assertSame(0, $service->unreadCountFor($admin->fresh()));
    }

    // ---------- API v1: ubicación resuelta para deep-link móvil ----------

    public function test_api_alarms_expose_resolved_target_location(): void
    {
        // La ONU se movió: la alarma quedó en 1/1/5, hoy vive en 2/4/9.
        $olt = $this->makeOlt('ZTE C320', 'ZTE ZXA10 C320', [$this->onu(2, 4, 9, 'ZTEGC0001')], '2_4');
        $this->alarm($olt);
        $operator = User::factory()->create();

        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/v1/alarms')
            ->assertOk()
            // Los IDs planos siguen siendo los HISTÓRICOS (registro del evento)…
            ->assertJsonPath('data.0.slot', 1)
            ->assertJsonPath('data.0.onu_id', 5)
            // …y `target` trae la posición ACTUAL, que es la que el móvil debe usar.
            ->assertJsonPath('data.0.target.resource_type', 'onu')
            ->assertJsonPath('data.0.target.slot', 2)
            ->assertJsonPath('data.0.target.port', 4)
            ->assertJsonPath('data.0.target.onu_id', 9)
            ->assertJsonPath('data.0.target.openable', true)
            ->assertJsonPath('data.0.target.reason', AlarmNotificationTargetResolver::REASON_ONU_MOVED);
    }

    public function test_api_alarms_mark_reused_position_as_not_openable(): void
    {
        // Otra ONU heredó 1/1/5 → el móvil no debe abrirla (mostraría otro cliente).
        $olt = $this->makeOlt('ZTE C320', 'ZTE ZXA10 C320', [$this->onu(1, 1, 5, 'OTRA-9999')]);
        $this->alarm($olt, ['serial_number' => 'ZTEGC0001']);
        $operator = User::factory()->create();

        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/v1/alarms')
            ->assertOk()
            ->assertJsonPath('data.0.target.openable', false)
            ->assertJsonPath('data.0.target.reason', AlarmNotificationTargetResolver::REASON_POSITION_REUSED)
            ->assertJsonPath('data.0.target.onu_id', null);
    }

    public function test_api_alarms_target_for_non_zte_is_openable_without_web_detail_capability(): void
    {
        // El móvil tiene su propia pantalla de detalle (vía API) para todas las familias,
        // así que `openable` no depende de la capability web `supports_cli_onu_detail`.
        $olt = $this->makeOlt('HIOSO HA7304', 'HIOSO OLT HA7304', [$this->onu(1, 1, 5, 'HS0001')]);
        $this->alarm($olt, ['serial_number' => 'HS0001']);
        $operator = User::factory()->create();

        $this->actingAs($operator, 'sanctum')
            ->getJson('/api/v1/alarms')
            ->assertOk()
            ->assertJsonPath('data.0.target.openable', true)
            ->assertJsonPath('data.0.target.onu_id', 5);
    }

    public function test_resolver_reuses_one_snapshot_scan_per_olt(): void
    {
        // Muchas alarmas del mismo OLT en una sola petición deben compartir el índice
        // serial→posición (memoizado), no re-escanear el snapshot por cada alarma.
        $onus = [];
        for ($i = 1; $i <= 30; $i++) {
            $onus[] = $this->onu(1, 1, $i, "SN{$i}");
        }
        $olt = $this->makeOlt('ZTE C320', 'ZTE ZXA10 C320', $onus);

        $resolver = $this->resolver();
        for ($i = 1; $i <= 30; $i++) {
            $alarm = $this->alarm($olt, ['signature' => "s{$i}", 'onu_id' => $i, 'serial_number' => "SN{$i}"]);
            $location = $resolver->resolveLocation($alarm);
            $this->assertTrue($location['openable']);
            $this->assertSame($i, $location['onu_id']);
        }
    }

    public function test_power_off_disappears_when_read(): void
    {
        $olt = $this->makeOlt('ZTE C320', 'ZTE ZXA10 C320');
        $powerOff = $this->alarm($olt, [
            'type' => AlarmEvent::TYPE_DYING_GASP,
            'signature' => 'power-off',
        ]);
        $admin = User::factory()->admin()->create();
        $service = app(AlarmNotificationService::class);

        $service->markRead($powerOff, $admin);
        $payload = $service->payloadFor($admin->fresh());

        $this->assertSame([], $payload['items']);
        $this->assertSame(0, $payload['unread_count']);
    }

    public function test_operational_alarms_remain_visible_when_read_until_recovery(): void
    {
        $olt = $this->makeOlt('ZTE C320', 'ZTE ZXA10 C320');
        $admin = User::factory()->admin()->create();
        $service = app(AlarmNotificationService::class);
        $persistent = collect([
            [AlarmEvent::TYPE_HIGH_RX, 'high-rx', 'onu'],
            [AlarmEvent::TYPE_PORT_DOWN, 'port-down', 'port'],
            [AlarmEvent::TYPE_LOS, 'los', 'onu'],
            [AlarmEvent::TYPE_OLT_UNREACHABLE, 'olt-down', 'olt'],
        ])->map(fn ($definition, $index) => $this->alarm($olt, [
            'type' => $definition[0],
            'signature' => $definition[1],
            'scope' => $definition[2],
            'last_seen_at' => now()->subSeconds($index),
        ]));

        $this->actingAs($admin)->post(route('notifications.read-all'))->assertRedirect();
        $payload = $service->payloadFor($admin->fresh());

        $this->assertSame(0, $payload['unread_count']);
        $this->assertEqualsCanonicalizing(
            $persistent->pluck('id')->all(),
            collect($payload['items'])->pluck('id')->all(),
        );
        $this->assertTrue(collect($payload['items'])->every(
            fn ($item) => $item['is_read'] === true
                && $item['persistent_until_recovery'] === true
                && $item['dismiss_on_read'] === false
        ));

        AlarmEvent::query()->whereIn('id', $persistent->pluck('id'))->update([
            'status' => AlarmEvent::STATUS_CLEARED,
            'cleared_at' => now(),
        ]);

        $this->assertSame([], $service->payloadFor($admin->fresh())['items']);
    }

    public function test_unread_alarms_have_priority_over_read_persistent_alarms(): void
    {
        $olt = $this->makeOlt('ZTE C320', 'ZTE ZXA10 C320');
        $admin = User::factory()->admin()->create();
        $service = app(AlarmNotificationService::class);
        $persistent = $this->alarm($olt, [
            'type' => AlarmEvent::TYPE_LOS,
            'signature' => 'read-los',
        ]);
        $service->markRead($persistent, $admin);

        for ($i = 1; $i <= 8; $i++) {
            $this->alarm($olt, [
                'signature' => "unread-{$i}",
                'onu_id' => $i,
                'last_seen_at' => now()->addSeconds($i),
            ]);
        }

        $payload = $service->payloadFor($admin->fresh());

        $this->assertCount(8, $payload['items']);
        $this->assertNotContains($persistent->id, collect($payload['items'])->pluck('id'));
        $this->assertTrue(collect($payload['items'])->every(fn ($item) => $item['is_read'] === false));
    }

    public function test_read_notifications_disappear_and_the_bell_refills_to_its_limit(): void
    {
        $olt = $this->makeOlt('ZTE C320', 'ZTE ZXA10 C320');
        $admin = User::factory()->admin()->create();
        $service = app(AlarmNotificationService::class);
        $alarms = collect();

        for ($i = 1; $i <= 9; $i++) {
            $alarms->push($this->alarm($olt, [
                'signature' => "bell-{$i}",
                'onu_id' => $i,
                'last_seen_at' => now()->subSeconds(10 - $i),
            ]));
        }

        $newest = $alarms->last();
        $service->markRead($newest, $admin);
        $payload = $service->payloadFor($admin->fresh());

        $this->assertCount(8, $payload['items']);
        $this->assertSame(8, $payload['unread_count']);
        $this->assertNotContains($newest->id, collect($payload['items'])->pluck('id'));
        $this->assertTrue(collect($payload['items'])->every(fn ($item) => $item['is_read'] === false));
    }

    public function test_mark_all_read_keeps_the_bell_empty_after_a_poll(): void
    {
        $olt = $this->makeOlt('ZTE C320', 'ZTE ZXA10 C320');
        $alarm = $this->alarm($olt);
        $admin = User::factory()->admin()->create();
        $service = app(AlarmNotificationService::class);

        $this->actingAs($admin)->post(route('notifications.read-all'))->assertRedirect();
        $this->travel(6)->minutes();
        $alarm->forceFill(['last_seen_at' => now()])->save();

        $payload = $service->payloadFor($admin->fresh());

        $this->assertSame([], $payload['items']);
        $this->assertSame(0, $payload['unread_count']);
    }

    public function test_payload_exposes_structured_ids_not_parsed_from_message(): void
    {
        $olt = $this->makeOlt('ZTE C320', 'ZTE ZXA10 C320', [$this->onu(1, 1, 5, 'ZTEGC0001')]);
        $this->alarm($olt);
        $admin = User::factory()->admin()->create();

        $item = app(AlarmNotificationService::class)->payloadFor($admin)['items'][0];

        $this->assertSame('onu', $item['resource_type']);
        $this->assertSame($olt->id, $item['smartolt_id']);
        $this->assertSame(1, $item['board_id']);
        $this->assertSame(1, $item['port_id']);
        $this->assertSame(5, $item['resource_id']);
        $this->assertSame('ZTEGC0001', $item['serial_number']);
        $this->assertFalse($item['is_read']);
    }
}
