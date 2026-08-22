@php
    $checkInEntry = \App\Models\QueueEntry::find($selectedCheckInEntryId);
    $checkInCandidates = collect($checkInEntry?->payload['candidate_member_ids'] ?? [])
        ->map(fn ($id) => \App\Models\Member::find((int) $id))
        ->filter()
        ->values();
    $checkInMember = $selectedCheckInMemberId ? \App\Models\Member::find($selectedCheckInMemberId) : null;
    $checkInServices = $this->checkInServices;
    $checkInSelectedRow = collect($checkInServices)->firstWhere('id', $checkInServiceId);
    $checkInStatus = $checkInMember
        ? \App\Support\Membership\MembershipStatus::forMember($checkInMember)
        : null;

    // Single severity order for the member card:
    // ok (success) < attention (unpaid / expiring) < blocked (overdue / expired / no access).
    // The photo border always shows the WORST applicable status across the
    // plan-expiry badge and every payment/service state, so border and
    // badges can never disagree.
    $cardSeverityRank = function (string $stateOrColor): int {
        return match (true) {
            in_array($stateOrColor, ['overdue', 'expired', 'no_access', 'danger'], true) => 2,
            in_array($stateOrColor, ['unpaid', 'warning'], true) => 1,
            default => 0,
        };
    };

    $serviceRanks = collect($checkInServices)
        ->map(fn (array $row): int => $cardSeverityRank((string) ($row['state'] ?? 'access')));
    $planRank = $cardSeverityRank((string) ($checkInStatus['color'] ?? 'gray'));

    $worstServiceRow = collect($checkInServices)
        ->sortByDesc(fn (array $row): int => $cardSeverityRank((string) ($row['state'] ?? 'access')))
        ->first();
    $worstServiceRank = $worstServiceRow !== null
        ? $cardSeverityRank((string) ($worstServiceRow['state'] ?? 'access'))
        : 0;

    // The plan-expiry badge and the worst payment/service badge share one
    // severity scale — whichever wins paints the border.
    $planWins = $planRank >= $worstServiceRank;
    $cardRank = max($planRank, $worstServiceRank);
    $planIsNone = ($checkInStatus['color'] ?? null) === 'gray';

    // Theme tokens only (AGENTS.md rule 7) — never hardcoded hex/rgba.
    [$cardRingVar, $cardIcon] = match (true) {
        $cardRank >= 2 => ['var(--danger-500)', 'heroicon-m-exclamation-triangle'],
        $cardRank === 1 => ['var(--warning-500)', 'heroicon-m-clock'],
        $planIsNone => ['var(--gray-400)', 'heroicon-o-user'],
        default => ['var(--success-500)', 'heroicon-m-check-circle'],
    };

    // Accessible title for the status marker: name the blocking reason.
    $cardTitle = ! $planWins && $worstServiceRow !== null
        ? match ((string) ($worstServiceRow['state'] ?? '')) {
            'overdue' => __('app.reception.service_overdue_short'),
            'expired' => __('app.reception.service_expired_short'),
            'no_access' => __('app.reception.service_no_access_short'),
            'unpaid' => __('app.reception.service_unpaid_short'),
            default => (string) ($checkInStatus['label'] ?? ''),
        }
        : (string) ($checkInStatus['label'] ?? '');
@endphp

