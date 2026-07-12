<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanduanPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_panduan_page_renders_for_authenticated_user(): void
    {
        // Operator (peran default) pun boleh — panduan tersedia untuk semua yang login.
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('panduan'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Panduan/Index'));
    }

    public function test_panduan_requires_authentication(): void
    {
        $this->get(route('panduan'))->assertRedirect(route('login'));
    }
}
