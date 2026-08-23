@if($entry)
    @php
        $member = ($entry->kind === 'checkin' && ! empty($entry->payload['member_id']))
            ? \App\Models\Member::find($entry->payload['member_id'])
            : null;

        $name = $member?->name
            ?? $entry->payload['identifier_value']
            ?? ($entry->kind === 'checkin' ? __('app.reception.unknown_member') : __('app.reception.new_member'));

        $eligible = [];
        $hasLimited = false;
        if ($member) {
            $subs = app(\App\Services\Membership\PlanCheckInService::class)
                ->eligibleSubscriptions($member);
            $eligible = $subs->map(fn ($s) => [
                'name' => $s->plan?->name,
                'limited' => $s->plan?->daily_checkin_limit !== null,
            ])->filter(fn ($s) => $s['name'])->values()->all();
            $hasLimited = collect($eligible)->contains('limited', true);
        }
    @endphp

    <div
        wire:key="confirm-overlay-{{ $entry->id }}"
        x-data
        x-on:close-modal.window="if ($event.detail.id === 'confirm-overlay') $wire.closeConfirmOverlay()"
    >
        <x-filament::modal
            id="confirm-overlay"
            width="lg"
            :close-by-clicking-away="false"
            :close-by-escaping="false"
            :heading="match ($action) {
                'approve' => __('app.reception.confirm.approve_title'),
                'deny' => __('app.reception.confirm.deny_title'),
                default => __('app.reception.confirm.override_title'),
            }"
            :icon="match ($action) {
                'approve' => 'heroicon-m-check-circle',
                'deny' => 'heroicon-m-x-circle',
                default => 'heroicon-m-exclamation-triangle',
            }"
            :icon-color="match ($action) {
                'approve' => 'success',
                'deny' => 'danger',
                default => 'warning',
            }"
        >
            <div class="space-y-4">
                <div class="flex items-center gap-3">
                    @include('filament.pages.partials.member-avatar', ['member' => $member])

                    <div class="min-w-0">
                        <h3 class="fi-text text-base font-semibold">{{ $name }}</h3>
                        <p class="fi-text text-sm">
                            {{ $entry->kind === 'checkin' ? __('app.reception.tabs.checkin') : __('app.reception.tabs.signup') }}
                        </p>
                    </div>
                </div>

                @if($entry->kind === 'checkin' && $member)
                    @if(! empty($eligible))
                        <x-filament::badge :color="$hasLimited ? 'warning' : 'success'">
                            {{ __('app.reception.eligible_plan') }}: {{ implode(', ', array_column($eligible, 'name')) }}
                        </x-filament::badge>
                    @else
                        <x-filament::badge color="danger">
                            {{ __('app.reception.no_eligible_plans') }}
                        </x-filament::badge>
                    @endif
                @endif

                @if($action === 'deny')
                    <div>
                        <label for="deny-reason" class="fi-text text-sm font-medium">
                            {{ __('app.reception.deny_reason') }}
                        </label>
                        <x-filament::input.wrapper class="fi-fo-textarea mt-2">
                            <div class="h-20">
                                <textarea
                                    id="deny-reason"
                                    wire:model="denyReason"
                                    rows="3"
                                    placeholder="{{ __('app.reception.deny_reason_placeholder') }}"
                                ></textarea>
                            </div>
                        </x-filament::input.wrapper>
                    </div>
                @endif
            </div>

            <x-slot name="footer">
                <div class="flex w-full gap-3">
                    @php
                        [$confirmColor, $confirmLabel] = match ($action) {
                            'approve' => ['success', __('app.reception.confirm.approve_confirm')],
                            'deny' => ['danger', __('app.reception.confirm.deny_confirm')],
                            default => ['warning', __('app.reception.confirm.override_confirm')],
                        };
                    @endphp

                    <x-filament::button
                        :color="$confirmColor"
                        wire:click="confirm"
                        wire:loading.attr="disabled"
                        class="flex-1"
                    >
                        {{ $confirmLabel }}
                    </x-filament::button>

                    <x-filament::button
                        color="gray"
                        wire:click="closeConfirmOverlay"
                        class="flex-1"
                    >
                        {{ __('app.reception.cancel') }}
                    </x-filament::button>
                </div>
            </x-slot>
        </x-filament::modal>
    </div>
@endif