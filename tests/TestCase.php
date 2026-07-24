<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Sabuk pengaman: pastikan test TIDAK PERNAH menyentuh DB produksi.
     *
     * Latar: server ini menjalankan config ter-cache (`bootstrap/cache/config.php`).
     * Cache config MENANG atas blok <env> di phpunit.xml, jadi tanpa penjagaan apa pun
     * `./vendor/bin/phpunit` polos akan tersambung ke Postgres produksi
     * (`kusumavision_nms`) dengan `app()->environment() === 'production'` — pernah
     * terjadi & sempat menabrak tabel `zones` asli. Pertahanan utama = `APP_CONFIG_CACHE`
     * di phpunit.xml (menunjuk path cache khusus test yang tak pernah ditulis
     * `config:cache`); guard ini lapis kedua kalau pertahanan itu hilang/ter-override.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertRunningAgainstDisposableDatabase();
    }

    private function assertRunningAgainstDisposableDatabase(): void
    {
        $env = $this->app->environment();
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");

        if ($env !== 'testing' || $connection !== 'sqlite' || $database !== ':memory:') {
            throw new RuntimeException(sprintf(
                "ABORT: test suite tersambung ke database NON-disposable.\n".
                "  environment : %s (harus: testing)\n".
                "  connection  : %s (harus: sqlite)\n".
                "  database    : %s (harus: :memory:)\n".
                'Kemungkinan besar cache config produksi (bootstrap/cache/config.php) menimpa '.
                'phpunit.xml. Pastikan <env name="APP_CONFIG_CACHE" …> di phpunit.xml masih ada.',
                $env,
                $connection,
                var_export($database, true),
            ));
        }
    }
}
