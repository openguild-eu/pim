<?php

declare(strict_types=1);

use App\Models\Subdomain;
use Spatie\Permission\PermissionRegistrar;

it('sets the permissions team id to the subdomain resolved from the host', function () {
    $subdomain = Subdomain::factory()->create();

    $this->get('http://'.$subdomain->subdomain.'.'.config('app.central_domain').'/login');

    expect(app(PermissionRegistrar::class)->getPermissionsTeamId())->toBe($subdomain->id);
});

it('leaves the permissions team id unset on the central domain', function () {
    $this->get('http://'.config('app.central_domain').'/');

    expect(app(PermissionRegistrar::class)->getPermissionsTeamId())->toBeNull();
});
