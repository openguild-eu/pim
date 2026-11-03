<?php

declare(strict_types=1);

namespace Modules\Attributes\Models;

use App\Models\Concerns\BelongsToCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttributeValue extends Model
{
    use BelongsToCatalog;

    public $timestamps = false;

    protected $fillable = [
        'catalog_id',
        'product_id',
        'attribute_id',
        'value_text',
        'value_integer',
        'value_decimal',
        'value_boolean',
        'value_date',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value_integer' => 'integer',
            'value_decimal' => 'decimal:2',
            'value_boolean' => 'boolean',
            'value_date' => 'date',
        ];
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(Attribute::class);
    }
}
