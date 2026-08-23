<div>
    @if($enabled)
        @if($pendingQueue)
            <x-filament::dropdown
                placement="bottom-end"
                width="sm"
                maxHeight="60vh"
                flip
                wire:key="pending-fab"
                class="pending-fab pending-fab-topbar"
            >
                <x-slot name="trigger">
                    <button
                        type="button"
                        class="pending-fab-button"
                        aria-label="{{ __('app.reception.waiting_title') }}"
                    >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M7 6.75h12.25M7 12h12.25M7 17.25h12.25M3.5 6.75h.007v.008H3.5V6.75Zm.5 0a.5.5 0 1 1-1 0 .5.5 0 0 1 1 0ZM3.5 12h.007v.008H3.5V12Zm.5 0a.5.5 0 1 1-1 0 .5.5 0 0 1 1 0Zm-.5 5.25h.007v.008H3.5v-.008Zm.5 0a.5.5 0 1 1-1 0 .5.5 0 0 1 1 0Z" />
                        </svg>
                        <span class="pending-fab-count">{{ count($pendingQueue) }}</span>
                    </button>
                </x-slot>

                <x-filament::dropdown.header>
                    {{ __('app.reception.waiting_title') }}
                </x-filament::dropdown.header>

                <x-filament::dropdown.list>
                    @foreach($pendingQueue as $entry)
                        <x-filament::dropdown.list.item
                            :wire:key="'pending-item-' . $entry['id']"
                            tag="button"
                            color="primary"
                            :icon="$entry['kind'] === 'checkin' ? 'heroicon-m-arrow-right-circle' : 'heroicon-m-user-plus'"
                            :wire:click="$entry['kind'] === 'checkin'
                                ? 'checkInFromPending(' . $entry['id'] . ')'
                                : 'verifyFromPending(' . $entry['id'] . ')'"
                        >
                            <span class="block text-sm font-medium">{{ $entry['name'] ?: __('app.reception.new_member') }}</span>
                            <span class="block text-xs opacity-60">
                                {{ $entry['kind'] === 'checkin' ? __('app.scan.kind_checkin') : __('app.scan.kind_signup') }}
                                @if($entry['contact'])
                                    · {{ $entry['contact'] }}
                                @endif
                            </span>
                        </x-filament::dropdown.list.item>
                    @endforeach
                </x-filament::dropdown.list>
            </x-filament::dropdown>
        @endif

        @if($showVerifyOverlay && $selectedQueueEntryId)
            @include('filament.pages.partials.verify-overlay')
        @endif

        @if($showCheckInOverlay && $selectedCheckInEntryId)
            @include('filament.pages.partials.checkin-overlay')
        @endif

        @if($claimedQueue)
            <div class="pending-claimed" style="display: none" aria-hidden="true">
                @foreach($claimedQueue as $claimed)
                    <div
                        wire:key="pending-claimed-{{ $claimed['id'] }}"
                        x-data="{}"
                        x-init="setTimeout(() => $wire.call('restoreClaimedToPending', {{ $claimed['id'] }}), {{ $claimed['seconds'] }} * 1000)"
                    ></div>
                @endforeach
            </div>
        @endif

        @include('filament.pages.partials.location-channel-listener')
        @include('filament.pages.partials.verify-camera-script')
    @endif
</div>