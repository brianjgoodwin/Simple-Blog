<?php

namespace App\Enums;

/**
 * The bundled public-blog color themes (Phase 10).
 *
 * Themes are CSS-only: each case maps to a [data-theme="..."] block of CSS
 * custom properties in resources/css/app.css. Rendered post HTML is
 * byte-identical across themes. Every theme ships AA-verified — the pairs
 * are checked once, when the theme is added, so authors can never produce
 * a failing combination. Adding a theme = a case here, a CSS block, one
 * contrast verification, and a radio in the appearance form.
 */
enum Theme: string
{
    case Default = 'default';
    case Sage = 'sage';
    case Dusk = 'dusk';
    case Dawn = 'dawn';
    case Honey = 'honey';
    case Ember = 'ember';
    case Iris = 'iris';

    public function label(): string
    {
        return match ($this) {
            self::Default => __('Default'),
            self::Sage => __('Sage'),
            self::Dusk => __('Dusk'),
            self::Dawn => __('Dawn'),
            self::Honey => __('Honey'),
            self::Ember => __('Ember'),
            self::Iris => __('Iris'),
        };
    }

    /**
     * Background + accent hex for the settings form's preview dots.
     *
     * These duplicate values from the [data-theme] blocks in app.css (the
     * source of truth for what a theme means) because Tailwind can't build
     * classes from runtime values and the dots are per-case. If a theme's
     * CSS changes, change it here too.
     *
     * @return array{bg: string, accent: string}
     */
    public function swatch(): array
    {
        return match ($this) {
            self::Default => ['bg' => '#ffffff', 'accent' => '#111827'],
            self::Sage => ['bg' => '#f3f6f3', 'accent' => '#166534'],
            self::Dusk => ['bg' => '#f3f5f9', 'accent' => '#1e40af'],
            self::Dawn => ['bg' => '#faf5eb', 'accent' => '#9a3412'],
            self::Honey => ['bg' => '#faf8ec', 'accent' => '#854d0e'],
            self::Ember => ['bg' => '#fcf3ec', 'accent' => '#b23c0e'],
            self::Iris => ['bg' => '#f6f3fb', 'accent' => '#6b21a8'],
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Default => __('Black on white — the standard look.'),
            self::Sage => __('A soft green tint with deep green links.'),
            self::Dusk => __('A cool blue-gray tint with deep blue links.'),
            self::Dawn => __('A warm cream tint with rust links.'),
            self::Honey => __('A soft yellow tint with deep gold links.'),
            self::Ember => __('A warm peach tint with burnt-orange links.'),
            self::Iris => __('A pale lavender tint with deep violet links.'),
        };
    }
}
