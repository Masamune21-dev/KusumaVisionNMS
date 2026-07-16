<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SnmpOlt;
use App\Services\CData\CDataCliWriteService;
use App\Services\Hioso\HiosoCliWriteService;
use App\Services\SmartOltSnmpServiceResolver;
use App\Services\Snmp\OltSnmpClient;
use App\Services\ZteRemoteOnuService;
use App\Support\SmartOltSupport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Aksi tulis ONU dari aplikasi mobile: reboot, rename & delete (bercabang
 * per-family ZTE / C-Data / HiOSO — pola sama dgn OnuMapController), plus
 * refresh live snapshot per-port dan discovery unconfigured (ZTE-only).
 * Rute-rute ini di-gate role (admin|operator|partner) + BlockDemoWrites di
 * routes/api.php. Aksi telnet/SNMP berjalan sinkron (sama seperti web) —
 * klien memakai timeout longgar.
 */
class OnuActionController extends Controller
{
    /**
     * POST /api/v1/olts/{olt}/onus/{slot}/{port}/{onuId}/reboot
     */
    public function reboot(SnmpOlt $olt, int $slot, int $port, int $onuId, ZteRemoteOnuService $remote, CDataCliWriteService $cdata, HiosoCliWriteService $hioso): JsonResponse
    {
        $this->assertCapability($olt, 'supports_reboot');

        try {
            if ($this->isHioso($olt)) {
                $result = $hioso->reboot($olt, $port, $onuId);
            } elseif ($this->isCdata($olt)) {
                $result = $cdata->reboot($olt, $this->ifaceKeyword($olt), $slot, $port, $onuId);
            } else {
                $result = $remote->reboot($olt, $slot, $port, $onuId);
            }

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
    public function rename(Request $request, SnmpOlt $olt, int $slot, int $port, int $onuId, ZteRemoteOnuService $remote, CDataCliWriteService $cdata, HiosoCliWriteService $hioso): JsonResponse
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

        try {
            if ($this->isHioso($olt) || $this->isCdata($olt)) {
                // Non-ZTE hanya punya satu field nama; `description` khusus ZTE (paritas web).
                if ($name === null) {
                    return response()->json(['message' => 'OLT ini hanya mendukung ubah nama ONU.'], 422);
                }

                $result = $this->isHioso($olt)
                    ? $hioso->setName($olt, $port, $onuId, $name)
                    : $cdata->setDescription($olt, $this->ifaceKeyword($olt), $slot, $port, $onuId, $name);

                if (! ($result['ok'] ?? false)) {
                    return response()->json(['message' => 'Update info ONU gagal: '.($result['error'] ?? '')], 422);
                }

                // Cache non-ZTE memakai name sekaligus sebagai description (paritas web).
                $description = $name;
            } else {
                $ifIndex = $this->resolveOnuIfIndex($olt, $slot, $port, $onuId, $data['if_index'] ?? null);
                $remote->setInfo($olt, $ifIndex, $onuId, $name, $description);
            }
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
     * DELETE /api/v1/olts/{olt}/onus/{slot}/{port}/{onuId} — hapus (deregister)
     * ONU dari OLT via CLI. Destruktif; gated capability `supports_onu_delete`.
     * ZTE `no onu {id}`, C-Data `ont delete`, HiOSO `delete onu {id}` — semua
     * lewat service family masing-masing (sama seperti web).
     */
    public function delete(SnmpOlt $olt, int $slot, int $port, int $onuId, ZteRemoteOnuService $remote, CDataCliWriteService $cdata, HiosoCliWriteService $hioso): JsonResponse
    {
        $this->assertCapability($olt, 'supports_onu_delete');

        try {
            if ($this->isHioso($olt)) {
                $result = $hioso->delete($olt, $port, $onuId);
            } elseif ($this->isCdata($olt)) {
                $result = $cdata->delete($olt, $this->ifaceKeyword($olt), $slot, $port, $onuId);
            } else {
                $result = $remote->delete($olt, $slot, $port, $onuId);
            }

            $ok = (bool) ($result['ok'] ?? false);
            if ($ok) {
                $this->removeCachedOnu($olt, $slot, $port, $onuId);
            }

            return response()->json([
                'data' => [
                    'ok' => $ok,
                    'message' => $ok
                        ? "ONU {$onuId} dihapus dari OLT."
                        : 'Hapus ONU selesai dengan indikasi error.',
                    'error' => $result['error'] ?? null,
                ],
            ], $ok ? 200 : 422);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Hapus ONU gagal: '.$e->getMessage()], 422);
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

    private function isCdata(SnmpOlt $olt): bool
    {
        return SmartOltSupport::isCData($this->driver($olt));
    }

    private function isHioso(SnmpOlt $olt): bool
    {
        return SmartOltSupport::isHioso($this->driver($olt));
    }

    private function ifaceKeyword(SnmpOlt $olt): string
    {
        return $this->driver($olt) === SmartOltSupport::DRIVER_CDATA_EPON ? 'epon' : 'gpon';
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

    /**
     * Buang ONU yang dihapus dari snapshot cache port supaya klien langsung
     * melihat hasilnya tanpa refresh SNMP penuh (mirror web removeCachedOnu).
     */
    private function removeCachedOnu(SnmpOlt $olt, int $slot, int $port, int $onuId): void
    {
        $snapshot = $olt->last_test_result ?? [];
        $path = "port_onus.{$slot}_{$port}.onus";
        $onus = data_get($snapshot, $path);

        if (! is_array($onus)) {
            return;
        }

        $onus = array_values(array_filter($onus, fn (array $onu): bool => (int) ($onu['onu_id'] ?? 0) !== $onuId));
        data_set($snapshot, $path, $onus);

        if (data_get($snapshot, "port_onus.{$slot}_{$port}.count") !== null) {
            data_set($snapshot, "port_onus.{$slot}_{$port}.count", count($onus));
        }

        $olt->forceFill(['last_test_result' => $snapshot])->save();
    }
}
