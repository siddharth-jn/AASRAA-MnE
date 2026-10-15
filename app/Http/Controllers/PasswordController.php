<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\PasswordPolicy;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PasswordController extends Controller
{
    public function request(): View
    {
        return view('auth.forgot-password');
    }

    public function email(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $status = Password::sendResetLink($request->only('email'));

        return $status === Password::ResetLinkSent
            ? back()->with('status', __($status))
            : back()->withErrors(['email' => __($status)])->onlyInput('email');
    }

    public function reset(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->string('email')->toString(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordPolicy::rule()],
        ]);

        $status = Password::reset($credentials, function (User $user, string $password): void {
            $user->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
            ])->save();

            if (config('session.driver') === 'database') {
                DB::table(config('session.table', 'sessions'))
                    ->where('user_id', $user->getAuthIdentifier())
                    ->delete();
            }

            event(new PasswordReset($user));
        });

        return $status === Password::PasswordReset
            ? redirect()->route('login')->with('status', __($status))
            : back()->withErrors(['email' => __($status)])->onlyInput('email');
    }

    public function edit(): View
    {
        return view('profile.password');
    }

    public function change(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', PasswordPolicy::rule()],
        ]);

        $request->user()->forceFill([
            'password' => Hash::make($validated['password']),
            'remember_token' => Str::random(60),
        ])->save();

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $request->user()->getAuthIdentifier())
                ->where('id', '!=', $request->session()->getId())
                ->delete();
        }

        $request->session()->regenerate();

        return back()->with('success', 'Your password has been changed. Other signed-in sessions were closed.');
    }
}
