<?php

namespace App\Models;

use App\Contracts\Telegram\TelegramBotConfig;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\TelegramBotConfigTrait;
use App\Models\Scopes\PartnerOltScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bot Telegram milik seorang partner (1 bot per partner). Menerima alarm & melayani
 * command HANYA untuk OLT yang di-assign ke partner tsb (webhook menyetel auth user =
 * partner sehingga {@see PartnerOltScope} membatasi query OLT).
 */
class PartnerTelegramBot extends Model implements TelegramBotConfig
{
    use Auditable, TelegramBotConfigTrait;

    /**
     * @var list<string>
     */
    protected $auditExclude = ['last_sent_at', 'last_error'];

    public function auditLabel(): string
    {
        return 'Bot Telegram Partner';
    }

    public function auditTitle(): string
    {
        return (string) ($this->user?->name ?? $this->user_id);
    }

    protected $fillable = [
        'user_id',
        'enabled',
        'bot_token',
        'chat_id',
        'webhook_secret',
        'commands_enabled',
        'min_severity',
        'notify_on_raise',
        'notify_on_clear',
        'notify_types',
        'last_sent_at',
        'last_error',
    ];

    protected $hidden = [
        'bot_token',
        'webhook_secret',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'bot_token' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'commands_enabled' => 'boolean',
            'notify_on_raise' => 'boolean',
            'notify_on_clear' => 'boolean',
            'notify_types' => 'array',
            'last_sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Bot partner didaftarkan pada URL webhook yang menyisipkan id-nya, sehingga update
     * dari Telegram bisa dipetakan kembali ke bot (dan partner) yang tepat.
     *
     * @return array<string, mixed>
     */
    public function webhookRouteParameters(): array
    {
        return ['bot' => $this->id];
    }

    /**
     * Ambil / buat baris bot untuk user (dipakai halaman self-service partner).
     */
    public static function forUser(User $user): self
    {
        return static::query()->firstOrNew(['user_id' => $user->id]);
    }
}
