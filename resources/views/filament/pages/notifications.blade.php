<x-filament-panels::page>
    <div class="flex flex-col gap-4">
        <x-filament::button
            :color="$unreadOnly ? 'primary' : 'gray'"
            icon="heroicon-m-bell-alert"
            wire:click="toggleUnreadOnly"
            wire:key="unread-filter-toggle"
        >
            {{ __('app.notifications.unread_only') }}
        </x-filament::button>

        @php
            $active = $this->activeNotifications();
        @endphp

        @if($active->isEmpty())
            <x-filament::empty-state
                icon="heroicon-o-bell"
                :heading="__('app.empty.no_records', ['records' => __('app.follow_up.title')])"
            />
        @else
            <div class="flex flex-col gap-4" wire:key="notification-list">
                @foreach($active as $item)
                    @include('filament.pages.partials.notification-item', [
                        'item' => $item,
                        'archived' => false,
                    ])
                @endforeach
            </div>

            <x-filament::pagination :paginator="$active" />
        @endif

        @if(count($archivedNotifications))
            <x-filament::section
                :heading="__('app.notifications.archived')"
                icon="heroicon-m-archive-box"
                collapsible
                collapsed
                wire:key="archived-section"
            >
                <div class="flex flex-col gap-4">
                    @foreach($archivedNotifications as $item)
                        @include('filament.pages.partials.notification-item', [
                            'item' => $item,
                            'archived' => true,
                        ])
                    @endforeach
                </div>
            </x-filament::section>
        @endif
    </div>

    @include('filament.pages.partials.user-channel-listener')
</x-filament-panels::page>
