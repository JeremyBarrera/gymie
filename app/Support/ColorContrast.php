<?php

namespace App\Support;

/**
 * WCAG-correct colour math for the member-facing scanner UI.
 *
 * The QR flow lets a gym pick a background colour and an accent colour. Those
 * two values can land on any point of the wheel, so hard-coding foreground
 * colours would eventually produce unreadable pairings. This class derives
 * every foreground/background pair from the chosen colours using the WCAG
 * relative-luminance contrast ratio, guaranteeing AA (>= 4.5:1) text on its
 * surface and on the accent, for any input.
 */
final class ColorContrast
{
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

    private static function toHex(int $r, int $g, int $b): string
    {
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    public static function mix(string $from, string $to, float $amount): string
    {
        [$r1, $g1, $b1] = self::rgb($from);
        [$r2, $g2, $b2] = self::rgb($to);

        $r = (int) round($r1 + ($r2 - $r1) * $amount);
        $g = (int) round($g1 + ($g2 - $g1) * $amount);
        $b = (int) round($b1 + ($b2 - $b1) * $amount);

        return self::toHex($r, $g, $b);
    }

    /**
     * WCAG 2.1 relative luminance (gamma-corrected).
     */
    public static function relativeLuminance(string $hex): float
    {
        [$r, $g, $b] = self::rgb($hex);

        $linear = static fn (float $c): float => $c <= 0.03928
            ? $c / 12.92
            : pow(($c + 0.055) / 1.055, 2.4);

        return 0.2126 * $linear($r / 255)
            + 0.7152 * $linear($g / 255)
            + 0.0722 * $linear($b / 255);
    }

    /**
     * WCAG 2.1 contrast ratio between two colours (1.0 – 21.0).
     */
    public static function ratio(string $a, string $b): float
    {
        $la = self::relativeLuminance($a);
        $lb = self::relativeLuminance($b);

        $lighter = max($la, $lb);
        $darker = min($la, $lb);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /**
     * Pick the readable foreground (near-white or near-black) for a background.
     *
     * Prefers whichever candidate meets the AA threshold (>= $min) and has
     * the higher ratio. If only one candidate clears AA, use it. If neither
     * does (extremely rare mid-luminance edge case), return whichever is
     * closer to the threshold so a future caller can keep adjusting.
     */
    public static function readableOn(string $bg, string $light = '#ffffff', string $dark = '#0f172a', float $min = 4.5): string
    {
        $rl = self::ratio($light, $bg);
        $rd = self::ratio($dark, $bg);

        // Both clear AA — prefer the higher ratio
        if ($rl >= $min && $rd >= $min) {
            return $rl >= $rd ? $light : $dark;
        }

        // Exactly one clears AA — use it
        if ($rl >= $min) {
            return $light;
        }
        if ($rd >= $min) {
            return $dark;
        }

        // Neither clears AA — pick whichever is closer
        return $rl >= $rd ? $light : $dark;
    }

    /**
     * A muted variant of the readable foreground that still clears the AA
     * threshold against the surface.
     */
    public static function mutedOn(string $surface, float $min = 4.5): string
    {
        $candidate = self::readableOn($surface);

        for ($i = 0; $i < 12; $i++) {
            $mixed = self::mix($candidate, $surface, 0.12);

            if (self::ratio($mixed, $surface) >= $min) {
                $candidate = $mixed;
            } else {
                break;
            }
        }

        return $candidate;
    }

    /**
     * Ensure a foreground colour has at least the required contrast ratio
     * against a background. If it already does, return unchanged; otherwise
     * pick whichever extreme (white or black) gives the higher contrast,
     * then binary-search along that axis for the lightest/darkest value
     * that clears the threshold.
     */
    public static function ensureContrast(string $fg, string $bg, float $min = 4.5): string
    {
        if (self::ratio($fg, $bg) >= $min) {
            return $fg;
        }

        // Pick the direction that maximises contrast.
        $whiteRatio = self::ratio('#ffffff', $bg);
        $blackRatio = self::ratio('#000000', $bg);
        $extreme = $whiteRatio >= $blackRatio ? '#ffffff' : '#000000';

        // Binary-search along the fg → extreme axis for the threshold.
        $lo = 0.0;
        $hi = 1.0;
        $best = $fg;

        for ($i = 0; $i < 16; $i++) {
            $mid = ($lo + $hi) / 2;
            $candidate = self::mix($fg, $extreme, $mid);
            $r = self::ratio($candidate, $bg);

            if ($r >= $min) {
                $hi = $mid;
                $best = $candidate;
            } else {
                $lo = $mid;
            }
        }

        return $best;
    }

    /**
     * Build the full set of CSS custom properties for the scanner UI from a
     * brand background colour and a brand accent colour.
     *
     * Every text/background pairing is derived so it is readable regardless of
     * how wild the two input colours are:
     *  - the surface (card) is pushed toward neutral from the background,
     *  - body text is the AA-safe foreground on that surface,
     *  - the accent button uses the AA-safe foreground on the accent,
     *  - success/danger tints are computed against the surface luminance.
     *
     * @return array<string, string>
     */
    public static function derivePalette(string $background, string $accent): array
    {
        $background = self::normalize($background);
        $accent = self::normalize($accent);
        $isDark = self::relativeLuminance($background) < 0.5;

        $surface = $isDark
            ? self::mix($background, '#0b1220', 0.82)
            : self::mix($background, '#ffffff', 0.94);

        $onSurface = self::readableOn($surface);
        $textMuted = self::mutedOn($surface);
        $border = self::mix($onSurface, $surface, 0.16);
        $ring = self::mix($accent, $surface, 0.35);
        $hover = self::mix($accent, '#000000', 0.12);
        $active = self::mix($accent, '#000000', 0.22);
        $onBase = self::ensureContrast(self::readableOn($accent), $accent);

        $bgA = self::mix($background, '#ffffff', 0.12);
        $bgB = self::mix($background, '#000000', 0.18);

        if ($isDark) {
            $success = '#4ade80';
            $successBg = self::mix($success, $surface, 0.18);
            $successBorder = self::mix($success, $surface, 0.40);
            $successStrong = self::mix($success, $surface, 0.55);
            $successIconBg = 'rgba(74, 222, 128, 0.18)';

            $danger = '#f87171';
            $dangerBg = self::mix($danger, $surface, 0.18);
            $dangerBorder = self::mix($danger, $surface, 0.40);
            $dangerStrong = self::mix($danger, $surface, 0.55);
            $dangerIconBg = 'rgba(248, 113, 113, 0.18)';
        } else {
            $success = '#16a34a';
            $successBg = self::mix($success, $surface, 0.88);
            $successBorder = self::mix($success, $surface, 0.60);
            $successStrong = self::mix($success, $surface, 0.92);
            $successIconBg = self::mix($success, $surface, 0.80);

            $danger = '#dc2626';
            $dangerBg = self::mix($danger, $surface, 0.88);
            $dangerBorder = self::mix($danger, $surface, 0.60);
            $dangerStrong = self::mix($danger, $surface, 0.92);
            $dangerIconBg = self::mix($danger, $surface, 0.80);
        }

        return [
            'base' => $accent,
            'hover' => $hover,
            'active' => $active,
            'onBase' => $onBase,
            'bgA' => $bgA,
            'bgB' => $bgB,
            'surface' => $surface,
            'border' => $border,
            'ring' => $ring,
            'text' => $onSurface,
            'textMuted' => $textMuted,
            'success' => $success,
            'successBg' => $successBg,
            'successBorder' => $successBorder,
            'successStrong' => $successStrong,
            'successIconBg' => $successIconBg,
            'danger' => $danger,
            'dangerBg' => $dangerBg,
            'dangerBorder' => $dangerBorder,
            'dangerStrong' => $dangerStrong,
            'dangerIconBg' => $dangerIconBg,
        ];
    }
}
