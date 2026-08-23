{{-- One notification row; used by the active list and the archive section.
     Expects $item (display row from Notifications::hydrate()) and $archived. --}}
<x-filament::section
    compact
    :wire:key="'notification-' . $item['id'] . ($archived ? '-archived' : '')"
    :icon="$item['unread'] && ! $archived ? 'heroicon-m-bell-alert' : 'heroicon-m-bell'"
    :icon-color="$item['unread'] && ! $archived ? 'primary' : 'gray'"
>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
        <div class="min-w-0 flex-1 space-y-1">
            <p class="{{ $item['unread'] && ! $archived
                ? 'fi-text text-sm font-semibold'
                : 'fi-text fi-text-muted text-sm' }}">
                {{ $item['message'] }}
            </p>

            @if(filled($item['reason']))
                <p class="fi-text fi-text-muted text-sm">{{ $item['reason'] }}</p>
            @endif

            <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                @if(filled($item['member_code']))
                    <x-filament::badge color="gray" wire:key="member-code-{{ $item['id'] }}-{{ $archived ? 'a' : '' }}">
                        {{ $item['member_code'] }}
                    </x-filament::badge>
                @endif

                <span class="fi-text fi-text-muted text-xs">{{ $item['occurred_at'] }}</span>
            </div>
        </div>

        @unless($archived)
            <div class="flex shrink-0 items-center gap-2 sm:flex-col sm:items-end">
                @if($item['unread'])
                    <x-filament::icon-button
                        icon="heroicon-m-check-circle"
                        color="gray"
                        :label="__('app.notifications.mark_read')"
                        wire:click="markRead('{{ $item['id'] }}')"
                        wire:loading.attr="disabled"
                        wire:key="'mark-read-' . $item['id']"
                    />
                @endif

                <x-filament::icon-button
                    icon="heroicon-m-archive-box"
                    color="gray"
                    :label="__('app.notifications.archive')"
                    wire:click="archive('{{ $item['id'] }}')"
                    wire:loading.attr="disabled"
                    wire:key="'archive-' . $item['id']"
                />
            </div>
        @endunless
    </div>
</x-filament::section>
