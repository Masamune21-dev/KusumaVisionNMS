<?php

namespace App\Services\Zte\Epon;

use App\Models\SnmpOlt;
use App\Services\Snmp\OltSnmpClient;
use App\Support\ZteEpon;
use Throwable;

/**
 * Kartu, port, dan ONU EPON ZTE C300/C320 lewat SNMP. Semua OID di sini DIBACA dari perangkat asli
 * (24 Okt 2026): C300 V2.1.0 berkartu ETGO milik penguji (2 kartu, 130 ONU), CLI belum tersambung.
 *
 *  - kartu: zxAnCardTable `1015.2.1.1.3.1.4` (nama, indeks rack.shelf.slot) — ETGO terbaca di slot 8 & 17;
 *  - port: IF-MIB ifName `epon_1/{slot}/{port}` (BUKAN `epon-olt_…` seperti CLI) + ifOperStatus —
 *    slot 8 port 1–4 up (port yang ber-ONU), 5–8 & slot 17 down;
 *  - ONU: tabel `1015.1010.1.1.1.1.1` (`.3` model mis. F663, `.4` MAC 6 byte) dan status
 *    `1015.1010.1.2.1.1.1` (1 = online, 2 = offline). Status dibuktikan lewat trafik: dua sampel
 *    penghitung `1015.1010.1.9.1.3.1.2` → 117/117 ONU berstatus 1 naik, 15/15 berstatus 2 diam.
 *    Indeks ONU = `0x3<<28 | slot<<19 | (port-1)<<16 | onuId<<8`, dicek silang dengan tabel RX sisi
 *    OLT `1015.1010.11.2.1.2` (indeks gaya GPON `{ifIndex port}.{onuId}`): 132/132 posisi cocok.
 *
 * Batas yang diketahui: (1) RX ONU tak ada di SNMP — tabel RX GPON `.1012.3.50.12.1.1.10` tak memuat
 * ONU EPON, `1015.1010.11.2.1.2` adalah RX sisi OLT dan hanya terisi di sebagian port, dan kolom optik
 * kandidat `1015.1010.1.1.1.23` menjawab genError → RX & nama ONU tetap lewat CLI. (2) Indeks ONU hanya
 * menyisakan 3 bit untuk port: kartu > 8 port (ETGH 16-port) belum terbukti, jadi untuk OLT seperti
 * itu pembaca ini menyerah dan jalur CLI yang dipakai. (3) Walk penuh subtree `1015.1010` berhenti
 * genError setelah kolom `.1.1.1.18`, jadi kolom dibaca satu per satu.
 */
class ZteEponSnmpReader
{
    private const BOARD_NAME = '1.3.6.1.4.1.3902.1015.2.1.1.3.1.4';

    private const BOARD_STATUS = '1.3.6.1.4.1.3902.1015.2.1.1.3.1.5';

    private const IF_NAME = '1.3.6.1.2.1.31.1.1.1.1';

    private const IF_OPER_STATUS = '1.3.6.1.2.1.2.2.1.8';

    private const ONU_MODEL = '1.3.6.1.4.1.3902.1015.1010.1.1.1.1.1.3';

    private const ONU_MAC = '1.3.6.1.4.1.3902.1015.1010.1.1.1.1.1.4';

    private const ONU_STATE = '1.3.6.1.4.1.3902.1015.1010.1.2.1.1.1';

    /** Port terbanyak yang bisa diwakili 3 bit port di indeks ONU EPON. */
    private const MAX_DECODABLE_PORTS = 8;

    public function __construct(private readonly OltSnmpClient $client) {}

