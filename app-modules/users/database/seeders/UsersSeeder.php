<?php

declare(strict_types=1);

namespace Modules\Users\Database\Seeders;

use App\Models\Catalog;
use Illuminate\Database\Seeder;
use Modules\Users\Models\User;

class UsersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(Catalog $catalog): void
    {
        $users = User::factory()
            ->count(4)
            ->create(['subdomain_id' => $catalog->subdomain_id]);

        $catalog->users()->attach($users);
    }
}
