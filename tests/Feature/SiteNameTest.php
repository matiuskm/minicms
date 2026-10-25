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
