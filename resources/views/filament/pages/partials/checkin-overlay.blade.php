@php
    $checkInEntry = $selectedCheckInEntryId ? \App\Models\QueueEntry::find($selectedCheckInEntryId) : null;
    $checkInCandidateIds = $checkInEntry
        ? collect($checkInEntry->payload['candidate_member_ids'] ?? [])
        : collect($this->manualCheckInCandidates);
    $checkInCandidates = $checkInCandidateIds
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
    // ok (success) < attention (unpaid / expiring) < blocked (overdue / expired / no access / uses exhausted).
    // The photo border always shows the WORST applicable status across the
    // plan-expiry badge and every payment/service state, so border and
    // badges can never disagree.
    $cardSeverityRank = function (string $stateOrColor): int {
        return match (true) {
            in_array($stateOrColor, ['overdue', 'expired', 'no_access', 'uses_exhausted', 'danger'], true) => 2,
            in_array($stateOrColor, ['unpaid', 'warning', 'same_day_duplicate'], true) => 1,
            default => 0,
        };
    };

    $serviceRanks = collect($checkInServices)
        ->map(fn (array $row): int => $cardSeverityRank((string) ($row['state'] ?? 'access')));
    $planRank = $cardSeverityRank((string) ($checkInStatus['color'] ?? 'gray'));

    // Only statuses APPLICABLE to this attendance decision may darken the
    // card: the selected service's own state, plus a member-wide overdue
    // invoice (which blocks every service). Non-entitled rows elsewhere in
    // the picker must not paint the whole member red.
    $selectedRowRank = $checkInSelectedRow !== null
        ? $cardSeverityRank((string) ($checkInSelectedRow['state'] ?? 'access'))
        : 0;
    $overdueRow = collect($checkInServices)
        ->first(fn (array $row): bool => ($row['state'] ?? null) === 'overdue');

    $serviceRank = max($selectedRowRank, $overdueRow !== null ? 2 : 0);
    $worstServiceRow = $selectedRowRank >= ($overdueRow !== null ? 2 : 0)
        ? $checkInSelectedRow
        : $overdueRow;

    // The plan-expiry badge and the worst payment/service badge share one
    // severity scale — whichever wins paints the border.
    $planWins = $planRank >= $serviceRank;
    $cardRank = max($planRank, $serviceRank);
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
            'uses_exhausted' => __('app.reception.service_uses_exhausted_short'),
            'same_day_duplicate' => __('app.reception.service_same_day_duplicate_short'),
            'unpaid' => __('app.reception.service_unpaid_short'),
            default => (string) ($checkInStatus['label'] ?? ''),
        }
        : (string) ($checkInStatus['label'] ?? '');

    // One shared state→label map for the service picker options and the
    // selected-service badge below.
    $serviceStateLabel = fn (?string $state): string => match ($state ?? 'access') {
        'access' => __('app.reception.service_access'),
        'unpaid' => __('app.reception.service_unpaid_short'),
        'overdue' => __('app.reception.service_overdue_short'),
        'expired' => __('app.reception.service_expired_short'),
        'uses_exhausted' => __('app.reception.service_uses_exhausted_short'),
        'same_day_duplicate' => __('app.reception.service_same_day_duplicate_short'),
        default => __('app.reception.service_no_access_short'),
    };

    // Same severity scale as the photo border: blocked states are red,
    // attention is amber.
    $selectedStateColor = match ($checkInSelectedRow['state'] ?? 'access') {
        'unpaid', 'same_day_duplicate' => 'warning',
        'overdue', 'expired', 'no_access', 'uses_exhausted' => 'danger',
        default => 'success',
    };

    // Member card detail columns (label/value pairs; null values are hidden).
    $memberDetailsLeft = [
        ['label' => __('app.fields.member_id'), 'value' => $checkInMember?->code],
        ['label' => __('app.fields.contact'), 'value' => $checkInMember?->contact],
        ['label' => __('app.fields.email'), 'value' => $checkInMember?->email, 'break' => true],
    ];
    $memberDetailsRight = [
        ['label' => __('app.fields.gender'), 'value' => filled($checkInMember?->gender) ? __('app.options.gender.'.$checkInMember->gender) : null],
        ['label' => __('app.fields.dob'), 'value' => filled($checkInMember?->dob) ? \App\Support\Dates\DeviceDateFormat::format($checkInMember->dob) : null],
    ];

    // Footer step: which back/confirm pair to render (null = member actions).
    $footerStep = $checkInDenyStep
        ? 'deny'
        : ($checkInOverrideStep ? 'override' : null);

    $footerBackActions = [
        'deny' => "\$set('checkInDenyStep', false)",
        'override' => "\$set('checkInOverrideStep', false)",
    ];

    $footerConfirmMeta = [
        'deny' => [
            'color' => 'gray',
            'icon' => 'heroicon-m-x-mark',
            'label' => __('app.reception.checkin_deny_confirm'),
            'action' => 'confirmDenyCheckIn',
        ],
        'override' => [
            'color' => 'info',
            'icon' => 'heroicon-m-wrench',
            'label' => __('app.reception.override_confirm'),
            'action' => 'confirmCheckInOverride',
        ],
    ];
