<div>
    <x-filament::modal
        id="change-due-date-modal"
        width="md"
        :close-by-clicking-away="false"
        :heading="__('app.check_in.change_due_date')"
        :description="__('app.reception.override_due_date_hint')"
    >
        <div class="space-y-5">
            <div>
                <label for="change-due-date" class="fi-text text-base font-semibold">
                    {{ __('app.fields.due_date') }}
                </label>
                <x-filament::input.wrapper class="mt-2">
                    <x-filament::input
                        id="change-due-date"
                        type="date"
                        wire:model.live="newDueDate"
                    />
                </x-filament::input.wrapper>
            </div>

            @if($this->canConfirm)
                <div>
                    <label for="due-date-reason" class="fi-text text-base font-semibold">
                        {{ __('app.check_in.override_message_optional') }}
                    </label>
                    <x-filament::input.wrapper class="fi-fo-textarea mt-2">
                        <div class="h-16">
                            <textarea
                                id="due-date-reason"
                                wire:model="reason"
                                rows="2"
                            ></textarea>
                        </div>
                    </x-filament::input.wrapper>
                    @error('reason') <p class="fi-text mt-1 text-sm text-danger-500">{{ $message }}</p> @enderror
                </div>
            @endif
        </div>

        <x-slot name="footer">
            <x-filament::button
                wire:key="change-due-date-submit"
                color="info"
                icon="heroicon-m-calendar-days"
                size="md"
                class="w-full"
                wire:click="confirm"
                wire:loading.attr="disabled"
                wire:target="confirm"
                :disabled="! $this->canConfirm"
            >
                {{ __('app.reception.override_due_date_confirm') }}
            </x-filament::button>
        </x-slot>
    </x-filament::modal>
</div>
