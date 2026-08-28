<div>
    <x-filament::modal
        id="expired-subscription-modal"
        width="lg"
        :close-by-clicking-away="false"
        :heading="__('app.check_in.add_subscription')"
        :description="__('app.check_in.add_subscription_hint')"
    >
        <div class="space-y-5">
            <div>
                <label for="expired-plan" class="fi-text text-base font-semibold">
                    {{ __('app.fields.plan') }}
                </label>
                <x-filament::input.wrapper class="mt-2">
                    <x-filament::input.select id="expired-plan" wire:model="planId">
                        @foreach($this->planOptions as $optionId => $label)
                            <option value="{{ $optionId }}" @selected((int) $optionId === (int) $planId)>
                                {{ $label }}
                            </option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
                @error('planId') <p class="fi-text mt-1 text-sm text-danger-500">{{ $message }}</p> @enderror
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="expired-start-date" class="fi-text text-base font-semibold">
                        {{ __('app.fields.start_date') }}
                    </label>
                    <x-filament::input.wrapper class="mt-2">
                        <x-filament::input
                            id="expired-start-date"
                            type="date"
                            wire:model="startDate"
                        />
                    </x-filament::input.wrapper>
                    @error('startDate') <p class="fi-text mt-1 text-sm text-danger-500">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="expired-end-date" class="fi-text text-base font-semibold">
                        {{ __('app.fields.end_date') }}
                    </label>
                    <x-filament::input.wrapper class="mt-2">
                        <x-filament::input
                            id="expired-end-date"
                            type="date"
                            wire:model="endDate"
                            disabled
                        />
                    </x-filament::input.wrapper>
                    <p class="fi-text fi-text-muted mt-1 text-xs">{{ __('app.help.end_date_derived') }}</p>
                    @error('endDate') <p class="fi-text mt-1 text-sm text-danger-500">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label for="expired-payment-method" class="fi-text text-base font-semibold">
                    {{ __('app.fields.payment_method') }}
                </label>
                <x-filament::input.wrapper class="mt-2">
                    <x-filament::input.select id="expired-payment-method" wire:model="paymentMethod">
                        @foreach(\App\Support\Billing\PaymentMethod::options() as $methodValue => $methodLabel)
                            <option value="{{ $methodValue }}" @selected($methodValue === $paymentMethod)>
                                {{ $methodLabel }}
                            </option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
                @error('paymentMethod') <p class="fi-text mt-1 text-sm text-danger-500">{{ $message }}</p> @enderror
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="expired-discount" class="fi-text text-base font-semibold">
                        {{ __('app.fields.discount') }}
                    </label>
                    <x-filament::input.wrapper class="mt-2">
                        <x-filament::input
                            id="expired-discount"
                            type="number"
                            min="0"
                            step="0.01"
                            wire:model="discountAmount"
                            class="verify-money-input"
                            placeholder="0.00"
                        />
                    </x-filament::input.wrapper>
                    @error('discountAmount') <p class="fi-text mt-1 text-sm text-danger-500">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="expired-paid" class="fi-text text-base font-semibold">
                        {{ __('app.fields.paid_amount') }}
                    </label>
                    <x-filament::input.wrapper class="mt-2">
                        <x-filament::input
                            id="expired-paid"
                            type="number"
                            min="0"
                            step="0.01"
                            wire:model="paidAmount"
                            class="verify-money-input"
                            placeholder="0.00"
                        />
                    </x-filament::input.wrapper>
                    @error('paidAmount') <p class="fi-text mt-1 text-sm text-danger-500">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        <x-slot name="footer">
            <div class="flex w-full gap-3">
                <x-filament::button
                    wire:key="expired-submit"
                    color="success"
                    size="md"
                    class="flex-1"
                    wire:click="submit"
                    wire:loading.attr="disabled"
                    wire:target="submit"
                >
                    {{ __('app.check_in.add_subscription') }}
                </x-filament::button>
                <x-filament::button
                    wire:key="expired-submit-add-another"
                    color="gray"
                    size="md"
                    class="flex-1"
                    wire:click="submitAndAddAnother"
                    wire:loading.attr="disabled"
                    wire:target="submitAndAddAnother"
                >
                    {{ __('app.actions.save_add_another') }}
                </x-filament::button>
            </div>
        </x-slot>
    </x-filament::modal>
</div>