    /**
     * Kartu EPON menurut zxAnCardTable. null = tabel tak terbaca (jalur CLI dipakai); [] = tak ada
     * kartu ETG/ETT di chassis.
     *
     * @return list<array{slot:int, cfg_type:string, real_type:null, ports:int, status:null, status_code:int|null}>|null
     */
    public function eponCards(SnmpOlt $olt): ?array
    {
        try {
            $names = $this->client->walk($olt, self::BOARD_NAME);
        } catch (Throwable) {
            return null;
        }

        if ($names === []) {
            return null;
        }

        $cards = [];
        foreach ($names as $oid => $name) {
            $slot = $this->boardSlot((string) $oid);
            if ($slot === null || ! ZteEpon::isEponCardType((string) $name)) {
                continue;
            }

            $cards[$slot] = [
                'slot' => $slot,
                'cfg_type' => strtoupper(trim((string) $name)),
                'real_type' => null,
                'ports' => 0,
                'status' => null,
                'status_code' => null,
            ];
        }

        if ($cards !== []) {
            try {
                foreach ($this->client->walk($olt, self::BOARD_STATUS) as $oid => $code) {
                    $slot = $this->boardSlot((string) $oid);
                    if ($slot !== null && isset($cards[$slot]) && is_numeric($code)) {
                        $cards[$slot]['status_code'] = (int) $code;
                    }
                }
            } catch (Throwable) {
                // Kode status kartu hanya informasi; artinya belum terpetakan.
            }
        }

        ksort($cards);

        return array_values($cards);
    }

    /**
     * Port & ONU EPON untuk kartu yang sudah ditemukan {@see eponCards()}. null = tak bisa dipetakan
     * lewat SNMP (nama port IF-MIB tak dikenali, kartu > 8 port, atau tabel status ONU tak terbaca).
     *
     * @param  list<array<string, mixed>>  $cards
     * @return array{
     *     cards: list<array<string, mixed>>,
     *     ports: list<array<string, mixed>>,
     *     onus: array<string, array<int, array{state_code:int, mac:string|null, model:string|null}>>,
     *     details_ok: bool
     * }|null
     */
    public function inventory(SnmpOlt $olt, array $cards): ?array
    {
        $slots = [];
        foreach ($cards as $card) {
            $slots[(int) $card['slot']] = true;
        }

        try {
            $ifNames = $this->client->walk($olt, self::IF_NAME);
        } catch (Throwable) {
            return null;
        }

        $ports = [];
        foreach ($ifNames as $oid => $name) {
            if (preg_match('/^epon(?:-olt)?_1\/(\d+)\/(\d+)$/i', trim((string) $name), $m) !== 1 || ! isset($slots[(int) $m[1]])) {
                continue;
            }

            $ifIndex = (int) substr((string) $oid, strrpos((string) $oid, '.') + 1);
            $ports["{$m[1]}_{$m[2]}"] = [
                'if_index' => $ifIndex,
                'name' => ZteEpon::oltInterface((int) $m[1], (int) $m[2]),
                'if_name' => trim((string) $name),
                'if_descr' => null,
                'slot' => (int) $m[1],
                'port' => (int) $m[2],
                'oper_status_code' => null,
                'oper_status' => 'unknown',
                'pon_type' => 'epon',
                'card_type' => null,
            ];
        }

        if ($ports === []) {
            return null;
        }

        foreach ($cards as $i => $card) {
            $count = count(array_filter($ports, fn (array $p): bool => $p['slot'] === (int) $card['slot']));
            if ($count > self::MAX_DECODABLE_PORTS) {
                return null;
            }
            $cards[$i]['ports'] = $count;
            foreach ($ports as $key => $port) {
                if ($port['slot'] === (int) $card['slot']) {
                    $ports[$key]['card_type'] = $card['cfg_type'];
                }
            }
        }

        try {
            $byIfIndex = [];
            foreach ($ports as $key => $port) {
                $byIfIndex[$port['if_index']] = $key;
            }
            foreach ($this->client->walk($olt, self::IF_OPER_STATUS) as $oid => $code) {
                $ifIndex = (int) substr((string) $oid, strrpos((string) $oid, '.') + 1);
                if (isset($byIfIndex[$ifIndex]) && is_numeric($code)) {
                    $key = $byIfIndex[$ifIndex];
                    $ports[$key]['oper_status_code'] = (int) $code;
                    $ports[$key]['oper_status'] = match ((int) $code) {
                        1 => 'up',
                        2 => 'down',
                        default => 'unknown',
                    };
                }
            }
        } catch (Throwable) {
            // Tanpa ifOperStatus port tetap 'unknown' (bukan 'down') — tak ada alarm port palsu.
        }

        try {
            $states = $this->client->walk($olt, self::ONU_STATE);
        } catch (Throwable) {
            return null;
        }

        $onus = [];
        foreach ($states as $oid => $code) {
            $pos = self::decodeOnuIndex((int) substr((string) $oid, strlen(self::ONU_STATE) + 1));
            if ($pos === null || ! isset($ports["{$pos['slot']}_{$pos['port']}"]) || ! is_numeric($code)) {
                continue;
            }
            $onus["{$pos['slot']}_{$pos['port']}"][$pos['onu_id']] = ['state_code' => (int) $code, 'mac' => null, 'model' => null];
        }

        // MAC & model melengkapi; gagal baca → baris tetap ada, MAC/model dibawa dari snapshot lama.
        $detailsOk = true;
        foreach ([self::ONU_MAC => 'mac', self::ONU_MODEL => 'model'] as $base => $field) {
            try {
                $rows = $this->client->walk($olt, $base, $field === 'mac');
            } catch (Throwable) {
                $detailsOk = false;

                continue;
            }

            foreach ($rows as $oid => $value) {
                $pos = self::decodeOnuIndex((int) substr((string) $oid, strlen($base) + 1));
                $key = $pos === null ? null : "{$pos['slot']}_{$pos['port']}";
                if ($key === null || ! isset($onus[$key][$pos['onu_id']])) {
                    continue;
                }
                $onus[$key][$pos['onu_id']][$field] = $field === 'mac' ? self::macFromOctets((string) $value) : (trim((string) $value) ?: null);
            }
        }

        return [
            'cards' => $cards,
            'ports' => ZteEpon::sortPorts($ports),
            'onus' => $onus,
            'details_ok' => $detailsOk,
        ];
    }

