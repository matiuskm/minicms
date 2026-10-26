<?php

return [
    'groups' => [
        // What an anonymous visitor's page carries (no admin route table in the page source). It must cover every route() call
        // made by the public, sign-in and error pages; tests/Feature/Auth/AnonymousRoutesTest.php checks that.
        'public' => [
            'home', 'blog', 'post', 'post.prefixed', 'page', 'category', 'tag', 'author', 'search',
            'login', 'logout', 'dashboard', 'password.request', 'password.email', 'password.reset', 'password.store', 'invite.*', 'two-factor.challenge',
        ],
    ],
];
