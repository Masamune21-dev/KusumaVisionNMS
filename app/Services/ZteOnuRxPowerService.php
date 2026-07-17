<?php

namespace App\Services;

use App\Models\SnmpOlt;
use App\Support\SmartOltSupport;

class ZteOnuRxPowerService
{
    public function __construct(private readonly ZteCliProvisioningExecutor $executor) {}

    /**
     * @return array{ok:bool, powers:array<int, array<string, mixed>>, output:string, error:string|null}
     */
    public function portRxPower(SnmpOlt $olt, int $slot, int $port): array
    {
        $iface = SmartOltSupport::gponOltInterface($slot, $port, SmartOltSupport::isC600($olt));
        $result = $this->executor->execute($olt, "terminal length 0\nshow pon power onu-rx {$iface}");

        return [
            'ok' => $result['ok'],
            'powers' => $this->parse($result['output'], SmartOltSupport::isC600($olt)),
            'output' => $result['output'],
            'error' => $result['error'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function parse(string $output, bool $isC600 = false): array
    {
        // Baik C300/C320 (`gpon-onu_1/{slot}/{port}:{id}`) maupun C600
        // (`gpon_onu-1/{slot}/{port}:{id}`) melistkan ONU sebagai interface 3-tier; hanya
        // ejaan prefiks yang beda (dash/underscore ditukar). Cabang C600 lama mengasumsikan
        // bentuk 4-tier `gpon-onu_1/1/{slot}/{port}` — itu keliru; dikoreksi agar sesuai
        // penamaan C600 yang terverifikasi (lihat SmartOltSupport::onuInterfaceId).
        $pattern = '/gpon[-_]onu[-_]\d+\/(\d+)\/(\d+):(\d+)\s+(-?\d+(?:\.\d+)?)\s*\(?dbm\)?/i';

        preg_match_all($pattern, $output, $matches, PREG_SET_ORDER);

        $powers = [];

        foreach ($matches as $match) {
            $onuId = (int) $match[3];
            $powers[$onuId] = [
                'slot' => (int) $match[1],
                'port' => (int) $match[2],
                'onu_id' => $onuId,
                'rx_power_dbm' => (float) $match[4],
                'rx_power_label' => sprintf('%.3f dBm', (float) $match[4]),
            ];
        }

        return $powers;
    }

    /**
     * @param  array<int, array<string, mixed>>  $onus
     * @param  array<int, array<string, mixed>>  $powers
     * @return array<int, array<string, mixed>>
     */
    public function merge(array $onus, array $powers): array
    {
        return array_map(function (array $onu) use ($powers) {
            $power = $powers[(int) $onu['onu_id']] ?? null;

            return [
                ...$onu,
                'rx_power_dbm' => $power['rx_power_dbm'] ?? null,
                'rx_power_label' => $power['rx_power_label'] ?? null,
            ];
        }, $onus);
    }
}
