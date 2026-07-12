<?php

namespace App\Enums;

/**
 * The public blog's body font (Phase 10).
 *
 * System font stacks only — the public pages make zero external requests,
 * so webfonts are out by design. Both cases resolve to a Tailwind
 * font-family utility written out literally in the layout (interpolated
 * class names are invisible to Tailwind's compile-time scan).
 */
enum BlogFont: string
{
    case Sans = 'sans';
    case Serif = 'serif';

    public function label(): string
    {
        return match ($this) {
            self::Sans => __('Sans-serif'),
            self::Serif => __('Serif'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Sans => __('Clean and modern — your device\'s standard interface font.'),
            self::Serif => __('Classic and bookish — your device\'s standard serif font.'),
        };
    }
}
