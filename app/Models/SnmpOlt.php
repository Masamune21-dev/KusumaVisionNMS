<?php

namespace App\Models;

use App\Console\Commands\AutosaveOltConfigsCommand;
use App\Models\Concerns\Auditable;
use App\Models\Scopes\DemoScope;
use App\Models\Scopes\PartnerOltScope;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class SnmpOlt extends Model
{
    use Auditable, HasFactory;

    /**
     * Field volatil hasil polling & jejak auto save — bukan aksi pengguna, jadi tidak diaudit.
     * Saklar `config_autosave_enabled` sendiri tetap diaudit.
     *
     * @var list<string>
     */
    protected $auditExclude = [
        'last_test_result',
        'last_tested_at',
        'last_polled_at',
        'last_rx_polled_at',
        'config_changed_at',
        'config_dirty_since',
        'config_saved_at',
        'config_autosave_at',
        'config_autosave_status',
        'config_autosave_error',
    ];

    public const AUTOSAVE_OK = 'ok';

    public const AUTOSAVE_FAILED = 'failed';

    /** Ditunda tanpa menyentuh OLT (mis. ada port PON yang sedang dimatikan dari NMS). */
    public const AUTOSAVE_DEFERRED = 'deferred';

    /**
     * Default atribut untuk instance baru — mencerminkan default kolom DB sehingga
     * model yang belum di-refresh (mis. hasil create() tanpa set flag) tetap konsisten.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'alarms_enabled' => true,
    ];

    protected $fillable = [
        'name',
        'vendor',
        'ip',
        'snmp_port',
        'snmp_read_community',
        'snmp_write_community',
        'snmp_version',
        'cli_transport',
        'cli_port',
        'web_port',
        'cli_username',
        'cli_password',
        'polling_enabled',
        'alarms_enabled',
        'config_backup_enabled',
        'config_autosave_enabled',
        'poll_interval_minutes',
        'rx_poll_interval_minutes',
        'last_test_result',
        'last_tested_at',
        'last_polled_at',
        'last_rx_polled_at',
        'is_demo',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new DemoScope);
        static::addGlobalScope(new PartnerOltScope);
    }

    /**
     * User (partner) yang di-assign ke OLT ini.
     *
     * @return BelongsToMany<User, $this>
     */
    public function configBackups(): HasMany
    {
        return $this->hasMany(OltConfigBackup::class)->latest('captured_at');
    }

    public function partners(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'olt_user')->withTimestamps();
    }

    /**
     * Partner pemilik OLT privat ini (NULL = OLT global admin/operator).
     *
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** OLT ini privat milik seorang partner (bukan global). */
    public function isPrivatelyOwned(): bool
    {
        return $this->owner_user_id !== null;
    }

    protected $hidden = [
        'snmp_read_community',
        'snmp_write_community',
        'cli_password',
    ];

    protected function casts(): array
    {
        return [
            'owner_user_id' => 'integer',
            'snmp_port' => 'integer',
            'cli_port' => 'integer',
            'web_port' => 'integer',
            'snmp_read_community' => 'encrypted',
            'snmp_write_community' => 'encrypted',
            'cli_password' => 'encrypted',
            'polling_enabled' => 'boolean',
            'alarms_enabled' => 'boolean',
            'config_backup_enabled' => 'boolean',
            'config_autosave_enabled' => 'boolean',
            'config_changed_at' => 'datetime',
            'config_dirty_since' => 'datetime',
            'config_saved_at' => 'datetime',
            'config_autosave_at' => 'datetime',
            'is_demo' => 'boolean',
            'poll_interval_minutes' => 'integer',
            'rx_poll_interval_minutes' => 'integer',
            'last_test_result' => 'array',
            'last_tested_at' => 'datetime',
            'last_polled_at' => 'datetime',
            'last_rx_polled_at' => 'datetime',
        ];
    }

    public function auditLabel(): string
    {
        return 'OLT';
    }

    public function auditTitle(): string
    {
        return (string) $this->name;
    }

    public function getHostAddress(): string
    {
        return $this->snmp_port === 161
            ? $this->ip
            : "{$this->ip}:{$this->snmp_port}";
    }

    public function cardStatuses(): HasMany
    {
        return $this->hasMany(SmartOltCardStatus::class, 'snmp_olt_id');
    }

    public function interfaceStatuses(): HasMany
    {
        return $this->hasMany(SmartOltInterfaceStatus::class, 'snmp_olt_id');
    }

    public function defaultCliPort(): int
    {
        return $this->cli_transport === 'ssh' ? 22 : 23;
    }

    public function isPollDue(): bool
    {
        if (! $this->polling_enabled) {
            return false;
        }

        if ($this->last_polled_at === null) {
            return true;
        }

        return $this->last_polled_at->lte(now()->subMinutes($this->pollIntervalMinutes()));
    }

    public function isRxPollDue(): bool
    {
        if ($this->last_rx_polled_at === null) {
            return true;
        }

        return $this->last_rx_polled_at->lte(now()->subMinutes($this->rxPollIntervalMinutes()));
    }

    public function pollIntervalMinutes(): int
    {
        return max(1, (int) ($this->poll_interval_minutes ?: 5));
    }

    public function rxPollIntervalMinutes(): int
    {
        return max(1, (int) ($this->rx_poll_interval_minutes ?: 5));
    }

    /**
     * Catat bahwa NMS baru saja mengubah konfigurasi OLT ini (running-config ≠ memori sampai
     * di-save). Query polos: tanpa global scope, tanpa `updated_at`, tanpa baris audit.
     * `config_dirty_since` hanya diisi saat sebelumnya bersih — umur perubahan tertua yang belum
     * tersimpan, dipakai {@see AutosaveOltConfigsCommand} sebagai batas tunggu.
     */
    public static function markConfigChanged(int $oltId): void
    {
        $now = now();

        DB::table('snmp_olts')->where('id', $oltId)->update(['config_changed_at' => $now]);
        DB::table('snmp_olts')->where('id', $oltId)->whereNull('config_dirty_since')->update(['config_dirty_since' => $now]);
    }

    /**
     * Catat save yang berhasil (manual maupun otomatis). `$startedAt` = saat save DIMULAI:
     * perubahan yang masuk selagi `write` berjalan belum tentu ikut tersimpan, jadi OLT tetap
     * ditandai kotor bila ada perubahan sejak detik itu (pembanding ketat → condong save ulang).
     */
    public static function markConfigSaved(int $oltId, CarbonInterface $startedAt): void
    {
        DB::table('snmp_olts')->where('id', $oltId)->update(['config_saved_at' => $startedAt]);
        DB::table('snmp_olts')->where('id', $oltId)
            ->where(fn ($query) => $query->whereNull('config_changed_at')->orWhere('config_changed_at', '<', $startedAt))
            ->update(['config_dirty_since' => null]);
    }

    /** Ada perubahan dari NMS yang belum di-save ke memori OLT. */
    public function hasUnsavedConfig(): bool
    {
        return $this->config_dirty_since !== null;
    }
}
