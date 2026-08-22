<style>
    @php
        $bg = $background ?? ($palette['base'] ?? '#2563eb');
        $accent = $accent ?? ($palette['base'] ?? '#2563eb');
        $c = \App\Support\ColorContrast::derivePalette($bg, $accent);
    @endphp
    :root {
        --c-base: {{ $c['base'] }};
        --c-hover: {{ $c['hover'] }};
        --c-active: {{ $c['active'] }};
        --c-on-base: {{ $c['onBase'] }};
        --c-bg-a: {{ $c['bgA'] }};
        --c-bg-b: {{ $c['bgB'] }};
        --c-surface: {{ $c['surface'] }};
        --c-border: {{ $c['border'] }};
        --c-ring: {{ $c['ring'] }};
        --c-text: {{ $c['text'] }};
        --c-text-muted: {{ $c['textMuted'] }};
        --c-success: {{ $c['success'] }};
        --c-success-bg: {{ $c['successBg'] }};
        --c-success-border: {{ $c['successBorder'] }};
        --c-success-strong: {{ $c['successStrong'] }};
        --c-success-icon-bg: {{ $c['successIconBg'] }};
        --c-danger: {{ $c['danger'] }};
        --c-danger-bg: {{ $c['dangerBg'] }};
        --c-danger-border: {{ $c['dangerBorder'] }};
        --c-danger-strong: {{ $c['dangerStrong'] }};
        --c-danger-icon-bg: {{ $c['dangerIconBg'] }};
    }
</style>
