<div class="flex flex-col gap-4" wire:key="queue-list-{{ $kind }}">
    @if(empty($entries))
        <x-filament::empty-state
            :icon="$kind === 'checkin' ? 'heroicon-m-clipboard-document-check' : 'heroicon-m-user-plus'"
            :heading="$kind === 'checkin' ? __('app.reception.empty_checkin') : __('app.reception.empty_signup')"
        />
    @else
        @foreach($entries as $entry)
            @php
                $member = ($entry['kind'] === 'checkin' && ! empty($entry['payload']['member_id']))
                    ? \App\Models\Member::find($entry['payload']['member_id'])
                    : null;

                $name = $member?->name
                    ?? $entry['payload']['identifier_value']
                    ?? ($entry['kind'] === 'checkin' ? __('app.reception.unknown_member') : __('app.reception.new_member'));

                $photoUrl = ($member && $member->photo) ? asset('storage/'.$member->photo) : null;

                $eligible = [];
                if ($member) {
                    $eligible = app(\App\Services\Membership\PlanCheckInService::class)
                        ->eligibleSubscriptions($member)
                        ->map(fn ($s) => $s->plan?->name)
                        ->filter()
                        ->values()
                        ->all();
                }

                $claimedByName = ! empty($entry['claimed_by_user_id'])
                    ? \App\Models\User::find($entry['claimed_by_user_id'])?->name
                    : null;

                $isAmbiguousCheckIn = $entry['kind'] === 'checkin'
                    && count($entry['payload']['candidate_member_ids'] ?? []) > 1;

                $statusColor = match ($entry['status']) {
                    'waiting' => 'info',
                    'attending' => 'warning',
                    'approved' => 'success',
                    'denied' => 'danger',
                    default => 'gray',
                };
            @endphp

            <x-filament::section compact wire:key="entry-{{ $entry['id'] }}">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start">
                    <div class="flex flex-1 items-start gap-3 min-w-0">
                        @if($photoUrl)
                            <x-filament::avatar :src="$photoUrl" size="lg" class="shrink-0" />
                        @else
                            <span class="fi-color fi-color-primary flex shrink-0 items-center justify-center rounded-lg fi-size-lg">
                                <x-filament::icon icon="heroicon-m-user" :size="\Filament\Support\Enums\IconSize::Large" />
                            </span>
                        @endif

                        <div class="min-w-0 space-y-2">
                            <h4 class="fi-text text-base font-semibold">{{ $name }}</h4>

                            <div class="flex flex-wrap items-center gap-2">
                                <x-filament::badge :color="$statusColor">
                                    {{ __('app.reception.status.'.$entry['status']) }}
                                </x-filament::badge>

                                @if($isAmbiguousCheckIn)
                                    <x-filament::badge color="warning">
                                        {{ __('app.reception.multiple_matches') }}
                                    </x-filament::badge>
                                @endif

                                @if($entry['kind'] === 'checkin' && $member)
                                    @if(! empty($eligible))
                                        <x-filament::badge color="success">
                                            {{ implode(', ', $eligible) }}
                                        </x-filament::badge>
                                    @else
                                        <x-filament::badge color="danger">
                                            {{ __('app.reception.no_eligible_plans') }}
                                        </x-filament::badge>
                                    @endif
                                @endif
                            </div>

                            <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                                <span class="fi-text text-sm">
                                    {{ \Carbon\Carbon::parse($entry['created_at'])
                                        ->timezone(\App\Support\AppConfig::timezone())
                                        ->translatedFormat(\App\Support\Dates\DeviceDateFormat::time()) }}
                                </span>

                                @if($claimedByName)
                                    <span class="fi-text text-sm">
                                        {{ __('app.reception.claimed_by') }}: {{ $claimedByName }}
                                    </span>
                                @endif
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-col gap-2 sm:shrink-0 sm:items-end">
                        @if($entry['kind'] === 'signup' && in_array($entry['status'], ['waiting', 'attending'], true))
                            @if($entry['status'] === 'waiting' || $entry['claimed_by_user_id'] == Auth::id())
                                <x-filament::button
                                    wire:click="openVerifyOverlay({{ $entry['id'] }})"
                                    wire:loading.attr="disabled"
                                >
                                    {{ __('app.reception.verify') }}
                                </x-filament::button>
                            @endif

                            @if($entry['status'] === 'attending' && $entry['claimed_by_user_id'] == Auth::id())
                                <x-filament::button
                                    color="danger"
                                    wire:click="openConfirmOverlay({{ $entry['id'] }}, 'deny')"
                                >
                                    {{ __('app.reception.deny') }}
                                </x-filament::button>
                            @endif

                            @if(in_array($entry['status'], ['waiting', 'attending'], true))
                                <x-filament::button
                                    color="gray"
                                    wire:click="deleteQueueEntry({{ $entry['id'] }})"
                                    wire:loading.attr="disabled"
                                >
                                    {{ __('app.reception.delete') }}
                                </x-filament::button>
                            @endif
                        @elseif($entry['status'] === 'waiting')
                            <x-filament::button
                                wire:click="claim({{ $entry['id'] }})"
                                wire:loading.attr="disabled"
                            >
                                {{ __('app.reception.check_in') }}
                            </x-filament::button>

                            <x-filament::button
                                color="gray"
                                wire:click="deleteQueueEntry({{ $entry['id'] }})"
                                wire:loading.attr="disabled"
                            >
                                {{ __('app.reception.delete') }}
                            </x-filament::button>
                        @elseif($entry['status'] === 'attending' && $entry['claimed_by_user_id'] == Auth::id())
                            <x-filament::button
                                wire:click="openCheckInOverlay({{ $entry['id'] }})"
                            >
                                {{ __('app.reception.check_in') }}
                            </x-filament::button>

                            <x-filament::button
                                color="gray"
                                wire:click="deleteQueueEntry({{ $entry['id'] }})"
                                wire:loading.attr="disabled"
                            >
                                {{ __('app.reception.delete') }}
                            </x-filament::button>
                        @elseif($entry['status'] === 'attending')
                            <span class="fi-text text-sm">
                                {{ __('app.reception.attending_by') }}: {{ $claimedByName ?? '—' }}
                            </span>
                        @endif
                    </div>
                </div>
            </x-filament::section>
        @endforeach
    @endif
</div>