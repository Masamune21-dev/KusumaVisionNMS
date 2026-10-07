<?php

namespace App\Console\Commands;

use App\Models\GenieacsDeviceMap;
use App\Models\SnmpOlt;
use App\Services\OnuInventoryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Daftar ONU yang BELUM berpasangan dengan device GenieACS, dikelompokkan
 * menurut merk ONT.
 *
 * Dipakai untuk menentukan urutan pengaktifan TR-069: merk yang paling banyak
 * tertinggal dikerjakan lebih dulu.
 *
 * Seluruhnya dibaca dari tabel lokal — tidak memanggil ACS sama sekali, jadi
 * aman dijalankan kapan pun.
 */
class GenieacsUnlinkedReportCommand extends Command
{
    protected $signature = 'genieacs:unlinked-report
                            {--csv= : Tulis rincian per-ONU ke berkas CSV (relatif ke storage/app/private)}
                            {--limit=15 : Jumlah baris merk yang ditampilkan di ringkasan}
                            {--include-partner : Ikutkan OLT privat milik mitra (bawaan: dikecualikan)}
                            {--include-demo : Ikutkan OLT demo (bawaan: dikecualikan)}';

    protected $description = 'Laporan ONU yang belum terhubung ke GenieACS, dikelompokkan per merk ONT';

    /**
     * Prefiks serial ONU → merk. Hanya prefiks yang benar-benar muncul di
     * armada ini; sisanya dilaporkan apa adanya supaya tidak ada tebakan.
     */
    private const SERIAL_VENDORS = [
        'ZTEG' => 'ZTE', 'ZTEL' => 'ZTE', 'ZTEN' => 'ZTE', 'ZTEB' => 'ZTE', 'ZTEE' => 'ZTE',
        'CDTC' => 'C-Data',
        'RTEG' => 'ZTE (rebrand RTE)', 'RTEE' => 'ZTE (rebrand RTE)',
        'ZICG' => 'ZTE (rebrand ZICG)',
        'FHTT' => 'FiberHome',
        'HWTC' => 'Huawei',
        'SKYW' => 'Skyworth',
        'ELWG' => 'Eloik',
        'ALCL' => 'Nokia/Alcatel',
    ];

    /**
     * Kode manufaktur dari GenieACS → nama yang dipakai laporan ini.
     *
     * Tanpa ini satu merk bisa muncul dua baris: `CDTC` (dari ACS) dan
     * `C-Data` (dari prefiks serial) adalah perangkat yang sama.
     */
    private const ACS_VENDOR_ALIASES = [
        'CDTC' => 'C-Data',
        'ZTE' => 'ZTE',
        'TM' => 'ZTE (rebrand TM)',
        'ZICG' => 'ZTE (rebrand ZICG)',
        'RTEE' => 'ZTE (rebrand RTE)',
        'HWTC' => 'Huawei',
        'FHTT' => 'FiberHome',
    ];

    public function handle(OnuInventoryService $inventory): int
    {
        $rows = GenieacsDeviceMap::query()->whereNotNull('onu_id')->get([
            'snmp_olt_id', 'slot', 'port', 'onu_id',
        ]);

        $paired = [];
        foreach ($rows as $row) {
            $paired["{$row->snmp_olt_id}.{$row->slot}.{$row->port}.{$row->onu_id}"] = true;
        }

        $ouiVendors = $this->ouiVendorMap();
        $onus = $inventory->collect($this->scopedOlts())['onus'];

        $total = [];
        $unlinked = [];
        $perOlt = [];
        $detail = [];

        foreach ($onus as $onu) {
            $vendor = $this->vendorOf($onu, $ouiVendors);
            $total[$vendor] = ($total[$vendor] ?? 0) + 1;

            $key = "{$onu['olt_id']}.{$onu['slot']}.{$onu['port']}.{$onu['onu_id']}";
            if (isset($paired[$key])) {
                continue;
            }

            $unlinked[$vendor] = ($unlinked[$vendor] ?? 0) + 1;
            $perOlt[$vendor][$onu['olt_name']] = ($perOlt[$vendor][$onu['olt_name']] ?? 0) + 1;

            $detail[] = [
                $vendor,
                $onu['olt_name'],
                $onu['slot'],
                $onu['port'],
                $onu['onu_id'],
                $onu['interface'] ?? '',
                $onu['serial_number'] ?? '',
                $onu['mac'] ?? '',
                $onu['customer_name'] ?? '',
                $onu['online'] ? 'online' : 'offline',
                $onu['rx_power_label'] ?? '',
            ];
        }

        arsort($unlinked);

        $this->newLine();
        $this->info(sprintf(
            'ONU di NMS %d · sudah ter-ACS %d · BELUM ter-ACS %d',
            count($onus),
            count($paired),
            array_sum($unlinked),
        ));
        $this->newLine();

        $limit = max(1, (int) $this->option('limit'));
        $table = [];
        foreach (array_slice($unlinked, 0, $limit, true) as $vendor => $count) {
            $table[] = [
                $vendor,
                number_format($total[$vendor]),
                number_format($count),
                sprintf('%.1f%%', $count / max($total[$vendor], 1) * 100),
                $this->topOlts($perOlt[$vendor] ?? []),
            ];
        }

        $this->table(['Merk ONT', 'Total', 'Belum ter-ACS', 'Porsi', 'OLT terbanyak'], $table);

        if ($path = $this->option('csv')) {
            $this->writeCsv($path, $detail);
        }

        return self::SUCCESS;
    }

