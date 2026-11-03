<?php

declare(strict_types=1);

namespace Modules\Attributes\Filament\Resources\AttributeSets\RelationManagers;

use Filament\Actions\Action;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Attributes\Models\Attribute;

class AttributesRelationManager extends RelationManager
{
    protected static string $relationship = 'attributes';

    protected static ?string $title = 'Attributes';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('label')
            ->reorderable('position')
            ->defaultSort('position')
            ->columns([
                TextColumn::make('code')
                    ->searchable(),

                TextColumn::make('label')
                    ->searchable(),

                TextColumn::make('type')
                    ->badge(),

                IconColumn::make('is_required')
                    ->boolean(),
            ])
            ->headerActions([
                AttachAction::make()
                    ->preloadRecordSelect()
                    ->recordSelectSearchColumns(['code', 'label'])
                    ->schema(fn (AttachAction $action): array => [
                        $action->getRecordSelect(),
                        Toggle::make('is_required')
                            ->label('Required')
                            ->default(false),
                    ])
                    ->using(function (RelationManager $livewire, array $data): void {
                        $ownerRecord = $livewire->getOwnerRecord();

                        $position = ($ownerRecord->attributes()->max('attribute_set_attributes.position') ?? -1) + 1;

                        $ownerRecord->attributes()->attach($data['recordId'], [
                            'position' => $position,
                            'is_required' => $data['is_required'] ?? false,
                        ]);
                    }),
            ])
            ->recordActions([
                Action::make('editRequirement')
                    ->label('Edit requirement')
                    ->icon(Heroicon::OutlinedPencil)
                    ->schema([
                        Toggle::make('is_required')->label('Required'),
                    ])
                    ->fillForm(fn (Attribute $record): array => [
                        'is_required' => $record->pivot->is_required,
                    ])
                    ->action(function (Attribute $record, array $data): void {
                        $record->pivot->update(['is_required' => $data['is_required']]);
                    }),
                DetachAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make(),
                ]),
            ]);
    }
}
