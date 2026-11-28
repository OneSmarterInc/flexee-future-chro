<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Http\Requests\Settings\TwoFactorAuthenticationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Laravel\Fortify\Features;

class SecurityController extends Controller
{
    /**
     * Show the user's security settings page: the password and two-step sign-in.
     */
    public function edit(TwoFactorAuthenticationRequest $request): View
    {
        $user = $request->user();
        $data = ['canManageTwoFactor' => Features::canManageTwoFactorAuthentication(), 'twoFactorState' => 'off'];

        if ($data['canManageTwoFactor']) {
            $request->ensureStateIsValid();
            $confirming = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
            if ($user->hasEnabledTwoFactorAuthentication()) {
                $data['twoFactorState'] = 'on';
                $data['recoveryCodes'] = $user->recoveryCodes();
                $data['showCodes'] = in_array(session('status'), ['two-factor-authentication-confirmed', 'recovery-codes-generated'], true);
            } elseif ($confirming && $user->two_factor_secret !== null) {
                $data['twoFactorState'] = 'pending';
                $data['qrSvg'] = $user->twoFactorQrCodeSvg();
                $data['setupKey'] = decrypt($user->two_factor_secret);
            }
        }

        return view('settings.security', $data);
    }

    /**
     * Update the user's password.
     */
    public function update(PasswordUpdateRequest $request): RedirectResponse
    {
        $request->user()->update([
            'password' => $request->password,
        ]);

        return back()->with('saved', true);
    }
}
