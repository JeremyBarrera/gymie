@php $verifyEntry = \App\Models\QueueEntry::find($selectedQueueEntryId);
    [$contactDial, $contactLocal] = $verifyEntry
        ? \App\Helpers\Helpers::parsePhoneField($verifyEntry->payload['contact'] ?? null)
        : ['', '']; @endphp

@if($verifyEntry)
    <div
        wire:key="verify-overlay-{{ $verifyEntry->id }}"
        x-data="{ photoReady: false }"
        x-on:close-modal.window="if ($event.detail.id === 'verify-overlay') $wire.closeVerifyOverlay()"
        x-on:verify-photo-ready="photoReady = $event.detail.ready"
        x-on:verify-overlay-closed.window="photoReady = false"
    >
        <x-filament::modal
            id="verify-overlay"
            width="3xl"
            :close-by-clicking-away="false"
            :close-by-escaping="false"
            :heading="__('app.reception.verify_title')"
            :description="($verifyEntry->payload['name'] ?? __('app.reception.new_member')) . ' · ' . $contactLocal"
        >
            @if($verifyStep === 1)
                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-y-1.5">
                        <label for="verify-name" class="fi-text text-sm font-medium">
                            {{ __('app.fields.name') }}
                        </label>
                        <x-filament::input.wrapper>
                            <x-filament::input type="text" id="verify-name" wire:model="verifyForm.name" maxlength="255" />
                        </x-filament::input.wrapper>
                    </div>

                    <div class="grid gap-y-1.5">
                        <label for="verify-contact" class="fi-text text-sm font-medium">
                            {{ __('app.fields.contact') }}
                        </label>
                        @include('checkin.partials.phone-field', ['wireModel' => 'verifyForm.contact', 'id' => 'verify-contact', 'required' => true, 'filament' => true, 'dialCode' => $verifyForm['contact_dial_code'] ?? null])
                    </div>

                    <div class="grid gap-y-1.5">
                        <label for="verify-government-id" class="fi-text text-sm font-medium">
                            {{ __('app.fields.government_id') }}
                        </label>
                        <x-filament::input.wrapper>
                            <x-filament::input type="text" id="verify-government-id" wire:model="verifyForm.government_id" maxlength="100" />
                        </x-filament::input.wrapper>
                    </div>

                    <div class="grid gap-y-1.5">
                        <label for="verify-email" class="fi-text text-sm font-medium">
                            {{ __('app.fields.email') }}
                        </label>
                        <x-filament::input.wrapper>
                            <x-filament::input type="email" id="verify-email" wire:model="verifyForm.email" maxlength="255" />
                        </x-filament::input.wrapper>
                    </div>

                    <div class="grid gap-y-1.5">
                        <label for="verify-gender" class="fi-text text-sm font-medium">
                            {{ __('app.fields.gender') }}
                        </label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select id="verify-gender" wire:model="verifyForm.gender">
                                <option value="male">{{ __('app.options.gender.male') }}</option>
                                <option value="female">{{ __('app.options.gender.female') }}</option>
                                <option value="other">{{ __('app.options.gender.other') }}</option>
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                    </div>

                    <div class="grid gap-y-1.5">
                        <label for="verify-dob" class="fi-text text-sm font-medium">
                            {{ __('app.fields.dob') }}
                        </label>
                        <x-filament::input.wrapper>
                            <x-filament::input type="date" id="verify-dob" wire:model="verifyForm.dob" min="1900-01-01" max="3000-01-01" />
                        </x-filament::input.wrapper>
                    </div>

                    <div class="grid gap-y-1.5">
                        <label for="verify-emergency" class="fi-text text-sm font-medium">
                            {{ __('app.fields.emergency_contact') }}
                        </label>
                        @include('checkin.partials.phone-field', ['wireModel' => 'verifyForm.emergency_contact', 'id' => 'verify-emergency', 'filament' => true, 'dialCode' => $verifyForm['emergency_contact_dial_code'] ?? null])
                    </div>

                    <div class="grid gap-y-1.5">
                        <label for="verify-goal" class="fi-text text-sm font-medium">
                            {{ __('app.fields.goal') }}
                        </label>
                        <x-filament::input.wrapper>
                            <x-filament::input type="text" id="verify-goal" wire:model="verifyForm.goal" maxlength="100" />
                        </x-filament::input.wrapper>
                    </div>

                    <div class="grid gap-y-1.5 sm:col-span-2">
                        <label for="verify-health-issues" class="fi-text text-sm font-medium">
                            {{ __('app.fields.health_issues') }}
                        </label>
                        <x-filament::input.wrapper class="fi-fo-textarea">
                            <div class="h-20">
                                <textarea
                                    id="verify-health-issues"
                                    wire:model="verifyForm.health_issue"
                                    rows="2"
                                    maxlength="500"
                                ></textarea>
                            </div>
                        </x-filament::input.wrapper>
                    </div>
                </div>

                <x-slot name="footer">
                    <div class="flex w-full gap-3">
                        <x-filament::button
                            wire:key="verify-cancel"
                            color="gray"
                            wire:click="closeVerifyOverlay"
                            class="flex-1"
                        >
                            {{ __('app.reception.cancel') }}
                        </x-filament::button>

                        <x-filament::button
                            wire:key="verify-continue"
                            wire:click="verifyContinue"
                            class="flex-1"
                        >
                            {{ __('app.reception.verify_continue') }}
                        </x-filament::button>
                    </div>
                </x-slot>
            @elseif($verifyStep === 2)
                <div class="space-y-4">
                    <p class="fi-text text-sm">{{ __('app.reception.verify_photo_hint') }}</p>

                    <div class="verify-camera-wrap relative mx-auto max-w-md overflow-hidden rounded-xl">
                        <video id="verify-camera" class="verify-camera" playsinline muted wire:ignore></video>
                        <img id="verify-photo-preview" class="verify-photo-preview" alt="" style="display:none" wire:ignore>
                        <div id="verify-camera-fallback" class="verify-camera-fallback absolute inset-0 flex items-center justify-center p-4" style="display:none" wire:ignore>
                            {{ __('app.reception.verify_no_camera') }}
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center justify-center gap-3">
                        <x-filament::button
                            id="verify-camera-capture"
                            color="success"
                            disabled
                            wire:ignore
                            onclick="window.verifyCamera && window.verifyCamera.capturePhoto(this)"
                        >
                            {{ __('app.reception.verify_photo_capture') }}
                        </x-filament::button>

                        <x-filament::button
                            id="verify-camera-retake"
                            style="display:none"
                            wire:ignore
                            onclick="window.verifyCamera && window.verifyCamera.retake(this)"
                        >
                            {{ __('app.reception.verify_photo_retake') }}
                        </x-filament::button>

                        <x-filament::button
                            tag="label"
                            color="danger"
                            class="verify-upload-label"
                        >
                            {{ __('app.reception.verify_photo_upload') }}
                            <input type="file" accept="image/jpeg,image/png,image/webp" hidden
                                onchange="window.verifyCamera && window.verifyCamera.handlePhotoFile(this)">
                        </x-filament::button>
                    </div>
                </div>

                <x-slot name="footer">
                    <div class="flex w-full gap-3">
                        <x-filament::button
                            wire:key="verify-back"
                            color="gray"
                            wire:click="verifyBack"
                            class="flex-1"
                        >
                            {{ __('app.reception.verify_back') }}
                        </x-filament::button>

                        <x-filament::button
                            wire:key="verify-to-plan"
                            color="success"
                            wire:click="verifyContinue"
                            class="flex-1"
                            x-show="photoReady"
                        >
                            {{ __('app.reception.verify_continue_to_plan') }}
                        </x-filament::button>
                    </div>
                </x-slot>
            @elseif($verifyStep === 3)
                <div class="space-y-4">
                    <p class="fi-text text-sm">{{ __('app.reception.verify_plan_hint') }}</p>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-y-1.5 sm:col-span-2">
                            <label for="verify-plan" class="fi-text text-sm font-medium">
                                {{ __('app.fields.plan') }}
                            </label>
                            <x-filament::input.wrapper>
                                <x-filament::input.select id="verify-plan" wire:model.live="verifyForm.sale.plan_id">
                                    @foreach($this->verifyPlans as $planId => $label)
                                        <option value="{{ $planId }}" @if($loop->first) selected @endif>{{ $label }}</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </div>

                        <div class="grid gap-y-1.5">
                            <label for="verify-quantity" class="fi-text text-sm font-medium">
                                {{ __('app.fields.quantity') }}
                            </label>
                            <x-filament::input.wrapper>
                                <x-filament::input type="number" id="verify-quantity" class="verify-money-input" min="1" step="1" wire:model.live.debounce.500ms="verifyForm.sale.quantity" />
                            </x-filament::input.wrapper>
                        </div>

                        <div class="grid gap-y-1.5">
                            <label for="verify-start-date" class="fi-text text-sm font-medium">
                                {{ __('app.fields.start_date') }}
                            </label>
                            <x-filament::input.wrapper>
                                <x-filament::input type="date" id="verify-start-date" wire:model.live="verifyForm.sale.start_date" min="1900-01-01" max="3000-01-01" />
                            </x-filament::input.wrapper>
                        </div>

                        <div class="grid gap-y-1.5 sm:col-span-2">
                            <label for="verify-end-date" class="fi-text text-sm font-medium">
                                {{ __('app.fields.end_date') }}
                            </label>
                            <x-filament::input.wrapper>
                                <x-filament::input type="date" id="verify-end-date" wire:model="verifyForm.sale.end_date" disabled min="1900-01-01" max="3000-01-01" />
                            </x-filament::input.wrapper>
                        </div>

                        <div class="grid gap-y-1.5 sm:col-span-2">
                            <span class="fi-text text-sm font-medium">{{ __('app.fields.payment_method') }}</span>
                            <div class="grid grid-cols-3 gap-3">
                                @foreach($this->verifyPaymentMethods as $method => $label)
                                    <label class="flex cursor-pointer items-center gap-2">
                                        <input type="radio" wire:model.live="verifyForm.sale.payment_method" value="{{ $method }}" />
                                        <span class="fi-text text-sm">{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        <div class="grid gap-y-1.5">
                            <label for="verify-discount" class="fi-text text-sm font-medium">
                                {{ __('app.fields.discount_amount') }}
                            </label>
                            <x-filament::input.wrapper>
                                <x-filament::input type="number" id="verify-discount" class="verify-money-input" min="0" wire:model.live.debounce.500ms="verifyForm.sale.discount_amount" />
                            </x-filament::input.wrapper>
                        </div>

                        <div class="grid gap-y-1.5">
                            <label for="verify-paid" class="fi-text text-sm font-medium">
                                {{ __('app.fields.paid_amount') }}
                            </label>
                            <x-filament::input.wrapper>
                                <x-filament::input type="number" id="verify-paid" class="verify-money-input" min="0" wire:model.live.debounce.500ms="verifyForm.sale.paid_amount" />
                            </x-filament::input.wrapper>
                        </div>
                    </div>

                    <x-filament::section>
                        <dl class="grid gap-x-6 gap-y-2 sm:grid-cols-2">
                            @foreach(['fee', 'tax', 'total', 'due'] as $saleRowKey)
                                <div class="flex min-w-0 items-baseline justify-between gap-3">
                                    <dt class="fi-text-muted whitespace-nowrap text-sm">{{ __("app.fields.{$saleRowKey}") }}</dt>
                                    <dd class="fi-text min-w-0 truncate text-sm font-medium">{{ \App\Helpers\Helpers::getCurrencySymbol() }} {{ number_format((float) ($verifyForm['sale'][$saleRowKey] ?? 0), 2) }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </x-filament::section>

                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" wire:model.live="verifyCheckIn" class="fi-checkbox-input rounded shadow-sm" />
                        <span class="fi-text text-sm font-medium">{{ __('app.reception.verify_checkin_toggle') }}</span>
                    </label>
                </div>

                <x-slot name="footer">
                    <div class="flex w-full gap-3">
                        <x-filament::button
                            wire:key="verify-back"
                            color="gray"
                            wire:click="verifyBack"
                            class="flex-1"
                        >
                            {{ __('app.reception.verify_back') }}
                        </x-filament::button>

                        <x-filament::button
                            wire:key="verify-create"
                            color="success"
                            wire:click="confirmSignup"
                            class="flex-1"
                        >
                            {{ $verifyCheckIn ? __('app.reception.verify_checkin_save') : __('app.reception.verify_save') }}
                        </x-filament::button>
                    </div>
                </x-slot>
            @elseif($verifyStep === 4)
                @php $verifyCheckInServices = $this->verifyCheckInServices;
                    $verifyCheckInSelectedRow = collect($verifyCheckInServices)->firstWhere('id', $verifyCheckInServiceId); @endphp

                <div class="space-y-4">
                    <div class="flex items-center gap-2">
                        <x-filament::badge color="success">
                            <x-filament::icon icon="heroicon-m-check-circle" :size="\Filament\Support\Enums\IconSize::Small" />
                        </x-filament::badge>
                        <h3 class="fi-text text-base font-semibold">{{ __('app.reception.verify_checkin_title') }}</h3>
                    </div>

                    <p class="fi-text text-sm leading-relaxed">
                        {{ __('app.reception.verify_checkin_hint', ['name' => $verifyCreatedMember?->name ?? '']) }}
                    </p>

                    <div class="space-y-3">
                        <label for="verify-checkin-service" class="fi-text text-sm font-medium">
                            {{ __('app.fields.service') }}
                        </label>

                        @if(empty($verifyCheckInServices))
                            <x-filament::section compact>
                                <p class="fi-text fi-text-muted text-sm">{{ __('app.reception.no_eligible_subscription') }}</p>
                            </x-filament::section>
                        @else
                            <x-filament::input.wrapper>
                                <x-filament::input.select
                                    id="verify-checkin-service"
                                    wire:model.live="verifyCheckInServiceId"
                                >
                                    @foreach($verifyCheckInServices as $row)
                                        <option value="{{ $row['id'] }}">
                                            {{ $row['name'] }} — {{ __('app.reception.service_access') }}
                                        </option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        @endif
                    </div>
                </div>

                <x-slot name="footer">
                    <div class="flex w-full gap-3">
                        <x-filament::button
                            wire:key="verify-checkin-confirm"
                            color="success"
                            wire:click="confirmVerifyCheckIn"
                            class="flex-1"
                            :disabled="! $verifyCheckInServiceId"
                            wire:loading.attr="disabled"
                        >
                            {{ __('app.reception.approve') }}
                        </x-filament::button>
                    </div>
                </x-slot>
            @endif
        </x-filament::modal>
    </div>
@endif