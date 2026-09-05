@props(['color' => 'gray', 'label' => '', 'size' => 'xl'])
<x-filament::badge :color="$color" :size="$size" class="px-4 py-2 text-base font-semibold !whitespace-normal break-words max-w-full h-auto text-wrap leading-snug">
    {{ $label }}
</x-filament::badge>
