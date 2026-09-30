<?php

namespace Tests\Unit;

use App\Services\Zte\UnconfiguredOnuDiscovery;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class UnconfiguredOnuDiscoveryTest extends TestCase
{
    public function test_first_tracked_refresh_marks_every_onu_as_baseline(): void
    {
        $now = CarbonImmutable::parse('2026-09-30 15:40:00', 'Asia/Jakarta');

        [$result, $seen] = UnconfiguredOnuDiscovery::stamp($this->discovered(['ZTEG0800AAAA', 'ZTEG0800BBBB']), null, $now);

        $this->assertSame([true, true], array_column($result['onus'], 'first_seen_baseline'));
        $this->assertSame([$now->toIso8601String(), $now->toIso8601String()], array_column($result['onus'], 'first_seen_at'));
        $this->assertSame(['ZTEG0800AAAA', 'ZTEG0800BBBB'], array_keys($seen));
    }

    public function test_new_onu_is_stamped_now_and_listed_first_while_old_ones_keep_their_time(): void
    {
        $first = CarbonImmutable::parse('2026-09-30 15:40:00', 'Asia/Jakarta');
        $later = $first->addHours(3);
        [, $seen] = UnconfiguredOnuDiscovery::stamp($this->discovered(['ZTEG0800AAAA', 'ZTEG0800BBBB']), null, $first);

        // AAAA dicabut (hilang dari daftar), CCCC baru muncul.
        [$result, $seen] = UnconfiguredOnuDiscovery::stamp($this->discovered(['ZTEG0800BBBB', 'ZTEG0800CCCC']), $seen, $later);

        $this->assertSame(['ZTEG0800CCCC', 'ZTEG0800BBBB'], array_column($result['onus'], 'serial_number'));
        $this->assertSame($later->toIso8601String(), $result['onus'][0]['first_seen_at']);
        $this->assertFalse($result['onus'][0]['first_seen_baseline']);
        $this->assertSame($first->toIso8601String(), $result['onus'][1]['first_seen_at']);
        $this->assertTrue($result['onus'][1]['first_seen_baseline']);
        $this->assertSame(['ZTEG0800BBBB', 'ZTEG0800CCCC'], array_keys($seen));

        // AAAA muncul lagi → dihitung baru, bukan waktu lama.
        [$result] = UnconfiguredOnuDiscovery::stamp($this->discovered(['ZTEG0800AAAA']), $seen, $later->addHour());
        $this->assertSame($later->addHour()->toIso8601String(), $result['onus'][0]['first_seen_at']);
        $this->assertFalse($result['onus'][0]['first_seen_baseline']);
    }

    public function test_failed_refresh_leaves_the_seen_map_untouched(): void
    {
        $seen = ['ZTEG0800AAAA' => ['at' => '2026-09-30T15:40:00+07:00', 'baseline' => true]];
        $failed = ['ok' => false, 'count' => 0, 'onus' => [], 'error' => 'timeout'];

        [$result, $next] = UnconfiguredOnuDiscovery::stamp($failed, $seen, CarbonImmutable::now());

        $this->assertSame($failed, $result);
        $this->assertSame($seen, $next);
    }

    public function test_empty_list_after_tracking_began_is_not_a_new_baseline(): void
    {
        [, $seen] = UnconfiguredOnuDiscovery::stamp($this->discovered([]), null, CarbonImmutable::now());
        $this->assertSame([], $seen);

        [$result] = UnconfiguredOnuDiscovery::stamp($this->discovered(['ZTEG0800AAAA']), $seen, CarbonImmutable::now());
        $this->assertFalse($result['onus'][0]['first_seen_baseline']);
    }

    /**
     * @param  array<int, string>  $serials
     * @return array<string, mixed>
     */
    private function discovered(array $serials): array
    {
        return [
            'ok' => true,
            'count' => count($serials),
            'onus' => array_map(fn (string $sn) => ['serial_number' => $sn, 'slot' => 3, 'port' => 1], $serials),
            'error' => null,
        ];
    }
}
