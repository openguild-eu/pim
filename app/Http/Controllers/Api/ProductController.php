<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Channels\Models\Channel;
use Modules\Products\Enums\ProductStatus;

class ProductController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $products = $this->channel($request)->products()
            ->wherePivot('is_published', true)
            ->where('products.status', ProductStatus::Published)
            ->with(['categories', 'attributeSet', 'media'])
            ->paginate(50);

        return ProductResource::collection($products);
    }

    public function show(Request $request, int|string $product): ProductResource
    {
        $record = $this->channel($request)->products()
            ->wherePivot('is_published', true)
            ->where('products.status', ProductStatus::Published)
            ->with(['categories', 'attributeSet', 'media'])
            ->where('products.id', $product)
            ->firstOrFail();

        return new ProductResource($record);
    }

    private function channel(Request $request): Channel
    {
        /** @var Channel $channel */
        $channel = $request->attributes->get('channel');

        return $channel;
    }
}
