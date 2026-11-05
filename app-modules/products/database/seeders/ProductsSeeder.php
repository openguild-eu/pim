<?php

declare(strict_types=1);

namespace Modules\Products\Database\Seeders;

use App\Models\Catalog;
use Illuminate\Database\Seeder;
use Modules\Attributes\Enums\AttributeType;
use Modules\Attributes\Models\AttributeSet;
use Modules\Attributes\Models\AttributeValue;
use Modules\Categories\Models\Category;
use Modules\Products\Models\Product;

class ProductsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(Catalog $catalog): void
    {
        $attributeSets = AttributeSet::where('catalog_id', $catalog->id)->get();
        $categories = Category::where('catalog_id', $catalog->id)->get();

        for ($i = 0; $i < 24; $i++) {
            $attributeSet = $attributeSets->random();

            $factory = Product::factory();

            if ($i % 3 !== 0) {
                $factory = $factory->published();
            }

            $product = $factory->create([
                'catalog_id' => $catalog->id,
                'attribute_set_id' => $attributeSet->id,
            ]);

            $product->categories()->attach(
                $categories->random(fake()->numberBetween(1, 3))->pluck('id')
            );

            foreach ($attributeSet->attributes as $attribute) {
                AttributeValue::create([
                    'catalog_id' => $catalog->id,
                    'product_id' => $product->id,
                    'attribute_id' => $attribute->id,
                    ...match ($attribute->type) {
                        AttributeType::Text, AttributeType::Textarea => ['value_text' => fake()->words(3, true)],
                        AttributeType::Number => ['value_integer' => fake()->numberBetween(1, 5000)],
                        AttributeType::Boolean => ['value_boolean' => fake()->boolean()],
                        AttributeType::Date => ['value_date' => fake()->date()],
                        AttributeType::Select => ['value_text' => fake()->randomElement(
                            collect($attribute->options ?? [['value' => 'red'], ['value' => 'blue']])->pluck('value')->all()
                        )],
                        AttributeType::Multiselect => ['value_text' => implode(',', fake()->randomElements(
                            collect($attribute->options ?? [['value' => 'red'], ['value' => 'blue']])->pluck('value')->all(),
                            2
                        ))],
                    },
                ]);
            }
        }
    }
}
