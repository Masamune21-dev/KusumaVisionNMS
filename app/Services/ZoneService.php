<?php

namespace App\Services;

use App\Models\OnuZoneLink;
use App\Models\SnmpOlt;
use App\Models\Zone;
use RuntimeException;

/**
 * Zona geografis global (mis. "PALMARITO", "RINCON") diasosiasikan ke ONU. ONU tak
 * punya tabel — identitas = komposit (snmp_olt_id, slot, port, onu_id), disimpan di
 * `onu_zone_links` (pola sama {@see OnuOdpService}). Beda dengan ODP:
 * zona bersifat GLOBAL (bukan per-OLT) — daftar zona dikelola admin saja lewat Settings.
 */
class ZoneService
{
    /**
     * Daftar zona + jumlah ONU (link) per zona, untuk halaman Settings → Zones.
     *
     * @return array<int, array{id:int, name:string, onu_count:int}>
     */
    public function listWithCounts(): array
    {
        return Zone::query()
            ->withCount('links')
            ->orderBy('name')
            ->get()
            ->map(fn (Zone $zone) => [
                'id' => $zone->id,
                'name' => $zone->name,
                'onu_count' => $zone->links_count,
            ])
            ->all();
    }

    /**
     * Daftar zona ringkas (id + name, urut alfabet) untuk dropdown select — provisioning,
     * edit inline detail ONU, dan filter ONU Monitoring.
     *
     * @return array<int, array{id:int, name:string}>
     */
    public function options(): array
    {
        return Zone::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Zone $zone) => ['id' => $zone->id, 'name' => $zone->name])
            ->all();
    }

    /**
     * Hapus zona. Bila $reassignToZoneId diberikan, semua ONU di zona ini dipindah ke
     * zona tujuan dulu; bila tidak, link-nya otomatis null (FK onDelete set null) —
     * ONU jadi "Sin zona".
     */
    public function destroy(Zone $zone, ?int $reassignToZoneId): void
    {
        if ($reassignToZoneId !== null) {
            if ($reassignToZoneId === $zone->id) {
                throw new RuntimeException('Zona tujuan tidak boleh sama dengan zona yang dihapus.');
            }

            $target = Zone::query()->find($reassignToZoneId);
            if ($target === null) {
                throw new RuntimeException('Zona tujuan tidak ditemukan.');
            }

            OnuZoneLink::query()->where('zone_id', $zone->id)->update(['zone_id' => $target->id]);
        }

        // Link yang tersisa (bila tak direassign) otomatis zone_id=null lewat FK nullOnDelete.
        $zone->delete();
    }

    /**
     * Assign / ganti / lepas zona satu ONU. $zoneId null ⇒ hapus link ("Sin zona").
     */
    public function assign(SnmpOlt $olt, int $slot, int $port, int $onuId, ?string $serial, ?int $zoneId, ?int $userId): void
    {
        $key = [
            'snmp_olt_id' => $olt->id,
            'slot' => $slot,
            'port' => $port,
            'onu_id' => $onuId,
        ];

        if ($zoneId === null) {
            OnuZoneLink::query()->where($key)->delete();

            return;
        }

        $zone = Zone::query()->find($zoneId);
        if ($zone === null) {
            throw new RuntimeException('Zona tidak ditemukan.');
        }

        OnuZoneLink::query()->updateOrCreate($key, [
            'zone_id' => $zone->id,
            'serial_number' => $serial,
            'created_by' => $userId,
        ]);
    }

    /**
     * Peta lookup zona untuk SEMUA ONU sekaligus (satu query, dipakai OnuInventoryService
     * agar tak N+1 saat meng-enrich ratusan/ribuan ONU). Key: "oltId.slot.port.onuId".
     *
     * @return array<string, array{zone_id:int, zone_name:string}>
     */
    public function lookupMap(): array
    {
        return OnuZoneLink::query()
            ->with('zone:id,name')
            ->get()
            ->filter(fn (OnuZoneLink $link) => $link->zone !== null)
            ->mapWithKeys(fn (OnuZoneLink $link) => [
                "{$link->snmp_olt_id}.{$link->slot}.{$link->port}.{$link->onu_id}" => [
                    'zone_id' => $link->zone_id,
                    'zone_name' => $link->zone->name,
                ],
            ])
            ->all();
    }

    /**
     * Peta lookup zona untuk satu port saja (dipakai OnuInventoryService::forPort — jauh lebih
     * murah daripada lookupMap() penuh saat cuma butuh satu port). Key: "onuId".
     *
     * @return array<int, array{zone_id:int, zone_name:string}>
     */
    public function lookupMapForPort(SnmpOlt $olt, int $slot, int $port): array
    {
        return OnuZoneLink::query()
            ->where('snmp_olt_id', $olt->id)
            ->where('slot', $slot)
            ->where('port', $port)
            ->with('zone:id,name')
            ->get()
            ->filter(fn (OnuZoneLink $link) => $link->zone !== null)
            ->mapWithKeys(fn (OnuZoneLink $link) => [
                $link->onu_id => ['zone_id' => $link->zone_id, 'zone_name' => $link->zone->name],
            ])
            ->all();
    }

    /**
     * Zona satu ONU spesifik (dipakai detail ONU — hindari load seluruh peta untuk satu baris).
     *
     * @return array{zone_id:int, zone_name:string}|null
     */
    public function forOnu(SnmpOlt $olt, int $slot, int $port, int $onuId): ?array
    {
        $link = OnuZoneLink::query()
            ->where('snmp_olt_id', $olt->id)
            ->where('slot', $slot)
            ->where('port', $port)
            ->where('onu_id', $onuId)
            ->with('zone:id,name')
            ->first();

        if ($link === null || $link->zone === null) {
            return null;
        }

        return ['zone_id' => $link->zone_id, 'zone_name' => $link->zone->name];
    }
}
