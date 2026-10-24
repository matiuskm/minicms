<?php

namespace App\Http\Requests\Auth;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\AccountLockedNotification;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Verify credentials (FL-01). Every failure path returns the same message
     * so unknown email, wrong password, disabled and locked accounts look identical.
     */
    public function authenticate(): User
    {
        $user = User::where('email', $this->string('email'))->first();

        // Burn comparable time when the user is unknown.
        if ($user?->password) {
            $valid = Hash::check($this->string('password'), $user->password);
        } else {
            Hash::make($this->string('password'));
            $valid = false;
        }

        $locked = $user?->locked_until?->isFuture() ?? false;

        if (! $user || ! $valid || ! $user->isActive() || $locked) {
            $this->fail($user, $locked);
        }

        RateLimiter::clear($this->failKey($user));

        return $user;
    }

    private function fail(?User $user, bool $locked): never
    {
        AuditLog::record('login.failed', $user, ['email' => mb_substr($this->string('email'), 0, 190)], $user?->id);

        if ($user && $user->isActive() && ! $locked) {
            $key = $this->failKey($user);
            RateLimiter::hit($key, config('cms.lock.window_seconds'));

            if (RateLimiter::tooManyAttempts($key, config('cms.lock.max_failures'))) {
                RateLimiter::clear($key);
                $user->forceFill(['locked_until' => now()->addMinutes(config('cms.lock.minutes'))])->save();
                AuditLog::record('login.locked', $user, [], $user->id);
                $user->notify(new AccountLockedNotification);
            }
        }

        throw ValidationException::withMessages(['email' => __('auth.failed')]);
    }

    private function failKey(User $user): string
    {
        return 'login-fail:'.$user->id;
    }
}
