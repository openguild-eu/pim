<?php

declare(strict_types=1);

namespace Modules\Categories\Database\Seeders;

use App\Models\Catalog;
use Illuminate\Database\Seeder;
use Modules\Categories\Models\Category;

class CategoriesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(Catalog $catalog): void
    {
        $roots = Category::factory()
            ->count(3)
            ->create(['catalog_id' => $catalog->id, 'parent_id' => null]);

        foreach ($roots as $root) {
            Category::factory()
                ->count(fake()->numberBetween(2, 3))
                ->create(['catalog_id' => $catalog->id, 'parent_id' => $root->id]);
        }
    }
}
