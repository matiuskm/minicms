<?php

use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Str;
use Tighten\Ziggy\BladeRouteGenerator;

/** Ziggy writes the full script once per PHP process; each request in these tests stands for a new process. */
function pageSource(string $url, ?User $as = null): string
{
    BladeRouteGenerator::$generated = false;

    return ($as ? test()->actingAs($as) : test())->get($url)->getContent();
}

test('a signed-out visitor gets only the public route group, never the admin route table', function () {
    Post::factory()->status('published')->create(['slug' => 'hello']);

    foreach (['/', '/blog', '/blog/hello', '/nope-404'] as $url) {
        expect(pageSource($url))->not->toContain('"login"')->not->toContain('password.request');
    }
    expect(pageSource('/login'))->toContain('"login"');

    foreach (['/', '/blog', '/blog/hello', '/login', '/nope-404'] as $url) {
        expect(pageSource($url))->toContain('const Ziggy')
            ->not->toContain('admin.posts')->not->toContain('two-factor.destroy')->not->toContain('sessions.');
    }
});

test('a signed-in admin still gets the full route table', function () {
    expect(pageSource('/dashboard', adminUser()))->toContain('admin.posts.index');
});

test('every route() the public, sign-in and error pages call is in the public group', function () {
    $allowed = config('ziggy.groups.auth');
    $files = array_merge(
        glob(resource_path('js/pages/auth/*.tsx')),
        glob(resource_path('js/pages/public/*.tsx')),
        glob(resource_path('js/components/public/*.tsx')),
        [resource_path('js/pages/error.tsx'), resource_path('js/pages/welcome.tsx')],
    );

    foreach ($files as $file) {
        preg_match_all('/\broute\(([^)]*)\)/', file_get_contents($file), $calls);
        foreach ($calls[1] as $args) {
            preg_match_all('/[\'"]([a-z][a-z0-9.\-]*)[\'"]/', $args, $names);
            foreach ($names[1] as $name) {
                expect(collect($allowed)->contains(fn ($p) => Str::is($p, $name)))->toBeTrue("route('{$name}') in ".basename($file).' is missing from config/ziggy.php');
            }
        }
    }
});

test('logging in answers with a full-page redirect for Inertia requests, so the dashboard loads with every route', function () {
    $user = User::factory()->create();

    $this->withHeaders(['X-Inertia' => 'true'])->post('/login', ['email' => $user->email, 'password' => PW])
        ->assertStatus(409)->assertHeader('X-Inertia-Location');
});

test('with a secret auth prefix the sign-in screens move and nothing else points at them', function () {
    config(['cms.auth_prefix' => 'ops-x1']);
    app('router')->setRoutes(new Illuminate\Routing\RouteCollection);
    require base_path('routes/web.php');
    app('router')->getRoutes()->refreshNameLookups();

    $this->get('/ops-x1/login')->assertOk();
    $this->get('/login')->assertNotFound();
    $this->get('/dashboard')->assertNotFound();
    $this->get('/admin/users')->assertNotFound();
    $this->get('/robots.txt')->assertDontSee('login')->assertDontSee('ops-x1');
    expect(pageSource('/'))->not->toContain('ops-x1');
});
