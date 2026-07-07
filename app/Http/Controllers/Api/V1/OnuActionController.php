<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SnmpOlt;
use App\Services\SmartOltSnmpServiceResolver;
use App\Services\Snmp\OltSnmpClient;
use App\Services\ZteRemoteOnuService;
use App\Support\SmartOltSupport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Aksi tulis ONU dari aplikasi mobile (ZTE): reboot & rename, plus refresh live
 * snapshot per-port dan discovery unconfigured. Rute-rute ini di-gate role
 * (admin|operator) + BlockDemoWrites di routes/api.php. Aksi telnet/SNMP berjalan
 * sinkron (sama seperti web) — klien memakai timeout longgar.
 */
class OnuActionController extends Controller
{
    /**
     * POST /api/v1/olts/{olt}/onus/{slot}/{port}/{onuId}/reboot
     */
    public function reboot(SnmpOlt $olt, int $slot, int $port, int $onuId, ZteRemoteOnuService $remote): JsonResponse
    {
        $this->assertCapability($olt, 'supports_reboot');

        try {
            $result = $remote->reboot($olt, $slot, $port, $onuId);

            return response()->json([
                'data' => [
                    'ok' => (bool) $result['ok'],
                    'message' => $result['ok']
                        ? 'Perintah reboot terkirim. ONU restart 30-60 detik.'
                        : 'Reboot selesai dengan indikasi error.',
                    'error' => $result['error'] ?? null,
                ],
            ], $result['ok'] ? 200 : 422);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Reboot ONU gagal: '.$e->getMessage()], 422);
        }
    }