    /**
     * OLT yang masuk hitungan.
     *
     * Perintah ini berjalan di konteks konsol, dan di sana `PartnerOltScope`
     * sengaja TIDAK membatasi apa pun (supaya penjadwal bisa mem-poll seluruh
     * OLT, termasuk milik mitra). Kalau dibiarkan, laporan ikut menghitung ONU
     * milik mitra — padahal ACS di Pengaturan hanya dicocokkan ke OLT global
     * (lihat {@see \App\Services\Genieacs\GenieacsDeviceSyncService::eligibleOlts()}).
     * Jadi di sini penyaringannya dilakukan eksplisit.
     *
     * OLT demo juga dikecualikan: isinya data contoh, bukan pelanggan.
     *
     * @return \Illuminate\Support\Collection<int, SnmpOlt>
     */
    private function scopedOlts(): \Illuminate\Support\Collection
    {
        $query = SnmpOlt::query()->orderBy('name');

        if (! $this->option('include-partner')) {
            $query->whereNull('owner_user_id');
        }

        if (! $this->option('include-demo')) {
            $query->where('is_demo', false);
        }

        $olts = $query->get();

        $excluded = SnmpOlt::query()->count() - $olts->count();
        if ($excluded > 0) {
            $this->line("Dikecualikan: {$excluded} OLT (milik mitra / demo). Pakai --include-partner atau --include-demo bila memang ingin diikutkan.");
        }

        return $olts;
    }

    /**
     * Peta OUI → merk, dibangun dari device yang SUDAH berpasangan.
     *
     * Merknya datang dari GenieACS sendiri (kolom `manufacturer`), jadi bukan
     * tebakan — dan karena MAC PON serta MAC LAN berbagi tiga oktet pertama,
     * peta ini juga berlaku untuk ONU yang belum berpasangan.
     *
     * @return array<string, string>
     */
    private function ouiVendorMap(): array
    {
        $map = [];

        GenieacsDeviceMap::query()
            ->whereNotNull('pon_mac')
            ->whereNotNull('manufacturer')
            ->get(['pon_mac', 'manufacturer'])
            ->each(function (GenieacsDeviceMap $row) use (&$map) {
                $oui = substr((string) $row->pon_mac, 0, 6);
                if (strlen($oui) === 6) {
                    $map[$oui] = (string) $row->manufacturer;
                }
            });

        return $map;
    }

    /**
     * @param  array<string, mixed>  $onu
     * @param  array<string, string>  $ouiVendors
     */
    private function vendorOf(array $onu, array $ouiVendors): string
    {
        $serial = strtoupper(trim((string) ($onu['serial_number'] ?? '')));

        // Serial vendor sungguhan (ZTEG…, CDTC…) — paling kuat.
        if ($serial !== '' && ! str_contains($serial, ':')) {
            $prefix = substr($serial, 0, 4);

            return self::SERIAL_VENDORS[$prefix] ?? "Lainnya ({$prefix})";
        }

        // OLT EPON menaruh MAC di kolom serial, atau serial kosong dan MAC ada.
        $hex = strtolower((string) preg_replace('/[^0-9a-fA-F]/', '', $serial !== '' ? $serial : (string) ($onu['mac'] ?? '')));

        if (strlen($hex) !== 12) {
            return 'Tidak teridentifikasi';
        }

        $oui = substr($hex, 0, 6);
        $vendor = $ouiVendors[$oui] ?? null;

        if ($vendor === null) {
            return 'EPON OUI '.strtoupper(implode(':', str_split($oui, 2)));
        }

        return self::ACS_VENDOR_ALIASES[strtoupper($vendor)] ?? $vendor;
    }

    /**
     * @param  array<string, int>  $olts
     */
    private function topOlts(array $olts): string
    {
        arsort($olts);
        $parts = [];

        foreach (array_slice($olts, 0, 2, true) as $name => $count) {
            $parts[] = "{$name} ({$count})";
        }

        if (count($olts) > 2) {
            $parts[] = '+'.(count($olts) - 2).' OLT lain';
        }

        return implode(', ', $parts);
    }

    /**
     * @param  array<int, array<int, mixed>>  $detail
     */
    private function writeCsv(string $path, array $detail): void
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['merk_ont', 'olt', 'slot', 'port', 'onu_id', 'interface', 'serial', 'mac', 'pelanggan', 'status', 'rx_power']);

        foreach ($detail as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        Storage::disk('local')->put($path, stream_get_contents($handle));
        fclose($handle);

        $this->line('Rincian per-ONU ditulis ke: '.Storage::disk('local')->path($path).' ('.count($detail).' baris)');
    }
}
