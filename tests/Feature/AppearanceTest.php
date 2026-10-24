<?php

use App\Models\AuditLog;
use App\Models\Palette;
use App\Models\Post;
use App\Models\Setting;
use App\Models\User;
use App\Support\ThemeCss;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function tokens(array $o = []): array
{
    return [...Palette::where('name', 'Light Classic')->first()->tokens, ...$o];
}

function css(): string
{
    return test()->get('/theme.css')->assertOk()->getContent();
}

test('TC-F08-01 six built-in palettes exist and Light Classic is live', function () {
    expect(Palette::where('is_preset', true)->pluck('name')->all())->toEqualCanonicalizing(['Light Classic', 'Dark', 'Ocean', 'Forest', 'Sunset', 'Monochrome']);
    expect(Palette::where('is_active', true)->pluck('name')->all())->toBe(['Light Classic']);
    expect(css())->toContain('--site-bg:#f1e5da')->toContain('--site-primary:#8f3f1f');
});

test('TC-F08-01 activating a built-in palette changes the public stylesheet and keeps exactly one active', function () {
    $dark = Palette::where('name', 'Dark')->first();
    $this->get('/theme.css'); // warm the cache so invalidation is exercised

    $this->actingAs(adminUser())->post("/admin/appearance/palettes/{$dark->id}/activate")->assertSessionHasNoErrors();
    auth()->logout();

    expect(css())->toContain('--site-bg:#0f1115')->not->toContain('#f1e5da');
    expect(Palette::where('is_active', true)->count())->toBe(1);
    expect(AuditLog::where('action', 'palette.activated')->exists())->toBeTrue();
});

test('PP-F08-03 the database refuses a second active palette', function () {
    $other = Palette::where('is_active', false)->first();

    expect(fn () => DB::table('palettes')->where('id', $other->id)->update(['is_active' => 1]))->toThrow(QueryException::class);
});

test('the theme selector outranks the default one so stylesheet order cannot undo it', function () {
    // Vite may re-insert app.css after theme.css at runtime; `html .site` still wins on specificity.
    expect(css())->toStartWith('html .site{');
});

test('TC-F08-02 an admin creates, edits and activates a custom palette', function () {
    $admin = adminUser();

    $this->actingAs($admin)->post('/admin/appearance/palettes', ['name' => 'Mine', 'tokens' => tokens(['primary' => '#112233', 'link' => '#112233'])])->assertSessionHasNoErrors();
    $mine = Palette::where('name', 'Mine')->firstOrFail();
    expect($mine->is_preset)->toBeFalse()->and($mine->is_active)->toBeFalse()->and($mine->created_by)->toBe($admin->id);

    $this->actingAs($admin)->put("/admin/appearance/palettes/{$mine->id}", ['name' => 'Mine v2', 'tokens' => tokens(['primary' => '#AABBCC'])])->assertSessionHasNoErrors();
    expect($mine->fresh()->name)->toBe('Mine v2')->and($mine->fresh()->tokens['primary'])->toBe('#aabbcc');

    $this->actingAs($admin)->post("/admin/appearance/palettes/{$mine->id}/activate");
    auth()->logout();
    expect(css())->toContain('--site-primary:#aabbcc');
});

test('TC-F08-03 colour values are strict hex, so no CSS can be injected', function () {
    $admin = adminUser();

    foreach (['red;}body{display:none', 'url(https://evil.example/x)', 'red', '#abc', '#12345', '#gggggg', '#aabbcc;', '#aabbcc}', "#aabbcc\n}", 'expression(alert(1))', '', '#aabbccddee'] as $bad) {
        $this->actingAs($admin)->post('/admin/appearance/palettes', ['name' => 'Bad', 'tokens' => tokens(['text' => $bad])])->assertSessionHasErrors('tokens.text');
    }
    $this->actingAs($admin)->post('/admin/appearance/palettes', ['name' => 'Bad', 'tokens' => ['background' => '#ffffff']])->assertSessionHasErrors('tokens.text');
    expect(Palette::where('is_preset', false)->count())->toBe(0);
});

test('only the eight known tokens are stored', function () {
    $this->actingAs(adminUser())->post('/admin/appearance/palettes', ['name' => 'Extra', 'tokens' => [...tokens(), 'evil' => '#ffffff', 'background-image' => 'url(x)']])->assertSessionHasNoErrors();

    expect(array_keys(Palette::where('name', 'Extra')->first()->tokens))->toEqualCanonicalizing(Palette::TOKENS);
});

