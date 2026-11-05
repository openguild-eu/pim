<?php

declare(strict_types=1);

use App\Models\Subdomain;
use Modules\Users\Models\User;
use Spatie\Permission\Exceptions\RoleAlreadyExists;
use Spatie\Permission\PermissionRegistrar;

it('does not grant a subdomain\'s admin role when the permissions team is another subdomain', function () {
    $subdomainA = Subdomain::factory()->create();
    $subdomainB = Subdomain::factory()->create();
    createAdminRole($subdomainA);
    createAdminRole($subdomainB);
    $user = User::factory()->create(['subdomain_id' => $subdomainA->id]);

    $registrar = app(PermissionRegistrar::class);

    $registrar->setPermissionsTeamId($subdomainA->id);
    $user->assignRole('admin');

    $registrar->setPermissionsTeamId($subdomainB->id);
    expect(User::query()->findOrFail($user->id)->hasRole('admin'))->toBeFalse();

    $registrar->setPermissionsTeamId($subdomainA->id);
    expect(User::query()->findOrFail($user->id)->hasRole('admin'))->toBeTrue();
});

it('keeps a separate admin role row per subdomain', function () {
    $subdomainA = Subdomain::factory()->create();
    $subdomainB = Subdomain::factory()->create();

    $roleA = createAdminRole($subdomainA);
    $roleB = createAdminRole($subdomainB);

    expect($roleA->id)->not->toBe($roleB->id)
        ->and($roleA->fresh()->subdomain_id)->toBe($subdomainA->id)
        ->and($roleB->fresh()->subdomain_id)->toBe($subdomainB->id);
});

it('rejects a second admin role row in the same subdomain', function () {
    $subdomain = Subdomain::factory()->create();
    createAdminRole($subdomain);

    createAdminRole($subdomain);
})->throws(RoleAlreadyExists::class);
