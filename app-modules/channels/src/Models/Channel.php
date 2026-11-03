<?php

declare(strict_types=1);

namespace Modules\Channels\Models;

use App\Models\Catalog;
use App\Models\Concerns\BelongsToCatalog;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Channels\Database\Factories\ChannelFactory;
use Modules\Products\Models\Product;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Channel extends Model
{
    use BelongsToCatalog;

    /** @use HasFactory<ChannelFactory> */
    use HasFactory;

    use LogsActivity;

    protected $fillable = [
        'catalog_id',
        'name',
        'slug',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(Catalog::class);
    }

    /**
     * `withPivot()` must match Product::channels() exactly (both sides).
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'channel_product')
            ->withPivot(['is_published', 'published_at'])
            ->withTimestamps();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('channel')
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function getActivityLogTitle(): string
    {
        return $this->name;
    }
}
