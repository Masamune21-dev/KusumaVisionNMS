<?php

namespace Tests\Unit;

use App\Services\Snmp\OltSnmpClient;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Serial GPON = 4 ASCII vendor + 4 byte biner. Bila 4 byte biner kebetulan tercetak
 * (50 57 3A 79 = "PW:y"), net-snmp mengembalikannya sebagai STRING, dan jalur teks
 * lama membuang ":" → "CDTCPWY". Kasus nyata di OLT C300 produksi (24 Sep 2026): puluhan ONU,
 * beberapa kembar, sehingga identitas ONU tak lagi unik.
 */
class OnuSerialDecodeTest extends TestCase
{
    private function decode(?string $raw): ?string
    {
        $method = new ReflectionMethod(OltSnmpClient::class, 'decodeOnuSn');

        return $method->invoke(app(OltSnmpClient::class), $raw);
    }

    public function test_raw_bytes_with_a_printable_tail_decode_to_vendor_plus_hex(): void
    {
        $this->assertSame('CDTC50573A79', $this->decode("CDTC\x50\x57\x3A\x79"));
    }

    public function test_raw_bytes_ending_in_space_or_quote_are_not_trimmed_away(): void
    {
        $this->assertSame('CDTC50572022', $this->decode("CDTC\x50\x57\x20\x22"));
    }

    public function test_hex_string_and_plain_text_forms_still_work(): void
    {
        $this->assertSame('ZTEGCDD9C316', $this->decode('5A 54 45 47 CD D9 C3 16'));
        $this->assertSame('ZTEGCDD9C316', $this->decode('ZTEGCDD9C316'));
        $this->assertNull($this->decode(''));
    }
}
