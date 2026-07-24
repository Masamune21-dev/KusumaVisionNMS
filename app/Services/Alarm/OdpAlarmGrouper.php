<?php

namespace App\Services\Alarm;

use App\Models\AlarmEvent;
use App\Models\Odp;
use App\Models\OnuOdpLink;
use App\Models\Scopes\PartnerOltScope;
use App\Models\SnmpOlt;
use App\Support\SmartOltSupport;

/**
 * Mengelompokkan alarm "down" ONU (LOS / dying gasp / offline) per-ODP untuk
 * notifikasi (Telegram & push FCM), agar monitoring tak banjir pesan saat satu
 * ODP (splitter lapangan) putus dan menjatuhkan banyak pelanggan sekaligus.
 *
 * Aturan (permintaan owner):
 *  - 1 ONU down di sebuah ODP  → kirim biasa (pesan per-ONU seperti sebelumnya).
 *  - >1 ONU down di 1 ODP      → 1 pesan grup berisi daftar pelanggan yang down.
 *  - SEMUA ONU 1 ODP down      → cukup 1 pesan "ODP ini down (semua)".
 *  - ONU tanpa ODP / alarm non-down (port, RX, OLT) → tetap per-item.
 *
 * MURNI layer presentasi notifikasi — tiap ONU tetap punya baris AlarmEvent
 * sendiri di UI/riwayat; grouping ini tak mengubah evaluasi/pencatatan alarm.
 */
class OdpAlarmGrouper
{
    /** Jenis alarm ONU "down" yang boleh dikelompokkan per-ODP. */
    private const DOWN_TYPES = [
        AlarmEvent::TYPE_LOS,
        AlarmEvent::TYPE_DYING_GASP,
        AlarmEvent::TYPE_ONU_OFFLINE,
    ];

    /** Urutan severity untuk memilih emoji/severity wakil grup. */
    private const SEVERITY_RANK = [
        AlarmEvent::SEVERITY_WARNING => 1,
        AlarmEvent::SEVERITY_MINOR => 2,
        AlarmEvent::SEVERITY_MAJOR => 3,
        AlarmEvent::SEVERITY_CRITICAL => 4,
    ];

    /**
     * Ubah daftar alarm raised (yang SUDAH lolos filter severity/tipe penerima)
     * menjadi daftar item notifikasi: singleton atau grup ODP.
     *
     * @param  array<int, AlarmEvent>  $alarms
     * @return array<int, array<string, mixed>> item: {kind:'single',alarm} | {kind:'odp',odp_id,odp_name,members,total,down_count,all_down,severity}
     */
    public function group(SnmpOlt $olt, array $alarms): array
    {
        if ($alarms === []) {
            return [];
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
        $odpNames = $this->odpNames(array_keys($byOdp));
        $downStats = $this->downStats($olt, array_keys($byOdp));

        foreach ($byOdp as $odpId => $members) {
            // Hanya 1 ONU down di ODP ini → tetap kirim biasa (per-ONU).
            if (count($members) < 2) {
                $singles[] = $members[0];

                continue;
            }

            [$total, $down] = $downStats[$odpId] ?? [count($members), count($members)];

            $items[] = [
                'kind' => 'odp',
                'odp_id' => $odpId,
                'odp_name' => $odpNames[$odpId] ?? ('ODP #'.$odpId),
                'members' => $members,
                'total' => $total,
                'down_count' => $down,
                'all_down' => $total > 0 && $down >= $total,
                'severity' => $this->maxSeverity($members),
            ];
        }

        foreach ($singles as $alarm) {
            $items[] = ['kind' => 'single', 'alarm' => $alarm];
        }

        return $items;
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
            AlarmEvent::TYPE_DYING_GASP => 'Dying Gasp',
            AlarmEvent::TYPE_ONU_OFFLINE => 'Offline',
            default => $type,
        };
    }

    /**
     * Peta "slot/port/onu_id" => odp_id untuk seluruh link ONU↔ODP OLT ini.
     *
     * @return array<string, int>
     */
    private function linkIndex(SnmpOlt $olt): array
    {
        $index = [];

        OnuOdpLink::withoutGlobalScope(PartnerOltScope::class)
            ->where('snmp_olt_id', $olt->id)
            ->get(['odp_id', 'slot', 'port', 'onu_id'])
            ->each(function (OnuOdpLink $link) use (&$index) {
                $index["{$link->slot}/{$link->port}/{$link->onu_id}"] = $link->odp_id;
            });

        return $index;
    }

    /**
     * Nama ODP by id.
     *
     * @param  array<int, int>  $odpIds
     * @return array<int, string>
     */
    private function odpNames(array $odpIds): array
    {
        if ($odpIds === []) {
            return [];
        }

        return Odp::withoutGlobalScope(PartnerOltScope::class)
            ->whereIn('id', $odpIds)
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Per-ODP: [total ONU (yang muncul di snapshot), jumlah offline] untuk menentukan "semua down".
     * ONU link yang tak ada di snapshot (stale) diabaikan agar tak memblok deteksi "semua".
     *
     * @param  array<int, int>  $odpIds
     * @return array<int, array{0:int, 1:int}>
     */
    private function downStats(SnmpOlt $olt, array $odpIds): array
    {
        if ($odpIds === []) {
            return [];
        }

        $online = $this->onlineMap($olt);

        $links = OnuOdpLink::withoutGlobalScope(PartnerOltScope::class)
            ->where('snmp_olt_id', $olt->id)
            ->whereIn('odp_id', $odpIds)
            ->get(['odp_id', 'slot', 'port', 'onu_id']);

        $stats = [];
        foreach ($links as $link) {
            $key = "{$link->slot}/{$link->port}/{$link->onu_id}";
            if (! array_key_exists($key, $online)) {
                continue; // ONU tak ada di snapshot → abaikan.
            }

            $stats[$link->odp_id] ??= [0, 0];
            $stats[$link->odp_id][0]++;
            if ($online[$key] === false) {
                $stats[$link->odp_id][1]++;
            }
        }

        return $stats;
    }

    /**
     * Peta "slot/port/onu_id" => bool online dari snapshot poll terakhir OLT.
     *
     * @return array<string, bool>
     */
    private function onlineMap(SnmpOlt $olt): array
    {
        $map = [];

        foreach (($olt->last_test_result['port_onus'] ?? []) as $portData) {
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
            $rank = self::SEVERITY_RANK[$alarm->severity] ?? 1;
            if ($rank > $bestRank) {
                $bestRank = $rank;
                $best = $alarm->severity;
            }
        }

        return $best;
    }
}
