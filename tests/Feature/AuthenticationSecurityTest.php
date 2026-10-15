<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Models\Centre;
use App\Models\Programme;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class AuthenticationSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_administration_uses_the_main_login_and_remains_admin_only(): void
    {
        $coordinator = User::factory()->create([
            'email' => 'coordinator@example.com',
            'password' => 'Coordinator-pass1',
            'role' => 'CENTRE COORDINATOR',
            'active' => true,
        ]);

        $this->get('/admin')->assertRedirect(route('login'));
        $this->get('/admin/login')->assertRedirect('/login');

        $this->post(route('login.store'), [
            'email' => $coordinator->email,
            'password' => 'Coordinator-pass1',
        ])->assertRedirect(route('dashboard'));

        $this->get('/admin')->assertForbidden();
    }

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
            'password' => 'New-secure-password1',
            'password_confirmation' => 'New-secure-password1',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('New-secure-password1', $user->fresh()->password));
    }

    public function test_authenticated_user_can_change_their_password(): void
    {
        $user = User::factory()->create(['password' => 'old-secure-password', 'active' => true]);

        $this->actingAs($user)->patch(route('password.change'), [
            'current_password' => 'old-secure-password',
            'password' => 'Replacement-password1',
            'password_confirmation' => 'Replacement-password1',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('Replacement-password1', $user->fresh()->password));
    }

    public function test_password_policy_is_enforced_during_password_change(): void
    {
        $user = User::factory()->create(['password' => 'Old-secure-password1', 'active' => true]);

        $this->actingAs($user)->patch(route('password.change'), [
            'current_password' => 'Old-secure-password1',
            'password' => 'weakpassword',
            'password_confirmation' => 'weakpassword',
        ])->assertSessionHasErrors('password');
    }

    public function test_administrator_must_set_a_valid_confirmed_password_when_creating_a_user(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN', 'active' => true]);

        Livewire::actingAs($admin)
            ->test(CreateUser::class)
            ->fillForm([
                'name' => 'New Coordinator',
                'email' => 'coordinator@example.com',
                'role' => 'CENTRE COORDINATOR',
                'active' => true,
            ])
            ->call('create')
            ->assertHasFormErrors(['password' => 'required']);

        Livewire::actingAs($admin)
            ->test(CreateUser::class)
            ->fillForm([
                'name' => 'New Coordinator',
                'email' => 'coordinator@example.com',
                'password' => 'Coordinator-pass1',
                'password_confirmation' => 'Coordinator-pass1',
                'role' => 'CENTRE COORDINATOR',
                'active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $createdUser = User::query()->where('email', 'coordinator@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('Coordinator-pass1', $createdUser->password));

        auth()->logout();
        $this->post(route('login.store'), [
            'email' => 'coordinator@example.com',
            'password' => 'Coordinator-pass1',
        ])->assertRedirect(route('dashboard'));
    }

    public function test_administrator_can_select_all_projects_and_their_centres_for_a_user(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN', 'active' => true]);
        $programme = Programme::create(['name' => 'Programme', 'description' => 'Test programme']);
        $firstProject = Project::create(['programme_id' => $programme->id, 'code' => 'P1', 'name' => 'First project', 'description' => 'Test']);
        $secondProject = Project::create(['programme_id' => $programme->id, 'code' => 'P2', 'name' => 'Second project', 'description' => 'Test']);
        $firstCentre = Centre::create(['code' => 'C1', 'name' => 'First centre', 'type' => 'Education Centre', 'region' => 'Dehradun']);
        $secondCentre = Centre::create(['code' => 'C2', 'name' => 'Second centre', 'type' => 'Education Centre', 'region' => 'Dehradun']);
        $firstProject->centres()->attach($firstCentre);
        $secondProject->centres()->attach($secondCentre);

        Livewire::actingAs($admin)
            ->test(CreateUser::class)
            ->callFormComponentAction('projectSelectionActions', 'selectAllProjects')
            ->assertSchemaStateSet(['projects' => [$firstProject->id, $secondProject->id]])
            ->callFormComponentAction('centreSelectionActions', 'selectAllCentres')
            ->assertSchemaStateSet(['centres' => [$firstCentre->id, $secondCentre->id]]);
    }

    public function test_profile_shows_role_scope_and_admin_link_only_to_administrators(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN', 'active' => true]);
        $viewer = User::factory()->create(['role' => 'VIEWER', 'active' => true]);

        $this->actingAs($admin)
            ->get(route('profile.show'))
            ->assertOk()
            ->assertSee('You can access all projects as an administrator.')
            ->assertSee('Open administration');

        $this->actingAs($viewer)
            ->get(route('profile.show'))
            ->assertOk()
            ->assertSee('No projects have been assigned.')
            ->assertDontSee('Open administration');
    }
}
