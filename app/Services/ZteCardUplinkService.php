<?php

namespace App\Services;

use App\Models\SnmpOlt;
use Illuminate\Support\Facades\Cache;

class ZteCardUplinkService
{
    // Card types that provide uplink via xgei_ (10GbE)
    private const XGEI_CARDS = ['HUVQ', 'HUVG', 'HUVX'];

    // Card types that provide uplink via gei_ (1GbE) — C320 control+uplink combo
    private const GEI_CARDS = ['SMXA', 'SMXB'];

    public function __construct(private ZteCliProvisioningExecutor $executor) {}

    /**
     * @return array<int, array{rack:int, shelf:int, slot:int, cfg_type:string, real_type:string, port_count:int, hard_ver:string, soft_ver:string, status:string}>
     */
    public function getCardStatus(SnmpOlt $olt): array
    {
        return Cache::remember("olt:{$olt->id}:cards", 300, function () use ($olt) {
            $result = $this->executor->execute($olt, 'show card');

            return $this->parseCards($result['output']);
        });
    }

    /**
     * Force-refresh card status cache from OLT.
     *
     * @return array<int, array{rack:int, shelf:int, slot:int, cfg_type:string, real_type:string, port_count:int, hard_ver:string, soft_ver:string, status:string}>
     */
    public function refreshCardStatus(SnmpOlt $olt): array
    {
        Cache::forget("olt:{$olt->id}:cards");

        return $this->getCardStatus($olt);
    }

    /**
     * Derive uplink interface names from card list.
     *
     * @param  array<int, array{rack:int, shelf:int, slot:int, cfg_type:string, real_type:string, port_count:int, status:string}>  $cards
     * @return array<int, array{interface:string, card_type:string, slot:int}>
     */
    public function discoverUplinkInterfaces(array $cards): array
    {
        $interfaces = [];

        foreach ($cards as $card) {
            if ($card['status'] === 'OFFLINE' || $card['status'] === 'EMPTY' || $card['status'] === 'PWROFF') {
                continue;
            }

            $cfgType = strtoupper($card['cfg_type']);

            if (in_array($cfgType, self::XGEI_CARDS, true)) {
                // HUVQ: xgei_1/{slot}/1 and xgei_1/{slot}/2 only
                for ($p = 1; $p <= 2; $p++) {
                    $interfaces[] = [
                        'interface' => "xgei_1/{$card['slot']}/{$p}",
                        'card_type' => $cfgType,
                        'slot' => $card['slot'],
                    ];
                }
            } elseif (in_array($cfgType, self::GEI_CARDS, true)) {
                // SMXA: gei_1/{slot}/1..port_count
                $portCount = max(1, (int) $card['port_count']);
                for ($p = 1; $p <= $portCount; $p++) {
                    $interfaces[] = [
                        'interface' => "gei_1/{$card['slot']}/{$p}",
                        'card_type' => $cfgType,
                        'slot' => $card['slot'],
                    ];
                }
            }
        }

        return $interfaces;
    }

    /**
     * @return array{interface:string, line_status:string, input_bps:int, output_bps:int, input_pps:int, output_pps:int, timestamp:int}
     */
    public function getUplinkInfo(SnmpOlt $olt, string $interface): array
    {
        $result = $this->executor->execute($olt, "show interface {$interface}");
        $output = $result['output'];

        // Status: "xgei_1/20/1 is up," or "xgei_1/19/1 is administratively down,"
        $lineStatus = 'unknown';
        if (preg_match('/\b' . preg_quote($interface, '/') . '\b\s+is\s+(administratively\s+down|up|down)/i', $output, $m)) {
            $raw = strtolower(trim($m[1]));
            $lineStatus = $raw === 'up' ? 'up' : ($raw === 'administratively down' ? 'admin-down' : 'down');
        }

        // Traffic: "20 seconds input rate :  171277875 Bps,  147488 pps"
        $inputBps = 0;
        $outputBps = 0;
        $inputPps = 0;
        $outputPps = 0;

        if (preg_match('/\d+\s+seconds\s+input\s+rate\s*:\s*(\d+)\s+Bps,\s*(\d+)\s+pps/i', $output, $m)) {
            $inputBps = (int) $m[1];
            $inputPps = (int) $m[2];
        }

        if (preg_match('/\d+\s+seconds\s+output\s+rate\s*:\s*(\d+)\s+Bps,\s*(\d+)\s+pps/i', $output, $m)) {
            $outputBps = (int) $m[1];
            $outputPps = (int) $m[2];
        }

        return [
            'interface' => $interface,
            'line_status' => $lineStatus,
            'input_bps' => $inputBps,
            'output_bps' => $outputBps,
            'input_pps' => $inputPps,
            'output_pps' => $outputPps,
            'timestamp' => time(),
        ];
    }

