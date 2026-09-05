@props(['color' => 'gray', 'label' => '', 'size' => 'xl', 'wrap' => false])
<x-filament::badge :color="$color" :size="$size" class="px-4 py-2 text-base font-semibold whitespace-normal break-words max-w-fit w-fit text-left">
    @if($wrap)
        {{ \Illuminate\Support\Str::beforeLast($label, ' ') }}<br>{{ \Illuminate\Support\Str::afterLast($label, ' ') }}
    @else
        {{ $label }}
    @endif
</x-filament::badge>
