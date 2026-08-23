<div>
    <x-filament::modal
        id="add-payment-modal"
        width="md"
        :close-by-clicking-away="false"
        :heading="__('app.check_in.add_payment')"
        :description="__('app.fields.due_date').': '.($this->invoice?->due_date?->format('Y-m-d') ?? '—')"
    >
        <div class="space-y-5">
            <div>
                <label for="payment-amount" class="fi-text text-base font-semibold">
                    {{ __('app.fields.amount_with_currency', ['currency' => \App\Helpers\Helpers::getCurrencyCode()]) }}
                </label>
                <x-filament::input.wrapper class="mt-2">
                    <x-filament::input
                        id="payment-amount"
                        type="number"
                        min="0.01"
                        step="0.01"
                        wire:model.live="amount"
                        class="verify-money-input"
                        placeholder="0.00"
                    />
                </x-filament::input.wrapper>
                @error('amount') <p class="fi-text mt-1 text-sm text-danger-500">{{ $message }}</p> @enderror

                <p class="fi-text fi-text-muted mt-2 text-sm">
                    {{ __('app.help.due_amount', ['amount' => \App\Helpers\Helpers::formatCurrency($this->projectedRemaining)]) }}
                </p>
            </div>

            <div>
                <label for="payment-method" class="fi-text text-base font-semibold">
                    {{ __('app.fields.payment_method') }}
                </label>
                <x-filament::input.wrapper class="mt-2">
                    <x-filament::input.select id="payment-method" wire:model="paymentMethod">
                        @foreach(\App\Support\Billing\PaymentMethod::options() as $methodValue => $methodLabel)
                            <option value="{{ $methodValue }}" @selected($methodValue === $paymentMethod)>
                                {{ $methodLabel }}
                            </option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
                @error('paymentMethod') <p class="fi-text mt-1 text-sm text-danger-500">{{ $message }}</p> @enderror
            </div>

            @if($this->projectedRemaining > 0)
                <div class="space-y-4 rounded-xl border border-(--gray-200) p-4">
                    <div>
                        <label for="payment-next-due" class="fi-text text-base font-semibold">
                            {{ __('app.check_in.next_payment_due') }}
                        </label>
                        <x-filament::input.wrapper class="mt-2">
                            <x-filament::input
                                id="payment-next-due"
                                type="date"
                                wire:model="nextDueDate"
                            />
                        </x-filament::input.wrapper>
                        <p class="fi-text fi-text-muted mt-1 text-xs">{{ __('app.check_in.next_payment_due_required') }}</p>
                        @error('nextDueDate') <p class="fi-text mt-1 text-sm text-danger-500">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="payment-reason" class="fi-text text-base font-semibold">
                            {{ __('app.check_in.override_message_optional') }}
                        </label>
                        <x-filament::input.wrapper class="fi-fo-textarea mt-2">
                            <div class="h-16">
                                <textarea
                                    id="payment-reason"
                                    wire:model="reason"
                                    rows="2"
                                ></textarea>
                            </div>
                        </x-filament::input.wrapper>
                    </div>
                </div>
            @endif
        </div>

        <x-slot name="footer">
            @if($this->projectedRemaining > 0)
                {{-- Balance remains: this confirm records the payment and
                     completes an override-semantics check-in. --}}
                <x-filament::button
                    wire:key="add-payment-partial-submit"
                    color="info"
                    icon="heroicon-m-wrench"
                    size="md"
                    class="w-full"
                    wire:click="submit"
                    wire:loading.attr="disabled"
                    wire:target="submit"
                >
                    {{ __('app.reception.override_confirm') }}
                </x-filament::button>
            @else
                <x-filament::button
                    wire:key="add-payment-full-submit"
                    color="success"
                    icon="heroicon-m-check-circle"
                    size="md"
                    class="w-full"
                    wire:click="submit"
                    wire:loading.attr="disabled"
                    wire:target="submit"
                >
                    {{ __('app.check_in.paid_in_full_checkin') }}
                </x-filament::button>
            @endif
        </x-slot>
    </x-filament::modal>
</div>
