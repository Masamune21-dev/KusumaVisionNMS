<?php

namespace App\Services\Telegram;

/**
 * A bot reply: HTML text plus an optional inline keyboard (list of button rows).
 *
 * Both the text-command path and the callback (button-press) path return this, so
 * the same screen renderer feeds a fresh sendMessage or an in-place editMessageText.
 */
class TelegramReply
{
    /**
     * @param  array<int, array<int, array<string, string>>>|null  $keyboard
     */
    public function __construct(
        public readonly string $text,
        public readonly ?array $keyboard = null,
    ) {}

    /**
     * @param  array<int, array<int, array<string, string>>>|null  $keyboard
     */
    public static function make(string $text, ?array $keyboard = null): self
    {
        return new self($text, $keyboard);
    }
}
