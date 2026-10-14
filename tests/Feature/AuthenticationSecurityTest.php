<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthenticationSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_request_and_complete_a_password_reset(): void
    {
        Notification::fake();
        $user = User::factory()->create(['active' => true]);

        $this->post(route('password.email'), ['email' => $user->email])->assertSessionHasNoErrors();

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
            $token = $notification->token;

            return true;
        });

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('new-secure-password', $user->fresh()->password));
    }

    public function test_authenticated_user_can_change_their_password(): void
    {
        $user = User::factory()->create(['password' => 'old-secure-password', 'active' => true]);

        $this->actingAs($user)->patch(route('password.change'), [
            'current_password' => 'old-secure-password',
            'password' => 'replacement-password',
            'password_confirmation' => 'replacement-password',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('replacement-password', $user->fresh()->password));
    }
}
