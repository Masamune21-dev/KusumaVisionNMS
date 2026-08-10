<?php

namespace App\Services;

use App\Models\OltPortLabel;
use App\Models\SnmpOlt;

/**
 * Label port PON sisi-NMS untuk family non-ZTE (C-Data EPON/GPON, HiOSO, HsAirPo).
 *
 * OLT ZTE menamai portnya di perangkat (CLI `interface gpon-olt_1/x/y` → `description …`,
 * lihat {@see ZteCardUplinkService::setGponPortDescription()}). Family lain tak
 * punya perintah setara yang terverifikasi live — probe `ifAlias` pun kosong / cuma cerminan
 * nama port bawaan agent — jadi labelnya disimpan di DB NMS dan digabung saat render. Tidak
 * ada yang ditulis ke OLT, dan label selamat dari scan/poll karena bukan bagian dari
 * `last_test_result` (yang ditimpa scanner tiap scan).
 */
class OltPortLabelService
{
    /** Batas panjang label — disamakan dengan deskripsi port ZTE (64 karakter). */
    public const MAX_LENGTH = 64;

    /**
     * Peta label satu OLT, ber-key `{slot}_{port}` (sama seperti key `port_onus`).
     *
     * @return array<string, string>
     */
    public function forOlt(SnmpOlt $olt): array
    {
        return OltPortLabel::query()
            ->where('snmp_olt_id', $olt->id)
            ->get(['slot', 'port', 'label'])
            ->mapWithKeys(fn (OltPortLabel $row) => ["{$row->slot}_{$row->port}" => $row->label])
            ->all();
    }

    /**
     * Simpan/ubah label satu port. Teks kosong menghapus barisnya.
     *
     * @return string|null label bersih yang tersimpan, null bila dihapus
     */
    public function set(SnmpOlt $olt, int $slot, int $port, ?string $label): ?string
    {
        $clean = $this->sanitize($label);

        if ($clean === null) {
            OltPortLabel::query()
                ->where('snmp_olt_id', $olt->id)
                ->where('slot', $slot)
                ->where('port', $port)
                ->delete();

            return null;
        }

        OltPortLabel::query()->updateOrCreate(
            ['snmp_olt_id' => $olt->id, 'slot' => $slot, 'port' => $port],
            ['label' => $clean],
        );

        return $clean;
    }

    /** Buang kontrol char, rapatkan spasi, potong 64 — kosong jadi null. */
    private function sanitize(?string $label): ?string
    {
        $clean = preg_replace('/[\x00-\x1f\x7f]+/u', ' ', (string) $label);
        $clean = trim(preg_replace('/\s{2,}/u', ' ', (string) $clean));

        if ($clean === '') {
            return null;
        }

        return mb_substr($clean, 0, self::MAX_LENGTH);
    }
}
