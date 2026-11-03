<?php

declare(strict_types=1);

namespace Modules\Attributes\Models;

use App\Models\Catalog;
use App\Models\Concerns\BelongsToCatalog;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Attributes\Database\Factories\AttributeFactory;
use Modules\Attributes\Enums\AttributeType;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Attribute extends Model
{
    use BelongsToCatalog;

    /** @use HasFactory<AttributeFactory> */
    use HasFactory;

    use LogsActivity;

    protected $fillable = [
        'catalog_id',
        'code',
        'label',
        'type',
        'options',
        'is_filterable',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AttributeType::class,
            'options' => 'array',
            'is_filterable' => 'boolean',
        ];
    }

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(Catalog::class);
    }

    /**
     * @return BelongsToMany<AttributeSet, $this>
     */
    public function attributeSets(): BelongsToMany
    {
        return $this->belongsToMany(AttributeSet::class, 'attribute_set_attributes')
            ->withPivot(['position', 'is_required']);
    }

    /**
     * Forward reference to AttributeValue, added purely so the
     * AttributeResource form can check `$record->values()->exists()`
     * before allowing `type` to change (see the Resources section) —
     * AttributeValue itself belongs to the Products blueprint's domain.
     *
     * @return HasMany<AttributeValue, $this>
     */
    public function values(): HasMany
    {
        return $this->hasMany(AttributeValue::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('attribute')
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function getActivityLogTitle(): string
    {
        return $this->label;
    }
}
