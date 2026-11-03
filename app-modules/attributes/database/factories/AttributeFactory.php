<?php

declare(strict_types=1);

namespace Modules\Attributes\Database\Factories;

use App\Models\Catalog;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Attributes\Enums\AttributeType;
use Modules\Attributes\Models\Attribute;

/**
 * @extends Factory<Attribute>
 */
class AttributeFactory extends Factory
{
    protected $model = Attribute::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'catalog_id' => Catalog::factory(),
            'code' => fake()->unique()->word(),
            'label' => fake()->words(2, true),
            'type' => AttributeType::Text,
            'options' => null,
            'is_filterable' => false,
        ];
    }

    public function select(): static
    {
        return $this->state(fn (): array => [
            'type' => AttributeType::Select,
            'options' => [
                ['value' => 'red', 'label' => 'Red'],
                ['value' => 'blue', 'label' => 'Blue'],
            ],
        ]);
    }

    public function multiselect(): static
    {
        return $this->state(fn (): array => [
            'type' => AttributeType::Multiselect,
            'options' => [
                ['value' => 'red', 'label' => 'Red'],
                ['value' => 'blue', 'label' => 'Blue'],
            ],
        ]);
    }
}
