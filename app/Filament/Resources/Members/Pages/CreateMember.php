<?php

namespace App\Filament\Resources\Members\Pages;

use App\Filament\Resources\Members\MemberResource;
use App\Models\Enquiry;
use App\Services\Subscriptions\MemberSubscriptionService;
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
            $sales = $data['sales'] ?? null;
            if (is_array($sales) && count(array_filter($sales, fn ($s) => filled($s['plan_id'] ?? null))) > 0) {
                $sales = array_values(array_filter($sales, fn ($s) => filled($s['plan_id'] ?? null)));
            } elseif (filled($data['plan_id'] ?? null)) {
                $sales = [[
                    'plan_id' => $data['plan_id'],
                    'quantity' => $data['quantity'] ?? 1,
                    'start_date' => $data['start_date'] ?? now()->toDateString(),
                    'end_date' => $data['end_date'] ?? null,
                    'payment_method' => $data['payment_method'] ?? 'cash',
                    'discount_amount' => $data['discount_amount'] ?? 0,
                    'paid_amount' => $data['paid_amount'] ?? 0,
                ]];
            } else {
                throw ValidationException::withMessages([
                    'sales.0.plan_id' => __('app.reception.verify_sale_required'),
                ]);
            }
            $memberData = collect($data)->except(['sales', 'plan_id', 'quantity', 'start_date', 'end_date', 'payment_method', 'discount_amount', 'paid_amount'])->toArray();
            $member = parent::handleRecordCreation($memberData);
            MemberSubscriptionService::createForMember($member, $sales);
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