    /**
     * Indeks tabel ONU EPON → posisi. `0x3<<28 | slot<<19 | (port-1)<<16 | onuId<<8` (byte terendah 0).
     *
     * @return array{slot:int, port:int, onu_id:int}|null
     */
    public static function decodeOnuIndex(int $index): ?array
    {
        if (($index >> 28) !== 3 || ($index & 0xFF) !== 0) {
            return null;
        }

        $onuId = ($index >> 8) & 0xFF;
        $slot = ($index >> 19) & 0x1F;

        if ($onuId < 1 || $slot < 1) {
            return null;
        }

        return ['slot' => $slot, 'port' => (($index >> 16) & 0x7) + 1, 'onu_id' => $onuId];
    }

    /** Kebalikan {@see decodeOnuIndex()} — dipakai test. */
    public static function encodeOnuIndex(int $slot, int $port, int $onuId): int
    {
        return (3 << 28) | ($slot << 19) | (($port - 1) << 16) | ($onuId << 8);
    }

    /**
     * MAC 6 byte (nilai SNMP mentah) → `AA:BB:CC:DD:EE:FF`. Menerima juga teks hex (`64 F8 8A …`) bila
     * ekstensi mengembalikannya dalam bentuk itu.
     */
    public static function macFromOctets(string $value): ?string
    {
        if (strlen($value) === 6) {
            return ZteEpon::displayMac(bin2hex($value));
        }

        return ZteEpon::displayMac(trim($value, "\" \t\n\r"));
    }

    private function boardSlot(string $oid): ?int
    {
        // indeks = rack.shelf.slot
        if (preg_match('/\.(\d+)\.(\d+)\.(\d+)$/', $oid, $m) !== 1) {
            return null;
        }

        return (int) $m[3];
    }
}
