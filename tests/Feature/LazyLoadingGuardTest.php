<?php

use App\Models\User;

// NFR-04: lazy loading must throw outside production so N+1 fails in tests.
// Relation-level N+1 tests arrive with Post/Category models (P-03/P-04).
test('lazy loading is prevented', function () {
    expect(User::preventsLazyLoading())->toBeTrue();
});
