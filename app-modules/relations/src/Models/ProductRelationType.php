<?php

declare(strict_types=1);

namespace Modules\Relations\Models;

use Filament\Support\Contracts\HasLabel;

enum ProductRelationType: string implements HasLabel
{
    case Related = 'related';
    case CrossSell = 'cross_sell';
    case UpSell = 'up_sell';

    public function getLabel(): string
    {
        return match ($this) {
            self::Related => 'Related',
            self::CrossSell => 'Cross-sell',
            self::UpSell => 'Up-sell',
        };
    }
}
