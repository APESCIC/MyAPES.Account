<?php

namespace Tests\Feature\Auth;

use App\Core\Accounts\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeLandingRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_see_public_login_doors_on_home(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSeeText('Public Login')
            ->assertSeeText('Register')
            ->assertSeeText('Staff Login')
            ->assertSee('href="'.route('public.login').'"', false)
            ->assertSee('href="'.route('public.register').'"', false)
            ->assertSee('href="'.route('staff.login').'"', false);
    }

    public function test_signed_in_service_users_are_redirected_from_home_to_dashboard(): void
    {
        $user = User::factory()->accessLevel(User::ROLE_SERVICE_USER)->create();

        $this->actingAs($user)
            ->get(route('home'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_signed_in_superadmins_are_redirected_from_home_to_dashboard(): void
    {
        $user = User::factory()->accessLevel(User::ROLE_ADMIN)->create();

        $this->actingAs($user)
            ->get(route('home'))
            ->assertRedirect(route('dashboard'));
    }
}
