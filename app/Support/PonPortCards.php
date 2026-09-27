<?php

namespace App\Support;

/**
 * Kartu port PON untuk halaman "PON Port" OLT non-ZTE (C-Data & HiOSO) — padanan
 * `SmartOltController::serializeSnapshot()` milik halaman GPON Port ZTE.
 *
 * Murni membaca cache `last_test_result` (tanpa SNMP/telnet). Deskripsi port = label sisi-NMS
 * (`olt_port_labels`), karena family ini tak punya deskripsi port di perangkat yang terverifikasi.
 */
class PonPortCards
{
    /**
     * @param  array<string, mixed>  $snapshot  isi `last_test_result`
     * @param  array<string, string>  $labels  peta "slot_port" → label (OltPortLabelService::forOlt)
     * @return list<array<string, mixed>>
     */
    public static function build(array $snapshot, array $labels = []): array
    {
        return collect(data_get($snapshot, 'ports', []))
            ->map(function (array $port) use ($snapshot, $labels) {
                $slot = data_get($port, 'slot');
                $number = data_get($port, 'port');
                $onus = data_get($snapshot, "port_onus.{$slot}_{$number}.onus", []);

                $items = collect($onus)
                    ->map(fn (array $onu) => [
                        'onu_id' => data_get($onu, 'onu_id'),
                        'interface' => data_get($onu, 'interface'),
                        'serial_number' => data_get($onu, 'serial_number'),
                        'name' => data_get($onu, 'name'),
                        'description' => data_get($onu, 'description'),
                        'online' => (bool) data_get($onu, 'online', false),
                        'search_text' => collect([
                            $onu['interface'] ?? null,
                            $onu['serial_number'] ?? null,
                            $onu['mac'] ?? null,
                            $onu['name'] ?? null,
                            $onu['description'] ?? null,
                        ])->filter()->implode(' '),
                    ])
                    ->values()
                    ->all();

                return [
                    'name' => data_get($port, 'name'),
                    'slot' => $slot,
                    'port' => $number,
                    'if_index' => data_get($port, 'if_index'),
                    'oper_status' => data_get($port, 'oper_status'),
                    'description' => $labels["{$slot}_{$number}"] ?? null,
                    'onu_count' => count($onus),
                    'online_onu_count' => collect($onus)->where('online', true)->count(),
                    'onu_search_items' => $items,
                ];
            })
            ->values()
            ->all();
    }
}
