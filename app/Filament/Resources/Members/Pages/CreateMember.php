<?php

namespace App\Filament\Resources\Members\Pages;

use App\Filament\Pages\MemberOnboardingStep2;
use App\Filament\Resources\Members\MemberResource;
use App\Models\Enquiry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Illuminate\Validation\ValidationException;

class CreateMember extends CreateRecord
{
    protected static string $resource = MemberResource::class;

    protected static bool $canCreateAnother = false;

    public ?int $enquiryId = null;

    public function mount(): void
    {
        parent::mount();

        if ($id = Request::query('enquiry_id')) {
            $this->enquiryId = (int) $id;

            $enquiry = Enquiry::find($this->enquiryId);
            if ($enquiry) {
                $this->form->fill([
                    ...($this->data ?? []),
                    'name' => $enquiry->name,
                    'email' => $enquiry->email,
                    'contact' => $enquiry->contact,
                    'gender' => $enquiry->gender,
                    'dob' => $enquiry->dob,
                    'source' => $enquiry->source,
                    'goal' => $enquiry->goal,
                ]);
            }
        }
    }

    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data): Model {
            if (blank($data['plan_id'] ?? null)) {
                throw ValidationException::withMessages([
                    'plan_id' => __('app.reception.verify_sale_required'),
                ]);
            }

            
            
            
            $member = parent::handleRecordCreation($data);

            $today = now()->toDateString();

            MemberOnboardingStep2::createSale($member, [
                'plan_id' => (int) $data['plan_id'],
                'start_date' => (string) ($data['start_date'] ?? $today),
                'end_date' => $data['end_date'] ?: null,
                'invoices' => [[
                    'date' => $today,
                    'due_date' => $today,
                    'payment_method' => (string) ($data['payment_method'] ?? 'cash'),
                    'discount' => 0,
                    'discount_amount' => (float) ($data['discount_amount'] ?? 0),
                    'discount_note' => null,
                    'paid_amount' => (float) ($data['paid_amount'] ?? 0),
                ]],
            ]);

            return $member;
        });
    }

    protected function getRedirectUrl(): string
    {
        return MemberResource::getUrl('view', ['record' => $this->record->id]);
    }

    protected function afterCreate(): void
    {
        if (! $this->enquiryId) {
            Notification::make()
                ->title(__('app.notifications.member_created'))
                ->body(__('app.notifications.member_created_with_plan'))
                ->success()
                ->send();

            return;
        }

        
        Enquiry::where('id', $this->enquiryId)
            ->update(['status' => 'member']);

        Notification::make()
            ->title(__('app.notifications.member_created'))
            ->body(__('app.notifications.enquiry_converted_to_member'))
            ->success()
            ->send();
    }

    public function getTitle(): string
    {
        return __('app.actions.new', ['resource' => MemberResource::getModelLabel()]);
    }

    public function getBreadcrumbs(): array
    {
        return [
            __('app.navigation.groups.memberships'),
            MemberResource::getUrl('index') => MemberResource::getNavigationLabel(),
        ];
    }
}
