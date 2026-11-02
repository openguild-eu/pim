<?php

namespace Database\Seeders;

use App\Models\Catalog;
use App\Models\Subdomain;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Modules\Users\Models\User;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $subdomain = Subdomain::create([
            'subdomain' => 'acme',
            'name' => 'Acme',
        ]);

        $catalog = Catalog::create([
            'subdomain_id' => $subdomain->id,
            'name' => 'Default Catalog',
        ]);

        $user = User::create([
            'subdomain_id' => $subdomain->id,
            'name' => 'Admin',
            'email' => 'admin@acme.test',
            'password' => 'password',
        ]);

        $user->catalogs()->attach($catalog);
    }
}
