<?php

namespace App\Services\Alarm;

use App\Models\AlarmEvent;
use App\Models\SnmpOlt;
use App\Support\SmartOltSupport;

/**
 * Resuelve a QUÉ pantalla lleva una notificación de alarma.
 *
 * Toda la ubicación sale de columnas estructuradas de `alarm_events`
 * (snmp_olt_id/scope/slot/port/onu_id/serial_number) — NUNCA se parsea `message`,
 * que es texto localizado y cambia con el idioma.
 *
 * Solo lee el snapshot cacheado (`snmp_olts.last_test_result`): jamás abre SNMP ni
 * Telnet, porque esto corre en el clic del usuario y debe responder al instante.
 */
class AlarmNotificationTargetResolver
{
    /** La ONU ya no aparece en el inventario actual del OLT. */
    public const REASON_ONU_NOT_FOUND = 'onu_not_found';

    /** Esa posición la ocupa AHORA otra ONU (serial distinto) — no abrimos la equivocada. */
    public const REASON_POSITION_REUSED = 'position_reused';

    /** La ONU se movió de puerto; sí navegamos, pero a su ubicación ACTUAL. */
    public const REASON_ONU_MOVED = 'onu_moved';

    /** Alarma de ONU sin slot/port/onu_id utilizables. */
    public const REASON_INCOMPLETE_LOCATION = 'incomplete_location';

    /** El OLT ya no existe o el usuario perdió acceso. */
    public const REASON_OLT_UNAVAILABLE = 'olt_unavailable';

    /**
     * Índice serial → posición actual, memoizado por OLT: varias alarmas del mismo OLT
     * (caso normal en la lista de alarmas de la API) reutilizan un único recorrido del
     * snapshot en lugar de re-escanearlo por cada alarma.
     *
     * @var array<int, array<string, array{0:int,1:int,2:int}>>
     */
    private array $serialIndex = [];

    /**
     * Destino WEB (ruta interna de Inertia).
     *
     * @return array{url:?string, fallback_url:string, reason:?string}
     */
    public function resolve(AlarmEvent $alarm): array
    {
        $fallback = $this->fallbackUrl($alarm);
        $location = $this->resolveLocation($alarm);

        if (! $location['openable']) {
            return $this->miss($location['reason'] ?? self::REASON_OLT_UNAVAILABLE, $fallback);
        }

        // $alarm->olt pasa por PartnerOltScope/DemoScope → null si el usuario no lo ve.
        $olt = $alarm->olt;
        $driver = SmartOltSupport::driverKey(
            $olt,
            data_get($olt->last_test_result, 'system.sys_descr'),
            data_get($olt->last_test_result, 'system.sys_object_id'),
        );
        $prefix = SmartOltSupport::inventoryRoutePrefix($driver);

        if ($location['resource_type'] === 'onu') {
            // La página de detalle individual solo existe donde la capability lo permite
            // (hoy: ZTE C300/C320/C600). En el resto se abre el puerto resaltando la ONU
            // con el parámetro `focus` que esas páginas ya soportan.
            $supportsDetail = (bool) (SmartOltSupport::capabilities($driver, $olt)['supports_cli_onu_detail'] ?? false);

            $url = $supportsDetail
                ? route("{$prefix}.onu.detail", [$olt, $location['slot'], $location['port'], $location['onu_id']], absolute: false)
                : route("{$prefix}.port-onus", [
                    'olt' => $olt,
                    'slot' => $location['slot'],
                    'port' => $location['port'],
                    'focus' => $location['onu_id'],
                ], absolute: false);

            return $this->hit($url, $fallback, $location['reason']);
        }

        if ($location['resource_type'] === 'port') {
            return $this->hit(
                route("{$prefix}.port-onus", [$olt, $location['slot'], $location['port']], absolute: false),
                $fallback,
                $location['reason'],
            );
        }

        return $this->hit(route("{$prefix}.detail", $olt, absolute: false), $fallback, $location['reason']);
    }

