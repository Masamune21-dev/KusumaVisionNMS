<?php

namespace Tests\Feature;

use App\Models\Zone;
use Database\Seeders\ZoneSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ZoneSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_the_11_initial_zones(): void
    {
        $this->seed(ZoneSeeder::class);

        $this->assertSame(11, Zone::count());
        $this->assertTrue(Zone::where('name', 'PALMARITO')->exists());
        $this->assertTrue(Zone::where('name', 'RINCON')->exists());
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(ZoneSeeder::class);
        $this->seed(ZoneSeeder::class);

        $this->assertSame(11, Zone::count());
    }
}
