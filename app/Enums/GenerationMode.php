<?php

namespace App\Enums;

enum GenerationMode: string
{
    case Continuous = 'continuous';
    case Segments = 'segments';

    public function label(): string
    {
        return match ($this) {
            self::Continuous => 'Continuous narration',
            self::Segments => 'Subtitle-synchronized segments',
        };
    }
}
