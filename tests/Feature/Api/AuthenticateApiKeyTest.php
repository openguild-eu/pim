<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;

it('returns 401 when no api key is provided', function () {
    ['apiUrl' => $apiUrl] = makeChannelWithApiKey();

    $this->getJson($apiUrl.'/products')
        ->assertUnauthorized();
});

it('returns 401 for a bogus api key', function () {
    ['apiUrl' => $apiUrl] = makeChannelWithApiKey();

    $this->getJson($apiUrl.'/products', ['X-Api-Key' => 'bogus-key'])
        ->assertUnauthorized();
});

it('returns 401 for an inactive api key', function () {
    ['apiUrl' => $apiUrl, 'plainTextKey' => $plainTextKey] = makeChannelWithApiKey(apiKeyAttributes: ['is_active' => false]);

    $this->getJson($apiUrl.'/products', ['X-Api-Key' => $plainTextKey])
        ->assertUnauthorized();
});

it('returns 401 for an expired api key', function () {
    ['apiUrl' => $apiUrl, 'plainTextKey' => $plainTextKey] = makeChannelWithApiKey(apiKeyAttributes: ['expires_at' => now()->subDay()]);

    $this->getJson($apiUrl.'/products', ['X-Api-Key' => $plainTextKey])
        ->assertUnauthorized();
});

it('returns 401 when the request ip is not in the allowed list', function () {
    ['apiUrl' => $apiUrl, 'plainTextKey' => $plainTextKey] = makeChannelWithApiKey(apiKeyAttributes: ['allowed_ips' => ['10.0.0.1']]);

    $this->getJson($apiUrl.'/products', ['X-Api-Key' => $plainTextKey])
        ->assertUnauthorized();
});

it('returns 401 on the central domain even with a valid key', function () {
    ['plainTextKey' => $plainTextKey] = makeChannelWithApiKey();

    $this->getJson('http://'.config('app.central_domain').'/api/v1/products', ['X-Api-Key' => $plainTextKey])
        ->assertUnauthorized();
});

it('returns 401 when the key is used against a different channel\'s subdomain', function () {
    ['plainTextKey' => $plainTextKey] = makeChannelWithApiKey();
    ['apiUrl' => $otherApiUrl] = makeChannelWithApiKey();

    $this->getJson($otherApiUrl.'/products', ['X-Api-Key' => $plainTextKey])
        ->assertUnauthorized();
});

it('returns 401 for a key belonging to an inactive channel', function () {
    ['apiUrl' => $apiUrl, 'plainTextKey' => $plainTextKey] = makeChannelWithApiKey(channelAttributes: ['is_active' => false]);

    $this->getJson($apiUrl.'/products', ['X-Api-Key' => $plainTextKey])
        ->assertUnauthorized();
});

it('returns 200 for a valid key sent via the X-Api-Key header', function () {
    ['apiUrl' => $apiUrl, 'plainTextKey' => $plainTextKey] = makeChannelWithApiKey();

    $this->getJson($apiUrl.'/products', ['X-Api-Key' => $plainTextKey])
        ->assertOk();
});

it('returns 200 for a valid key sent as an Authorization bearer token', function () {
    ['apiUrl' => $apiUrl, 'plainTextKey' => $plainTextKey] = makeChannelWithApiKey();

    $this->getJson($apiUrl.'/products', ['Authorization' => 'Bearer '.$plainTextKey])
        ->assertOk();
});

it('touches last_used_at on a successful request', function () {
    Carbon::setTestNow('2026-01-01 12:00:00');
    ['apiUrl' => $apiUrl, 'plainTextKey' => $plainTextKey, 'apiKey' => $apiKey] = makeChannelWithApiKey();

    $this->getJson($apiUrl.'/products', ['X-Api-Key' => $plainTextKey])
        ->assertOk();

    expect($apiKey->refresh()->last_used_at->equalTo('2026-01-01 12:00:00'))->toBeTrue();

    Carbon::setTestNow();
});
