<?php

test('baseline security headers are present on public and error responses', function () {
    foreach (['/', '/login', '/does-not-exist'] as $path) {
        $this->get($path)
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy');
    }
});

test('HSTS is sent over HTTPS', function () {
    $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security');
});
