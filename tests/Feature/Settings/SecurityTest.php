<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Features;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_page_is_displayed()
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'))
            ->assertOk()
            ->assertViewIs('settings.security')
            ->assertViewHas('canManageTwoFactor', true)
            ->assertViewHas('twoFactorState', 'off')
            ->assertSee('Two-step sign-in is off');
    }

    public function test_security_page_requires_password_confirmation_when_enabled()
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        $user = User::factory()->create();

        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]);

        $response = $this->actingAs($user)
            ->get(route('security.edit'));

        $response->assertRedirect(route('password.confirm'));
    }

    public function test_security_page_renders_without_two_factor_when_feature_is_disabled()
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        config(['fortify.features' => []]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get(route('security.edit'))
            ->assertOk()
            ->assertViewIs('settings.security')
            ->assertViewHas('canManageTwoFactor', false)
            ->assertDontSee('Two-step sign-in is off');
    }

    public function test_password_can_be_updated()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from(route('security.edit'))
            ->put(route('user-password.update'), [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('security.edit'));

        $this->assertTrue(Hash::check('new-password', $user->refresh()->password));
    }

    public function test_correct_password_must_be_provided_to_update_password()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from(route('security.edit'))
            ->put(route('user-password.update'), [
                'current_password' => 'wrong-password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        $response
            ->assertSessionHasErrors('current_password')
            ->assertRedirect(route('security.edit'));
    }

    public function test_two_step_sign_in_can_be_turned_on_and_confirmed_from_the_page()
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        $user = User::factory()->create();
        $session = ['auth.password_confirmed_at' => time()];

        $this->actingAs($user)->withSession($session)->post(route('two-factor.enable'))->assertRedirect();
        $this->actingAs($user)->withSession($session)->get(route('security.edit'))
            ->assertViewHas('twoFactorState', 'pending')
            ->assertSee('<svg', false);

        $secret = decrypt((string) $user->fresh()?->two_factor_secret);
        $code = app(Google2FA::class)->getCurrentOtp($secret);
        $this->actingAs($user)->withSession($session)->post(route('two-factor.confirm'), ['code' => $code])->assertRedirect();

        $this->actingAs($user)->withSession($session + ['status' => 'two-factor-authentication-confirmed'])->get(route('security.edit'))
            ->assertViewHas('twoFactorState', 'on')
            ->assertViewHas('showCodes', true);
    }

    public function test_profile_page_saves_name_and_email()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('profile.edit'))->assertOk()->assertViewIs('settings.profile')->assertSee($user->email);
        $this->actingAs($user)->patch(route('profile.update'), ['name' => 'New Name', 'email' => $user->email])
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('saved', true);
        $this->assertSame('New Name', $user->fresh()?->name);
    }
}
