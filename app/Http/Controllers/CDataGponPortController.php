<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\SnmpOlt;
use App\Services\CData\CDataGponPortService;
use App\Services\OltPortLabelService;
use App\Support\AuditLogger;
use App\Support\SmartOltSupport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Halaman VLAN & detail port (GPON/GE/XGE) OLT C-Data GPON FlashV3 — padanan halaman Port Detail
 * ZTE. Data dibaca LANGSUNG dari CLI saat halaman dibuka ({@see CDataGponPortService}), sebagai
 * deferred prop supaya kerangka halaman tampil dulu. Hasil baca terakhir yang sukses disimpan di
 * cache dan dipakai bila OLT sedang tak terjangkau (ditandai `stale`).
 *
 * Aksi tulis (buat VLAN, tag VLAN ke uplink) mengubah konfigurasi jaringan OLT → hanya staf Pusat
 * atau pemilik OLT privat; partner pada OLT global yang sekadar di-assign cuma bisa melihat.
 */
class CDataGponPortController extends Controller
{
    private const CACHE_DAYS = 7;

    public function vlans(Request $request, SnmpOlt $olt, CDataGponPortService $service): Response
    {
        $this->assertCapability($olt, 'supports_cli_vlan');

        return Inertia::render('CDataOlt/Vlans', [
            'olt' => $this->serializeOlt($olt),
            'tag_ports' => $this->taggablePorts($olt),
            'can_write' => $this->canWrite($request, $olt),
            'vlan_data' => Inertia::defer(fn () => $this->remember(
                "cdata-gpon:{$olt->id}:vlans",
                fn () => ['vlans' => $service->vlans($olt)],
            )),
        ]);
    }

    public function storeVlan(Request $request, SnmpOlt $olt, CDataGponPortService $service): RedirectResponse
    {
        $this->assertCapability($olt, 'supports_cli_vlan');
        $this->authorizeWrite($request, $olt);

        $data = $request->validate([
            'vlan_id' => ['required', 'integer', 'between:1,4094'],
            'description' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'ports' => ['nullable', 'array', 'max:24'],
            'ports.*' => ['string', 'distinct', Rule::in($this->taggablePorts($olt))],
        ], [
            'description.regex' => __('flash.cdata_vlan_description_rule'),
        ]);

        $vlanId = (int) $data['vlan_id'];
        $description = $data['description'] ?? null;
        $back = redirect()->route('cdata-olt.vlans', $olt);

        try {
            $result = $service->createVlan($olt, $vlanId, $description);
        } catch (Throwable $e) {
            return $back->with('error', __('flash.cdata_vlan_create_failed').$e->getMessage());
        }

        $this->audit($olt, $result['ok'], "Membuat VLAN {$vlanId}".($description ? " ({$description})" : '')." di OLT {$olt->name}", [
            'vlan_id' => $vlanId,
            'description' => $description,
        ]);

        if (! $result['ok']) {
            return $back->with('error', __('flash.cdata_vlan_create_failed').$result['error']);
        }

        Cache::forget("cdata-gpon:{$olt->id}:vlans");
        $message = __('flash.cdata_vlan_created', ['vlan' => $vlanId]);

        // Satu sesi telnet per port (±1 dtk/port) — tiap port diverifikasi sendiri; satu gagal tak
        // membatalkan yang lain, dan semuanya dilaporkan.
        $tagged = [];
        $failed = [];
        foreach ($data['ports'] ?? [] as $portName) {
            preg_match('/^(ge|xge|epon) 0\/(\d+)\/(\d+)$/', $portName, $m);
            $tag = $this->tag($service, $olt, $m[1], (int) $m[2], (int) $m[3], $vlanId);
            if ($tag['ok']) {
                $tagged[] = $portName;
            } else {
                $failed[] = "{$portName}: {$tag['error']}";
            }
        }

        if ($tagged !== []) {
            $message .= ' '.__('flash.cdata_vlan_tagged_ports', ['vlan' => $vlanId, 'ports' => implode(', ', $tagged)]);
        }
        if ($failed !== []) {
            return $back->with('error', $message.' '.__('flash.cdata_vlan_tag_failed_some').implode('; ', $failed));
        }

        return $back->with('success', $message.' '.__('flash.cdata_config_unsaved'));
    }

    public function portDetail(Request $request, SnmpOlt $olt, string $kind, int $slot, int $port, CDataGponPortService $service, OltPortLabelService $labels): Response
    {
        $this->assertCapability($olt, 'supports_cli_port_detail');
        $name = sprintf('%s 0/%d/%d', $kind, $slot, $port);

        $isPon = in_array($kind, CDataGponPortService::PON_KINDS, true);
        $onuSummary = null;
        if ($isPon) {
            $onus = collect(data_get($olt->last_test_result, "port_onus.{$slot}_{$port}.onus", []));
            $onuSummary = ['total' => $onus->count(), 'online' => $onus->where('online', true)->count()];
        }

        return Inertia::render('CDataOlt/PortDetail', [
            'olt' => $this->serializeOlt($olt),
            'kind' => $kind,
            'slot' => $slot,
            'port' => $port,
            'name' => $name,
            'port_label' => $isPon ? ($labels->forOlt($olt)["{$slot}_{$port}"] ?? null) : null,
            'onu_summary' => $onuSummary,
            'can_write' => $this->canWrite($request, $olt),
            'detail' => Inertia::defer(fn () => $this->remember(
                "cdata-gpon:{$olt->id}:port:{$kind}:{$slot}:{$port}",
                fn () => $service->portDetail($olt, $kind, $slot, $port),
            )),
        ]);
    }

