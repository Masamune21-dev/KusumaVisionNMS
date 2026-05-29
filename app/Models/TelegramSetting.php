<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class TelegramSetting extends Model
{
    use Auditable;

    /**
     * @var list<string>
     */
    protected $auditExclude = ['last_sent_at', 'last_error'];

    public function auditLabel(): string
    {
        return 'Pengaturan Telegram';
    }

    public function auditTitle(): string
    {
        return '';
    }

    /**
     * Severity ordering (low → high) used for the minimum-severity filter.
     */
    public const SEVERITY_RANK = [
        AlarmEvent::SEVERITY_WARNING => 1,
        AlarmEvent::SEVERITY_MINOR => 2,
        AlarmEvent::SEVERITY_MAJOR => 3,
        AlarmEvent::SEVERITY_CRITICAL => 4,
    ];

    protected $fillable = [
        'enabled',
        'bot_token',
        'chat_id',
        'webhook_secret',
        'commands_enabled',
        'min_severity',
        'notify_on_raise',
        'notify_on_clear',
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
            'last_sent_at' => 'datetime',
        ];
    }

    /**
     * The singleton settings row (or a fresh unsaved instance if none exists yet).
     */
    public static function instance(): self
    {
        return static::query()->firstOrNew([]);
    }

    /**
     * Parse the chat_id field into a list of individual chat ids.
     *
     * @return array<int, string>
     */
    public function chatIds(): array
    {
        if (blank($this->chat_id)) {
            return [];
        }

        return array_values(array_filter(
            preg_split('/[\s,]+/', trim((string) $this->chat_id)) ?: [],
            fn (string $id) => $id !== '',
        ));
    }

    public function isConfigured(): bool
    {
        return filled($this->bot_token) && $this->chatIds() !== [];
    }

    public function isReady(): bool
    {
        return $this->enabled && $this->isConfigured();
    }

    /**
     * Whether inbound command handling is switched on and fully configured.
     */
    public function commandsReady(): bool
    {
        return $this->commands_enabled
            && filled($this->bot_token)
            && filled($this->webhook_secret);
    }

    /**
     * Only chat ids present in the configured allow-list may run data commands.
     */
    public function isChatAuthorized(string $chatId): bool
    {
        return in_array(trim($chatId), $this->chatIds(), true);
    }

    public function minSeverityRank(): int
    {
        return self::SEVERITY_RANK[$this->min_severity] ?? 1;
    }
}
