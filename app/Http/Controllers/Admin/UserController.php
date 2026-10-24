<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\InviteNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('q', ''));

        $users = User::query()
            ->select('id', 'name', 'email', 'role', 'status', 'two_factor_confirmed_at', 'last_login_at', 'locked_until')
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (User $u) => [
                ...$u->only('id', 'name', 'email', 'role', 'status', 'last_login_at'),
                'two_factor' => $u->hasTwoFactor(),
                'locked' => (bool) $u->locked_until?->isFuture(),
            ]);

        return Inertia::render('admin/users/index', [
            'users' => $users,
            'filters' => ['q' => $search],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:190', Rule::unique(User::class)],
            'role' => ['required', Rule::in(['admin', 'writer'])],
        ]);

        $user = new User(['name' => $data['name'], 'email' => $data['email']]);
        $user->forceFill(['role' => $data['role'], 'status' => 'invited'])->save();

        $this->sendInvite($user);
        AuditLog::record('user.invited', $user, ['role' => $user->role]);

        return back()->with('status', 'Invitation sent.');
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'role' => ['required', Rule::in(['admin', 'writer'])],
        ]);

        if ($data['role'] !== $user->role) {
            $this->guardNotSelf($request, $user, 'You cannot change your own role.');
            $this->guardLastAdmin($user, 'The last active admin cannot be demoted.');
            AuditLog::record('user.role_changed', $user, ['from' => $user->role, 'to' => $data['role']]);
            $user->forceFill(['role' => $data['role']]);
        }

        $user->name = $data['name'];
        $user->save();

        return back();
    }

    public function disable(Request $request, User $user): RedirectResponse
    {
        $this->guardNotSelf($request, $user, 'You cannot disable your own account.');
        $this->guardLastAdmin($user, 'The last active admin cannot be disabled.'); // BR-07

        $user->forceFill(['status' => 'disabled'])->save();
        $user->endSessions();
        AuditLog::record('user.disabled', $user);

        return back();
    }

    public function enable(User $user): RedirectResponse
    {
        $user->forceFill(['status' => $user->password ? 'active' : 'invited', 'locked_until' => null])->save();
        AuditLog::record('user.enabled', $user);

        return back();
    }

    public function forceReset(User $user): RedirectResponse
    {
        // Old password stops working immediately; the user must use the emailed link.
        $user->forceFill(['password' => Hash::make(Str::random(40))])->save();
        $user->endSessions();
        Password::sendResetLink(['email' => $user->email]);
        AuditLog::record('user.force_reset', $user);

        return back()->with('status', 'Password reset forced; a reset link was sent.');
    }

    public function resendInvite(User $user): RedirectResponse
    {
        abort_unless($user->status === 'invited', 422);
        $this->sendInvite($user);

        return back()->with('status', 'Invitation re-sent.');
    }

    private function sendInvite(User $user): void
    {
        $user->notify(new InviteNotification(Password::broker('invites')->createToken($user)));
    }

    private function guardNotSelf(Request $request, User $user, string $message): void
    {
        if ($request->user()->is($user)) {
            throw ValidationException::withMessages(['user' => $message]);
        }
    }

    private function guardLastAdmin(User $user, string $message): void
    {
        if ($user->isLastActiveAdmin()) {
            throw ValidationException::withMessages(['user' => $message]);
        }
    }
}
