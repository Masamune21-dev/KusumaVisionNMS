<?php

namespace Tests\Feature;

use App\Services\Map\OnuMapPayloadService;
use Tests\TestCase;

/**
 * Titik awal peta: wilayah utama (opsional, env MAP_HOME_*) bila ada titik di sekitarnya,
 * selain itu kelompok titik terpadat — bukan rata-rata koordinat, yang jatuh di tengah
 * dua wilayah berjauhan.
 */
class MapDefaultCenterTest extends TestCase
{
    private function center(array $points): array
    {
        $rows = array_map(fn (array $p) => ['latitude' => $p[0], 'longitude' => $p[1]], $points);

        return app(OnuMapPayloadService::class)->defaultCenter($rows);
    }

    /** @return array<int, array{0: float, 1: float}> */
    private function cluster(float $lat, float $lng, int $n): array
    {
        return array_map(fn (int $i) => [$lat + $i * 0.001, $lng + $i * 0.001], range(1, $n));
    }

    private function setHome(?float $lat, ?float $lng): void
    {
        config()->set('services.map.home_lat', $lat);
        config()->set('services.map.home_lng', $lng);
        config()->set('services.map.home_zoom', 12);
        config()->set('services.map.home_radius_km', 20);
    }

    public function test_opens_on_home_region_when_user_has_points_there(): void
    {
        $this->setHome(-7.00, 110.40);

        $center = $this->center([
            ...$this->cluster(-6.20, 106.80, 160), // wilayah A (terpadat)
            ...$this->cluster(-7.25, 112.75, 60),  // wilayah B
            ...$this->cluster(-7.01, 110.41, 90),  // dekat wilayah utama
        ]);

        $this->assertSame(['lat' => -7.0, 'lng' => 110.4, 'zoom' => 12], $center);
    }

    public function test_without_points_near_home_opens_on_the_densest_cluster(): void
    {
        $this->setHome(-7.00, 110.40);

        $center = $this->center([
            ...$this->cluster(-7.25, 112.75, 40),
            ...$this->cluster(-6.20, 106.80, 5),
        ]);

        $this->assertEqualsWithDelta(-7.25, $center['lat'], 0.05);
        $this->assertEqualsWithDelta(112.75, $center['lng'], 0.05);
    }

    public function test_without_home_config_opens_on_the_densest_cluster_not_the_average(): void
    {
        $this->setHome(null, null);

        $center = $this->center([
            ...$this->cluster(-6.20, 106.80, 30),
            ...$this->cluster(-7.25, 112.75, 10),
        ]);

        // Rata-rata akan jatuh di sekitar bujur 108 — di antara keduanya, bukan area kerja.
        $this->assertEqualsWithDelta(106.80, $center['lng'], 0.05);
        $this->assertSame(13, $center['zoom']);
    }

    public function test_single_point_and_empty_fallbacks(): void
    {
        $this->setHome(null, null);
        $this->assertSame(['lat' => -6.9, 'lng' => 109.7, 'zoom' => 15], $this->center([[-6.9, 109.7]]));
        $this->assertSame(['lat' => -2.5, 'lng' => 118.0, 'zoom' => 5], $this->center([]));

        $this->setHome(-7.00, 110.40);
        $this->assertSame(['lat' => -7.0, 'lng' => 110.4, 'zoom' => 12], $this->center([]));
    }
}
