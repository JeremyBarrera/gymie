@props(['color' => 'gray', 'label' => ''])
<x-filament::badge :color="$color" size="lg" class="px-3.5 py-1.5 text-sm font-semibold">
    {{ $label }}
</x-filament::badge>
