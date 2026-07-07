<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SnmpOlt;
use App\Support\SmartOltSupport;
use Illuminate\Http\JsonResponse;

/**
 * Read-only API inventaris & status OLT (data dari snapshot polling terakhir,
 * field `snmp_olts.last_test_result`). Tidak menyentuh perangkat secara live.
 */
class OltController extends Controller
{
    /**
     * GET /api/v1/olts — daftar seluruh OLT dengan ringkasan status.
     */
    public function index(): JsonResponse
    {
        $olts = SnmpOlt::query()->orderBy('name')->get();

        return response()->json([
            'data' => $olts->map(fn (SnmpOlt $olt) => $this->summary($olt))->all(),
        ]);
    }

    /**
     * GET /api/v1/olts/{olt} — detail satu OLT: info sistem, daftar port, ringkasan ONU.
     */
    public function show(SnmpOlt $olt): JsonResponse
    {
        $result = $olt->last_test_result ?? [];
        $portOnus = collect($result['port_onus'] ?? []);

        $ports = collect($result['ports'] ?? [])->map(function (array $port) use ($portOnus) {
            $key = ($port['slot'] ?? 0).'_'.($port['port'] ?? 0);
            $bucket = collect($portOnus->get($key)['onus'] ?? []);

            return [
                'if_index' => $port['if_index'] ?? null,
                'name' => $port['name'] ?? null,
                'slot' => (int) ($port['slot'] ?? 0),
                'port' => (int) ($port['port'] ?? 0),
                'oper_status' => $port['oper_status'] ?? null,
                'onu_total' => $bucket->count(),
                'onu_online' => $bucket->where('online', true)->count(),
            ];
        })->values();

        $driver = SmartOltSupport::driverKey(
            $olt,
            data_get($result, 'system.sys_descr'),
            data_get($result, 'system.sys_object_id'),
        );

        return response()->json([
            'data' => array_merge($this->summary($olt), [
                // Peta kapabilitas per-driver — klien pakai untuk menampilkan/menyembunyikan
                // aksi (registrasi ZTE-only, reboot/rename, unconfigured discovery, dll).
                'capabilities' => SmartOltSupport::capabilities($driver, $olt),
                'system' => [
                    'sys_name' => data_get($result, 'system.sys_name'),
                    'sys_descr' => data_get($result, 'system.sys_descr'),
                    'sys_object_id' => data_get($result, 'system.sys_object_id'),
                    'sys_uptime' => data_get($result, 'system.sys_uptime'),
                ],
                'ports' => $ports->all(),
            ]),
        ]);
    }

    /**
     * Ringkasan satu OLT yang dipakai bersama index & show.
     *
     * @return array<string, mixed>
     */
    private function summary(SnmpOlt $olt): array
    {
        $result = $olt->last_test_result ?? [];
        $reachable = (bool) ($result['ok'] ?? false);
        $ports = collect($result['ports'] ?? []);
        $portOnus = collect($result['port_onus'] ?? []);
        $onuTotal = (int) $portOnus->sum('count');
        $onuOnline = $portOnus->flatMap(fn ($p) => $p['onus'] ?? [])->where('online', true)->count();

        $driver = SmartOltSupport::driverKey(
            $olt,
            data_get($result, 'system.sys_descr'),
            data_get($result, 'system.sys_object_id'),
        );

        return [
            'id' => $olt->id,
            'name' => $olt->name,
            'ip' => $olt->ip,
            'vendor' => $olt->vendor,
            'driver' => $driver,
            'is_cdata' => SmartOltSupport::isCData($driver),
            'reachable' => $reachable,
            'polling_enabled' => (bool) $olt->polling_enabled,
            'ports_total' => $ports->count(),
            'ports_up' => $ports->where('oper_status', 'up')->count(),
            'ports_down' => $ports->where('oper_status', 'down')->count(),
            'onu_total' => $onuTotal,
            'onu_online' => $onuOnline,
            'onu_offline' => max($onuTotal - $onuOnline, 0),
            'last_polled_at' => $olt->last_polled_at?->toIso8601String(),
            'last_tested_at' => $olt->last_tested_at?->toIso8601String(),
        ];
    }
}