    /**
     * Ubicación ESTRUCTURADA ya resuelta (posición actual tras seguir el serial), sin URLs.
     *
     * Es lo que consume la API/aplicación móvil: una ruta web no le sirve a `go_router`, y
     * además el móvil tiene su propia pantalla de detalle de ONU que funciona para todas las
     * familias (lee el snapshot vía API), así que aquí NO se aplica la capability web
     * `supports_cli_onu_detail`.
     *
     * @return array{resource_type:?string, olt_id:?int, slot:?int, port:?int, onu_id:?int, openable:bool, reason:?string}
     */
    public function resolveLocation(AlarmEvent $alarm): array
    {
        $olt = $alarm->olt;

        if ($olt === null) {
            return $this->location(null, null, null, null, null, false, self::REASON_OLT_UNAVAILABLE);
        }

        if ($alarm->scope === 'onu') {
            $located = $this->locateOnu($alarm, $olt);

            if ($located['position'] === null) {
                return $this->location('onu', $olt->id, null, null, null, false, $located['reason']);
            }

            [$slot, $port, $onuId] = $located['position'];

            return $this->location('onu', $olt->id, $slot, $port, $onuId, true, $located['reason']);
        }

        // Un ODP vive en exactamente UN puerto PON, y la pantalla útil es la lista de ONUs de ese
        // puerto: por eso `scope=odp` viaja como destino de tipo 'port' (el tipo describe A DÓNDE
        // navegar, no qué es la alarma — para eso está el campo `scope`). Así web y móvil abren
        // algo útil sin tocar el cliente. Sin slot/port (ODP sin puerto aún) → el OLT.
        if ($alarm->scope === 'port' || $alarm->scope === 'odp') {
            if ($alarm->slot === null || $alarm->port === null) {
                return $alarm->scope === 'odp'
                    ? $this->location('olt', $olt->id, null, null, null, true, null)
                    : $this->location('port', $olt->id, null, null, null, false, self::REASON_INCOMPLETE_LOCATION);
            }

            return $this->location('port', $olt->id, (int) $alarm->slot, (int) $alarm->port, null, true, null);
        }

        // scope 'olt' (y cualquier valor inesperado) → el OLT en sí.
        return $this->location('olt', $olt->id, null, null, null, true, null);
    }

    /**
     * @return array{resource_type:?string, olt_id:?int, slot:?int, port:?int, onu_id:?int, openable:bool, reason:?string}
     */
    private function location(?string $type, ?int $oltId, ?int $slot, ?int $port, ?int $onuId, bool $openable, ?string $reason): array
    {
        return [
            'resource_type' => $type,
            'olt_id' => $oltId,
            'slot' => $slot,
            'port' => $port,
            'onu_id' => $onuId,
            'openable' => $openable,
            'reason' => $reason,
        ];
    }

    /**
     * Ubica la ONU en el snapshot ACTUAL.
     *
     * El serial es el ancla estable: si la ONU fue reprovisionada en otro puerto, la alarma
     * conserva la ubicación histórica y seguirla a ciegas abriría otra ONU que heredó ese ID.
     *
     * @return array{position:?array{0:int,1:int,2:int}, reason:?string}
     */
    private function locateOnu(AlarmEvent $alarm, SnmpOlt $olt): array
    {
        $serial = $alarm->serial_number !== null && $alarm->serial_number !== ''
            ? $alarm->serial_number
            : null;

        // 1. Preferente: buscar el serial en todo el snapshot del OLT.
        if ($serial !== null) {
            $current = $this->findBySerial($olt, $serial);

            if ($current !== null) {
                $moved = $current !== [(int) $alarm->slot, (int) $alarm->port, (int) $alarm->onu_id];

                return ['position' => $current, 'reason' => $moved ? self::REASON_ONU_MOVED : null];
            }
        }

        if ($alarm->slot === null || $alarm->port === null || $alarm->onu_id === null) {
            return ['position' => null, 'reason' => self::REASON_INCOMPLETE_LOCATION];
        }

        // 2. El serial no aparece (o la alarma no lo trae): mirar la posición histórica.
        $occupant = $this->onuAt($olt, (int) $alarm->slot, (int) $alarm->port, (int) $alarm->onu_id);

        if ($occupant === null) {
            return ['position' => null, 'reason' => self::REASON_ONU_NOT_FOUND];
        }

        $occupantSerial = (string) ($occupant['serial_number'] ?? '');

        // 3. Si sabemos el serial y NO coincide con quien ocupa hoy esa posición, no abrimos:
        //    sería una ONU distinta que reutilizó slot/port/onu_id.
        if ($serial !== null && $occupantSerial !== '' && $occupantSerial !== $serial) {
            return ['position' => null, 'reason' => self::REASON_POSITION_REUSED];
        }

        return [
            'position' => [(int) $alarm->slot, (int) $alarm->port, (int) $alarm->onu_id],
            'reason' => null,
        ];
    }

