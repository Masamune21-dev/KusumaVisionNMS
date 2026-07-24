<?php

namespace App\Services;

use App\Models\Odp;
use App\Models\OnuMapPin;
use App\Models\OnuOdpLink;
use App\Models\SnmpOlt;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Relasi ONU↔ODP. ONU tak punya tabel — identitas = komposit
 * (snmp_olt_id, slot, port, onu_id), disimpan di `onu_odp_links`. Dipakai bersama
 * oleh 3 halaman Port ONU (kolom ODP di tabel) dan Peta ONU (garis ODP→ONU + kartu ODP).
 */
class OnuOdpService
{
    public function __construct(private readonly OnuInventoryService $inventory) {}

    /**
     * Daftar ODP satu OLT untuk dropdown kolom tabel ONU.
     *
     * Bila $slot/$port diberikan, hanya ODP di port itu yang ditampilkan — ODP
     * terkunci ke satu port (ONU dalam satu ODP pasti se-port). ODP yang belum
     * punya port (belum ada ONU) tetap muncul di semua port; portnya terisi otomatis
     * saat ONU pertama di-assign (lihat assign()).
     *
     * @return array<int, array{id:int, name:string}>
     */
    public function odpsForOlt(SnmpOlt $olt, ?int $slot = null, ?int $port = null): array
    {
        return Odp::query()
            ->where('snmp_olt_id', $olt->id)
            ->when($slot !== null && $port !== null, fn ($query) => $query->where(function ($group) use ($slot, $port) {
                $group->where(fn ($m) => $m->where('slot', $slot)->where('port', $port))
                    ->orWhere(fn ($n) => $n->whereNull('slot')->whereNull('port'));
            }))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Odp $odp) => ['id' => $odp->id, 'name' => $odp->name])
            ->all();
    }

    /**
     * Assignment ODP untuk ONU di satu PON port, di-key onu_id (untuk baris tabel).
     *
     * @return array<int, array{odp_id:int, odp_name:?string}>
     */
    public function linksForPort(SnmpOlt $olt, int $slot, int $port): array
    {
        return OnuOdpLink::query()
            ->where('snmp_olt_id', $olt->id)
            ->where('slot', $slot)
            ->where('port', $port)
            ->with('odp:id,name')
            ->get()
            ->mapWithKeys(fn (OnuOdpLink $link) => [
                $link->onu_id => ['odp_id' => $link->odp_id, 'odp_name' => $link->odp?->name],
            ])
            ->all();
    }

    /**
     * Assign / ganti / lepas ODP satu ONU. $odpId null ⇒ hapus link.
     */
    public function assign(SnmpOlt $olt, int $slot, int $port, int $onuId, ?string $serial, ?int $odpId, ?int $userId): void
    {
        $key = [
            'snmp_olt_id' => $olt->id,
            'slot' => $slot,
            'port' => $port,
            'onu_id' => $onuId,
        ];

        if ($odpId === null) {
            OnuOdpLink::query()->where($key)->delete();

            return;
        }

        // ODP harus milik OLT yang sama (dan dalam scope partner — Odp ber-PartnerOltScope).
        $odp = Odp::query()->where('id', $odpId)->where('snmp_olt_id', $olt->id)->first();
        if ($odp === null) {
            throw new RuntimeException('ODP tidak ditemukan untuk OLT ini.');
        }

        // ODP terkunci ke satu port — tolak assign ONU dari port lain (jaga integritas,
        // konsisten dgn dropdown yang sudah difilter per-port di odpsForOlt).
        if ($odp->slot !== null && ($odp->slot !== $slot || $odp->port !== $port)) {
            throw new RuntimeException("ODP ini berada di port {$odp->slot}/{$odp->port}, tidak bisa dipasang ke ONU di port {$slot}/{$port}.");
        }

        // ONU dalam satu ODP pasti di port yang sama → isi port ODP otomatis saat
        // ONU pertama di-assign (kalau ODP dibuat tanpa port).
        if ($odp->slot === null && $odp->port === null) {
            $odp->forceFill(['slot' => $slot, 'port' => $port])->save();
        }

        OnuOdpLink::query()->updateOrCreate($key, [
            'odp_id' => $odp->id,
            'serial_number' => $serial,
            'created_by' => $userId,
        ]);
    }

    /**
     * ONU terhubung tiap ODP (di-key odp_id), dienrich status live + koordinat pin.
     * Dipakai peta (garis ODP→ONU) & kartu detail ODP.
     *
     * @param  Collection<int, Odp>  $odps
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function connectedOnus(Collection $odps): array
    {
        if ($odps->isEmpty()) {
            return [];
        }

        $links = OnuOdpLink::query()->whereIn('odp_id', $odps->pluck('id')->all())->get();
        if ($links->isEmpty()) {
            return [];
        }

        $oltIds = $links->pluck('snmp_olt_id')->unique()->all();
        $olts = SnmpOlt::query()->whereIn('id', $oltIds)->get()->keyBy('id');

        $pinKey = fn ($oltId, $slot, $port, $onu) => "{$oltId}/{$slot}/{$port}/{$onu}";
        $pins = OnuMapPin::query()
            ->whereIn('snmp_olt_id', $oltIds)
            ->get()
            ->keyBy(fn (OnuMapPin $pin) => $pinKey($pin->snmp_olt_id, $pin->slot, $pin->port, $pin->onu_id));

        $result = [];
        foreach ($links as $link) {
            $olt = $olts->get($link->snmp_olt_id);
            $live = $olt ? $this->inventory->findOne($olt, $link->slot, $link->port, $link->onu_id) : null;
            $pin = $pins->get($pinKey($link->snmp_olt_id, $link->slot, $link->port, $link->onu_id));

            $result[$link->odp_id][] = [
                'snmp_olt_id' => $link->snmp_olt_id,
                'slot' => $link->slot,
                'port' => $link->port,
                'onu_id' => $link->onu_id,
                'serial_number' => $link->serial_number ?? ($live['serial_number'] ?? null),
                'interface' => $live['interface'] ?? null,
                'name' => $live['customer_name'] ?? null,
                'online' => (bool) ($live['online'] ?? false),
                'has_live' => $live !== null,
                // Koordinat dari pin ONU (null bila ONU belum di-pin → tak ada garis di peta).
                'latitude' => $pin ? (float) $pin->latitude : null,
                'longitude' => $pin ? (float) $pin->longitude : null,
            ];
        }

        return $result;
    }
}
