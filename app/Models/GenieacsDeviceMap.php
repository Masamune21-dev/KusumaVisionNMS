<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Hasil pencocokan satu device GenieACS ke posisi ONU di NMS.
 *
 * Lihat migrasi `create_genieacs_device_map_table` untuk arti `match_method`.
 * Baris `manual` tidak pernah ditimpa oleh pencocokan otomatis.
 */
class GenieacsDeviceMap extends Model
{
    public const METHOD_SERIAL = 'serial';

    public const METHOD_MAC = 'mac';

    public const METHOD_MANUAL = 'manual';

    /** Jenis identitas yang bisa disematkan operator, diurut dari yang paling kuat. */
    public const REF_SERIAL = 'serial';

    public const REF_MAC = 'mac';

    /** Jalan terakhir: ONU tanpa serial maupun MAC. Tidak tahan pindah port. */
    public const REF_POSITION = 'position';

    protected $table = 'genieacs_device_map';

    protected $fillable = [
        'device_id',
        'serial_number',
        'pon_mac',
        'manufacturer',
        'product_class',
        'snmp_olt_id',
        'slot',
        'port',
        'onu_id',
        'match_method',
        'manual_ref_type',
        'manual_ref',
        'manual_stale',
        'manual_by',
        'manual_at',
        'pppoe_username',
        'tr069_ip',
        'matched_at',
        'last_inform_at',
    ];

    protected function casts(): array
    {
        return [
            'snmp_olt_id' => 'integer',
            'slot' => 'integer',
            'port' => 'integer',
            'onu_id' => 'integer',
            'matched_at' => 'datetime',
            'last_inform_at' => 'datetime',
            'manual_stale' => 'boolean',
            'manual_at' => 'datetime',
            'manual_by' => 'integer',
        ];
    }

    public function isManual(): bool
    {
        return $this->match_method === self::METHOD_MANUAL;
    }

    /**
     * Pin manual yang identitasnya tak lagi ditemukan di inventori ONU —
     * biasanya karena ONU-nya diganti unit baru atau dicabut.
     */
    public function isStalePin(): bool
    {
        return $this->isManual() && $this->manual_stale;
    }

    public function isMatched(): bool
    {
        return $this->snmp_olt_id !== null && $this->onu_id !== null;
    }

    /**
     * Kunci posisi ONU, dipakai menyusun peta lookup di sisi PHP.
     */
    public function onuKey(): ?string
    {
        return $this->isMatched()
            ? "{$this->snmp_olt_id}.{$this->slot}.{$this->port}.{$this->onu_id}"
            : null;
    }
}
