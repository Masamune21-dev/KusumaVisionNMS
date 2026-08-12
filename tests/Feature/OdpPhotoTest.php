<?php

namespace Tests\Feature;

use App\Models\Odp;
use App\Models\Scopes\PartnerOltScope;
use App\Models\SnmpOlt;
use App\Models\User;
use App\Services\Odp\OdpPhotoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

/**
 * Foto dokumentasi ODP: unggah (dikonversi ke WebP), ganti, hapus, dan penyajian
 * berkas lewat rute ber-auth.
 *
 * Berkas TIDAK boleh berada di disk publik — otorisasinya bersandar pada
 * {@see PartnerOltScope} lewat route-model binding, sama seperti aksi ODP lain.
 *
 * Catatan: PHP di server ini tak punya GD, jadi `UploadedFile::fake()->image()`
 * (butuh GD) tidak dipakai — test memakai berkas PNG 1×1 asli di bawah.
 */
class OdpPhotoTest extends TestCase
{
    use RefreshDatabase;

    /** PNG 1×1 valid (base64) — cukup untuk validasi `image` dan konversi cwebp. */
    private const PNG_1PX = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        // Disk privat dipalsukan supaya test tak menulis ke storage produksi.
        Storage::fake(OdpPhotoService::DISK);
    }

    private function makeOlt(string $name = 'OLT-A', string $ip = '10.8.0.1'): SnmpOlt
    {
        return SnmpOlt::create([
            'name' => $name,
            'vendor' => 'ZTE C320',
            'ip' => $ip,
            'snmp_port' => 161,
            'snmp_read_community' => 'public',
            'snmp_version' => 'v2c',
            'cli_transport' => 'telnet',
            'last_test_result' => ['ok' => true],
        ]);
    }

    private function makeOdp(SnmpOlt $olt, string $name = 'ODP-01'): Odp
    {
        return Odp::create([
            'snmp_olt_id' => $olt->id,
            'name' => $name,
            'slot' => 1,
            'port' => 1,
            'latitude' => -6.75,
            'longitude' => 111.03,
        ]);
    }

    private function pngUpload(string $name = 'foto.png'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'odp-test-').'.png';
        file_put_contents($path, base64_decode(self::PNG_1PX));

        // test: true → UploadedFile tak menolak berkas yang bukan hasil upload HTTP.
        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    public function test_upload_stores_photo_and_serves_it_through_the_auth_route(): void
    {
        $olt = $this->makeOlt();
        $odp = $this->makeOdp($olt);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->from(route('map.index'))
            ->post(route('map.odps.photo.store', $odp), ['photo' => $this->pngUpload()])
            ->assertRedirect(route('map.index'));

        $stored = $odp->fresh()->photo_path;
        $this->assertNotNull($stored);
        Storage::disk(OdpPhotoService::DISK)->assertExists($stored);
        $this->assertStringStartsWith("odp-photos/{$odp->id}/", $stored);

        // Konversi hanya bisa diuji bila biner cwebp tersedia (opsional, lihat service).
        if ((new ExecutableFinder)->find('cwebp') !== null) {
            $this->assertStringEndsWith('.webp', $stored, 'Foto seharusnya dikonversi ke WebP.');
            $this->assertStringStartsWith(
                'RIFF',
                Storage::disk(OdpPhotoService::DISK)->get($stored),
                'Berkas hasil konversi harus benar-benar WebP (header RIFF).',
            );
        }

        $this->actingAs($admin)->get(route('odp.photo', $odp))->assertOk();
    }

    public function test_replacing_a_photo_deletes_the_previous_file(): void
    {
        $olt = $this->makeOlt();
        $odp = $this->makeOdp($olt);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('map.odps.photo.store', $odp), ['photo' => $this->pngUpload()]);
        $first = $odp->fresh()->photo_path;

        $this->actingAs($admin)->post(route('map.odps.photo.store', $odp), ['photo' => $this->pngUpload('lain.png')]);
        $second = $odp->fresh()->photo_path;

        $this->assertNotSame($first, $second, 'Nama berkas acak → URL ikut berubah (cache-busting).');
        Storage::disk(OdpPhotoService::DISK)->assertMissing($first);
        Storage::disk(OdpPhotoService::DISK)->assertExists($second);
    }

    public function test_photo_can_be_deleted_and_is_removed_with_the_odp(): void
    {
        $olt = $this->makeOlt();
        $admin = User::factory()->admin()->create();

        $odp = $this->makeOdp($olt);
        $this->actingAs($admin)->post(route('map.odps.photo.store', $odp), ['photo' => $this->pngUpload()]);
        $path = $odp->fresh()->photo_path;

        $this->actingAs($admin)->delete(route('map.odps.photo.destroy', $odp))->assertRedirect();
        $this->assertNull($odp->fresh()->photo_path);
        Storage::disk(OdpPhotoService::DISK)->assertMissing($path);

        // Menghapus ODP juga membuang berkasnya (jangan tinggalkan sampah di storage).
        $other = $this->makeOdp($olt, 'ODP-02');
        $this->actingAs($admin)->post(route('map.odps.photo.store', $other), ['photo' => $this->pngUpload()]);
        $otherPath = $other->fresh()->photo_path;

        $this->actingAs($admin)->delete(route('map.odps.destroy', $other))->assertRedirect();
        Storage::disk(OdpPhotoService::DISK)->assertMissing($otherPath);
    }

    public function test_non_image_upload_is_rejected(): void
    {
        $olt = $this->makeOlt();
        $odp = $this->makeOdp($olt);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('map.odps.photo.store', $odp), [
                'photo' => UploadedFile::fake()->create('config.txt', 8, 'text/plain'),
            ])
            ->assertSessionHasErrors('photo');

        $this->assertNull($odp->fresh()->photo_path);
    }

    public function test_partner_cannot_upload_or_read_photo_of_another_olt(): void
    {
        $mine = $this->makeOlt('OLT-MINE', '10.8.0.1');
        $other = $this->makeOlt('OLT-OTHER', '10.8.0.2');
        $this->makeOdp($mine, 'ODP-MINE');
        $foreign = $this->makeOdp($other, 'ODP-OTHER');

        // Foto milik OLT lain sudah ada di storage.
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('map.odps.photo.store', $foreign), ['photo' => $this->pngUpload()]);

        $partner = User::factory()->partner()->create();
        $partner->partnerOlts()->sync([$mine->id]);

        $this->actingAs($partner)->get(route('odp.photo', $foreign))->assertNotFound();
        $this->actingAs($partner)
            ->post(route('map.odps.photo.store', $foreign), ['photo' => $this->pngUpload()])
            ->assertNotFound();
    }

    public function test_app_can_upload_and_delete_photo_through_the_api(): void
    {
        $olt = $this->makeOlt();
        $odp = $this->makeOdp($olt);
        $operator = User::factory()->create(); // factory default = operator

        $response = $this->actingAs($operator, 'sanctum')
            ->postJson("/api/v1/odps/{$odp->id}/photo", ['photo' => $this->pngUpload()])
            ->assertOk()
            ->assertJsonPath('data.ok', true);

        $stored = $odp->fresh()->photo_path;
        $this->assertNotNull($stored);
        Storage::disk(OdpPhotoService::DISK)->assertExists($stored);
        // Jalur unggah aplikasi memakai service yang sama → URL-nya rute Sanctum.
        $this->assertStringContainsString("/api/v1/odps/{$odp->id}/photo", (string) $response->json('data.photo_url'));

        $this->actingAs($operator, 'sanctum')
            ->deleteJson("/api/v1/odps/{$odp->id}/photo")
            ->assertOk()
            ->assertJsonPath('data.photo_url', null);

        $this->assertNull($odp->fresh()->photo_path);
        Storage::disk(OdpPhotoService::DISK)->assertMissing($stored);
    }

    public function test_api_photo_upload_rejects_demo_foreign_odp_and_non_images(): void
    {
        $mine = $this->makeOlt('OLT-MINE', '10.8.0.1');
        $foreign = $this->makeOlt('OLT-FOREIGN', '10.8.0.2');
        $odp = $this->makeOdp($mine, 'ODP-MINE');
        $foreignOdp = $this->makeOdp($foreign, 'ODP-FOREIGN');

        // Akun demo read-only (BlockDemoWrites).
        $this->actingAs(User::factory()->demo()->create(), 'sanctum')
            ->postJson("/api/v1/odps/{$odp->id}/photo", ['photo' => $this->pngUpload()])
            ->assertForbidden();

        // Partner tak bisa menyentuh ODP OLT lain (PartnerOltScope → 404).
        $partner = User::factory()->partner()->create();
        $partner->partnerOlts()->sync([$mine->id]);
        $this->actingAs($partner, 'sanctum')
            ->postJson("/api/v1/odps/{$foreignOdp->id}/photo", ['photo' => $this->pngUpload()])
            ->assertNotFound();

        // Batas format sama dengan web (aturan validasi dipakai bersama).
        $this->actingAs($partner, 'sanctum')
            ->postJson("/api/v1/odps/{$odp->id}/photo", [
                'photo' => UploadedFile::fake()->create('config.txt', 8, 'text/plain'),
            ])
            ->assertStatus(422);

        $this->assertNull($odp->fresh()->photo_path);
        $this->assertNull($foreignOdp->fresh()->photo_path);
    }

    public function test_photo_url_is_exposed_to_web_pages_and_the_api(): void
    {
        $olt = $this->makeOlt();
        $odp = $this->makeOdp($olt);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('map.odps.photo.store', $odp), ['photo' => $this->pngUpload()]);

        $this->actingAs($admin)
            ->get(route('odp.index'))
            ->assertInertia(fn ($page) => $page->where(
                'odps.0.photo_url',
                fn (?string $url) => is_string($url) && str_contains($url, "/odp/{$odp->id}/photo"),
            ));

        $this->actingAs($admin)
            ->get(route('map.index'))
            ->assertInertia(fn ($page) => $page->where(
                'odps.0.photo_url',
                fn (?string $url) => is_string($url) && str_contains($url, "/odp/{$odp->id}/photo"),
            ));

        // API memakai rute Sanctum-nya sendiri (token bearer tak berlaku di rute web).
        $response = $this->actingAs($admin, 'sanctum')->getJson("/api/v1/odps/{$odp->id}")->assertOk();
        $this->assertStringContainsString("/api/v1/odps/{$odp->id}/photo", (string) $response->json('data.photo_url'));

        $this->actingAs($admin, 'sanctum')->get("/api/v1/odps/{$odp->id}/photo")->assertOk();
    }
}
