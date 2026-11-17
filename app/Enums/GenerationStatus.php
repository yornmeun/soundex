<?php

namespace App\Enums;

enum GenerationStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Pending, self::Processing], true);
    }

    public function isFinished(): bool
    {
        return ! $this->isActive();
    }

    /** Tailwind classes for the status badge. */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::Pending => 'bg-slate-100 text-slate-700 ring-slate-200',
            self::Processing => 'bg-sky-50 text-sky-700 ring-sky-200',
            self::Completed => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
            self::Failed => 'bg-rose-50 text-rose-700 ring-rose-200',
            self::Cancelled => 'bg-amber-50 text-amber-700 ring-amber-200',
        };
    }
}
