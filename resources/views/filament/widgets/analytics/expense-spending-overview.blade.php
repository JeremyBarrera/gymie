@php
/** @var string $heading */
/** @var string $totalExpense */
/** @var \Illuminate\Support\Collection<int, array{label: string, total: float, color: string, flex: float}> $segments */

    $filters = $this->getFilters();
    @endphp

    <x-filament-widgets::widget>
        <x-filament::section :heading="$heading" class="fi-wi-chart h-full">
            <x-slot name="afterHeader">
                @if ($filters)
                <x-filament::input.wrapper inline-prefix wire:target="filter" class="fi-wi-chart-filter">
                    <x-filament::input.select inline-prefix wire:model.live="filter">
                        @foreach ($filters as $value => $label)
                        <option value="{{ $value }}">
                            {{ $label }}
                        </option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
                @endif
            </x-slot>

            <div class="grid gap-4">
                <div class="fi-wi-chart-text-color truncate text-3xl font-semibold tracking-tight">
                    {{ $totalExpense }}
                </div>

                <div class="grid gap-8">
                    <div class="fi-wi-chart-bg-color h-8 w-full overflow-hidden rounded-md shadow-sm">
                        @if ($segments->isEmpty())
                        <div class="fi-wi-chart-text-color flex h-full items-center justify-center text-sm">
                            {{ __('app.widgets.no_expenses_in_period') }}
                        </div>
                        @else
                        <div class="flex h-full w-full gap-[1px]">
                            @foreach ($segments as $segment)
                            <div
                                class="h-full"
                                style="flex: {{ $segment['flex'] }}; background-color: {{ $segment['color'] }};"
                                title="{{ $segment['label'] }}"></div>
                            @endforeach
                        </div>
                        @endif
                    </div>

                    @if ($segments->isNotEmpty())
                    <div class="grid grid-cols-1 gap-x-8 gap-y-5 sm:grid-cols-3">
                        @foreach ($segments as $segment)
                        <div class="flex items-start gap-3">
                            <span
                                class="mt-1 h-3 w-3 shrink-0 rounded-full"
                                style="background-color: {{ $segment['color'] }};"></span>

                            <div class="grid gap-1">
                                <div class="fi-wi-chart-text-color text-sm">
                                    {{ $segment['label'] }}
                                </div>
                                <div class="fi-wi-chart-text-color text-base font-semibold">
                                    {{ \App\Helpers\Helpers::formatCurrency($segment['total']) }}
                                </div>
                            </div>
                        </div>
                        @endforeach
                    </div>
                    @endif
                </div>
            </div>
        </x-filament::section>
    </x-filament-widgets::widget>
