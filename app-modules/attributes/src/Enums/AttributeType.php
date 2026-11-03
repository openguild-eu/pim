<?php

declare(strict_types=1);

namespace Modules\Attributes\Enums;

use Filament\Support\Contracts\HasLabel;

enum AttributeType: string implements HasLabel
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Number = 'number';
    case Boolean = 'boolean';
    case Date = 'date';
    case Select = 'select';
    case Multiselect = 'multiselect';

    public function getLabel(): string
    {
        return match ($this) {
            self::Text => 'Text',
            self::Textarea => 'Textarea',
            self::Number => 'Number',
            self::Boolean => 'Boolean',
            self::Date => 'Date',
            self::Select => 'Select',
            self::Multiselect => 'Multiselect',
        };
    }
}
