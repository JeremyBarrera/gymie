<?php

namespace App\Support;

/**
 * Derives a cohesive CSS palette from a single theme color so member-facing
 * pages fully follow the configured theme instead of hard-coding neutrals.
 */
final class ThemeColor
{
    private string $hex;

    public function __construct(string $hex)
    {
        $this->hex = self::normalize($hex);
    }

    public static function from(string $hex): self
    {
        return new self($hex);
    }

    public function hex(): string
    {
        return $this->hex;
    }

    /**
     * Mix toward white. 0 keeps the color, 1 is pure white.
     */
    public function tint(float $amount): string
    {
        return self::mix($this->hex, '#ffffff', $amount);
    }

    /**
     * Mix toward black. 0 keeps the color, 1 is pure black.
     */
    public function shade(float $amount): string
    {
        return self::mix($this->hex, '#000000', $amount);
    }

    /**
     * The readable text color to sit on top of the theme color.
     */
    public function contrastText(): string
    {
        [$r, $g, $b] = self::rgb($this->hex);

        $luminance = 0.2126 * ($r / 255)
            + 0.7152 * ($g / 255)
            + 0.0722 * ($b / 255);

        return $luminance > 0.55 ? '#111827' : '#ffffff';
    }

    /**
     * @return array<string, string> CSS custom property values (without the -- prefix).
     */
    public function palette(): array
    {
        return [
            'base' => $this->hex,
            'hover' => $this->shade(0.12),
            'active' => $this->shade(0.22),
            'tint-15' => $this->tint(0.15),
            'tint-30' => $this->tint(0.30),
            'tint-60' => $this->tint(0.60),
            'tint-75' => $this->tint(0.75),
            'tint-88' => $this->tint(0.88),
            'tint-94' => $this->tint(0.94),
            'shade-30' => $this->shade(0.30),
            'shade-50' => $this->shade(0.50),
            'shade-60' => $this->shade(0.60),
            'shade-70' => $this->shade(0.70),
            'shade-85' => $this->shade(0.85),
            'on-base' => $this->contrastText(),
        ];
    }

    public static function normalize(string $hex): string
    {
        $hex = ltrim(trim($hex), '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return '#'.strtolower(substr($hex, 0, 6));
    }

    /**
     * @return array{int, int, int}
     */
    private static function rgb(string $hex): array
    {
        $hex = ltrim(self::normalize($hex), '#');

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    private static function mix(string $from, string $to, float $amount): string
    {
        [$r1, $g1, $b1] = self::rgb($from);
        [$r2, $g2, $b2] = self::rgb($to);

        $r = (int) round($r1 + ($r2 - $r1) * $amount);
        $g = (int) round($g1 + ($g2 - $g1) * $amount);
        $b = (int) round($b1 + ($b2 - $b1) * $amount);

        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }
}
