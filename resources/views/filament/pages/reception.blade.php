<x-filament-panels::page>
    <div class="flex flex-col gap-4">
        <x-filament::tabs>
            <x-filament::tabs.item
                :active="$activeTab === 'checkin'"
                icon="heroicon-m-clipboard-document-check"
                badge-color="primary"
                :badge="count($checkinEntries) ?: null"
                wire:click="setActiveTab('checkin')"
            >
                {{ __('app.reception.tabs.checkin') }}
            </x-filament::tabs.item>

            <x-filament::tabs.item
                :active="$activeTab === 'signup'"
                icon="heroicon-m-user-plus"
                badge-color="primary"
                :badge="count($signupEntries) ?: null"
                wire:click="setActiveTab('signup')"
            >
                {{ __('app.reception.tabs.signup') }}
            </x-filament::tabs.item>
        </x-filament::tabs>

        @if($activeTab === 'checkin')
            <x-filament::section
                :heading="__('app.reception.check_in')"
                icon="heroicon-m-clipboard-document-check"
                compact
            >
                <div class="flex flex-col gap-3">
                    <x-filament::input.wrapper>
                        <x-filament::input
                            id="manual-checkin-search"
                            type="search"
                            wire:model.live.debounce.400ms="manualCheckInSearch"
                            autocomplete="off"
                            :placeholder="__('app.reception.manual_search_placeholder')"
                        />
                    </x-filament::input.wrapper>

                    <p
                        wire:loading
                        wire:target="manualCheckInSearch"
                        class="fi-text fi-text-muted flex items-center gap-2 text-sm"
                    >
                        {{ __('app.check_in.search_searching') }}
                    </p>

                    @if(trim($manualCheckInSearch) !== '')
                        <div wire:loading.remove wire:target="manualCheckInSearch" class="space-y-2">
                            @forelse($manualSearchResults as $result)
                                <x-filament::section compact wire:key="manual-search-result-{{ $result['id'] }}">
                                    <div class="flex items-center justify-between gap-3">
                                        <div class="min-w-0 space-y-1">
                                            <h4 class="fi-text truncate font-semibold">{{ $result['name'] }}</h4>
                                            <p class="fi-text fi-text-muted truncate text-sm">
                                                {{ $result['code'] }}@if(filled($result['contact'])) · {{ $result['contact'] }}@endif
                                            </p>
                                        </div>

                                        <x-filament::button
                                            wire:key="manual-search-select-{{ $result['id'] }}"
                                            wire:click="openManualCheckInForMember({{ $result['id'] }})"
                                            size="sm"
                                            class="shrink-0"
                                        >
                                            {{ __('app.reception.select') }}
                                        </x-filament::button>
                                    </div>
                                </x-filament::section>
                            @empty
                                <p class="fi-text fi-text-muted text-sm">{{ __('app.check_in.search_no_results') }}</p>
                            @endforelse
                        </div>
                    @endif
                </div>
            </x-filament::section>
        @endif

        @include('filament.pages.partials.queue-list', [
            'entries' => $activeTab === 'checkin' ? $checkinEntries : $signupEntries,
            'kind' => $activeTab,
        ])
    </div>

    @if($showConfirmOverlay && $selectedQueueEntryId)
        @include('filament.pages.partials.confirm-overlay', [
            'entry' => \App\Models\QueueEntry::find($selectedQueueEntryId),
            'action' => $confirmAction,
        ])
    @endif

    @if($showVerifyOverlay && $selectedQueueEntryId)
        @include('filament.pages.partials.verify-overlay')
    @endif

    @if($showCheckInOverlay)
        @include('filament.pages.partials.checkin-overlay')
    @endif

    @include('filament.pages.partials.location-channel-listener', ['resyncMethod' => 'loadQueueEntries'])
    @include('filament.pages.partials.verify-camera-script')
</x-filament-panels::page>