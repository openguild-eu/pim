<?php

namespace App\Filament\Widgets;

use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Modules\Attributes\Models\Attribute;
use Modules\Attributes\Models\AttributeSet;
use Modules\Categories\Models\Category;
use Modules\Channels\Models\Channel;
use Modules\Products\Models\Product;
use Spatie\Activitylog\Models\Activity;

class RecentActivityWidget extends TableWidget
{
    protected static ?int $sort = 4;

    protected array|string|int $columnSpan = 'full';

    protected static ?string $heading = 'Recent Activity';

    public function table(Table $table): Table
    {
        $catalogId = Filament::getTenant()?->getKey();

        return $table
            ->query(
                Activity::query()
                    ->whereHasMorph(
                        'subject',
                        [Product::class, Category::class, AttributeSet::class, Attribute::class, Channel::class],
                        fn (Builder $query) => $query->where('catalog_id', $catalogId),
                    )
                    ->with(['causer', 'subject'])
                    ->latest()
                    ->limit(10)
            )
            ->columns([
                TextColumn::make('event')
                    ->badge()
                    ->colors([
                        'success' => 'created',
                        'warning' => 'updated',
                        'danger' => 'deleted',
                    ]),

                TextColumn::make('subject_type')
                    ->label('Subject')
                    ->formatStateUsing(fn (?string $state): string => $state ? class_basename($state) : '-'),

                TextColumn::make('description')
                    ->default('-')
                    ->limit(60),

                TextColumn::make('causer.name')
                    ->label('By')
                    ->default('System'),

                TextColumn::make('created_at')
                    ->since()
                    ->sortable(),
            ])
            ->paginated(false);
    }
}
