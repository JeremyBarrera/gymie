<?php

namespace App\Services\Members;

use App\Enums\Status;
use App\Helpers\Helpers;
use App\Models\Member;
use App\Models\MemberApplication;
use App\Models\QueueEntry;
use App\Models\User;
use App\Services\Subscriptions\MemberSubscriptionService;
use App\Support\Billing\PaymentMethod;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class MemberApplicationService
{
    

    public function signupRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'contact' => ['required', 'string', 'max:50'],
            'government_id' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'gender' => ['required', 'string', 'in:male,female,other'],
            'dob' => ['required', 'date'],
            'emergency_contact' => ['nullable', 'string', 'max:50'],
            'health_issue' => ['nullable', 'string', 'max:500'],
            'goal' => ['nullable', 'string', 'max:100'],
        ];
    }

    

    public function saleRules(): array
    {
        return [
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'invoices' => ['required', 'array', 'min:1'],
            'invoices.*.date' => ['required', 'date'],
            'invoices.*.due_date' => ['required', 'date'],
            'invoices.*.payment_method' => ['required', Rule::in(array_keys(PaymentMethod::options()))],
            'invoices.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'invoices.*.paid_amount' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    

    public function validateSale(array $sale): array
    {
        return validator($sale, $this->saleRules())->validate();
    }

    

    public function approveSignup(QueueEntry $entry, array $data, ?string $photoDataUrl, array $sale, ?User $staff = null): Member
    {
        $validated = $this->validateSignup($data, $photoDataUrl);
        $sale = $this->validateSale($sale);

        $photoPath = $photoDataUrl
            ? $this->storePhoto($photoDataUrl)
            : null;

        return DB::transaction(function () use ($entry, $validated, $photoPath, $sale): Member {
            $finalized = QueueEntry::query()
                ->where('id', $entry->id)
                ->whereIn('status', ['waiting', 'attending'])
                ->update([
                    'status' => 'approved',
                    'override' => false,
                ]);

            if (! $finalized) {
                throw new InvalidArgumentException(__('app.reception.already_claimed'));
            }

            if ($this->duplicateExists($validated)) {
                throw new InvalidArgumentException(__('app.reception.verify_duplicate'));
            }

            $member = Member::create([
                'photo' => $photoPath,
                'code' => Helpers::generateLastNumber('member', Member::class, null, 'code'),
                'name' => $validated['name'],
                'email' => $validated['email'] ?? null,
                'contact' => $validated['contact'],
                'emergency_contact' => $validated['emergency_contact'] ?? null,
                'health_issue' => $validated['health_issue'] ?? null,
                'gender' => $validated['gender'],
                'dob' => $validated['dob'],
                'government_id' => $validated['government_id'],
                'goal' => $validated['goal'] ?? null,
                'status' => Status::Active,
            ]);

            MemberApplication::create([
                'location_id' => $entry->location_id,
                'identifier_type' => 'contact',
                'identifier_value' => $validated['contact'],
                'payload' => array_merge(
                    array_intersect_key($validated, array_flip([
                        'name', 'contact', 'government_id', 'email', 'gender', 'dob',
                        'emergency_contact', 'health_issue', 'goal',
                    ])),
                    ['location_id' => $entry->location_id, 'member_id' => $member->id],
                ),
                'status' => 'approved',
                'created_member_id' => $member->id,
            ]);

            MemberSubscriptionService::createSingle($member, $sale);
            return $member;
        });
    }

    

    public function validateSignup(array $data, ?string $photoDataUrl): array
    {
        $validated = validator($data, $this->signupRules())->validate();

        if (blank($photoDataUrl)) {
            throw ValidationException::withMessages([
                'photo' => __('app.reception.verify_photo_required'),
            ]);
        }

        $validated['contact'] = Helpers::normalizePhone($validated['contact']) ?? $validated['contact'];

        if (filled($validated['emergency_contact'] ?? null)) {
            $validated['emergency_contact'] = Helpers::normalizePhone($validated['emergency_contact']) ?? $validated['emergency_contact'];
        }

        $duplicate = Member::findDuplicateByIdentifiers([
            'name' => $validated['name'],
            'contact' => $validated['contact'],
            'government_id' => $validated['government_id'],
        ]);

        if ($duplicate) {
            throw new InvalidArgumentException(__('app.reception.verify_duplicate'));
        }

        return $validated;
    }

    

    private function duplicateExists(array $validated): bool
    {
        return Member::findDuplicateByIdentifiers([
            'name' => $validated['name'],
            'contact' => $validated['contact'],
            'government_id' => $validated['government_id'],
        ]) !== null;
    }

    

    public function storePhoto(string $dataUrl): string
    {
        return Helpers::storePhotoDataUrl($dataUrl);
    }
}
