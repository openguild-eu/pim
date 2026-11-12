<?php

namespace Database\Seeders;

use App\Models\Catalog;
use App\Models\Subdomain;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Modules\Attributes\Database\Seeders\AttributesSeeder;
use Modules\Categories\Database\Seeders\CategoriesSeeder;
use Modules\Channels\Database\Seeders\ChannelsSeeder;
use Modules\Products\Database\Seeders\ProductsSeeder;
use Modules\Relations\Database\Seeders\RelationsSeeder;
use Modules\Users\Database\Seeders\UsersSeeder;
use Modules\Users\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * The admin bootstrap below runs inside Model::withoutEvents() (what the
     * `WithoutModelEvents` trait would otherwise apply to this entire
     * `run()`), scoped narrowly to just this block. `Model::withoutEvents()`
     * swaps a single global event dispatcher for its duration, so wrapping
     * the whole method — as the trait does, since Seeder::__invoke() checks
     * for the trait once, up front — would also silence model events for
     * every `callWith()` seeder below, including `Category::booted()` and
     * `ProductRelation::booted()`'s auto-`position` assignment, which both
     * module seeders rely on.
     */
    public function run(): void
    {
        /** @var array{0: Subdomain, 1: Catalog, 2: User} $bootstrap */
        $bootstrap = Model::withoutEvents(function (): array {
            $subdomain = Subdomain::create([
                'subdomain' => 'acme',
                'name' => 'Acme',
            ]);

            $catalog = Catalog::create([
                'subdomain_id' => $subdomain->id,
                'name' => 'Default Catalog',
                'slug' => Catalog::resolveUniqueSlug('Default Catalog', $subdomain->id),
            ]);

            $user = User::create([
                'subdomain_id' => $subdomain->id,
                'name' => 'Admin',
                'email' => 'admin@acme.test',
                'password' => 'password',
            ]);

            $user->catalogs()->attach($catalog);

            $role = Role::create(['name' => 'admin', 'subdomain_id' => $subdomain->id]);

            app(PermissionRegistrar::class)->setPermissionsTeamId($subdomain->id);
            $role->givePermissionTo(Permission::findOrCreate('manage sso settings'));
            $user->assignRole('admin');

            return [$subdomain, $catalog, $user];
        });

        [, $catalog] = $bootstrap;

        $this->callWith(UsersSeeder::class, ['catalog' => $catalog]);
        $this->callWith(CategoriesSeeder::class, ['catalog' => $catalog]);
        $this->callWith(AttributesSeeder::class, ['catalog' => $catalog]);
        $this->callWith(ProductsSeeder::class, ['catalog' => $catalog]);
        $this->callWith(ChannelsSeeder::class, ['catalog' => $catalog]);
        $this->callWith(RelationsSeeder::class, ['catalog' => $catalog]);
    }
}
