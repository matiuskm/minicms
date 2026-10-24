<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Accept an invitation: set a password and activate the account (F-02). */
class InviteController extends Controller
{
    public function show(Request $request): Response
    {
        return Inertia::render('auth/reset-password', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'invite' => true,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $status = Password::broker('invites')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user) use ($request) {
                // Only invited accounts can be activated here.
                if ($user->status !== 'invited') {
                    return;
                }
                $user->forceFill([
                    'password' => Hash::make($request->password),
                    'status' => 'active',
                    'remember_token' => Str::random(60),
                ])->save();
                AuditLog::record('user.activated', $user, [], $user->id);
            }
        );

        if ($status === Password::PasswordReset && User::where('email', $request->email)->value('status') === 'active') {
            return to_route('login')->with('status', 'Account activated. You can log in now.');
        }

        throw ValidationException::withMessages(['email' => ['This invitation link is invalid or has expired.']]);
    }
}
