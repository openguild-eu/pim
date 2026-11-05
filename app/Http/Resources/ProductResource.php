<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status->value,
            'description' => $this->description,
            'price' => $this->price === null ? null : round($this->price / 100, 2),
            'attribute_set' => $this->whenLoaded('attributeSet', fn () => $this->attributeSet?->name),
            'categories' => $this->whenLoaded('categories', fn () => $this->categories->pluck('name')),
            'images' => $this->whenLoaded('media', fn () => $this->media
                ->where('collection_name', 'images')
                ->map(fn ($media) => [
                    'url' => $media->getUrl(),
                    'thumb_url' => $media->getUrl('thumb'),
                ])
                ->values()),
            'channel' => $this->whenPivotLoaded('channel_product', fn () => [
                'is_published' => (bool) $this->pivot->is_published,
                'published_at' => $this->pivot->published_at,
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
