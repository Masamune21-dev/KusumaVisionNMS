<?php

namespace App\Services\Zte;

use App\Models\SnmpOlt;
use App\Services\Snmp\OltSnmpClient;
use Carbon\CarbonInterface;

/**
 * Refresh Discovery ONU unconfigured (ZTE) + waktu "pertama terlihat" per SN.
 *
 * OLT C300/C320 tidak menyimpan kapan ONU unconfigured muncul (tabel SNMP
 * `1012.3.13.3.1` kolom `.7` selalu nol di C300 live, CLI `show gpon onu uncfg` tanpa
 * waktu), jadi NMS mencatatnya sendiri di `last_test_result.unconfigured_seen` (peta
 * SN → {at, baseline}). Waktunya = Refresh Discovery pertama yang melihat SN itu. SN yang
 * hilang dari daftar dilupakan (muncul lagi = dihitung baru); refresh gagal tak menyentuh
 * peta. `baseline` = sudah ada saat pencatatan dimulai, waktu aslinya tak diketahui.
 * Dipakai web (`smartolt.unconfigured.refresh`) dan API (`api.olts.unconfigured.refresh`).
 */
class UnconfiguredOnuDiscovery
{
    public const SEEN_KEY = 'unconfigured_seen';

    public function __construct(private readonly OltSnmpClient $client) {}

    /**
     * @return array<string, mixed> snapshot yang disimpan di `last_test_result.unconfigured_onus`
     */
    public function refresh(SnmpOlt $olt): array
    {
        $now = now();
        $result = $this->client->unconfiguredOnusSnapshot($olt);
        $result['refreshed_at'] = $now->toIso8601String();

        $snapshot = $olt->last_test_result ?? [];
        [$result, $seen] = self::stamp($result, $snapshot[self::SEEN_KEY] ?? null, $now);

        data_set($snapshot, 'unconfigured_onus', $result);
        if ($seen !== null) {
            $snapshot[self::SEEN_KEY] = $seen;
        }

        $olt->forceFill(['last_test_result' => $snapshot])->save();

        return $result;
    }

    /**
     * Tempel `first_seen_at`/`first_seen_baseline` ke tiap baris dan urutkan terbaru di atas.
     *
     * @param  array<string, mixed>  $result  hasil `OltSnmpClient::unconfiguredOnusSnapshot()`
     * @param  array<string, array<string, mixed>>|null  $seen  peta sebelumnya; null = OLT belum pernah dicatat
     * @return array{0: array<string, mixed>, 1: array<string, array{at: string, baseline: bool}>|null}
     */
    public static function stamp(array $result, ?array $seen, CarbonInterface $now): array
    {
        if (! ($result['ok'] ?? false)) {
            return [$result, $seen];
        }

        $baseline = $seen === null;
        $next = [];
        $rows = [];

        foreach ($result['onus'] ?? [] as $row) {
            $serial = (string) ($row['serial_number'] ?? '');
            $previous = $seen[$serial] ?? null;
            $entry = is_array($previous) && isset($previous['at'])
                ? ['at' => (string) $previous['at'], 'baseline' => (bool) ($previous['baseline'] ?? false)]
                : ['at' => $now->toIso8601String(), 'baseline' => $baseline];

            $next[$serial] = $entry;
            $rows[] = [...$row, 'first_seen_at' => $entry['at'], 'first_seen_baseline' => $entry['baseline']];
        }

        // Terbaru di atas; yang terlihat bersamaan tetap urut slot/port/SN bawaan (usort stabil).
        usort($rows, fn (array $a, array $b): int => strtotime($b['first_seen_at']) <=> strtotime($a['first_seen_at']));
        $result['onus'] = $rows;

        return [$result, $next];
    }
}
