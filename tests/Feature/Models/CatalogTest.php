<?php

declare(strict_types=1);

use App\Models\Catalog;
use App\Models\Subdomain;

it('resolves collision-safe, distinct slugs for two catalogs with the same name in the same subdomain', function () {
    $subdomain = Subdomain::factory()->create();

    $first = Catalog::factory()->create([
        'subdomain_id' => $subdomain->id,
        'name' => 'Spring Collection',
    ]);

    $second = Catalog::factory()->create([
        'subdomain_id' => $subdomain->id,
        'name' => 'Spring Collection',
    ]);

    $third = Catalog::factory()->create([
        'subdomain_id' => $subdomain->id,
        'name' => 'Spring Collection',
    ]);

    expect($first->slug)->toBe('spring-collection');
    expect($second->slug)->toBe('spring-collection-2');
    expect($third->slug)->toBe('spring-collection-3');

    expect(collect([$first->slug, $second->slug, $third->slug])->unique())->toHaveCount(3);
});

it('does not collide with a slug used in a different subdomain', function () {
    Catalog::factory()->create(['name' => 'Spring Collection']);

    $catalog = Catalog::factory()->create(['name' => 'Spring Collection']);

    expect($catalog->slug)->toBe('spring-collection');
});

it('does not overwrite a slug explicitly provided on create', function () {
    $catalog = Catalog::factory()->create([
        'name' => 'Spring Collection',
        'slug' => 'custom-slug',
    ]);

    expect($catalog->slug)->toBe('custom-slug');
});