    /**
     * POST /api/v1/olts/{olt}/onus/{slot}/{port}/{onuId}/name  {name?, description?}
     */
    public function rename(Request $request, SnmpOlt $olt, int $slot, int $port, int $onuId, ZteRemoteOnuService $remote): JsonResponse
    {
        $this->assertCapability($olt, 'supports_onu_info_write');

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:191'],
            'if_index' => ['nullable', 'integer'],
        ]);

        $name = ($data['name'] ?? '') !== '' ? $data['name'] : null;
        $description = ($data['description'] ?? '') !== '' ? $data['description'] : null;

        if ($name === null && $description === null) {
            return response()->json(['message' => 'Isi minimal nama atau deskripsi ONU.'], 422);
        }

        $ifIndex = $this->resolveOnuIfIndex($olt, $slot, $port, $onuId, $data['if_index'] ?? null);

        try {
            $remote->setInfo($olt, $ifIndex, $onuId, $name, $description);
            $this->mutateCachedOnu($olt, $slot, $port, $onuId, function (array $onu) use ($name, $description) {
                if ($name !== null) {
                    $onu['name'] = $name;
                }
                if ($description !== null) {
                    $onu['description'] = $description;
                }

                return $onu;
            });

            return response()->json(['data' => ['ok' => true, 'message' => 'Info ONU diperbarui.']]);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Update info ONU gagal: '.$e->getMessage()], 422);
        }
    }

    /**
     * POST /api/v1/olts/{olt}/ports/{slot}/{port}/refresh — refresh ONU 1 port secara live.
     *
     * ZTE: walk subtree ONU-table port ini ({@see OltSnmpClient::portOnusSnapshot}).
     * Non-ZTE (C-Data EPON/GPON, HiOSO): query per-port lewat driver SNMP
     * ({@see SmartOltSnmpDriver::getRegisteredOnusByPort}) — sama seperti tombol refresh
     * per-port di halaman web C-Data/HiOSO. Keduanya menulis `port_onus.{slot}_{port}`
     * berbentuk-ZTE yang dibaca endpoint GET port-onus.
     */
    public function refreshPort(SnmpOlt $olt, int $slot, int $port, OltSnmpClient $client, SmartOltSnmpServiceResolver $resolver): JsonResponse
    {
        if (SmartOltSupport::isNonZte($this->driver($olt))) {
            try {
                $onus = $resolver->resolve($olt)->getRegisteredOnusByPort($olt, $slot, $port);
                $result = [
                    'ok' => true,
                    'slot' => $slot,
                    'port' => $port,
                    'onus' => $onus,
                    'count' => count($onus),
                    'error' => null,
                ];
            } catch (\Throwable $e) {
                $result = ['ok' => false, 'slot' => $slot, 'port' => $port, 'onus' => [], 'count' => 0, 'error' => $e->getMessage()];
            }
        } else {
            $result = $client->portOnusSnapshot($olt, $slot, $port);
        }
        $result['refreshed_at'] = now()->toIso8601String();

        $snapshot = $olt->last_test_result ?? [];
        data_set($snapshot, "port_onus.{$slot}_{$port}", $result);
        $olt->forceFill(['last_test_result' => $snapshot])->save();

        return response()->json([
            'data' => [
                'ok' => (bool) $result['ok'],
                'count' => $result['count'] ?? 0,
                'error' => $result['error'] ?? null,
                'refreshed_at' => $result['refreshed_at'],
            ],
        ], ($result['ok'] ?? false) ? 200 : 422);
    }

    /**
     * POST /api/v1/olts/{olt}/unconfigured/refresh — discovery ONU autofind live.
     */
    public function refreshUnconfigured(SnmpOlt $olt, OltSnmpClient $client): JsonResponse
    {
        $this->assertNonZteGuard($olt);

        $result = $client->unconfiguredOnusSnapshot($olt);
        $result['refreshed_at'] = now()->toIso8601String();

        $snapshot = $olt->last_test_result ?? [];
        data_set($snapshot, 'unconfigured_onus', $result);
        $olt->forceFill(['last_test_result' => $snapshot])->save();

        return response()->json([
            'data' => [
                'ok' => (bool) $result['ok'],
                'count' => $result['count'] ?? 0,
                'onus' => $result['onus'] ?? [],
                'error' => $result['error'] ?? null,
                'refreshed_at' => $result['refreshed_at'],
            ],
        ], ($result['ok'] ?? false) ? 200 : 422);
    }

    private function driver(SnmpOlt $olt): string
    {
        return SmartOltSupport::driverKey(
            $olt,
            data_get($olt->last_test_result, 'system.sys_descr'),
            data_get($olt->last_test_result, 'system.sys_object_id'),
        );
    }

    private function assertCapability(SnmpOlt $olt, string $capability): void
    {
        abort_unless(
            (bool) (SmartOltSupport::capabilities($this->driver($olt), $olt)[$capability] ?? false),
            422,
            'Aksi ini tidak didukung untuk driver OLT ini.',
        );
    }

    /** Refresh live SNMP (walk) hanya untuk ZTE; family lain punya jalur scanner sendiri. */
    private function assertNonZteGuard(SnmpOlt $olt): void
    {
        abort_if(
            SmartOltSupport::isNonZte($this->driver($olt)),
            422,
            'Refresh live via endpoint ini hanya untuk OLT ZTE.',
        );
    }

    private function resolveOnuIfIndex(SnmpOlt $olt, int $slot, int $port, int $onuId, ?int $provided): int
    {
        if ($provided !== null && $provided > 0) {
            return $provided;
        }

        $onus = data_get($olt->last_test_result ?? [], "port_onus.{$slot}_{$port}.onus", []);
        foreach ($onus as $onu) {
            if ((int) ($onu['onu_id'] ?? 0) === $onuId && ($onu['if_index'] ?? null) !== null) {
                return (int) $onu['if_index'];
            }
        }

        return 0x10000000 | ($slot << 16) | ($port << 8);
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutator
     */
    private function mutateCachedOnu(SnmpOlt $olt, int $slot, int $port, int $onuId, callable $mutator): void
    {
        $snapshot = $olt->last_test_result ?? [];
        $path = "port_onus.{$slot}_{$port}.onus";
        $onus = data_get($snapshot, $path);
        if (! is_array($onus)) {
            return;
        }

        foreach ($onus as $index => $onu) {
            if ((int) ($onu['onu_id'] ?? 0) === $onuId) {
                $onus[$index] = $mutator($onu);
            }
        }

        data_set($snapshot, $path, $onus);
        $olt->forceFill(['last_test_result' => $snapshot])->save();
    }
}