@endphp

@if($showCheckInOverlay)
    <div
        wire:key="checkin-overlay-{{ $checkInEntry?->id ?? 'manual' }}"
        x-data
        x-on:close-modal.window="if ($event.detail.id === 'checkin-overlay') $wire.closeCheckInOverlay()"
    >
        <x-filament::modal
            id="checkin-overlay"
            width="3xl"
            :close-by-clicking-away="false"
            :close-by-escaping="false"
            :heading="$checkInDenyStep
                ? __('app.reception.checkin_deny_title')
                : ($checkInOverrideStep
                    ? __('app.reception.override_confirm_title')
                    : __('app.reception.checkin_overlay_title'))"
            :description="$checkInDenyStep
                ? __('app.reception.deny_reason')
                : ($checkInOverrideStep
                    ? __('app.reception.override_hint')
                    : __('app.reception.checkin_overlay_hint'))"
        >            <div class="space-y-6">
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
                            {{ __('app.check_in.override_message_optional') }}
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
                                        @include('filament.pages.partials.member-avatar', ['member' => $candidate])

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
                                    class="h-64 w-52 rounded-xl object-cover cursor-pointer"
                                    style="box-shadow: 0 0 0 3px {{ $cardRingVar }};"
                                    alt="{{ $checkInMember->name }}"
                                    onclick='event.preventDefault(); event.stopPropagation(); window.dispatchEvent(new CustomEvent("open-photo-zoom", { detail: { src: @js(asset('storage/'.$checkInMember->photo)), alt: @js($checkInMember->name) } }))'
                                >
                            @else
                                <div class="flex h-64 w-52 flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-(--gray-300)"
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
                                @foreach([$memberDetailsLeft, $memberDetailsRight] as $memberDetailsColumn)
                                    <div class="space-y-4">
                                        @foreach($memberDetailsColumn as $detail)
                                            @if(filled($detail['value']))
                                                <div class="space-y-1">
                                                    <span class="fi-text-muted text-xs font-medium uppercase tracking-wide">{{ $detail['label'] }}</span>
                                                    <p class="fi-text text-base font-medium {{ ($detail['break'] ?? false) ? 'break-all' : '' }}">{{ $detail['value'] }}</p>
                                                </div>
                                            @endif
                                        @endforeach
                                    </div>
                                @endforeach
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
                                            {{ $row['name'] }} — {{ $serviceStateLabel($row['state']) }}
                                        </option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>

                            @if($checkInSelectedRow)
                                <div class="flex flex-wrap items-center gap-3">
                                    <x-filament::badge :color="$selectedStateColor" size="md">
                                        {{ $serviceStateLabel($checkInSelectedRow['state'] ?? null) }}
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
                    @if($footerStep !== null)
                        <x-filament::button
                            wire:key="checkin-{{ $footerStep }}-back"
                            color="gray"
                            size="md"
                            class="min-w-28"
                            wire:click="{{ $footerBackActions[$footerStep] }}"
                        >
                            {{ __('app.reception.back') }}
                        </x-filament::button>

                        <x-filament::button
                            wire:key="checkin-{{ $footerStep }}-confirm"
                            :color="$footerConfirmMeta[$footerStep]['color']"
                            :icon="$footerConfirmMeta[$footerStep]['icon']"
                            size="md"
                            class="min-w-28"
                            wire:click="{{ $footerConfirmMeta[$footerStep]['action'] }}"
                            wire:loading.attr="disabled"
                        >
                            {{ $footerConfirmMeta[$footerStep]['label'] }}
                        </x-filament::button>
                    @else
                        @if($checkInMember)
                            {{-- Actions use neutral/info/success styles, never the status palette —
                                 red/amber/green must only ever mean state, not action.
                                 The action set is driven by the selected service's state:
                                 access → approve · expired → renewal popup (O3) ·
                                 no_access → optional-message override (O4) ·
                                 unpaid/overdue → payment / due-date popups (O5). --}}
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

                            @if(! $checkInSelectedRow || ($checkInSelectedRow['state'] ?? null) === 'access')
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
                            @elseif(($checkInSelectedRow['state'] ?? null) === 'expired')
                                <x-filament::button
                                    wire:key="checkin-renew"
                                    color="success"
                                    icon="heroicon-m-plus-circle"
                                    size="md"
                                    class="min-w-28"
                                    wire:click="openExpiredSubscriptionModal({{ $checkInSelectedRow['id'] }})"
                                    wire:loading.attr="disabled"
                                >
                                    {{ __('app.check_in.add_subscription') }}
                                </x-filament::button>
                            @elseif(($checkInSelectedRow['state'] ?? null) === 'uses_exhausted')
                                <x-filament::button
                                    wire:key="checkin-renew"
                                    color="success"
                                    icon="heroicon-m-plus-circle"
                                    size="md"
                                    class="min-w-28"
                                    wire:click="openExpiredSubscriptionModal({{ $checkInSelectedRow['id'] }})"
                                    wire:loading.attr="disabled"
                                >
                                    {{ __('app.check_in.add_subscription') }}
                                </x-filament::button>

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
                            @elseif(($checkInSelectedRow['state'] ?? null) === 'no_access')
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
                            @elseif(($checkInSelectedRow['state'] ?? null) === 'same_day_duplicate')
                                <x-filament::button
                                    wire:key="checkin-deny-same-day"
                                    color="gray"
                                    icon="heroicon-m-x-mark"
                                    size="md"
                                    class="min-w-28"
                                    wire:click="denySameDayDuplicateCheckIn"
                                    wire:loading.attr="disabled"
                                >
                                    {{ __('app.reception.deny') }}
                                </x-filament::button>

                                <x-filament::button
                                    wire:key="checkin-same-day-approve"
                                    color="warning"
                                    icon="heroicon-m-check"
                                    size="md"
                                    class="min-w-28"
                                    wire:click="confirmSameDayDuplicateCheckIn"
                                    wire:loading.attr="disabled"
                                >
                                    {{ __('app.reception.same_day_duplicate_check_in') }}
                                </x-filament::button>
                            @else
                                <x-filament::button
                                    wire:key="checkin-add-payment"
                                    color="info"
                                    icon="heroicon-m-banknotes"
                                    size="md"
                                    class="min-w-28"
                                    wire:click="openAddPaymentModal({{ $checkInSelectedRow['id'] }})"
                                    wire:loading.attr="disabled"
                                >
                                    {{ __('app.check_in.add_payment') }}
                                </x-filament::button>

                                <x-filament::button
                                    wire:key="checkin-change-due-date"
                                    color="gray"
                                    icon="heroicon-m-calendar-days"
                                    size="md"
                                    class="min-w-28"
                                    wire:click="openChangeDueDateModal({{ $checkInSelectedRow['id'] }})"
                                    wire:loading.attr="disabled"
                                >
                                    {{ __('app.check_in.change_due_date') }}
                                </x-filament::button>
                            @endif
                        @endif
                    @endif
                </div>
            </x-slot>
        </x-filament::modal>

        {{-- Override-path popups (O3/O5): mounted while the overlay is open,
             opened on top of it via the open-modal dispatch, and closed
             together with the overlay on success. --}}
        @livewire(\App\Filament\Livewire\ExpiredSubscriptionModal::class, [], key('livewire-expired-subscription-modal'))
        @livewire(\App\Filament\Livewire\AddPaymentModal::class, [], key('livewire-add-payment-modal'))
        @livewire(\App\Filament\Livewire\ChangeDueDateModal::class, [], key('livewire-change-due-date-modal'))
    </div>
@endif