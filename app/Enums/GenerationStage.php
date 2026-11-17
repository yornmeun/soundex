<?php

namespace App\Enums;

enum GenerationStage: string
{
    case Queued = 'queued';
    case Preparing = 'preparing';
    case Splitting = 'splitting';
    case Generating = 'generating';
    case Processing = 'processing';
    case Merging = 'merging';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Waiting in queue',
            self::Preparing => 'Preparing script',
            self::Splitting => 'Splitting script',
            self::Generating => 'Generating audio',
            self::Processing => 'Processing audio',
            self::Merging => 'Merging audio',
            self::Completed => 'Completed',
        };
    }

    /** @return list<self> stages shown in the progress stepper */
    public static function visible(): array
    {
        return [self::Preparing, self::Splitting, self::Generating, self::Processing, self::Merging, self::Completed];
    }
}
