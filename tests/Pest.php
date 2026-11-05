<?php

use App\Models\Catalog;
use App\Models\Subdomain;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Modules\Channels\Models\ApiKey;
use Modules\Channels\Models\Channel;
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

/**
 * Creates a Subdomain, a Catalog under it, an active Channel under that
 * catalog, and an active ApiKey for the channel. actingAsCatalogUser() only
 * sets Filament's in-process tenant and never goes through real host-based
 * routing, so it's unusable for API tests that must exercise
 * IdentifySubdomain against a real Host header — this builds the whole
 * chain and returns the plaintext key, which is only ever available at
 * creation time.
 *
 * @return array{channel: Channel, apiKey: ApiKey, plainTextKey: string, subdomain: Subdomain, catalog: Catalog, apiUrl: string}
 */
function makeChannelWithApiKey(array $channelAttributes = [], array $apiKeyAttributes = []): array
{
    $subdomain = Subdomain::factory()->create();
    $catalog = Catalog::factory()->create(['subdomain_id' => $subdomain->id]);
    $channel = Channel::factory()->create([
        ...$channelAttributes,
        'catalog_id' => $catalog->id,
    ]);

    $plainTextKey = Str::random(64);

    $apiKey = ApiKey::factory()->create([
        ...$apiKeyAttributes,
        'channel_id' => $channel->id,
        'key_hash' => hash('sha256', $plainTextKey),
    ]);

    return [
        'channel' => $channel,
        'apiKey' => $apiKey,
        'plainTextKey' => $plainTextKey,
        'subdomain' => $subdomain,
        'catalog' => $catalog,
        'apiUrl' => 'http://'.$subdomain->subdomain.'.'.config('app.central_domain').'/api/v1',
    ];
}
