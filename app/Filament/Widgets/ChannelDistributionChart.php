<?php

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use Modules\Channels\Models\Channel;

class ChannelDistributionChart extends ChartWidget
{
    protected static ?int $sort = 3;

    protected ?string $heading = 'Products per Channel';

    protected ?string $pollingInterval = null;

    protected function getData(): array
    {
        // NOTE: deviation from the blueprint's exact code. ->wherePivot('is_published', ...)
        // inside a withCount() closure produces `where "pivot" = ?` (a literal, unqualified
        // "pivot" column) rather than `channel_product.is_published` on this installed
        // Laravel version — verified empirically (dumped the generated SQL), which made both
        // counts always resolve to 0. Qualifying the pivot column explicitly sidesteps the
        // bug without changing the query's intent.
        $channels = Channel::query()
            ->withCount([
                'products as published_count' => fn ($query) => $query->where('channel_product.is_published', true),
                'products as unpublished_count' => fn ($query) => $query->where('channel_product.is_published', false),
            ])
            ->orderBy('name')
            ->get();

        return [
            'datasets' => [
                [
                    'label' => 'Published',
                    'data' => $channels->pluck('published_count')->all(),
                    'backgroundColor' => '#2547D0',
                ],
                [
                    'label' => 'Unpublished',
                    'data' => $channels->pluck('unpublished_count')->all(),
                    'backgroundColor' => '#F2B134',
                ],
            ],
            'labels' => $channels->pluck('name')->all(),
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
