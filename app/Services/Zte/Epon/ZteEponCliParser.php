<?php

namespace App\Services\Zte\Epon;

use App\Support\ZteEpon;

/**
 * Parser murni (tanpa telnet/DB) untuk keluaran CLI EPON ZTE C300/C320. Semua bentuk keluaran di
 * bawah diambil dari contoh nyata pihak lain — belum pernah dibaca dari OLT ETG asli (lihat
 * {@see ZteEpon}); fixture-nya ada di tests/Unit/ZteEponCliParserTest.php beserta sumbernya.
 *
 * Parser dibuat toleran: ia mencari baris berpola khas (`epon-onu_1/s/p:n …`, `onu N type T mac M`)
 * di keluaran gabungan satu sesi telnet, jadi echo perintah, prompt, dan error perintah lain tak
 * mengganggu.
 */
class ZteEponCliParser
{
    private const MAC = '[0-9A-Fa-f]{4}\.[0-9A-Fa-f]{4}\.[0-9A-Fa-f]{4}';

    /**
     * `show card`: hanya kartu EPON (CfgType/RealType ETG…/ETT…). Kolom
     * `Rack Shelf Slot CfgType RealType Port HardVer SoftVer Status`; RealType kosong saat kartu
     * offline, jadi kolom dibaca dari token, bukan posisi karakter.
     *
     * @return list<array{slot:int, cfg_type:string, real_type:string|null, ports:int, status:string}>
     */
    public function eponCards(string $output): array
    {
        $cards = [];

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $parts = preg_split('/\s+/', trim($line)) ?: [];

            if (count($parts) < 5 || ! ctype_digit($parts[0]) || ! ctype_digit($parts[1]) || ! ctype_digit($parts[2])) {
                continue;
            }

            $cfgType = $parts[3];
            $realType = ctype_digit($parts[4]) ? null : $parts[4];
            $portToken = $realType === null ? $parts[4] : ($parts[5] ?? '');

            if (! ZteEpon::isEponCardType($cfgType) && ! ZteEpon::isEponCardType($realType)) {
                continue;
            }

            $ports = ctype_digit($portToken) ? (int) $portToken : 0;
            $cards[(int) $parts[2]] = [
                'slot' => (int) $parts[2],
                'cfg_type' => $cfgType,
                'real_type' => $realType,
                // ETGO = 8 port, ETGH = 16 port; kolom Port 0 (kartu belum siap) → pakai jumlah
                // bawaan per tipe supaya port tetap bisa dibuka.
                'ports' => $ports > 0 ? $ports : (str_starts_with(strtoupper($cfgType), 'ETGH') ? 16 : 8),
                'status' => strtoupper((string) end($parts)),
            ];
        }

        ksort($cards);

