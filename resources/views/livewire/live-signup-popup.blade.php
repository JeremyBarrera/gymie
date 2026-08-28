<div>
    @if($enabled)
        @if($pendingQueue)
            <div class="pending-fab-wrap">
                <x-filament::dropdown
                    placement="top-end"
                    width="sm"
                    maxHeight="60vh"
                    flip
                    wire:key="pending-fab"
                >
                    <x-slot name="trigger">
                        <button
                            type="button"
                            class="pending-fab-button pending-fab-flash"
                            aria-label="{{ __('app.reception.waiting_title') }}"
                        >
                            <x-filament::icon icon="heroicon-m-clipboard-document-check" class="pending-fab-icon" />
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
            </div>
        @endif

        @if($showVerifyOverlay && $selectedQueueEntryId)
            @include('filament.pages.partials.verify-overlay')
        @endif

        @if($showCheckInOverlay)
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

        @include('filament.pages.partials.location-channel-listener', ['resyncMethod' => 'refreshPendingQueue'])
        @include('filament.pages.partials.verify-camera-script')
    @endif
</div>