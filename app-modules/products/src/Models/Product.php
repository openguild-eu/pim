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
use Illuminate\Support\Collection;
use Modules\Attributes\Models\Attribute;
use Modules\Attributes\Models\AttributeSet;
use Modules\Attributes\Models\AttributeValue;
use Modules\Categories\Models\Category;
use Modules\Channels\Models\Channel;
use Modules\Products\Database\Factories\ProductFactory;
use Modules\Products\Enums\ProductIssue;
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

    /**
     * The attribute set's required attributes. Reads from already-loaded
     * relations (`attributeSet.attributes`) rather than issuing a fresh
     * query, so callers must eager-load that relation to avoid N+1 — see
     * ProductResource::table()'s `modifyQueryUsing()`.
     *
     * @return Collection<int, Attribute>
     */
    private function requiredAttributes(): Collection
    {
        if (! $this->attributeSet) {
            return collect();
        }

        // Accessed via getRelationValue() rather than the `attributes`
        // property: Product and AttributeSet are both Eloquent models, so
        // from inside this (also-a-Model) class, `$this->attributeSet
        // ->attributes` resolves to AttributeSet's own protected raw
        // attribute array (a PHP protected-property-visibility quirk
        // shared across sibling Model subclasses) instead of the
        // `attributes()` relation — returning an array, not a Collection.
        return $this->attributeSet->getRelationValue('attributes')
            ->filter(fn (Attribute $attribute): bool => (bool) $attribute->pivot->is_required);
    }

    /**
     * Required attributes with no filled value yet, in the attribute set's
     * own position order. Reads from the already-loaded `attributeValues`
     * relation (with `attribute` eager-loaded on each) — see
     * ProductResource::table()'s `modifyQueryUsing()`.
     *
     * @return Collection<int, Attribute>
     */
    public function missingRequiredAttributes(): Collection
    {
        $required = $this->requiredAttributes();

        if ($required->isEmpty()) {
            return $required;
        }

        $valuesByAttributeId = $this->attributeValues->keyBy('attribute_id');

        return $required
            ->reject(fn (Attribute $attribute): bool => $valuesByAttributeId->get($attribute->id)?->isFilled() ?? false)
            ->values();
    }

    /**
     * Percentage of the attribute set's required attributes that have a
     * filled value. 100 when there is no attribute set, or the attribute
     * set has no required attributes — there is nothing to require.
     */
    public function completenessPercentage(): int
    {
        $required = $this->requiredAttributes();

        if ($required->isEmpty()) {
            return 100;
        }

        $missingCount = $this->missingRequiredAttributes()->count();

        return (int) round((($required->count() - $missingCount) / $required->count()) * 100);
    }

    /**
     * Data-quality issues affecting this product, for the "Needs Attention"
     * page. Reads from already-loaded relations — callers must eager-load
     * `media`, `categories`, `attributeSet.attributes`,
     * `attributeValues.attribute`, and `channels` to avoid N+1 (see
     * App\Filament\Pages\NeedsAttention).
     *
     * @return Collection<int, ProductIssue>
     */
    public function issues(): Collection
    {
        $issues = collect();

        if ($this->getMedia('images')->isEmpty()) {
            $issues->push(ProductIssue::MissingImages);
        }

        if ($this->categories->isEmpty()) {
            $issues->push(ProductIssue::MissingCategories);
        }

        if ($this->missingRequiredAttributes()->isNotEmpty()) {
            $issues->push(ProductIssue::MissingRequiredAttributes);
        }

        $isPublishedOnAnyChannel = $this->channels
            ->contains(fn (Channel $channel): bool => (bool) $channel->pivot->is_published);

        if ($isPublishedOnAnyChannel && $issues->isNotEmpty()) {
            $issues->push(ProductIssue::PublishedButIncomplete);
        }

        return $issues->values();
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
