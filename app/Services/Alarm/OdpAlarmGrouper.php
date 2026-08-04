<?php

namespace App\Services\Alarm;

use App\Models\AlarmEvent;
use App\Models\AlarmSetting;
use App\Models\Odp;
use App\Models\OnuOdpLink;
use App\Models\Scopes\PartnerOltScope;
use App\Models\SnmpOlt;
use App\Support\SmartOltSupport;

/**
 * Korelasi & pengelompokan alarm pada level ODP (splitter lapangan).
 *
 * Dua peran:
 *
 * 1. **Status ODP** ({@see self::statuses()}) — dari snapshot poll, hitung per-ODP berapa ONU-nya
 *    yang muncul & berapa yang offline, sehingga {@see App\Services\AlarmEvaluator} bisa
 *    menaikkan SATU alarm `odp_down` saat SEMUA ONU satu ODP mati (akar masalah = ODP/kabel
 *    distribusinya) dan mensupres alarm ONU anaknya.
 *
 * 2. **Pengelompokan notifikasi** ({@see self::group()}) — untuk ODP yang baru SEBAGIAN ONU-nya
 *    down (belum semua, jadi belum jadi alarm `odp_down`), rangkum jadi satu pesan berisi daftar
 *    pelanggan alih-alih satu pesan per pelanggan. Aturan:
 *      - 1 ONU down di sebuah ODP → kirim biasa (pesan per-ONU seperti sebelumnya).
 *      - >1 ONU down di 1 ODP     → 1 pesan grup berisi daftar pelanggan.
 *      - ONU tanpa ODP / alarm non-down (port, RX, OLT) → tetap per-item.
 *    Bisa dimatikan admin lewat Settings → Alarm (`group_odp_alarms`).
 *
 * Peran (2) MURNI layer presentasi notifikasi — tiap ONU tetap punya baris AlarmEvent sendiri
 * di UI/riwayat.
 */
class OdpAlarmGrouper
{
    /** Jenis alarm ONU "down" yang boleh dikelompokkan per-ODP. */
    private const DOWN_TYPES = [
        AlarmEvent::TYPE_LOS,
        AlarmEvent::TYPE_DYING_GASP,
        AlarmEvent::TYPE_ONU_OFFLINE,
    ];

    /**
     * Topologi ODP per-OLT (link ONU↔ODP + atribut ODP), dimemo per instance: evaluator
     * memanggil {@see self::statuses()} dua kali (snapshot sekarang & sebelumnya) dalam satu
     * evaluasi, jadi query link/ODP cukup sekali.
     *
     * @var array<int, array{links: array<string, int>, odps: array<int, array{name:string, slot:?int, port:?int}>}>
     */
    private array $topology = [];

    /**
     * Ubah daftar alarm (yang SUDAH lolos filter severity/tipe penerima) menjadi daftar item
     * notifikasi: singleton atau grup ODP.
     *
     * @param  array<int, AlarmEvent>  $alarms
     * @param  bool  $recovered  true = daftar alarm yang PULIH (clear), memengaruhi kata-kata di notifier
     * @return array<int, array<string, mixed>> item: {kind:'single',alarm} | {kind:'odp',odp_id,odp_name,members,total,down_count,all_down,severity,recovered}
     */
    public function group(SnmpOlt $olt, array $alarms, bool $recovered = false): array
    {
        if ($alarms === []) {
            return [];
        }

        if (! AlarmSetting::groupOdpAlarms()) {
            return array_map(fn (AlarmEvent $alarm) => ['kind' => 'single', 'alarm' => $alarm], $alarms);
        }

        $linkIndex = $this->linkIndex($olt);

        /** @var array<int, array<int, AlarmEvent>> $byOdp odp_id => alarm[] */
        $byOdp = [];
        /** @var array<int, AlarmEvent> $singles */
        $singles = [];

        foreach ($alarms as $alarm) {
            $odpId = in_array($alarm->type, self::DOWN_TYPES, true)
                ? ($linkIndex[$this->onuKey($alarm)] ?? null)
                : null;

            if ($odpId === null) {
                $singles[] = $alarm;

                continue;
            }

            $byOdp[$odpId][] = $alarm;
        }

        $items = [];
        $statuses = $this->statuses($olt, $olt->last_test_result ?? []);

        foreach ($byOdp as $odpId => $members) {
            // Hanya 1 ONU down di ODP ini → tetap kirim biasa (per-ONU).
            if (count($members) < 2) {
                $singles[] = $members[0];

                continue;
            }

            $status = $statuses[$odpId] ?? null;
            $total = $status['total'] ?? count($members);
            $down = $status['down'] ?? count($members);

            $items[] = [
                'kind' => 'odp',
                'odp_id' => $odpId,
                'odp_name' => $status['name'] ?? ('ODP #'.$odpId),
                'members' => $members,
                'total' => $total,
                'down_count' => $recovered ? count($members) : $down,
                // Saat semua ONU 1 ODP down, alarm `odp_down` yang mewakili (alarm anak disupres),
                // jadi grup di sini praktis selalu "sebagian" — flag tetap dihitung demi kompatibilitas.
                'all_down' => ! $recovered && $total > 0 && $down >= $total,
                'severity' => $this->maxSeverity($members),
                'recovered' => $recovered,
            ];
        }

        foreach ($singles as $alarm) {
            $items[] = ['kind' => 'single', 'alarm' => $alarm];
        }

        return $items;
    }

