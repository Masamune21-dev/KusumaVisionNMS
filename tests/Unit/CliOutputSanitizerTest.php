<?php

namespace Tests\Unit;

use App\Support\CliOutputSanitizer;
use PHPUnit\Framework\TestCase;

class CliOutputSanitizerTest extends TestCase
{
    public function test_it_strips_telnet_negotiation_bytes_and_keeps_utf8_text(): void
    {
        $output = "\xff\xfb\x01\xff\xfb\x1f\xff\xfb\x18\xff\xfd\x20\xff\xfd\x03"
            ."Welcome to ZXAN product C300\x00\r\n"
            ."\x1B[24;1HBMKV-C300#";

        $cleaned = CliOutputSanitizer::clean($output);

        $this->assertSame(1, preg_match('//u', $cleaned));
        $this->assertStringNotContainsString("\xff", $cleaned);
        $this->assertStringNotContainsString("\x00", $cleaned);
        $this->assertStringNotContainsString("\x1B", $cleaned);
        $this->assertStringContainsString('Welcome to ZXAN product C300', $cleaned);
        $this->assertStringContainsString('BMKV-C300#', $cleaned);
    }
}
