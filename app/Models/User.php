<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use App\Models\Concerns\Auditable;
use App\Models\Scopes\PartnerOltScope;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasApiTokens, HasFactory, Notifiable;

    /**
     * Field non-aksi (diperbarui sistem) yang tidak perlu diaudit.
     *
     * @var list<string>
     */
    protected $auditExclude = [
        'last_notifications_read_at',
        'email_verified_at',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'locale',
        'last_notifications_read_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_notifications_read_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    public function auditLabel(): string
    {
        return 'Pengguna';
    }

    public function auditTitle(): string
    {
        return (string) $this->name;
    }

    /**
     * OLT yang di-assign ke user untuk membatasi akses (role "partner"; opsional untuk "operator").
     *
     * @return BelongsToMany<SnmpOlt, $this>
     */
    public function partnerOlts(): BelongsToMany
    {
        return $this->belongsToMany(SnmpOlt::class, 'olt_user')
            ->withPivot('alarms_enabled')
            ->withTimestamps();
    }

    /**
     * Bot Telegram milik partner (self-service, 1 bot per partner).
     *
     * @return HasOne<PartnerTelegramBot, $this>
     */
    public function telegramBot(): HasOne
    {
        return $this->hasOne(PartnerTelegramBot::class);
    }

    /**
     * Id OLT yang boleh diakses partner ini. Di-cache per-instance agar tidak
     * mengulang query saat dipakai global scope pada banyak model.
     *
     * PENTING: query tabel pivot & snmp_olts langsung (bukan relasi Eloquent) supaya
     * TIDAK memicu {@see PartnerOltScope} pada SnmpOlt — itu akan memanggil
     * allowedOltIds() lagi → rekursi tak terhingga.
     *
     * Gabungan penugasan pivot (`olt_user`) + OLT privat milik sendiri
     * (`snmp_olts.owner_user_id`). Untuk OLT milik sendiri normalnya pivot juga ada;
     * union hanya jaga-jaga bila baris pivot hilang.
     *
     * @return array<int, int>
     */
    public function allowedOltIds(): array
    {
        if ($this->allowedOltIds !== null) {
            return $this->allowedOltIds;
        }

        $assigned = DB::table('olt_user')
            ->where('user_id', $this->id)
            ->pluck('snmp_olt_id');

        $owned = DB::table('snmp_olts')
            ->where('owner_user_id', $this->id)
            ->pluck('id');

        return $this->allowedOltIds = $assigned
            ->merge($owned)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /** @var array<int, int>|null */
    protected ?array $allowedOltIds = null;

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isOperator(): bool
    {
        return $this->role === UserRole::Operator;
    }

    public function isPartner(): bool
    {
        return $this->role === UserRole::Partner;
    }

    public function isDemo(): bool
    {
        return $this->role === UserRole::Demo;
    }

    /**
     * Apakah visibilitas OLT user ini dibatasi ke daftar assignment ({@see PartnerOltScope}).
     * - Partner: SELALU dibatasi (tanpa assignment = tidak lihat OLT apa pun).
     * - Operator: dibatasi HANYA bila punya assignment eksplisit; tanpa assignment =
     *   akses penuh ke semua OLT (assignment bersifat opsional/pembatas).
     * - Admin/Demo/lainnya: tidak dibatasi oleh scope ini.
     */
    public function isOltScoped(): bool
    {
        if ($this->isPartner()) {
            return true;
        }

        return $this->isOperator() && count($this->allowedOltIds()) > 0;
    }

    /**
     * Boleh melakukan aksi/edit pada OLT. Partner ikut, TAPI cakupan OLT dibatasi
     * {@see PartnerOltScope}. Untuk tambah/hapus device OLT
     * gunakan {@see canManageOltInventory()}.
     */
    public function canManageOlt(): bool
    {
        return in_array($this->role, [UserRole::Admin, UserRole::Operator, UserRole::Partner], true);
    }

    /**
     * Boleh menambah/menghapus device OLT dari POOL GLOBAL (owner_user_id = null).
     * Admin/operator saja. Partner memakai {@see canAddOlt()} untuk OLT privatnya.
     */
    public function canManageOltInventory(): bool
    {
        return in_array($this->role, [UserRole::Admin, UserRole::Operator], true);
    }

    /**
     * Boleh menambah device OLT baru. Partner IKUT — OLT yang ia tambah menjadi
     * privat miliknya sendiri (owner_user_id = id-nya), tak terlihat admin/operator.
     * Admin/operator menambah OLT global (owner_user_id = null).
     */
    public function canAddOlt(): bool
    {
        return in_array($this->role, [UserRole::Admin, UserRole::Operator, UserRole::Partner], true);
    }

    /** Apakah OLT ini privat milik user ini (bukan sekadar di-assign). */
    public function ownsOlt(SnmpOlt $olt): bool
    {
        return $olt->owner_user_id !== null && $olt->owner_user_id === $this->id;
    }

    public function canManageUsers(): bool
    {
        return $this->role === UserRole::Admin;
    }
}
