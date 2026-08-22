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
            <div>
                {{ $this->form }}
            </div>
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

    @if($showCheckInOverlay && $selectedCheckInEntryId)
        @include('filament.pages.partials.checkin-overlay')
    @endif

    @include('filament.pages.partials.location-channel-listener')
    @include('filament.pages.partials.verify-camera-script')
</x-filament-panels::page>