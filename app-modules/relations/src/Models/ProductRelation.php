<?php

declare(strict_types=1);

namespace Modules\Relations\Models;

use App\Models\Catalog;
use App\Models\Concerns\BelongsToCatalog;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Modules\Products\Models\Product;
use Modules\Relations\Database\Factories\ProductRelationFactory;

class ProductRelation extends Pivot
{
    use BelongsToCatalog;

    /** @use HasFactory<ProductRelationFactory> */
    use HasFactory;

    /**
     * `Pivot`'s own `getTable()` (via the `AsPivot` trait) defaults to the
     * singularized class name ("product_relation"), not the real migrated
     * table — it must be set explicitly whenever it doesn't match.
     */
    protected $table = 'product_relations';

    /**
     * Pivot defaults `$incrementing = false` (composite-key pivots). This
     * table has its own auto-incrementing `id`, so it must be turned back on
     * — otherwise Eloquent will not know how to persist the primary key.
     */
    public $incrementing = true;

    protected function casts(): array
    {
        return [
            'type' => ProductRelationType::class,
        ];
    }

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(Catalog::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function relatedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'related_product_id');
    }

    protected static function booted(): void
    {
        static::creating(function (ProductRelation $productRelation): void {
            // Pivot rows are never created through a tenant-aware Filament
            // CreateRecord page (they're inserted by AttachAction calling
            // BelongsToMany::attach(), which only reaches this event because
            // the relationship is registered with ->using(self::class)).
            // There is no other point where catalog_id would get filled in.
            $productRelation->catalog_id ??= Filament::getTenant()?->getKey();

            // Same max(position)+1 pattern Category uses for its sibling
            // `position`, scoped to [product_id, type] instead of parent_id.
            $productRelation->position ??= (static::query()
                ->where('product_id', $productRelation->product_id)
                ->where('type', $productRelation->type)
                ->max('position') ?? -1) + 1;
        });
    }
}
