<?php

namespace App\Contracts;

use App\Jobs\PollOltJob;
use App\Models\SnmpOlt;

/**
 * Driver non-ZTE yang bisa membaca inventory ONU TANPA walk tabel Rx. Dipakai polling terjadwal
 * ({@see PollOltJob}) saat Rx belum jatuh tempo (`rx_poll_interval_minutes`), padanan
 * `preserveExistingRx` di jalur ZTE.
 *
 * Alasannya waktu, bukan bahasa: tabel Rx HiOSO dihitung OLT per ONU (±150 ms/ONU — 155 ONU ±22 dtk),
 * sedangkan tabel MAC/nama/link-state selesai < 1 dtk; Go dan PHP terukur sama lambatnya (26 Okt 2026).
 */
interface SkipsRxWalk
{
    /**
     * Sama dengan {@see SmartOltSnmpDriver::getRegisteredOnus()}, tetapi Rx tiap ONU dibawa dari snapshot
     * poll sebelumnya alih-alih dibaca ulang. Status online tetap dibaca segar.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRegisteredOnusWithoutRx(SnmpOlt $olt): array;
}