        return array_values($cards);
    }

    /**
     * `show epon onu state [epon-olt_1/s/p]`:
     *
     *   OnuIndex               OnlineStatus  OamStatus   RegMac
     *   epon-onu_1/4/8:1       Online       complete     1c87.7912.0c39
     *   epon-onu_1/4/8:2       Offline      idle         0000.0000.0000
     *
     * MAC `0000.0000.0000` (ONU offline) → null; MAC tetapnya diambil dari running-config.
     * Status bisa dua kata — C300 V2.1 (OLT penguji, 24 Okt 2026) menulis `Power Off   idle  0000.…`
     * — jadi kolom dibaca dari ujung: MAC, lalu OamStatus, sisanya status.
     *
     * @return list<array{slot:int, port:int, onu_id:int, status:string, oam_status:string|null, mac:string|null}>
     */
    public function onuStates(string $output): array
    {
        preg_match_all('/^\s*epon-onu_\d+\/(\d+)\/(\d+):(\d+)\s+(\S.*?)\s*$/mi', $output, $matches, PREG_SET_ORDER);

        $rows = [];
        foreach ($matches as $m) {
            $tokens = preg_split('/\s+/', $m[4]) ?: [];
            $mac = null;
            $oam = null;
            if (count($tokens) > 1 && preg_match('/^[0-9a-f]{4}\.[0-9a-f]{4}\.[0-9a-f]{4}$/i', (string) end($tokens)) === 1) {
                $mac = array_pop($tokens);
            }
            if (count($tokens) > 1 && ($mac !== null || preg_match('/^(complete|idle|discovery|incomplete)$/i', (string) end($tokens)) === 1)) {
                $oam = array_pop($tokens);
            }

            $key = "{$m[1]}_{$m[2]}_{$m[3]}";
            $rows[$key] = [
                'slot' => (int) $m[1],
                'port' => (int) $m[2],
                'onu_id' => (int) $m[3],
                'status' => implode(' ', $tokens),
                'oam_status' => $oam,
                'mac' => ZteEpon::displayMac($mac),
            ];
        }

        return array_values($rows);
    }

    /**
     * Status `OnlineStatus` → bentuk snapshot yang dibaca AlarmEvaluator (`online`, `phase_state`
     * 'LOS'/'DyingGasp' memicu jenis alarm khusus; selain itu offline biasa).
     *
     * @return array{online:bool, phase_state:string, last_down_cause:string|null}
     */
    public function statusFields(string $status): array
    {
        $lower = strtolower($status);

        return match (true) {
            str_starts_with($lower, 'online') => ['online' => true, 'phase_state' => 'Online', 'last_down_cause' => null],
            str_contains($lower, 'los') => ['online' => false, 'phase_state' => 'LOS', 'last_down_cause' => 'LOS'],
            str_contains($lower, 'dying') => ['online' => false, 'phase_state' => 'DyingGasp', 'last_down_cause' => 'DyingGasp'],
            str_contains($lower, 'power') => ['online' => false, 'phase_state' => 'PowerOff', 'last_down_cause' => 'DyingGasp'],
            default => ['online' => false, 'phase_state' => $status !== '' ? ucfirst($lower) : 'Offline', 'last_down_cause' => null],
        };
    }

    /**
     * `show running-config interface epon-olt_1/s/p` → ONU terdaftar per port:
     *
     *   interface epon-olt_1/4/8
     *     onu 1 type universalOnuType mac 1c87.7912.0c39 ip-cfg static
     *
     * @return array<string, array<int, array{onu_id:int, type_name:string, mac:string|null}>> kunci "{slot}_{port}"
     */
    public function registeredOnus(string $output): array
    {
        $result = [];
        $current = null;

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match('/^\s*interface\s+epon-olt_\d+\/(\d+)\/(\d+)\s*$/i', $line, $m)) {
                $current = "{$m[1]}_{$m[2]}";
                $result[$current] ??= [];

                continue;
            }

            // Blok interface lain (gpon-olt, epon-onu, …) atau akhir blok `!` menutup konteks.
            if (preg_match('/^\s*(interface\s|!|end\b|pon-onu-mng\s)/i', $line)) {
                $current = null;

                continue;
            }

            if ($current !== null && preg_match('/^\s*onu\s+(\d+)\s+type\s+(\S+)(?:\s+mac\s+('.self::MAC.'))?/i', $line, $m)) {
                $result[$current][(int) $m[1]] = [
                    'onu_id' => (int) $m[1],
                    'type_name' => $m[2],
                    'mac' => ZteEpon::displayMac($m[3] ?? null),
                ];
            }
        }

        return $result;
    }

    /**
     * `show running-config interface epon-onu_1/s/p:n` → info per ONU. Nama/deskripsi EPON
     * disimpan dalam SATU baris `property description $$Nama$$Deskripsi` (ONU EPON tak punya
     * perintah `name`/`description` terpisah seperti GPON); `name`/`description` tetap dibaca
     * bila ada.
     *
     * @return array<string, array{name:string|null, description:string|null, sla_profile:string|null, vlans:list<int>}> kunci "{slot}_{port}_{onu}"
     */
    public function onuConfigs(string $output): array
    {
        $result = [];
        $current = null;

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match('/^\s*interface\s+epon-onu_\d+\/(\d+)\/(\d+):(\d+)\s*$/i', $line, $m)) {
                $current = "{$m[1]}_{$m[2]}_{$m[3]}";
                $result[$current] ??= ['name' => null, 'description' => null, 'sla_profile' => null, 'vlans' => []];

                continue;
            }

            if (preg_match('/^\s*(interface\s|!|end\b|pon-onu-mng\s)/i', $line)) {
                $current = null;

                continue;
            }

            if ($current === null) {
                continue;
            }

            $trimmed = trim($line);

            if (preg_match('/^property\s+description\s+(.+)$/i', $trimmed, $m)) {
                [$name, $description] = $this->splitPropertyDescription($m[1]);
                $result[$current]['name'] = $name;
                $result[$current]['description'] = $description;
            } elseif (preg_match('/^name\s+(.+)$/i', $trimmed, $m)) {
                $result[$current]['name'] = trim($m[1]);
            } elseif (preg_match('/^description\s+(.+)$/i', $trimmed, $m)) {
                $result[$current]['description'] = trim($m[1]);
            } elseif (preg_match('/^sla-profile\s+(\S+)/i', $trimmed, $m)) {
                $result[$current]['sla_profile'] = $m[1];
            } elseif (preg_match('/^service-port\s+\d+\s+.*?(?<!user-)vlan\s+(\d+)/i', $trimmed, $m)
                // `service-port 1 vport 1 user-vlan 1120 transparent` (C300 V2.1): VLAN diteruskan apa adanya.
                || preg_match('/^service-port\s+\d+\s+.*?user-vlan\s+(\d+)\s+transparent\b/i', $trimmed, $m)
                || preg_match('/^switchport\s+vlan\s+(\d+)/i', $trimmed, $m)) {
                $result[$current]['vlans'][] = (int) $m[1];
            }
        }

        foreach ($result as $key => $row) {
            $result[$key]['vlans'] = array_values(array_unique($row['vlans']));
        }

        return $result;
    }

    /**
     * `$$Nama$$Deskripsi` → [nama, deskripsi]. Tanpa `$$` seluruh teks dianggap nama.
     *
     * @return array{0:string|null, 1:string|null}
     */
    public function splitPropertyDescription(string $value): array
    {
        $value = trim($value);

        if (! str_contains($value, '$$')) {
            return [$value !== '' ? $value : null, null];
        }

        $parts = array_values(array_filter(array_map('trim', explode('$$', $value)), fn (string $p): bool => $p !== ''));

        return [$parts[0] ?? null, $parts[1] ?? null];
    }

    /**
     * `show onu unauthentication epon-olt_1/s/p` — blok key : value per ONU:
     *
     *   Onu Interface    : epon-onu_1/2/1:1
     *   Onu Model        : RL-4W
     *   MAC Address      : 1c18.4a0d.d30f
     *   …
     *   Online State     : authentication deny
     *
     * Bentuk tabel (`show epon onu uncfg`/`show pon onu uncfg`) juga diterima: baris
     * `epon-olt_1/s/p  Model  SN/MAC …` atau `epon-onu_1/s/p:n  SN/MAC …` yang memuat MAC.
     *
     * @return list<array{slot:int, port:int, mac:string, model:string|null, serial:string|null, loid:string|null, state:string|null, firmware:string|null}>
     */
    public function unauthenticated(string $output): array
    {
        $rows = [];
        $block = null;

        $flush = function () use (&$block, &$rows): void {
            if ($block !== null && isset($block['slot'], $block['mac'])) {
                $rows[$block['mac']] = $block;
            }
            $block = null;
        };

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (! preg_match('/^\s*([A-Za-z][A-Za-z _\/-]*?)\s*:\s*(.*?)\s*$/', $line, $kv)) {
                // Bentuk tabel: satu baris = satu ONU.
                if (preg_match('/^\s*epon-(?:olt|onu)_\d+\/(\d+)\/(\d+)(?::\d+)?\s+(.*)$/i', $line, $t)
                    && preg_match('/('.self::MAC.'|\b[0-9A-Fa-f]{12}\b)/', $t[3], $mac)) {
                    $display = ZteEpon::displayMac($mac[1]);
                    if ($display !== null) {
                        $tokens = preg_split('/\s+/', trim($t[3])) ?: [];
                        $model = ($tokens[0] ?? null) !== $mac[1] ? ($tokens[0] ?? null) : null;
                        $rows[$display] = [
                            'slot' => (int) $t[1],
                            'port' => (int) $t[2],
                            'mac' => $display,
                            'model' => $model,
                            'serial' => null,
                            'loid' => null,
                            'state' => null,
                            'firmware' => null,
                        ];
                    }
                }

                continue;
            }

            $key = strtolower(preg_replace('/\s+/', ' ', trim($kv[1])) ?? '');
            $value = trim($kv[2]);

            if ($key === 'onu interface') {
                $flush();
                if (preg_match('/epon-onu_\d+\/(\d+)\/(\d+)/i', $value, $m)) {
                    $block = [
                        'slot' => (int) $m[1],
                        'port' => (int) $m[2],
                        'model' => null,
                        'serial' => null,
                        'loid' => null,
                        'state' => null,
                        'firmware' => null,
                    ];
                }

                continue;
            }

            if ($block === null) {
                continue;
            }

            match ($key) {
                'onu model' => $block['model'] = $value !== '' ? $value : null,
                'mac address' => ($mac = ZteEpon::displayMac($value)) !== null ? $block['mac'] = $mac : null,
                'sn' => $block['serial'] = $value !== '' ? $value : null,
                'loid' => $block['loid'] = $value !== '' ? $value : null,
                'online state' => $block['state'] = $value !== '' ? $value : null,
                'software version' => $block['firmware'] = $value !== '' ? $value : null,
                default => null,
            };
        }

        $flush();

        return array_values($rows);
    }

    /**
     * Rx ONU per port: baris `epon-onu_1/s/p:n   -20.530(dbm)` — bentuk yang sama dengan
     * `show pon power onu-rx` GPON. Belum ada contoh keluaran EPON asli; bila firmware menolak
     * perintahnya, hasilnya kosong dan RX ONU EPON tampil "-".
     *
     * @return array<string, float> kunci "{slot}_{port}_{onu}"
     */
    public function onuRxPowers(string $output): array
    {
        preg_match_all('/epon-onu_\d+\/(\d+)\/(\d+):(\d+)\s+(-?\d+(?:\.\d+)?)\s*\(?dbm\)?/i', $output, $matches, PREG_SET_ORDER);

        $powers = [];
        foreach ($matches as $m) {
            $dbm = (float) $m[4];
            // Jendela wajar Rx ONU; nilai di luar itu = sampah/sentinel.
            if ($dbm > -50.0 && $dbm < 10.0) {
                $powers["{$m[1]}_{$m[2]}_{$m[3]}"] = $dbm;
            }
        }

        return $powers;
    }

    /**
     * `show onu-type epon` → nama onu-type ber-`PON type: epon`.
     *
     * @return list<string>
     */
    public function eponOnuTypes(string $output): array
    {
        preg_match_all('/ONU type name:\s*([^\r\n]+)(.*?)(?=ONU type name:|$)/is', $output, $matches, PREG_SET_ORDER);

        $names = [];
        foreach ($matches as $m) {
            if (preg_match('/PON type:\s*epon\b/i', $m[2]) && preg_match('/^[A-Za-z0-9._-]{1,64}$/', trim($m[1]))) {
                $names[] = trim($m[1]);
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Potong keluaran satu sesi `ZteCliProvisioningExecutor` per perintah. Executor menandai tiap
     * perintah dengan baris `> {perintah}` sebelum keluarannya.
     *
     * @return list<array{command:string, output:string}>
     */
    public function splitByCommand(string $output): array
    {
        $parts = preg_split('/^> (.+)$/m', $output, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $chunks = [];

        for ($i = 1; $i < count($parts); $i += 2) {
            $chunks[] = ['command' => trim($parts[$i]), 'output' => (string) ($parts[$i + 1] ?? '')];
        }

        return $chunks;
    }

    /**
     * Gabungan keluaran semua perintah yang diawali $prefix.
     */
    /**
     * Galat executor yang isinya HANYA `%Code 70529: No related information to show.` — jawaban C300
     * V2.1 (OLT penguji, 24 Okt 2026) untuk `show onu unauthentication …` saat tak ada ONU baru. Itu
     * hasil kosong, bukan galat. Format galat executor: "`perintah` → pesan; `perintah` → pesan".
     */
    public function onlyNoInformation(?string $error): bool
    {
        if ($error === null || trim($error) === '') {
            return false;
        }

        $parts = preg_split('/;\s*(?=`)/', $error) ?: [];

        foreach ($parts as $part) {
            if (preg_match('/No related information|Code 70529/i', $part) !== 1) {
                return false;
            }
        }

        return $parts !== [];
    }

    public function outputOf(string $output, string $prefix): string
    {
        $buf = [];
        foreach ($this->splitByCommand($output) as $chunk) {
            if (str_starts_with(strtolower($chunk['command']), strtolower($prefix))) {
                $buf[] = $chunk['output'];
            }
        }

        return implode("\n", $buf);
    }
}