test('the generated stylesheet only ever contains safe characters', function () {
    // Even if a bad value got into the database some other way, it never reaches the CSS.
    $bad = Palette::create(['name' => 'Poisoned', 'tokens' => tokens(['text' => 'red;}body{display:none', 'link' => 'url(x)'])]);
    Palette::where('is_active', true)->update(['is_active' => false]);
    $bad->forceFill(['is_active' => true])->save();

    $out = css();
    expect($out)->not->toContain('display:none')->not->toContain('url(');
    expect($out)->toMatch('/^[a-z0-9#:;,.\-%\'" \n{}]+$/i');
    expect($out)->toContain('--site-bg'); // the valid tokens still render
});

test('TC-F08-04 built-in palettes cannot be edited or deleted, and the active one cannot be deleted', function () {
    $admin = adminUser();
    $preset = Palette::where('name', 'Ocean')->first();

    $this->actingAs($admin)->put("/admin/appearance/palettes/{$preset->id}", ['name' => 'Hacked', 'tokens' => tokens()])->assertStatus(422);
    $this->actingAs($admin)->delete("/admin/appearance/palettes/{$preset->id}")->assertStatus(422);
    expect($preset->fresh()->name)->toBe('Ocean');

    $this->actingAs($admin)->post('/admin/appearance/palettes', ['name' => 'Mine', 'tokens' => tokens()]);
    $mine = Palette::where('name', 'Mine')->first();
    $this->actingAs($admin)->post("/admin/appearance/palettes/{$mine->id}/activate");
    $this->actingAs($admin)->delete("/admin/appearance/palettes/{$mine->id}")->assertSessionHasErrors('palette');
    expect(Palette::find($mine->id))->not->toBeNull();

    $this->actingAs($admin)->post('/admin/appearance/palettes/'.Palette::where('name', 'Light Classic')->value('id').'/activate');
    $this->actingAs($admin)->delete("/admin/appearance/palettes/{$mine->id}")->assertSessionHasNoErrors();
    expect(Palette::find($mine->id))->toBeNull();
});

test('duplicating a palette makes an editable custom copy with a unique name', function () {
    $admin = adminUser();
    $preset = Palette::where('name', 'Forest')->first();

    $this->actingAs($admin)->post("/admin/appearance/palettes/{$preset->id}/duplicate")->assertSessionHasNoErrors();
    $this->actingAs($admin)->post("/admin/appearance/palettes/{$preset->id}/duplicate")->assertSessionHasNoErrors();

    $copies = Palette::where('is_preset', false)->orderBy('id')->get();
    expect($copies->pluck('name')->all())->toBe(['Forest copy', 'Forest copy 2'])->and($copies[0]->tokens)->toBe($preset->tokens)->and($copies[0]->is_active)->toBeFalse();
});

test('names are unique and custom palettes are capped', function () {
    $admin = adminUser();
    $this->actingAs($admin)->post('/admin/appearance/palettes', ['name' => 'Dark', 'tokens' => tokens()])->assertSessionHasErrors('name');

    foreach (range(1, config('cms.max_custom_palettes')) as $i) {
        $this->actingAs($admin)->post('/admin/appearance/palettes', ['name' => "P{$i}", 'tokens' => tokens()])->assertSessionHasNoErrors();
    }
    $this->actingAs($admin)->post('/admin/appearance/palettes', ['name' => 'One too many', 'tokens' => tokens()])->assertSessionHasErrors('name');
    $this->actingAs($admin)->post('/admin/appearance/palettes/'.Palette::where('name', 'Dark')->value('id').'/duplicate')->assertSessionHasErrors('palette');
});

test('TC-F08-06 fonts come from the allowlist; the stylesheet and the page load the chosen families', function () {
    $admin = adminUser();

    foreach ([['heading' => 'comic-sans'], ['body' => 'Arial; }'], ['size' => 'xl'], ['heading' => '']] as $bad) {
        $this->actingAs($admin)->put('/admin/appearance/typography', [...['heading' => 'system-sans', 'body' => 'system-sans', 'size' => 'm'], ...$bad])->assertSessionHasErrors();
    }
    expect(Setting::get('font_heading'))->toBe('system-sans');

    $this->actingAs($admin)->put('/admin/appearance/typography', ['heading' => 'merriweather', 'body' => 'open-sans', 'size' => 'l'])->assertSessionHasNoErrors();
    auth()->logout();

    expect(css())->toContain('--site-font-heading:Merriweather, Georgia, serif')->toContain("--site-font-body:'Open Sans'")->toContain('html{font-size:112.5%}');
    Post::factory()->status('published')->create();
    $this->get('/')->assertInertia(fn ($p) => $p->where('theme.fonts', ['resources/css/fonts/merriweather.css', 'resources/css/fonts/open-sans.css']));
});

