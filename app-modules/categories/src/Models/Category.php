<?php

declare(strict_types=1);

namespace Modules\Categories\Models;

use App\Models\Catalog;
use App\Models\Concerns\BelongsToCatalog;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Categories\Database\Factories\CategoryFactory;
use Modules\Products\Models\Product;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Category extends Model
{
    use BelongsToCatalog;

    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'catalog_id',
        'parent_id',
        'name',
        'slug',
        'position',
    ];

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(Catalog::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id')->orderBy('position');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'category_product');
    }

    /**
     * IDs of every descendant (not including $this), via BFS over `children`.
     * Excludes already-soft-deleted descendants (default SoftDeletingScope
     * applies to the query), which is intentional: a trashed branch is not a
     * live cycle risk.
     *
     * @return list<int>
     */
    public function descendantIds(): array
    {
        $ids = [];
        $frontier = [$this->getKey()];

        while ($frontier !== []) {
            $frontier = static::query()->whereIn('parent_id', $frontier)->pluck('id')->all();
            $ids = [...$ids, ...$frontier];
        }

        return $ids;
    }

    protected static function booted(): void
    {
        static::creating(function (Category $category): void {
            $category->position ??= (static::query()->where('parent_id', $category->parent_id)->max('position') ?? -1) + 1;
        });

        // Canonical enforcement of "cannot delete a category that still has
        // subcategories" — returning false cancels the delete. This is the
        // ONLY place this rule lives; the Delete actions' before() hooks
        // just re-check it early for a clean notification instead of a
        // silent no-op.
        static::deleting(function (Category $category): bool {
            return $category->children()->doesntExist();
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('category')
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function getActivityLogTitle(): string
    {
        return $this->name;
    }
}