    /**
     * @return array{0:int,1:int,2:int}|null [slot, port, onu_id] actual del serial
     */
    private function findBySerial(SnmpOlt $olt, string $serial): ?array
    {
        return $this->serialIndexFor($olt)[$serial] ?? null;
    }

    /**
     * Recorre el snapshot UNA vez por OLT y memoiza serial → posición: la lista de alarmas
     * de la API resuelve muchas alarmas del mismo OLT en una sola petición.
     *
     * @return array<string, array{0:int,1:int,2:int}>
     */
    private function serialIndexFor(SnmpOlt $olt): array
    {
        if (isset($this->serialIndex[$olt->id])) {
            return $this->serialIndex[$olt->id];
        }

        $index = [];

        foreach ($this->portOnusEntries($olt) as $entry) {
            foreach (data_get($entry, 'onus', []) as $onu) {
                $serial = (string) ($onu['serial_number'] ?? '');

                if ($serial === '') {
                    continue;
                }

                $index[$serial] = [
                    (int) ($onu['slot'] ?? 0),
                    (int) ($onu['port'] ?? 0),
                    (int) ($onu['onu_id'] ?? 0),
                ];
            }
        }

        return $this->serialIndex[$olt->id] = $index;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function onuAt(SnmpOlt $olt, int $slot, int $port, int $onuId): ?array
    {
        $onus = data_get($olt->last_test_result ?? [], "port_onus.{$slot}_{$port}.onus", []);

        foreach ($onus as $onu) {
            if ((int) ($onu['onu_id'] ?? 0) === $onuId) {
                return $onu;
            }
        }

        return null;
    }

    /**
     * @return array<int|string, mixed>
     */
    private function portOnusEntries(SnmpOlt $olt): array
    {
        $portOnus = data_get($olt->last_test_result ?? [], 'port_onus', []);

        return is_array($portOnus) ? $portOnus : [];
    }

    /**
     * Lista de alarmas pre-filtrada al contexto de la alarma, para que el fallback no
     * deje al operador en una lista cruda.
     */
    private function fallbackUrl(AlarmEvent $alarm): string
    {
        return route('alarms.index', array_filter([
            'olt_id' => $alarm->snmp_olt_id,
            'scope' => in_array($alarm->scope, ['olt', 'port', 'odp', 'onu'], true) ? $alarm->scope : null,
        ]), absolute: false);
    }

    /**
     * @return array{url:string, fallback_url:string, reason:?string}
     */
    private function hit(string $url, string $fallback, ?string $reason = null): array
    {
        return ['url' => $url, 'fallback_url' => $fallback, 'reason' => $reason];
    }

    /**
     * @return array{url:null, fallback_url:string, reason:string}
     */
    private function miss(string $reason, string $fallback): array
    {
        return ['url' => null, 'fallback_url' => $fallback, 'reason' => $reason];
    }
}
