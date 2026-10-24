<?php

use App\Models\User;
use App\Support\HtmlSanitizer;

function clean(string $html): string
{
    return app(HtmlSanitizer::class)->clean($html);
}

// NFR-02 / PP-F03-01: every payload must come out without anything executable.
dataset('xss payloads', [
    '<script>alert(1)</script>',
    '<SCRIPT SRC=//evil.example/x.js></SCRIPT>',
    '<img src=x onerror=alert(1)>',
    '<img src="/storage/a.jpg" onload="alert(1)">',
    '<a href="javascript:alert(1)">x</a>',
    '<a href="JaVaScRiPt:alert(1)">x</a>',
    '<a href="&#106;avascript:alert(1)">x</a>',
    '<a href="data:text/html,<script>alert(1)</script>">x</a>',
    '<a href="vbscript:msgbox(1)">x</a>',
    '<img src="data:image/svg+xml;base64,PHN2ZyBvbmxvYWQ9YWxlcnQoMSk+">',
    '<svg onload=alert(1)></svg>',
    '<svg><script>alert(1)</script></svg>',
    '<math><mi xlink:href="javascript:alert(1)">x</mi></math>',
    '<iframe src="https://evil.example"></iframe>',
    '<iframe src="javascript:alert(1)"></iframe>',
    '<iframe srcdoc="<script>alert(1)</script>"></iframe>',
    '<object data="x.swf"></object>',
    '<embed src="x.swf">',
    '<form action="https://evil.example"><input name=a></form>',
    '<input autofocus onfocus=alert(1)>',
    '<button onclick=alert(1)>x</button>',
    '<style>body{background:url(javascript:alert(1))}</style>',
    '<p style="background:url(javascript:alert(1))">x</p>',
    '<p style="width:expression(alert(1))">x</p>',
    '<p style="position:fixed;top:0;left:0;width:100%;height:100%">overlay</p>',
    '<link rel="stylesheet" href="https://evil.example/x.css">',
    '<meta http-equiv="refresh" content="0;url=https://evil.example">',
    '<base href="https://evil.example/">',
    '<body onload=alert(1)>',
    '<div onmouseover="alert(1)">x</div>',
    '<details open ontoggle=alert(1)>x</details>',
    '<video><source onerror=alert(1)></video>',
    '<a href="https://ok.example" onclick="alert(1)">x</a>',
    '<img src="https://tracker.example/pixel.gif">',
    '<div data-embed="youtube" data-id="x&quot; onload=&quot;alert(1)">x</div>',
    '<div data-embed="javascript" data-id="abcdefgh">x</div>',
    '<<script>alert(1)//<</script>',
    '<img src="/a.jpg" srcset="javascript:alert(1)">',
    '"><script>alert(1)</script>',
    "<a href=\"java\nscript:alert(1)\">x</a>",
]);

it('neutralises XSS payloads', function (string $payload) {
    $out = strtolower(clean($payload));

    foreach (['<script', '<iframe', '<svg', '<style', '<object', '<embed', '<form', '<input', '<button', '<link', '<meta', '<base', '<math', '<video', '<details', 'javascript:', 'vbscript:', 'data:', 'onerror', 'onload', 'onclick', 'onfocus', 'onmouse', 'ontoggle', 'expression(', 'srcdoc', 'srcset', 'position:', 'tracker.example'] as $needle) {
        expect($out)->not->toContain($needle);
    }
})->with('xss payloads');

it('is idempotent', function (string $payload) {
    $once = clean($payload);
    expect(clean($once))->toBe($once);
})->with('xss payloads');

test('TC-F03-02 allowlisted tags survive', function () {
    $html = '<h2>Title</h2><p>Text <strong>b</strong> <em>i</em> <u>u</u> <s>s</s> <code>x</code></p>'
        .'<blockquote><p>q</p></blockquote><pre><code>$a = 1;</code></pre><ul><li>a</li></ul><ol><li>b</li></ol><hr>'
        .'<table><thead><tr><th>h</th></tr></thead><tbody><tr><td colspan="2">c</td></tr></tbody></table>'
        .'<figure><img src="/storage/uploads/a.jpg" alt="A"><figcaption>cap</figcaption></figure>'
        .'<p style="text-align:center">c</p>';

    $out = clean($html);

    foreach (['<h2>', '<strong>', '<em>', '<u>', '<s>', '<code>', '<blockquote>', '<pre>', '<ul>', '<ol>', '<hr', '<table>', '<thead>', 'colspan="2"', '<figure>', '<figcaption>', 'src="/storage/uploads/a.jpg"', 'text-align:center'] as $needle) {
        expect($out)->toContain($needle);
    }
});

test('TC-F03-03 external links get rel noopener noreferrer nofollow', function () {
    $out = clean('<a href="https://example.com" target="_blank">x</a>');

    expect($out)->toContain('noopener')->toContain('noreferrer')->toContain('nofollow');
    expect(clean('<a href="https://example.com">x</a>'))->toContain('nofollow');
});

test('only http, https and mailto links survive; relative links are kept', function () {
    expect(clean('<a href="mailto:a@example.com">m</a>'))->toContain('mailto:a@example.com');
    expect(clean('<a href="/files/0b0e0c1e-0000-4000-8000-000000000000">pdf</a>'))->toContain('href="/files/');
    expect(clean('<a href="ftp://example.com/x">f</a>'))->not->toContain('ftp:');
});

test('TC-F03-05 video embeds keep only provider and a safe id', function () {
    expect(clean('<div data-embed="youtube" data-id="dQw4w9WgXcQ"></div>'))
        ->toContain('data-embed="youtube"')->toContain('data-id="dQw4w9WgXcQ"');
    expect(clean('<div data-embed="vimeo" data-id="76979871"></div>'))->toContain('data-embed="vimeo"');
    expect(clean('<div data-embed="youtube" data-id="https://evil.example/x"></div>'))->not->toContain('evil');
    expect(clean('<div data-embed="evil" data-id="dQw4w9WgXcQ"></div>'))->not->toContain('data-embed');
});

test('non-Latin and right-to-left content is preserved', function () {
    $html = '<p>日本語のテキスト</p><p dir="rtl">مرحبا بالعالم</p><p>Привет, мир</p>';
    $out = clean($html);

    expect($out)->toContain('日本語のテキスト')->toContain('مرحبا بالعالم')->toContain('Привет, мир');
});

test('empty input stays empty', function () {
    expect(clean(''))->toBe('')->and(clean('   '))->toBe('');
});

test('TC-F03-04 preview endpoint applies the same sanitizing for writers and admins', function () {
    foreach ([User::factory()->create(), User::factory()->admin()->withTwoFactor()->create()] as $user) {
        $this->actingAs($user)->postJson('/content/preview', ['html' => '<p>ok</p><script>alert(1)</script><img src=x onerror=alert(1)>'])
            ->assertOk()
            ->assertJsonPath('html', fn ($html) => str_contains($html, '<p>ok</p>') && ! str_contains($html, 'script') && ! str_contains($html, 'onerror'));
    }
});

test('preview endpoint needs a login', function () {
    $this->postJson('/content/preview', ['html' => '<p>x</p>'])->assertUnauthorized();
});