    public function tagPortVlan(Request $request, SnmpOlt $olt, string $kind, int $slot, int $port, CDataGponPortService $service): RedirectResponse
    {
        $this->assertCapability($olt, 'supports_cli_port_detail');
        $this->authorizeWrite($request, $olt);
        abort_unless(in_array($kind, CDataGponPortService::TAGGABLE_KINDS, true), 404);

        $vlanId = (int) $request->validate(['vlan_id' => ['required', 'integer', 'between:1,4094']])['vlan_id'];
        $name = sprintf('%s 0/%d/%d', $kind, $slot, $port);
        $back = redirect()->route('cdata-olt.port.detail', [$olt, $kind, $slot, $port]);

        $result = $this->tag($service, $olt, $kind, $slot, $port, $vlanId);

        if (! $result['ok']) {
            return $back->with('error', __('flash.cdata_vlan_tag_failed', ['interface' => $name]).$result['error']);
        }

        return $back->with('success', $result['already']
            ? __('flash.cdata_vlan_already_tagged', ['vlan' => $vlanId, 'interface' => $name])
            : __('flash.vlan_added', ['vlan' => $vlanId, 'interface' => $name]).' '.__('flash.cdata_config_unsaved'));
    }

    /**
     * @return array{ok: bool, error: ?string, already: bool}
     */
    private function tag(CDataGponPortService $service, SnmpOlt $olt, string $kind, int $slot, int $port, int $vlanId): array
    {
        $name = sprintf('%s 0/%d/%d', $kind, $slot, $port);

        try {
            $result = $service->tagPortVlan($olt, $kind, $slot, $port, $vlanId);
        } catch (Throwable $e) {
            $result = ['ok' => false, 'error' => $e->getMessage(), 'already' => false];
        }

        if (! $result['already']) {
            $this->audit($olt, $result['ok'], "Tag VLAN {$vlanId} ke {$name} di OLT {$olt->name}", [
                'vlan_id' => $vlanId,
                'interface' => $name,
                'error' => $result['error'],
            ]);
        }

        Cache::forget("cdata-gpon:{$olt->id}:vlans");
        Cache::forget("cdata-gpon:{$olt->id}:port:{$kind}:{$slot}:{$port}");

        return $result;
    }

    /**
     * Baca live; sukses → simpan ke cache; gagal → sajikan salinan cache terakhir sebagai `stale`.
     *
     * @param  callable(): array<string, mixed>  $fetch
     * @return array<string, mixed>
     */
    private function remember(string $key, callable $fetch): array
    {
        try {
            $payload = $fetch() + ['fetched_at' => now()->toIso8601String()];
            Cache::put($key, $payload, now()->addDays(self::CACHE_DAYS));

            return $payload + ['ok' => true, 'stale' => false, 'error' => null];
        } catch (Throwable $e) {
            $cached = Cache::get($key);

            return (is_array($cached) ? $cached : []) + [
                'ok' => false,
                'stale' => is_array($cached),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Port yang boleh di-tag dari form Tambah VLAN, dari panel depan hasil scan (SNMP IF-MIB):
     * uplink GE/XGE + port PON EPON (port GPON otomatis ikut VLAN baru). Urutan: XGE, GE, EPON.
     *
     * @return list<string> mis. ["xge 0/0/1", "ge 0/0/1", "epon 0/1/1"]
     */
    private function taggablePorts(SnmpOlt $olt): array
    {
        $order = ['xge' => 0, 'ge' => 1, 'epon' => 2];
        $ports = [];
        foreach (data_get($olt->last_test_result, 'panel.groups', []) as $group) {
            foreach ($group['ports'] ?? [] as $p) {
                $name = strtolower(trim((string) ($p['name'] ?? '')));
                if (preg_match('/^(ge|xge|epon) \d+\/(\d+)\/(\d+)$/', $name, $m)) {
                    $ports[$name] = [$order[$m[1]], (int) $m[2], (int) $m[3]];
                }
            }
        }

        uasort($ports, fn ($a, $b) => $a <=> $b);

        return array_keys($ports);
    }

    private function canWrite(Request $request, SnmpOlt $olt): bool
    {
        $user = $request->user();

        return (bool) ($user?->canManageOlt() && $user->canEditOltConnection($olt) && $olt->cli_transport === 'telnet');
    }

    private function authorizeWrite(Request $request, SnmpOlt $olt): void
    {
        abort_unless($this->canWrite($request, $olt), 403, 'Mengubah VLAN OLT ini hanya untuk staf Pusat atau pemilik OLT.');
    }

    private function assertCapability(SnmpOlt $olt, string $capability): void
    {
        abort_unless((bool) ($this->capabilities($olt)[$capability] ?? false), 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function capabilities(SnmpOlt $olt): array
    {
        return SmartOltSupport::capabilities($this->driverOf($olt), $olt);
    }

    private function driverOf(SnmpOlt $olt): string
    {
        return SmartOltSupport::driverKey(
            $olt,
            data_get($olt->last_test_result, 'system.sys_descr'),
            data_get($olt->last_test_result, 'system.sys_object_id'),
        );
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function audit(SnmpOlt $olt, bool $ok, string $description, array $properties): void
    {
        AuditLogger::log(
            AuditLog::EVENT_UPDATED,
            $olt,
            ['subject_title' => $olt->name, 'success' => $ok] + $properties,
            ($ok ? '' : 'GAGAL: ').$description,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeOlt(SnmpOlt $olt): array
    {
        $driver = $this->driverOf($olt);

        return [
            'id' => $olt->id,
            'name' => $olt->name,
            'ip' => $olt->ip,
            'driver' => $driver,
            'cli_transport' => $olt->cli_transport,
            'capabilities' => SmartOltSupport::capabilities($driver, $olt),
            'model' => data_get($olt->last_test_result, 'panel.device.model'),
        ];
    }
}
