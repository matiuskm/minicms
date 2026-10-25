<?php

use App\Models\Setting;

// Renaming the site in Site settings must show up everywhere, not only on the public header.
test('TC-F13-20 the admin shows the configured site name, falling back to APP_NAME', function () {
    config(['app.name' => 'from-env.example']);
    $admin = adminUser();

    $this->actingAs($admin)->get('/admin/settings')->assertInertia(fn ($p) => $p->where('name', 'from-env.example'));

    Setting::put(['site_name' => 'My Blog']);

    $this->actingAs($admin)->get('/admin/settings')
        ->assertInertia(fn ($p) => $p->where('name', 'My Blog'))
        ->assertSee('<meta name="app-name" content="My Blog">', false)
        ->assertSee('<title inertia>My Blog</title>', false);
});

test('TC-F09-20 public pages do not put the signed-in user\'s details in the page source', function () {
    $admin = adminUser();
    Setting::put(['site_name' => 'My Blog']);

    $html = $this->actingAs($admin)->get('/')->assertOk()->getContent();

    expect($html)->not->toContain($admin->email)->not->toContain('updateAvailable')->not->toContain('twoFactorBypassed')
        ->and($this->actingAs($admin)->get('/')->assertInertia(fn ($p) => $p->where('auth.user', ['id' => $admin->id])));
    $this->actingAs($admin)->get('/admin/settings')->assertInertia(fn ($p) => $p->where('auth.user.email', $admin->email)->has('updateAvailable'));
});
