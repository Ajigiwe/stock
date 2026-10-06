<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Ports of setupOwner / login / logout / the src/proxy.ts routing decisions.
 * Error strings are asserted verbatim so they can never drift from the
 * original app's wording.
 */
class AuthFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mrjeff.setup_secret' => 'correct-horse-battery']);
    }

    /**
     * /login only exists once an owner has been bootstrapped (EnsureSetupState
     * sends fresh installs to /setup) â€” every login-path test seeds one.
     */
    private function seedOwner(): User
    {
        return $this->makeUser([
            'role' => User::ROLE_OWNER,
            'email' => 'owner@example.com',
        ]);
    }

    private function makeUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'id' => (string) Str::uuid(),
            'name' => 'Kofi Mensah',
            'email' => 'kofi@example.com',
            'password' => Hash::make('secret1234'),
            'role' => User::ROLE_ATTENDANT,
            'active' => true,
        ], $overrides));
    }

    public function test_visitors_are_sent_to_setup_while_no_owner_exists(): void
    {
        $this->get('/login')->assertRedirect(route('setup'));
        $this->get('/setup')->assertOk();
    }

    public function test_signup_route_still_redirects_to_login(): void
    {
        $this->get('/signup')->assertRedirect('/login');
    }

    public function test_setup_refuses_a_wrong_secret(): void
    {
        $this->from('/setup')
            ->post('/setup', [
                'name' => 'Owner',
                'email' => 'owner@example.com',
                'password' => 'password123',
                'secret' => 'wrong',
            ])
            ->assertRedirect('/setup')
            ->assertSessionHasErrors('action');

        $this->assertSame(0, User::count());
    }

    public function test_setup_refuses_weak_input_with_the_original_message(): void
    {
        $this->post('/setup', [
            'name' => 'Owner',
            'email' => 'not-an-email',
            'password' => 'short',
            'secret' => 'correct-horse-battery',
        ])->assertSessionHasErrors([
            'action' => 'Name and a valid email are required; password must be at least 8 characters.',
        ]);
    }

    public function test_setup_creates_the_owner_and_signs_them_in(): void
    {
        $this->post('/setup', [
            'name' => 'Afua Owusu',
            'email' => 'afua@example.com',
            'password' => 'password123',
            'secret' => 'correct-horse-battery',
        ])->assertRedirect(route('dashboard'));

        $owner = User::where('role', User::ROLE_OWNER)->firstOrFail();
        $this->assertSame('Afua Owusu', $owner->name);
        $this->assertTrue(Hash::check('password123', $owner->password));
        $this->assertAuthenticatedAs($owner);
    }

    public function test_setup_is_refused_once_an_owner_exists(): void
    {
        $this->makeUser(['role' => User::ROLE_OWNER, 'email' => 'first@example.com']);

        // Signed-out: /setup itself is no longer reachable.
        $this->get('/setup')->assertRedirect(route('login'));

        // Even a direct POST cannot mint a second owner.
        $this->post('/setup', [
            'name' => 'Second',
            'email' => 'second@example.com',
            'password' => 'password123',
            'secret' => 'correct-horse-battery',
        ]);

        $this->assertSame(1, User::where('role', User::ROLE_OWNER)->count());
    }

    public function test_login_rejects_bad_credentials(): void
    {
        $this->seedOwner();

        $this->makeUser();

        $this->from('/login')
            ->post('/login', ['email' => 'kofi@example.com', 'password' => 'nope'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('action');

        $this->assertGuest();
    }

    public function test_login_requires_email_and_password(): void
    {
        $this->seedOwner();

        $this->post('/login', [])->assertSessionHasErrors([
            'action' => 'Enter your email or phone number and password.',
        ]);
    }

    public function test_login_accepts_a_phone_number_instead_of_email(): void
    {
        $this->seedOwner();

        $user = $this->makeUser(['email' => null, 'phone' => '0241234567']);

        // Spacing/formatting differences must not lock anyone out.
        $this->post('/login', [
            'email' => '024 123 4567',
            'password' => 'secret1234',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('login_logs', ['user_id' => $user->id]);
    }

    public function test_staff_accounts_can_be_created_with_only_a_phone_number(): void
    {
        $owner = $this->seedOwner();

        $this->actingAs($owner)
            ->from('/settings')
            ->post('/settings/staff', [
                'name' => 'Ama Serwaa',
                'phone' => '020 765 4321',
                'password' => 'secret1234',
            ])
            ->assertRedirect('/settings')
            ->assertSessionHas('success', 'Staff account for Ama Serwaa created.');

        $this->assertDatabaseHas('users', [
            'name' => 'Ama Serwaa',
            'email' => null,
            'phone' => '0207654321',
            'role' => User::ROLE_ATTENDANT,
        ]);

        // And the new account signs straight in with that number.
        $this->post('/login', [
            'email' => '0207654321',
            'password' => 'secret1234',
        ])->assertRedirect(route('dashboard'));
    }

    public function test_staff_creation_rejects_a_taken_phone_number_and_a_bad_email(): void
    {
        $owner = $this->seedOwner();
        $this->makeUser(['email' => null, 'phone' => '0241234567']);

        $this->actingAs($owner)
            ->from('/settings')
            ->post('/settings/staff', [
                'name' => 'Copy Cat',
                'phone' => '0241234567',
                'password' => 'secret1234',
            ])
            ->assertRedirect('/settings')
            ->assertSessionHasErrors(['action' => 'That phone number is already in use.']);

        $this->actingAs($owner)
            ->from('/settings')
            ->post('/settings/staff', [
                'name' => 'Bad Mail',
                'email' => 'not-an-email',
                'password' => 'secret1234',
            ])
            ->assertRedirect('/settings')
            ->assertSessionHasErrors(['action' => 'That email address is not valid.']);
    }

    public function test_login_signs_the_user_in_and_records_the_log(): void
    {
        $this->seedOwner();

        $user = $this->makeUser();

        $this->post('/login', [
            'email' => 'kofi@example.com',
            'password' => 'secret1234',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('login_logs', ['user_id' => $user->id, 'email' => 'kofi@example.com']);
    }

    public function test_login_is_rate_limited_after_eight_attempts(): void
    {
        $this->seedOwner();

        $this->makeUser();

        for ($i = 0; $i < 8; $i++) {
            $this->post('/login', ['email' => 'kofi@example.com', 'password' => 'wrong']);
        }

        $this->post('/login', ['email' => 'kofi@example.com', 'password' => 'secret1234'])
            ->assertSessionHasErrors([
                'action' => 'Too many sign-in attempts. Wait 10 minutes and try again.',
            ]);

        $this->assertGuest();
    }

    public function test_deactivated_users_cannot_sign_in(): void
    {
        $this->seedOwner();

        $this->makeUser(['active' => false]);

        $this->post('/login', [
            'email' => 'kofi@example.com',
            'password' => 'secret1234',
        ])->assertSessionHasErrors([
            'action' => 'This account is deactivated. Ask the owner to restore access.',
        ]);

        $this->assertGuest();
    }

    public function test_deactivated_users_are_signed_out_mid_session(): void
    {
        $user = $this->makeUser(['active' => true]);
        $this->actingAs($user);

        $user->forceFill(['active' => false])->save();

        // The `active` middleware must kill the session on the next request,
        // which is exactly what src/proxy.ts did with app_metadata.deactivated.
        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_authenticated_users_are_redirected_off_the_auth_pages(): void
    {
        $user = $this->makeUser(['role' => User::ROLE_OWNER]);
        $this->actingAs($user)->get('/login')->assertRedirect(route('dashboard'));
    }

    public function test_protected_pages_require_a_session(): void
    {
        $this->seedOwner();

        $this->get('/')->assertRedirect(route('login'));
        $this->get('/reports')->assertRedirect(route('login'));
        $this->post('/logout')->assertRedirect(route('login'));
    }
}
