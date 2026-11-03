<?php

use App\Models\Catalog;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Modules\Users\Models\User;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class)
    ->in('Feature', '../app-modules');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Creates a Catalog (with its own Subdomain), a User who is a member of
 * it, authenticates as that user, and boots the Filament tenant context
 * for the "admin" panel so CatalogScope-backed queries and
 * BelongsToTenant's model-creation listener behave as they do in a real
 * request. Every module's resource/page tests build on this.
 */
function actingAsCatalogUser(?Catalog $catalog = null): Catalog
{
    $catalog ??= Catalog::factory()->create();

    $user = User::factory()->create([
        'subdomain_id' => $catalog->subdomain_id,
    ]);

    $catalog->users()->attach($user);

    test()->actingAs($user);

    Filament\Facades\Filament::setCurrentPanel('admin');
    Filament\Facades\Filament::setTenant($catalog);
    Filament\Facades\Filament::bootCurrentPanel();

    return $catalog;
}
