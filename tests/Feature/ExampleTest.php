<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    // `/` は画面を返さず、移動先を返す（docs/design/permissions-api.md 2-1 の No 1）
    public function test_the_root_redirects_to_login_when_logged_out(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_the_root_redirects_to_channels_when_logged_in(): void
    {
        $this->actingAs(User::factory()->create())->get('/')->assertRedirect('/channels');
    }
}
