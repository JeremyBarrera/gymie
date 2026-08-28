<div
    class="fi-inline-flex relative items-center ms-1"
    x-on:modal-closed.window="if ($event.detail.id === 'notification-bell-modal') $wire.closeBell()"
>
    <x-filament::icon-button
        icon="heroicon-o-bell"
        color="gray"
        :label="__('app.notifications.title')"
        :badge="$this->unreadBadge"
        wire:key="notification-bell-topbar"
        wire:click="openBell"
    />

    @if($modalOpen)
        <x-filament::modal
            id="notification-bell-modal"
            width="lg"
            :heading="__('app.notifications.title')"
        >
            <div class="flex flex-col gap-4">
                <div class="flex items-center gap-2" role="tablist" wire:key="notification-bell-tabs">
                    <x-filament::button
                        size="sm"
                        :color="$activeTab === 'active' ? 'primary' : 'gray'"
                        wire:click="switchTab('active')"
                        wire:key="notification-bell-tab-active"
                        role="tab"
                        :aria-selected="$activeTab === 'active' ? 'true' : 'false'"
                    >
                        {{ __('app.notifications.tab_active') }}
                    </x-filament::button>

                    <x-filament::button
                        size="sm"
                        :color="$activeTab === 'archived' ? 'primary' : 'gray'"
                        wire:click="switchTab('archived')"
                        wire:key="notification-bell-tab-archived"
                        role="tab"
                        :aria-selected="$activeTab === 'archived' ? 'true' : 'false'"
                    >
                        {{ __('app.notifications.archived') }}
                    </x-filament::button>
                </div>

                @if(count($notifications) === 0)
                    <x-filament::empty-state
                        icon="heroicon-o-bell"
                        :heading="$activeTab === 'archived'
                            ? __('app.notifications.empty_archived')
                            : __('app.notifications.empty_active')"
                    />
                @else
                    <div class="flex flex-col gap-4" wire:key="notification-bell-list">
                        @foreach($notifications as $item)
                            <x-filament::section
                                compact
                                :wire:key="'notification-bell-row-' . $item['id']"
                                :icon="$item['archived'] ? 'heroicon-m-archive-box' : ($item['unread'] ? 'heroicon-m-bell-alert' : 'heroicon-m-bell')"
                                :icon-color="$item['archived'] || ! $item['unread'] ? 'gray' : 'primary'"
                            >
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                                    <div class="min-w-0 flex-1 space-y-1">
                                        <p class="{{ $item['unread'] && ! $item['archived']
                                            ? 'fi-text text-sm font-semibold'
                                            : 'fi-text fi-text-muted text-sm' }}">
                                            {{ $item['message'] }}
                                        </p>

                                        @if(filled($item['reason']))
                                            <p class="fi-text fi-text-muted text-sm">{{ $item['reason'] }}</p>
                                        @endif

                                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                                            @if(filled($item['member_code']))
                                                <x-filament::badge color="gray" wire:key="notification-bell-member-code-{{ $item['id'] }}">
                                                    {{ $item['member_code'] }}
                                                </x-filament::badge>
                                            @endif

                                            <span class="fi-text fi-text-muted text-xs">{{ $item['occurred_at'] }}</span>
                                        </div>
                                    </div>

                                    <div class="flex shrink-0 items-center gap-2 sm:flex-col sm:items-end">
                                        @if($item['archived'])
                                            <x-filament::icon-button
                                                icon="heroicon-m-arrow-up-tray"
                                                color="gray"
                                                :label="__('app.notifications.unarchive')"
                                                wire:click="unarchive('{{ $item['id'] }}')"
                                                wire:loading.attr="disabled"
                                            />
                                        @else
                                            @if($item['unread'])
                                                <x-filament::icon-button
                                                    icon="heroicon-m-check-circle"
                                                    color="gray"
                                                    :label="__('app.notifications.mark_read')"
                                                    wire:click="markRead('{{ $item['id'] }}')"
                                                    wire:loading.attr="disabled"
                                                />
                                            @endif

                                            <x-filament::icon-button
                                                icon="heroicon-m-archive-box"
                                                color="gray"
                                                :label="__('app.notifications.archive')"
                                                wire:click="archive('{{ $item['id'] }}')"
                                                wire:loading.attr="disabled"
                                            />
                                        @endif
                                    </div>
                                </div>
                            </x-filament::section>
                        @endforeach
                    </div>

                    @if($hasMore)
                        <x-filament::button
                            color="gray"
                            wire:click="loadMore"
                            wire:key="notification-bell-load-more"
                            wire:loading.attr="disabled"
                        >
                            {{ __('app.notifications.load_more') }}
                        </x-filament::button>
                    @endif
                @endif
            </div>
        </x-filament::modal>
    @endif

    
    <script>
        document.addEventListener('livewire:init', () => {
            const userId = @js(auth()->id());

            if (! userId || ! window.Echo) {
                return;
            }

            window.Echo.private(`user.${userId}`)
                .listen('FollowUpEscalated', (e) => {
                    
                    
                    
                    @this.call('onFollowUpEscalated', e);
                });

            
            
            window.Echo.connector.pusher.connection.bind('connected', () => {
                @this.call('loadNotifications');
            });
        });
    </script>
</div>
