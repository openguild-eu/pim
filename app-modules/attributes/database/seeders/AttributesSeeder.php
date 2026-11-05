<?php

declare(strict_types=1);

namespace Modules\Attributes\Database\Seeders;

use App\Models\Catalog;
use Illuminate\Database\Seeder;
use Modules\Attributes\Enums\AttributeType;
use Modules\Attributes\Models\Attribute;
use Modules\Attributes\Models\AttributeSet;

class AttributesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(Catalog $catalog): void
    {
        $generalSet = AttributeSet::factory()->create([
            'catalog_id' => $catalog->id,
            'name' => 'General',
            'slug' => 'general',
        ]);

        $apparelSet = AttributeSet::factory()->create([
            'catalog_id' => $catalog->id,
            'name' => 'Apparel',
            'slug' => 'apparel',
        ]);

        $material = Attribute::factory()->create([
            'catalog_id' => $catalog->id,
            'code' => 'material',
            'label' => 'Material',
            'type' => AttributeType::Text,
        ]);

        $weight = Attribute::factory()->create([
            'catalog_id' => $catalog->id,
            'code' => 'weight',
            'label' => 'Weight (g)',
            'type' => AttributeType::Number,
        ]);

        $isFeatured = Attribute::factory()->create([
            'catalog_id' => $catalog->id,
            'code' => 'is_featured',
            'label' => 'Featured',
            'type' => AttributeType::Boolean,
        ]);

        $releaseDate = Attribute::factory()->create([
            'catalog_id' => $catalog->id,
            'code' => 'release_date',
            'label' => 'Release Date',
            'type' => AttributeType::Date,
        ]);

        $color = Attribute::factory()->select()->create([
            'catalog_id' => $catalog->id,
            'code' => 'color',
            'label' => 'Color',
        ]);

        $tags = Attribute::factory()->multiselect()->create([
            'catalog_id' => $catalog->id,
            'code' => 'tags',
            'label' => 'Tags',
        ]);

        $generalSet->attributes()->attach([
            $material->id => ['position' => 0, 'is_required' => true],
            $color->id => ['position' => 1, 'is_required' => true],
            $weight->id => ['position' => 2, 'is_required' => false],
            $isFeatured->id => ['position' => 3, 'is_required' => false],
            $releaseDate->id => ['position' => 4, 'is_required' => false],
            $tags->id => ['position' => 5, 'is_required' => false],
        ]);

        $apparelSet->attributes()->attach([
            $material->id => ['position' => 0, 'is_required' => true],
            $color->id => ['position' => 1, 'is_required' => true],
            $tags->id => ['position' => 2, 'is_required' => false],
        ]);
    }
}