    /**
     * Status tiap ODP milik OLT ini menurut sebuah snapshot poll.
     *
     * `total` = jumlah ONU ODP yang muncul di snapshot (link basi diabaikan agar tak memblok
     * deteksi "semua down"), `down` = yang offline, `all_down` = semuanya offline.
     * ODP yang tak punya satu pun ONU di snapshot tidak ikut (tak ada yang bisa disimpulkan).
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<int, array{odp_id:int, name:string, slot:?int, port:?int, total:int, down:int, all_down:bool}>
     */
    public function statuses(SnmpOlt $olt, array $snapshot): array
    {
        $topology = $this->topologyFor($olt);

        if ($topology['links'] === []) {
            return [];
        }

        $online = $this->onlineMap($snapshot);
        $stats = [];

        foreach ($topology['links'] as $key => $odpId) {
            if (! array_key_exists($key, $online)) {
                continue; // ONU tak ada di snapshot ini → abaikan.
            }

            $odp = $topology['odps'][$odpId] ?? ['name' => 'ODP #'.$odpId, 'slot' => null, 'port' => null];

            $stats[$odpId] ??= [
                'odp_id' => $odpId,
                'name' => $odp['name'],
                // Port ODP boleh kosong (ODP lama/baru dibuat) → pakai posisi ONU-nya.
                'slot' => $odp['slot'] ?? (int) explode('/', $key)[0],
                'port' => $odp['port'] ?? (int) explode('/', $key)[1],
                'total' => 0,
                'down' => 0,
                'all_down' => false,
            ];

            $stats[$odpId]['total']++;

            if ($online[$key] === false) {
                $stats[$odpId]['down']++;
            }
        }

        foreach ($stats as $odpId => $stat) {
            $stats[$odpId]['all_down'] = $stat['total'] > 0 && $stat['down'] >= $stat['total'];
        }

        return $stats;
    }

    /**
     * Peta "slot/port/onu_id" => odp_id untuk seluruh link ONU↔ODP OLT ini.
     * Dipakai evaluator untuk tahu ODP induk sebuah ONU.
     *
     * @return array<string, int>
     */
    public function linkIndex(SnmpOlt $olt): array
    {
        return $this->topologyFor($olt)['links'];
    }

    /**
     * Label pelanggan/ONU untuk baris di dalam grup — dipakai bersama Telegram & FCM.
     */
    public static function memberLabel(AlarmEvent $alarm): string
    {
        $customer = SmartOltSupport::cleanCustomerName(
            data_get($alarm->meta, 'customer_name'),
            (string) $alarm->serial_number,
        );

        if ($customer !== null && $customer !== '') {
            return $customer;
        }

        $onuName = data_get($alarm->meta, 'onu_name');
        if (filled($onuName)) {
            return (string) $onuName;
        }

        return $alarm->serial_number
            ?: sprintf('%d/%d:%d', $alarm->slot ?? 0, $alarm->port ?? 0, $alarm->onu_id ?? 0);
    }

    /**
     * Sebab down singkat (untuk baris di grup Telegram).
     */
    public static function causeLabel(string $type): string
    {
        return match ($type) {
            AlarmEvent::TYPE_LOS => 'LOS',
            AlarmEvent::TYPE_DYING_GASP => 'Power Off',
            AlarmEvent::TYPE_ONU_OFFLINE => 'Offline',
            default => $type,
        };
    }

    /**
     * Link ONU↔ODP + atribut ODP milik satu OLT (query sekali per instance).
     * Lookup pakai `withoutGlobalScope(PartnerOltScope)` agar deterministik di konteks
     * queue/polling (tak ada user yang login).
     *
     * @return array{links: array<string, int>, odps: array<int, array{name:string, slot:?int, port:?int}>}
     */
    private function topologyFor(SnmpOlt $olt): array
    {
        if (isset($this->topology[$olt->id])) {
            return $this->topology[$olt->id];
        }

        $links = [];

        OnuOdpLink::withoutGlobalScope(PartnerOltScope::class)
            ->where('snmp_olt_id', $olt->id)
            ->get(['odp_id', 'slot', 'port', 'onu_id'])
            ->each(function (OnuOdpLink $link) use (&$links) {
                $links["{$link->slot}/{$link->port}/{$link->onu_id}"] = (int) $link->odp_id;
            });

        $odps = [];

        if ($links !== []) {
            $odps = Odp::withoutGlobalScope(PartnerOltScope::class)
                ->whereIn('id', array_values(array_unique($links)))
                ->get(['id', 'name', 'slot', 'port'])
                ->mapWithKeys(fn (Odp $odp) => [$odp->id => [
                    'name' => (string) $odp->name,
                    'slot' => $odp->slot,
                    'port' => $odp->port,
                ]])
                ->all();
        }

        return $this->topology[$olt->id] = ['links' => $links, 'odps' => $odps];
    }

    /**
     * Peta "slot/port/onu_id" => bool online dari sebuah snapshot poll.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, bool>
     */
    private function onlineMap(array $snapshot): array
    {
        $map = [];

        foreach (($snapshot['port_onus'] ?? []) as $portData) {
            foreach ($portData['onus'] ?? [] as $onu) {
                $key = ((int) ($onu['slot'] ?? 0)).'/'.((int) ($onu['port'] ?? 0)).'/'.((int) ($onu['onu_id'] ?? 0));
                $map[$key] = (bool) ($onu['online'] ?? false);
            }
        }

        return $map;
    }

    private function onuKey(AlarmEvent $alarm): string
    {
        return ((int) $alarm->slot).'/'.((int) $alarm->port).'/'.((int) $alarm->onu_id);
    }

    /**
     * @param  array<int, AlarmEvent>  $members
     */
    private function maxSeverity(array $members): string
    {
        $best = AlarmEvent::SEVERITY_WARNING;
        $bestRank = 0;

        foreach ($members as $alarm) {
            $rank = AlarmEvent::SEVERITY_RANK[$alarm->severity] ?? 1;
            if ($rank > $bestRank) {
                $bestRank = $rank;
                $best = $alarm->severity;
            }
        }

        return $best;
    }
}