test('system fonts load no font files', function () {
    $this->actingAs(adminUser())->put('/admin/appearance/typography', ['heading' => 'system-serif', 'body' => 'monospace', 'size' => 's'])->assertSessionHasNoErrors();
    auth()->logout();

    $this->get('/')->assertInertia(fn ($p) => $p->where('theme.fonts', []));
    expect(css())->toContain('Georgia')->toContain('ui-monospace')->toContain('93.75%');
});

test('the public page links the theme stylesheet with a cache-busting version', function () {
    $html = $this->get('/')->getContent();
    expect($html)->toMatch('#<link rel="stylesheet" href="/theme\.css\?v=\d+">#');
    $this->get('/login')->assertDontSee('/theme.css', false); // not part of the admin/account screens
});

test('the stylesheet is cached for a year when versioned, briefly when not', function () {
    $this->get('/theme.css?v=3')->assertHeader('Cache-Control', 'immutable, max-age=31536000, public')->assertHeader('Content-Type', 'text/css; charset=UTF-8');
    $this->get('/theme.css')->assertHeader('Cache-Control', 'max-age=300, public');
});

test('changing the palette changes the cache-busting version', function () {
    $v1 = $this->get('/')->viewData('page')['props']['theme']['v'];
    $this->actingAs(adminUser())->post('/admin/appearance/palettes/'.Palette::where('name', 'Sunset')->value('id').'/activate');
    auth()->logout();

    expect($this->get('/')->viewData('page')['props']['theme']['v'])->toBeGreaterThan($v1);
});

test('reset restores Light Classic and the default fonts', function () {
    $admin = adminUser();
    $this->actingAs($admin)->post('/admin/appearance/palettes/'.Palette::where('name', 'Dark')->value('id').'/activate');
    $this->actingAs($admin)->put('/admin/appearance/typography', ['heading' => 'lato', 'body' => 'roboto', 'size' => 'l']);

    $this->actingAs($admin)->post('/admin/appearance/reset')->assertSessionHasNoErrors();

    expect(Palette::where('is_active', true)->value('name'))->toBe('Light Classic')->and(Setting::get('font_heading'))->toBe('system-sans')->and(Setting::get('font_size'))->toBe('m');
    expect(AuditLog::where('action', 'appearance.reset')->exists())->toBeTrue();
});

test('TC-F08-07 only admins reach the appearance screens and endpoints', function () {
    $writer = User::factory()->create();
    $id = Palette::first()->id;

    $this->actingAs($writer)->get('/admin/appearance')->assertForbidden();
    $this->actingAs($writer)->post('/admin/appearance/palettes', ['name' => 'X', 'tokens' => tokens()])->assertForbidden();
    $this->actingAs($writer)->post("/admin/appearance/palettes/{$id}/activate")->assertForbidden();
    $this->actingAs($writer)->put('/admin/appearance/typography', ['heading' => 'lato', 'body' => 'lato', 'size' => 'm'])->assertForbidden();
    $this->actingAs($writer)->post('/admin/appearance/reset')->assertForbidden();
    expect(Palette::where('is_active', true)->value('name'))->toBe('Light Classic');
});

test('the appearance page lists palettes and fonts in a constant number of queries', function () {
    DB::enableQueryLog();
    $this->actingAs(adminUser())->get('/admin/appearance')->assertOk()
        ->assertInertia(fn ($p) => $p->component('admin/appearance')->has('palettes', 6)->has('fonts', 8)->where('typography.size', 'm'));
    expect(count(DB::getQueryLog()))->toBeLessThanOrEqual(8);
});

test('TC-F08-05 contrast helper agrees with WCAG reference values', function () {
    expect(ThemeCss::contrast('#000000', '#ffffff'))->toEqualWithDelta(21, 0.01)
        ->and(ThemeCss::contrast('#767676', '#ffffff'))->toBeGreaterThan(4.5)
        ->and(ThemeCss::contrast('#777777', '#ffffff'))->toBeLessThan(4.6);
    foreach (Palette::where('is_preset', true)->get() as $p) {
        expect(ThemeCss::contrast($p->tokens['text'], $p->tokens['surface']))->toBeGreaterThan(4.5, "{$p->name} text/surface");
        expect(ThemeCss::contrast($p->tokens['link'], $p->tokens['surface']))->toBeGreaterThan(4.5, "{$p->name} link/surface");
    }
});
