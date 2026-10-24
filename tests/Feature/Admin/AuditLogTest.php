<?php

use App\Models\AuditLog;
use App\Models\User;

test('TC-F11-01 sensitive actions record actor, object and IP', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    $writer = User::factory()->create();

    $this->actingAs($admin)->post("/admin/users/{$writer->id}/disable");

    $log = AuditLog::where('action', 'user.disabled')->firstOrFail();
    expect($log->actor_id)->toBe($admin->id)
        ->and($log->object_type)->toBe('User')->and($log->object_id)->toBe($writer->id)
        ->and($log->ipText())->not->toBeNull();
});

test('TC-F11-02 only admins can read or export the audit log', function () {
    $writer = User::factory()->create();
    $admin = User::factory()->admin()->withTwoFactor()->create();

    $this->actingAs($writer)->get('/admin/audit-logs')->assertForbidden();
    $this->actingAs($writer)->get('/admin/audit-logs/export')->assertForbidden();
    $this->actingAs($admin)->get('/admin/audit-logs')->assertOk();
});

test('audit log filters by action and exports CSV', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    AuditLog::record('login.failed');
    AuditLog::record('user.invited');

    $this->actingAs($admin)->get('/admin/audit-logs?action=login')->assertOk()
        ->assertInertia(fn ($page) => $page->has('logs.data', 1)->where('logs.data.0.action', 'login.failed'));

    $csv = $this->actingAs($admin)->get('/admin/audit-logs/export')->assertOk()->streamedContent();
    expect($csv)->toContain('login.failed')->toContain('user.invited');
});

test('PP-F11-02 audit rows cannot be updated or deleted through the model', function () {
    $log = AuditLog::record('login.success');

    expect(fn () => $log->update(['action' => 'tampered']))->toThrow(LogicException::class);
    expect(fn () => $log->delete())->toThrow(LogicException::class);
});

test('audit log entries are not queried per row (no N+1)', function () {
    $admin = User::factory()->admin()->withTwoFactor()->create();
    foreach (range(1, 10) as $i) {
        AuditLog::record('login.success', null, [], $admin->id);
    }

    DB::enableQueryLog();
    $this->actingAs($admin)->get('/admin/audit-logs')->assertOk();
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(8);
});