    /**
     * @return array{interface:string, tagged_vlans:string[], raw:string}
     */
    public function getVlanMapping(SnmpOlt $olt, string $interface): array
    {
        return Cache::remember("olt:{$olt->id}:vlans:{$interface}", 300, function () use ($olt, $interface) {
            $result = $this->executor->execute($olt, "show vlan port {$interface}");

            return [
                'interface' => $interface,
                'tagged_vlans' => $this->parseTaggedVlans($result['output']),
                'raw' => $result['output'],
            ];
        });
    }

    public function invalidateVlanCache(SnmpOlt $olt, string $interface): void
    {
        Cache::forget("olt:{$olt->id}:vlans:{$interface}");
    }

    /**
     * Force-refresh VLAN mapping cache.
     *
     * @return array{interface:string, tagged_vlans:string[], raw:string}
     */
    public function refreshVlanMapping(SnmpOlt $olt, string $interface): array
    {
        $this->invalidateVlanCache($olt, $interface);

        return $this->getVlanMapping($olt, $interface);
    }

    /**
     * @return array{ok:bool, output:string, error:string|null}
     */
    public function addAndTagVlan(SnmpOlt $olt, string $interface, int $vlanId): array
    {
        $script = implode("\n", [
            'configure terminal',
            "vlan {$vlanId}",
            'exit',
            "interface {$interface}",
            "switchport vlan {$vlanId} tag",
            'end',
            'write',
        ]);

        $result = $this->executor->execute($olt, $script);

        if ($result['ok']) {
            $this->invalidateVlanCache($olt, $interface);
        }

        return $result;
    }

    /**
     * Parse `show card` output into structured card list.
     *
     * @return array<int, array{rack:int, shelf:int, slot:int, cfg_type:string, real_type:string, port_count:int, hard_ver:string, soft_ver:string, status:string}>
     */
    private function parseCards(string $output): array
    {
        $cards = [];

        // Statuses to match at end of line
        $statusPattern = 'INSERVICE|STANDBY|OFFLINE|EMPTY|PWROFF|PROV';

        foreach (explode("\n", $output) as $line) {
            $line = trim($line);

            // Expect: Rack Shelf Slot CfgType [RealType] Port [HardVer] [SoftVer] Status
            // Example: "1    1     2    GTGO    GTGOG    8     V1.0.0  V2.1.0   INSERVICE"
            if (! preg_match(
                '/^(\d+)\s+(\d+)\s+(\d+)\s+(\S+)(?:\s+(\S+))?\s+(\d+)\s+(?:(\S+)\s+)?(?:(\S+)\s+)?(' . $statusPattern . ')\s*$/i',
                $line,
                $m
            )) {
                continue;
            }

            $cards[] = [
                'rack' => (int) $m[1],
                'shelf' => (int) $m[2],
                'slot' => (int) $m[3],
                'cfg_type' => strtoupper($m[4]),
                'real_type' => isset($m[5]) ? strtoupper($m[5]) : '',
                'port_count' => (int) $m[6],
                'hard_ver' => $m[7] ?? '',
                'soft_ver' => $m[8] ?? '',
                'status' => strtoupper($m[9]),
            ];
        }

        return $cards;
    }

    /**
     * Parse tagged VLANs from `show vlan port` output.
     * Returns an array of range strings like ["1", "15", "20-120", "122"].
     * Handles multi-line VLAN lists and \r\n line endings.
     *
     * @return string[]
     */
    private function parseTaggedVlans(string $output): array
    {
        // Normalise line endings
        $output = str_replace("\r\n", "\n", $output);
        $output = str_replace("\r", "\n", $output);

        $lines = explode("\n", $output);
        $collecting = false;
        $vlanBlock = '';

        foreach ($lines as $line) {
            $line = trim($line);

            if (stripos($line, 'TaggedVlan:') !== false) {
                $collecting = true;
                // Content may appear on the same line after the colon
                $inline = trim(substr($line, strpos($line, ':') + 1));
                if ($inline !== '' && preg_match('/^[\d,\-]+$/', $inline)) {
                    $vlanBlock .= ($vlanBlock ? ',' : '') . $inline;
                }
                continue;
            }

            if (! $collecting) {
                continue;
            }

            if ($line === '') {
                continue;
            }

            // Accumulate lines that are pure VLAN range notation
            if (preg_match('/^[\d,\-]+$/', $line)) {
                $vlanBlock .= ($vlanBlock ? ',' : '') . $line;
            } else {
                // Any other content (prompt, next section) ends collection
                break;
            }
        }

        if ($vlanBlock === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $vlanBlock)),
            fn (string $v) => $v !== ''
        ));
    }
}
