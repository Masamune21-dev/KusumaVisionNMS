<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * `user:create` dipakai install.sh (dengan --role=admin) dan dokumentasi instalasi untuk
 * membuat admin pertama — registrasi publik dimatikan, jadi ini satu-satunya pintu masuk.
 */
class CreateUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_admin_creates_an_administrator(): void
    {
        $this->artisan('user:create', [
            '--name' => 'Admin Uji',
            '--email' => 'admin-uji@example.test',
            '--password' => 'passwordkuat',
            '--role' => 'admin',
        ])->assertSuccessful();

        $user = User::where('email', 'admin-uji@example.test')->firstOrFail();
        $this->assertSame(UserRole::Admin, $user->role);
        $this->assertTrue(Hash::check('passwordkuat', $user->password));
    }

    public function test_without_role_defaults_to_operator(): void
    {
        $this->artisan('user:create', [
            '--name' => 'Operator Uji',
            '--email' => 'operator-uji@example.test',
            '--password' => 'passwordkuat',
        ])->assertSuccessful();

        $this->assertSame(UserRole::Operator, User::where('email', 'operator-uji@example.test')->firstOrFail()->role);
    }

    public function test_password_shorter_than_eight_characters_is_rejected(): void
    {
        $this->artisan('user:create', [
            '--name' => 'Admin Uji',
            '--email' => 'admin-uji@example.test',
            '--password' => 'rahasia',
            '--role' => 'admin',
        ])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'admin-uji@example.test']);
    }

    public function test_unknown_role_is_rejected(): void
    {
        $this->artisan('user:create', [
            '--name' => 'Admin Uji',
            '--email' => 'admin-uji@example.test',
            '--password' => 'passwordkuat',
            '--role' => 'superadmin',
        ])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'admin-uji@example.test']);
    }
}
