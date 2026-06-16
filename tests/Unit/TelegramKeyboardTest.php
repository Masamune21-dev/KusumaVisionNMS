<?php

namespace Tests\Unit;

use App\Services\Telegram\TelegramKeyboard;
use App\Services\Telegram\TelegramOnuQueryService;
use PHPUnit\Framework\TestCase;

class TelegramKeyboardTest extends TestCase
{
    public function test_parse_splits_screen_and_int_args(): void
    {
        [$screen, $args] = TelegramKeyboard::parse('on:5:1:2:1:3');

        $this->assertSame('on', $screen);
        $this->assertSame([5, 1, 2, 1, 3], $args);
    }

    public function test_builders_stay_under_telegram_64_byte_limit(): void
    {
        $longest = TelegramKeyboard::onuDetail(65535, 9, 16, 127, 4, 65535, 99);

        $this->assertLessThanOrEqual(64, strlen($longest));
        $this->assertSame('u:65535:9:16:127:4:65535:99', $longest);
    }

    public function test_pager_hidden_for_single_page(): void
    {
        $this->assertSame([], TelegramKeyboard::pager(0, 1, fn ($p) => "x:{$p}"));
    }

    public function test_pager_shows_prev_next_and_indicator(): void
    {
        $row = TelegramKeyboard::pager(1, 3, fn ($p) => "x:{$p}");

        $this->assertCount(3, $row);
        $this->assertSame('x:0', $row[0]['callback_data']);
        $this->assertSame('2/3', $row[1]['text']);
        $this->assertSame('x:2', $row[2]['callback_data']);
    }

    public function test_rx_severity_is_tiered(): void
    {
        $this->assertSame('none', TelegramOnuQueryService::rxSeverity(null));
        $this->assertSame('ok', TelegramOnuQueryService::rxSeverity(-21.0));
        $this->assertSame('warning', TelegramOnuQueryService::rxSeverity(-26.0));
        $this->assertSame('critical', TelegramOnuQueryService::rxSeverity(-29.0));
        $this->assertSame('high', TelegramOnuQueryService::rxSeverity(-5.0));
    }

    public function test_rx_alert_excludes_safe_band(): void
    {
        $this->assertFalse(TelegramOnuQueryService::rxIsAlert(-21.0));
        $this->assertFalse(TelegramOnuQueryService::rxIsAlert(null));
        $this->assertTrue(TelegramOnuQueryService::rxIsAlert(-26.0));
        $this->assertTrue(TelegramOnuQueryService::rxIsAlert(-30.0));
    }
}
