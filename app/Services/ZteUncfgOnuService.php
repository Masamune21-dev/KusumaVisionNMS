<?php

namespace App\Services;

use App\Models\SnmpOlt;

/**
 * Discovery ONU yang belum dikonfigurasi pada OLT ZTE, LANGSUNG dari CLI
 * (`show gpon onu uncfg`) — sengaja bukan dari cache polling/SNMP supaya pemakainya
 * (bot Telegram /uncfg) melihat ONU baru yang tercolok detik itu juga.
 *
 * Format output terverifikasi live di OLT-C320-PATI:
 *
 *     OnuIndex                 Sn                  State
 *     ---------------------------------------------------------------------
 *     gpon-onu_1/2/2:1         ZTEGCD7D2FD6        unknown
 *
 * Tanpa ONU uncfg, tabel kosong (tidak ada baris `gpon-onu_...`).
 */
class ZteUncfgOnuService
{
    public function __construct(private readonly ZteCliProvisioningExecutor $executor) {}

    /**
     * @return array{ok:bool, onus:array<int, array{interface:string, slot:int, port:int, seq:int|null, serial_number:string, state:string|null}>, error:string|null}
     */
    public function fetch(SnmpOlt $olt): array
    {
        $result = $this->executor->execute($olt, "terminal length 0\nshow gpon onu uncfg");

        if (! ($result['ok'] ?? false)) {
            return ['ok' => false, 'onus' => [], 'error' => $result['error'] ?? 'Eksekusi CLI gagal'];
        }

        return ['ok' => true, 'onus' => $this->parse((string) $result['output']), 'error' => null];
    }

    /**
     * @return array<int, array{interface:string, slot:int, port:int, seq:int|null, serial_number:string, state:string|null}>
     */
    private function parse(string $output): array
    {
        $onus = [];
        $seen = [];

        foreach (preg_split('/\r\n|\r|\n/', $output) ?: [] as $line) {
            // Lewati echo perintah ("> show gpon onu uncfg") — hanya baris data tabel.
            if (str_starts_with(ltrim($line), '>')) {
                continue;
            }

            if (! preg_match('/^\s*(gpon[-_]onu[-_](\d+)\/(\d+)\/(\d+)(?::(\d+))?)\s+([0-9A-Za-z]{8,16})(?:\s+(\S+))?/', $line, $m)) {
                continue;
            }

            $sn = strtoupper($m[6]);
            if (isset($seen[$sn])) {
                continue;
            }
            $seen[$sn] = true;

            $onus[] = [
                'interface' => $m[1],
                'slot' => (int) $m[3],
                'port' => (int) $m[4],
                'seq' => ($m[5] ?? '') !== '' ? (int) $m[5] : null,
                'serial_number' => $sn,
                'state' => ($m[7] ?? '') !== '' ? $m[7] : null,
            ];
        }

        usort($onus, fn (array $a, array $b) => [$a['slot'], $a['port'], $a['serial_number']] <=> [$b['slot'], $b['port'], $b['serial_number']]);

        return $onus;
    }
}
