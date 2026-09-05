@props(['color' => 'gray', 'label' => '', 'size' => 'xl'])
<x-filament::badge :color="$color" :size="$size" class="px-4 py-2 text-base font-semibold !whitespace-normal break-words max-w-full w-fit h-auto text-wrap leading-snug text-left justify-start items-start flex-col [&_.fi-badge-label-ctn]:!w-full [&_.fi-badge-label-ctn]:flex [&_.fi-badge-label-ctn]:flex-col [&_.fi-badge-label-ctn]:items-start [&_.fi-badge-label-ctn]:w-fit [&_span.fi-badge-label]:!whitespace-normal [&_span.fi-badge-label]:break-words [&_span.fi-badge-label]:whitespace-normal [&_span.fi-badge-label]:text-left [&_span.fi-badge-label]:block [&_span.fi-badge-label]:w-full [&_span.fi-badge-label]:text-left">
    {{ $label }}
</x-filament::badge>
