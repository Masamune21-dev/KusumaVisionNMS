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
     * @return array{url:?string, fallback_url:string, reason:?string}
     */
    public function resolve(AlarmEvent $alarm): array
    {
        // $alarm->olt pasa por PartnerOltScope/DemoScope → null si el usuario no lo ve.
        $olt = $alarm->olt;
        $fallback = $this->fallbackUrl($alarm);

        if ($olt === null) {
            return $this->miss(self::REASON_OLT_UNAVAILABLE, $fallback);
        }

        $driver = SmartOltSupport::driverKey(
            $olt,
            data_get($olt->last_test_result, 'system.sys_descr'),
            data_get($olt->last_test_result, 'system.sys_object_id'),
        );
        $prefix = SmartOltSupport::inventoryRoutePrefix($driver);

        return match ($alarm->scope) {
            'onu' => $this->resolveOnu($alarm, $olt, $driver, $prefix, $fallback),
            'port' => $this->resolvePort($alarm, $olt, $prefix, $fallback),
            // scope 'olt' (y cualquier valor inesperado) → detalle del OLT de su familia.
            default => $this->hit(route("{$prefix}.detail", $olt, absolute: false), $fallback),
        };
    }

    /**
     * @return array{url:?string, fallback_url:string, reason:?string}
     */
    private function resolveOnu(AlarmEvent $alarm, SnmpOlt $olt, string $driver, string $prefix, string $fallback): array
    {
        $located = $this->locateOnu($alarm, $olt);

        if ($located['reason'] !== null && $located['position'] === null) {
            return $this->miss($located['reason'], $fallback);
        }

        [$slot, $port, $onuId] = $located['position'];

        // La página de detalle individual solo existe donde la capability lo permite
        // (hoy: ZTE C300/C320/C600). En el resto se abre el puerto resaltando la ONU
        // con el parámetro `focus` que esas páginas ya soportan.
        $supportsDetail = (bool) (SmartOltSupport::capabilities($driver, $olt)['supports_cli_onu_detail'] ?? false);

        $url = $supportsDetail
            ? route("{$prefix}.onu.detail", [$olt, $slot, $port, $onuId], absolute: false)
            : route("{$prefix}.port-onus", ['olt' => $olt, 'slot' => $slot, 'port' => $port, 'focus' => $onuId], absolute: false);

        return $this->hit($url, $fallback, $located['reason']);
    }

    /**
     * @return array{url:?string, fallback_url:string, reason:?string}
     */
    private function resolvePort(AlarmEvent $alarm, SnmpOlt $olt, string $prefix, string $fallback): array
    {
        if ($alarm->slot === null || $alarm->port === null) {
            return $this->miss(self::REASON_INCOMPLETE_LOCATION, $fallback);
        }

        return $this->hit(
            route("{$prefix}.port-onus", [$olt, $alarm->slot, $alarm->port], absolute: false),
            $fallback,
        );
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
        foreach ($this->portOnusEntries($olt) as $entry) {
            foreach (data_get($entry, 'onus', []) as $onu) {
                if ((string) ($onu['serial_number'] ?? '') === $serial) {
                    return [
                        (int) ($onu['slot'] ?? 0),
                        (int) ($onu['port'] ?? 0),
                        (int) ($onu['onu_id'] ?? 0),
                    ];
                }
            }
        }

        return null;
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
            'scope' => in_array($alarm->scope, ['olt', 'port', 'onu'], true) ? $alarm->scope : null,
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
