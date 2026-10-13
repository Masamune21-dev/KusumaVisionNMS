<?php

namespace App\Services\Zte;

use App\Models\SmartOltOnuRegistration;
use App\Models\SnmpOlt;
use App\Services\Snmp\OltSnmpClient;
use App\Support\SmartOltSupport;
use Closure;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Pre-Register ONU (C300/C320): SN diketik manual lalu didaftarkan ke OLT sebagai ONU offline, sebelum
 * perangkatnya tersambung. OLT menyimpan seluruh config (onu/tcont/gemport/service-port + pon-onu-mng)
 * dan mendorongnya via OMCI begitu ONU ber-SN itu tersambung ke port tersebut — terverifikasi live di
 * C320 V2.1 (13 Okt 2026, SN fiktif: `[Successful]`, phase OffLine, pon-onu-mng tersimpan utuh).
 *
 * Skrip & eksekusinya memakai jalur registrasi biasa (SmartOltController::storeOnu/storeOnuAdvanced);
 * kelas ini hanya menambah pengaman untuk SN manual — typo SN berarti ONU tak pernah cocok — plus
 * status registrasi pre-register di halaman riwayat.
 */
class OnuPreregisterService
{
    // SN GPON: 4 karakter vendor (ZTEG, HWTC, CDTC, …) + 8 heksa.
    public const SERIAL_PATTERN = '/^[A-Z0-9]{4}[0-9A-F]{8}$/';

    public const STATUS_ONLINE = 'online';

    public const STATUS_WAITING = 'waiting';

    public const STATUS_OFFLINE = 'offline';

    public const STATUS_MISSING = 'missing';

    public function __construct(private OltSnmpClient $snmp) {}

    /**
     * Huruf besar tanpa spasi/titik dua/strip/titik — bentuk yang biasa tersalin dari label dus ONU
     * atau aplikasi lain (`zteg:c1234567`, `ZTEG-C123 4567`).
     */
    public static function normalizeSerial(string $serial): string
    {
        return strtoupper(preg_replace('/[\s:.\-]+/', '', $serial) ?? '');
    }

    /**
     * Tolak sebelum OLT disentuh: format SN, SN sudah terdaftar di OLT ini, ONU ID terpakai di port.
     * Sumbernya cache polling (`port_onus`), jadi bisa tertinggal satu siklus poll — bentrok sungguhan
     * tetap ditolak OLT; pengaman ini supaya pesannya jelas dan menyebut posisinya.
     *
     * @throws ValidationException
     */
    public function assertCanPreregister(SnmpOlt $olt, string $serial, int $slot, int $port, int $onuId): void
    {
        if (! preg_match(self::SERIAL_PATTERN, $serial)) {
            throw ValidationException::withMessages(['serial_number' => __('zte.prereg_serial_format')]);
        }

        $isC600 = SmartOltSupport::isC600($olt);
        $errors = [];

        foreach ((array) data_get($olt->last_test_result ?? [], 'port_onus', []) as $key => $entry) {
            [$cachedSlot, $cachedPort] = array_map('intval', explode('_', (string) $key) + [0, 0]);

            foreach ((array) data_get($entry, 'onus', []) as $onu) {
                $id = (int) ($onu['onu_id'] ?? 0);

                if (! isset($errors['serial_number']) && self::normalizeSerial((string) ($onu['serial_number'] ?? '')) === $serial) {
                    $errors['serial_number'] = __('zte.prereg_serial_registered', [
                        'interface' => SmartOltSupport::onuInterfaceId($cachedSlot, $cachedPort, $id, $isC600),
                    ]);
                }

                if ($cachedSlot === $slot && $cachedPort === $port && $id === $onuId) {
                    $errors['onu_id'] = __('zte.prereg_onu_id_used', ['onu_id' => $onuId]);
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * ONU ID terpakai per port dari cache ("slot_port" => [id, …]) — dipakai form pre-register untuk
     * menghitung ulang ONU ID kosong saat operator mengganti slot/port.
     *
     * @return array<string, list<int>>
     */
    public function usedOnuIdsByPort(SnmpOlt $olt): array
    {
        $used = [];

        foreach ((array) data_get($olt->last_test_result ?? [], 'port_onus', []) as $key => $entry) {
            $ids = array_values(array_filter(
                array_map(fn ($onu) => (int) ($onu['onu_id'] ?? 0), (array) data_get($entry, 'onus', [])),
                fn (int $id) => $id > 0,
            ));

            if ($ids !== []) {
                $used[(string) $key] = $ids;
            }
        }

        return $used;
    }

    /**
     * Segarkan cache satu port (SNMP subtree port itu saja) supaya ONU pre-register langsung tampil
     * offline di daftar port. Gagal = diam: registrasinya sudah sukses, poll berikutnya menyusul.
     */
    public function refreshPortCache(SnmpOlt $olt, int $slot, int $port): void
    {
        try {
            $result = $this->snmp->portOnusSnapshot($olt, $slot, $port);
        } catch (Throwable) {
            return;
        }

        if (! ($result['ok'] ?? false)) {
            return;
        }

        $result['refreshed_at'] = now()->toIso8601String();
        $snapshot = $olt->last_test_result ?? [];
        data_set($snapshot, "port_onus.{$slot}_{$port}", $result);
        $olt->forceFill(['last_test_result' => $snapshot])->save();
    }

    /**
     * Penentu status registrasi pre-register dari cache port (snapshot dibaca sekali — cast array
     * men-decode ulang JSON tiap akses atribut). Null untuk registrasi biasa / yang belum dieksekusi.
     *
     * @return Closure(SmartOltOnuRegistration): ?string
     */
    public function statusResolver(SnmpOlt $olt): Closure
    {
        $snapshot = $olt->last_test_result ?? [];

        return function (SmartOltOnuRegistration $registration) use ($snapshot): ?string {
            if (! $registration->preregistered || $registration->status !== 'executed') {
                return null;
            }

            $onu = collect(data_get($snapshot, "port_onus.{$registration->slot}_{$registration->port}.onus", []))
                ->first(fn ($row) => (int) ($row['onu_id'] ?? 0) === (int) $registration->onu_id);

            // Posisi kosong atau ditempati SN lain = ONU pre-register sudah dihapus/diganti.
            if (! is_array($onu)
                || self::normalizeSerial((string) ($onu['serial_number'] ?? '')) !== self::normalizeSerial((string) $registration->serial_number)) {
                return self::STATUS_MISSING;
            }

            if ($onu['online'] ?? false) {
                return self::STATUS_ONLINE;
            }

            // Phase `Offline` = ONU belum tersambung (begitu juga ONU pre-register di uji live C320);
            // LOS/DyingGasp dsb. = pernah tersambung lalu mati. SNMP selalu mengisi last_down_cause
            // (`Unknown`/`Normal`), jadi kolom itu tak bisa jadi penanda "belum pernah online".
            return strcasecmp((string) ($onu['phase_state'] ?? ''), 'Offline') === 0
                ? self::STATUS_WAITING
                : self::STATUS_OFFLINE;
        };
    }
}
