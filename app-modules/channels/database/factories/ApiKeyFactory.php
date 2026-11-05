<?php

declare(strict_types=1);

namespace Modules\Channels\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Channels\Models\ApiKey;
use Modules\Channels\Models\Channel;

/**
 * @extends Factory<ApiKey>
 */
class ApiKeyFactory extends Factory
{
    protected $model = ApiKey::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel_id' => Channel::factory(),
            'name' => fake()->unique()->words(2, true),
            'key_hash' => hash('sha256', Str::random(64)),
            'is_active' => true,
            'last_used_at' => null,
            'expires_at' => null,
            'allowed_ips' => null,
        ];
    }
}
