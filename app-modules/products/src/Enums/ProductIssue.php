<?php

declare(strict_types=1);

namespace Modules\Products\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;

enum ProductIssue: string implements HasColor, HasIcon, HasLabel
{
    case MissingImages = 'missing_images';
    case MissingCategories = 'missing_categories';
    case MissingRequiredAttributes = 'missing_required_attributes';
    case PublishedButIncomplete = 'published_but_incomplete';

    public function getLabel(): string
    {
        return match ($this) {
            self::MissingImages => 'Missing images',
            self::MissingCategories => 'Missing categories',
            self::MissingRequiredAttributes => 'Missing required attributes',
            self::PublishedButIncomplete => 'Published but incomplete',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::PublishedButIncomplete => 'danger',
            default => 'warning',
        };
    }

    public function getIcon(): string|\BackedEnum
    {
        return match ($this) {
            self::MissingImages => Heroicon::OutlinedPhoto,
            self::MissingCategories => Heroicon::OutlinedTag,
            self::MissingRequiredAttributes => Heroicon::OutlinedExclamationTriangle,
            self::PublishedButIncomplete => Heroicon::OutlinedExclamationCircle,
        };
    }
}
