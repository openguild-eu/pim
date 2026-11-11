<?php

declare(strict_types=1);

namespace Modules\Products\Models;

use App\Models\Catalog;
use App\Models\Concerns\BelongsToCatalog;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Attributes\Models\AttributeSet;
use Modules\Attributes\Models\AttributeValue;
use Modules\Categories\Models\Category;
use Modules\Channels\Models\Channel;
use Modules\Products\Database\Factories\ProductFactory;
use Modules\Products\Enums\ProductStatus;
use Modules\Relations\Models\ProductRelation;
use Modules\Relations\Models\ProductRelationType;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class Product extends Model implements HasMedia
{
    use BelongsToCatalog;

    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    use InteractsWithMedia;
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'catalog_id',
        'attribute_set_id',
        'sku',
        'name',
        'slug',
        'status',
        'description',
        'price',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProductStatus::class,
        ];
    }

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(Catalog::class);
    }

    public function attributeSet(): BelongsTo
    {
        return $this->belongsTo(AttributeSet::class);
    }

    public function attributeSets(): BelongsToMany
    {
        return $this->belongsToMany(AttributeSet::class, 'attribute_set_product');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'category_product');
    }

    public function attributeValues(): HasMany
    {
        return $this->hasMany(AttributeValue::class);
    }

    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(Channel::class, 'channel_product')
            ->withPivot(['is_published', 'published_at'])
            ->withTimestamps();
    }

    public function relatedProducts(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_relations', 'product_id', 'related_product_id')
            ->using(ProductRelation::class)
            ->withPivot(['type', 'position'])
            ->withPivotValue('type', ProductRelationType::Related->value)
            ->orderByPivot('position');
    }

    public function crossSellProducts(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_relations', 'product_id', 'related_product_id')
            ->using(ProductRelation::class)
            ->withPivot(['type', 'position'])
            ->withPivotValue('type', ProductRelationType::CrossSell->value)
            ->orderByPivot('position');
    }

    public function upSellProducts(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_relations', 'product_id', 'related_product_id')
            ->using(ProductRelation::class)
            ->withPivot(['type', 'position'])
            ->withPivotValue('type', ProductRelationType::UpSell->value)
            ->orderByPivot('position');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('product')
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function getActivityLogTitle(): string
    {
        return $this->name;
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('images');
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->performOnCollections('images')
            ->fit(Fit::Crop, 150, 150)
            ->nonQueued();
    }
}