@if($checkInEntry)
    <div
        wire:key="checkin-overlay-{{ $checkInEntry->id }}"
        x-data
        x-on:close-modal.window="if ($event.detail.id === 'checkin-overlay') $wire.closeCheckInOverlay()"
    >
        <x-filament::modal
            id="checkin-overlay"
            x-init="$nextTick(() => open())"
            width="3xl"
            :close-by-clicking-away="false"
            :close-by-escaping="false"
            :heading="$checkInDenyStep
                ? __('app.reception.checkin_deny_title')
                : ($checkInOverrideDueDateStep
                    ? __('app.reception.override_due_date_title')
                    : ($checkInOverrideStep
                        ? __('app.reception.override_confirm_title')
                        : __('app.reception.checkin_overlay_title')))"
            :description="$checkInDenyStep
                ? __('app.reception.deny_reason')
                : ($checkInOverrideDueDateStep
                    ? __('app.reception.override_due_date_hint')
                    : ($checkInOverrideStep
                        ? __('app.reception.override_hint')
                        : __('app.reception.checkin_overlay_hint')))"
        >
            <div class="space-y-6">
                @if($checkInDenyStep)
                    <div>
                        <label for="checkin-deny-reason" class="fi-text text-base font-semibold">
                            {{ __('app.reception.deny_reason') }}
                        </label>
                        <x-filament::input.wrapper class="fi-fo-textarea mt-2">
                            <div class="h-20">
                                <textarea
                                    id="checkin-deny-reason"
                                    wire:model="checkInDenyReason"
                                    rows="3"
                                    placeholder="{{ __('app.reception.deny_reason_placeholder') }}"
                                ></textarea>
                            </div>
                        </x-filament::input.wrapper>
                    </div>
                @elseif($checkInOverrideStep)
                    <div class="flex items-center gap-2">
                        <x-filament::badge color="warning">
                            {{ __('app.reception.override') }}
                        </x-filament::badge>
                        <h3 class="fi-text text-base font-semibold">{{ __('app.reception.override_confirm_title') }}</h3>
                    </div>

                    <p class="fi-text text-base leading-relaxed">
                        {{ __('app.reception.override_notifies', ['names' => implode(', ', $checkInOverrideRecipients) ?: __('app.reception.override_no_recipients')]) }}
                    </p>

                    <div>
                        <label for="checkin-override-reason" class="fi-text text-base font-semibold">
                            {{ __('app.reception.override_reason') }}
                        </label>
                        <x-filament::input.wrapper class="fi-fo-textarea mt-2">
                            <div class="h-20">
                                <textarea
                                    id="checkin-override-reason"
                                    wire:model="checkInOverrideReason"
                                    rows="3"
                                    placeholder="{{ __('app.reception.override_reason_placeholder') }}"
                                ></textarea>
                            </div>
                        </x-filament::input.wrapper>
                    </div>
                @elseif($checkInOverrideDueDateStep)
                    <div class="flex items-center gap-2">
                        <x-filament::badge color="danger">
                            <x-filament::icon icon="heroicon-m-exclamation-triangle" :size="\Filament\Support\Enums\IconSize::Small" />
                        </x-filament::badge>
                        <h3 class="fi-text text-base font-semibold">{{ __('app.reception.override_due_date_title') }}</h3>
                    </div>

                    <p class="fi-text text-base leading-relaxed">
                        {{ __('app.reception.override_due_date_hint') }}
                    </p>

                    <div>
                        <label for="checkin-override-due-date" class="fi-text text-base font-semibold">
                            {{ __('app.fields.due_date') }}
                        </label>
                        <x-filament::input
                            id="checkin-override-due-date"
                            type="date"
                            wire:model="checkInOverrideNewDueDate"
                            class="mt-2 border-danger-500 focus:border-danger-500 focus:ring-danger-500"
                        />
                    </div>
                @elseif(! $checkInMember && $checkInCandidates->isNotEmpty())
                    <div class="flex items-center gap-2">
                        <span class="checkin-flash-chip">
                            <x-filament::icon icon="heroicon-m-bolt" :size="\Filament\Support\Enums\IconSize::Small" />
                        </span>
                        <h3 class="fi-text text-base font-semibold">{{ __('app.reception.multiple_matches') }}</h3>
                    </div>

                    <p class="fi-text text-sm">{{ __('app.reception.select_member_prompt') }}</p>

                    <div class="space-y-3">
                        @foreach($checkInCandidates as $candidate)
                            @php
                                $candidateStatus = \App\Support\Membership\MembershipStatus::forMember($candidate);
                            @endphp

                            <x-filament::section compact wire:key="checkin-candidate-{{ $candidate->id }}">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                                    <div class="flex min-w-0 flex-1 items-center gap-3">
                                        @if($candidate->photo)
                                            <img
                                                src="{{ asset('storage/'.$candidate->photo) }}"
                                                class="h-16 w-16 shrink-0 rounded-lg object-cover"
                                                alt="{{ $candidate->name }}"
                                            >
                                        @else
                                            <span class="fi-color fi-color-primary flex h-16 w-16 shrink-0 items-center justify-center rounded-lg">
                                                <x-filament::icon icon="heroicon-m-user" :size="\Filament\Support\Enums\IconSize::Large" />
                                            </span>
                                        @endif

                                        <div class="min-w-0 space-y-1">
                                            <h4 class="fi-text text-lg font-semibold">{{ $candidate->name }}</h4>
                                            <p class="fi-text fi-text-muted text-base">{{ $candidate->code }}</p>
                                            <x-filament::badge :color="$candidateStatus['color']" size="md">
                                                {{ $candidateStatus['label'] }}
                                            </x-filament::badge>
                                        </div>
                                    </div>

                                    <x-filament::button
                                        wire:key="checkin-select-{{ $candidate->id }}"
                                        wire:click="selectCheckInMember({{ $candidate->id }})"
                                        class="sm:shrink-0"
                                    >
                                        {{ __('app.reception.select') }}
                                    </x-filament::button>
                                </div>
                            </x-filament::section>
                        @endforeach
                    </div>
                @elseif($checkInMember)
                    <div class="flex flex-col gap-5 sm:flex-row sm:items-start">
                        <div class="relative shrink-0">
                            @if($checkInMember->photo)
                                <img
                                    src="{{ asset('storage/'.$checkInMember->photo) }}"
                                    class="h-48 w-40 rounded-xl object-cover"
                                    style="box-shadow: 0 0 0 3px {{ $cardRingVar }};"
                                    alt="{{ $checkInMember->name }}"
                                >
                            @else
                                <div class="flex h-48 w-40 flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-(--gray-300)"
                                    style="box-shadow: 0 0 0 3px {{ $cardRingVar }};"
                                >
                                    <x-filament::icon icon="heroicon-o-user" class="h-8 w-8" />
                                    <span class="fi-text-muted px-4 text-center text-sm">
                                        {{ __('app.reception.unknown_member') }}
                                    </span>
                                </div>
                            @endif

                            {{-- Non-color reinforcement of the card status (a11y) --}}
                            <span
                                class="absolute -top-2 -end-2 z-10 flex h-7 w-7 items-center justify-center rounded-full"
                                style="background: {{ $cardRingVar }};"
                                role="img"
                                aria-label="{{ $cardTitle }}"
                                title="{{ $cardTitle }}"
                            >
                                <x-filament::icon icon="{{ $cardIcon }}" class="h-4 w-4 text-white" />
                            </span>
                        </div>

                        <div class="flex-1 min-w-0 space-y-5">
                            <h3 class="fi-text text-4xl font-bold leading-tight tracking-tight">{{ $checkInMember->name }}</h3>

                            <div class="grid gap-x-8 sm:grid-cols-2">
                                <div class="space-y-4">
                                    <div class="space-y-1">
                                        <span class="fi-text-muted text-xs font-medium uppercase tracking-wide">{{ __('app.fields.member_id') }}</span>
                                        <p class="fi-text text-base font-medium">{{ $checkInMember->code }}</p>
                                    </div>

                                    @if($checkInMember->contact)
                                        <div class="space-y-1">
                                            <span class="fi-text-muted text-xs font-medium uppercase tracking-wide">{{ __('app.fields.contact') }}</span>
                                            <p class="fi-text text-base font-medium">{{ $checkInMember->contact }}</p>
                                        </div>
                                    @endif

                                    @if($checkInMember->email)
                                        <div class="space-y-1">
                                            <span class="fi-text-muted text-xs font-medium uppercase tracking-wide">{{ __('app.fields.email') }}</span>
                                            <p class="fi-text text-base font-medium break-all">{{ $checkInMember->email }}</p>
                                        </div>
                                    @endif
                                </div>

                                <div class="space-y-4">
                                    @if($checkInMember->gender)
                                        <div class="space-y-1">
                                            <span class="fi-text-muted text-xs font-medium uppercase tracking-wide">{{ __('app.fields.gender') }}</span>
                                            <p class="fi-text text-base font-medium">{{ __('app.options.gender.'.$checkInMember->gender) }}</p>
                                        </div>
                                    @endif

                                    @if($checkInMember->dob)
                                        <div class="space-y-1">
                                            <span class="fi-text-muted text-xs font-medium uppercase tracking-wide">{{ __('app.fields.dob') }}</span>
                                            <p class="fi-text text-base font-medium">{{ \App\Support\Dates\DeviceDateFormat::format($checkInMember->dob) }}</p>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>

                    @if($checkInStatus)
                        <div class="flex flex-wrap items-center gap-3">
                            <x-filament::badge :color="$checkInStatus['color']" size="md">
                                {{ $checkInStatus['label'] }}
                            </x-filament::badge>

                            @if(! empty($checkInStatus['hint']))
                                <span class="fi-text fi-text-muted text-sm">
                                    {{ $checkInStatus['hint'] }}
                                </span>
                            @endif

                            @if(! empty($checkInStatus['help']))
                                <span class="fi-text fi-text-muted text-sm">
                                    {{ $checkInStatus['help'] }}
                                </span>
                            @endif
                        </div>
                    @endif

                    <div class="space-y-4">
                        <label for="checkin-service-select" class="fi-text text-base font-semibold">
                            {{ __('app.fields.service') }}
                        </label>

                        @if(empty($checkInServices))
                            <x-filament::section compact>
                                <p class="fi-text fi-text-muted text-sm">{{ __('app.reception.no_eligible_subscription') }}</p>
                            </x-filament::section>
                        @else
                            <x-filament::input.wrapper>
                                <x-filament::input.select
                                    id="checkin-service-select"
                                    wire:model.live="checkInServiceId"
                                >
                                    @foreach($checkInServices as $row)
                                        <option value="{{ $row['id'] }}">
                                            {{ $row['name'] }} — @if($row['state'] === 'access'){{ __('app.reception.service_access') }}@elseif($row['state'] === 'unpaid'){{ __('app.reception.service_unpaid_short') }}@elseif($row['state'] === 'overdue'){{ __('app.reception.service_overdue_short') }}@elseif($row['state'] === 'expired'){{ __('app.reception.service_expired_short') }}@else{{ __('app.reception.service_no_access_short') }}@endif
                                        </option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>

                            @if($checkInSelectedRow)
                                @php
                                    // Same severity scale as the photo border:
                                    // blocked states are red, attention is amber.
                                    $selectedStateColor = match ($checkInSelectedRow['state'] ?? 'access') {
                                        'unpaid' => 'warning',
                                        'overdue', 'expired', 'no_access' => 'danger',
                                        default => 'success',
                                    };
                                @endphp
                                <div class="flex flex-wrap items-center gap-3">
                                    <x-filament::badge :color="$selectedStateColor" size="md">
                                        @if(($checkInSelectedRow['state'] ?? null) === 'access')
                                            {{ __('app.reception.service_access') }}
                                        @elseif(($checkInSelectedRow['state'] ?? null) === 'unpaid')
                                            {{ __('app.reception.service_unpaid_short') }}
                                        @elseif(($checkInSelectedRow['state'] ?? null) === 'overdue')
                                            {{ __('app.reception.service_overdue_short') }}
                                        @elseif(($checkInSelectedRow['state'] ?? null) === 'expired')
                                            {{ __('app.reception.service_expired_short') }}
                                        @else
                                            {{ __('app.reception.service_no_access_short') }}
                                        @endif
                                    </x-filament::badge>
                                </div>

                                @if(! empty($checkInSelectedRow['warning']))
                                    <p class="fi-text fi-text-muted text-sm leading-relaxed">
                                        {{ $checkInSelectedRow['warning'] }}
                                    </p>
                                @endif
                            @endif
                        @endif
                    </div>
                @endif
            </div>

            <x-slot name="footer">
                <div class="flex w-full justify-end gap-2">
                    @if($checkInDenyStep)
                        <x-filament::button
                            wire:key="checkin-deny-back"
                            color="gray"
                            class="min-w-28"
                            wire:click="$set('checkInDenyStep', false)"
                        >
                            {{ __('app.reception.back') }}
                        </x-filament::button>

                        <x-filament::button
                            wire:key="checkin-deny-confirm"
                            color="gray"
                            icon="heroicon-m-x-mark"
                            size="md"
                            class="min-w-28"
                            wire:click="confirmDenyCheckIn"
                            wire:loading.attr="disabled"
                        >
                            {{ __('app.reception.checkin_deny_confirm') }}
                        </x-filament::button>
                    @elseif($checkInOverrideStep)
                        <x-filament::button
                            wire:key="checkin-override-back"
                            color="gray"
                            size="md"
                            class="min-w-28"
                            wire:click="$set('checkInOverrideStep', false)"
                        >
                            {{ __('app.reception.back') }}
                        </x-filament::button>

                        <x-filament::button
                            wire:key="checkin-override-confirm"
                            color="info"
                            icon="heroicon-m-wrench"
                            size="md"
                            class="min-w-28"
                            wire:click="confirmCheckInOverride"
                            wire:loading.attr="disabled"
                        >
                            {{ __('app.reception.override_confirm') }}
                        </x-filament::button>
                    @elseif($checkInOverrideDueDateStep)
                        <x-filament::button
                            wire:key="checkin-due-date-back"
                            color="gray"
                            size="md"
                            class="min-w-28"
                            wire:click="cancelDueDateChange"
                        >
                            {{ __('app.reception.back') }}
                        </x-filament::button>

                        <x-filament::button
                            wire:key="checkin-due-date-confirm"
                            color="info"
                            icon="heroicon-m-calendar-days"
                            size="md"
                            class="min-w-28"
                            wire:click="confirmDueDateChange"
                            wire:loading.attr="disabled"
                        >
                            {{ __('app.reception.override_due_date_confirm') }}
                        </x-filament::button>
                    @else
                        @if($checkInMember)
                            {{-- Actions use neutral/info styles, never the status palette —
                                 red/amber/green must only ever mean state, not action. --}}
                            <x-filament::button
                                wire:key="checkin-deny"
                                color="gray"
                                icon="heroicon-m-x-mark"
                                size="md"
                                class="min-w-28"
                                wire:click="denyCheckIn"
                            >
                                {{ __('app.reception.deny') }}
                            </x-filament::button>

                            @if($checkInSelectedRow && ($checkInSelectedRow['state'] ?? null) !== 'access')
                                <x-filament::button
                                    wire:key="checkin-override"
                                    color="info"
                                    icon="heroicon-m-wrench"
                                    size="md"
                                    class="min-w-28"
                                    wire:click="openCheckInOverrideFor({{ $checkInSelectedRow['id'] }})"
                                >
                                    {{ __('app.reception.override') }}
                                </x-filament::button>
                            @else
                                <x-filament::button
                                    wire:key="checkin-approve"
                                    color="success"
                                    size="md"
                                    class="min-w-28"
                                    wire:click="approveCheckIn"
                                    wire:loading.attr="disabled"
                                    :disabled="! $checkInSelectedRow || ($checkInSelectedRow['state'] ?? null) !== 'access'"
                                >
                                    {{ __('app.reception.approve') }}
                                </x-filament::button>
                            @endif
                        @endif
                    @endif
                </div>
            </x-slot>
        </x-filament::modal>
    </div>
@endif