<?php

namespace App\Filament\Resources\Members\Schemas;

use App\Filament\Forms\Components\CameraUploadField;
use App\Filament\Resources\Subscriptions\Schemas\SubscriptionForm;
use App\Helpers\Helpers;
use App\Models\Member;
use App\Models\Plan;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class MemberForm
{
    

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('app.ui.member_details'))
                    ->schema([
                        CameraUploadField::make('photo')
                            ->label(__('app.fields.photo'))
                            ->required(fn (CameraUploadField $component): bool => $component->getRecord() === null),

                        Grid::make()
                            ->schema([
                                TextInput::make('code')
                                    ->placeholder(__('app.placeholders.member_code'))
                                    ->label(__('app.fields.member_code'))
                                    ->required()
                                    ->readOnly()
                                    ->disabled()
                                    ->dehydrated()
                                    ->default(fn (Get $get) => Helpers::generateLastNumber(
                                        'member',
                                        Member::class,
                                        null,
                                        'code'
                                    )),
                                TextInput::make('name')
                                    ->label(__('app.fields.name'))
                                    ->required()
                                    ->maxLength(255)
                                    ->placeholder(__('app.placeholders.example_full_name'))
                                    ->columnSpan(2),
                                TextInput::make('email')
                                    ->label(__('app.fields.email'))
                                    ->email()
                                    ->live()
                                    ->maxLength(255)
                                    ->nullable()
                                    ->placeholder(__('app.placeholders.example_email'))
                                    ->unique('members', 'email', ignoreRecord: true),
                                TextInput::make('government_id')
                                    ->label(__('app.fields.government_id'))
                                    ->maxLength(255)
                                    ->required()
                                    ->placeholder(__('app.placeholders.government_id')),
                                Helpers::phoneField('contact', required: true),
                                Helpers::phoneField('emergency_contact'),
                                Select::make('gender')
                                    ->options([
                                        'male' => __('app.options.gender.male'),
                                        'female' => __('app.options.gender.female'),
                                        'other' => __('app.options.gender.other'),
                                    ])->default('male')
                                    ->label(__('app.fields.gender'))
                                    ->selectablePlaceholder(false)
                                    ->required(),
                                DatePicker::make('dob')
                                    ->required()
                                    ->label(__('app.fields.dob'))
                                    ->placeholder(__('app.placeholders.date_example')),
                                TextInput::make('health_issue')
                                    ->label(__('app.fields.health_issues'))
                                    ->maxLength(500)
                                    ->placeholder(__('app.placeholders.health_issues')),
                                Select::make('goal')
                                    ->options([
                                        'fitness' => __('app.options.goal.fitness'),
                                        'body_building' => __('app.options.goal.body_building'),
                                        'fatloss' => __('app.options.goal.fatloss'),
                                        'weightgain' => __('app.options.goal.weightgain'),
                                        'others' => __('app.options.goal.others'),
                                    ])->default('fitness')
                                    ->label(__('app.fields.goal'))
                                    ->selectablePlaceholder(false),
                            ])->columns(3)->columnSpan(3),
                    ])->columns(4),
                Section::make(__('app.titles.membership_plan'))
                    ->hiddenOn('edit')
                    ->schema([
                        Grid::make()
                            ->schema([
                                Select::make('plan_id')
                                    ->label(__('app.fields.plan'))
                                    ->options(fn (): array => Plan::query()
                                        ->orderBy('name')
                                        ->get()
                                        ->mapWithKeys(fn (Plan $plan): array => [
                                            $plan->id => SubscriptionForm::formatPlanOptionLabel($plan),
                                        ])
                                        ->all())
                                    ->searchable()
                                    ->live()
                                    ->default(fn () => Plan::query()->orderBy('name')->first()?->id)
                                    ->required()
                                    ->afterStateUpdated(fn (Get $get, Set $set) => $set('end_date', Helpers::calculateSubscriptionEndDate(
                                        (string) $get('start_date'),
                                        (int) $get('plan_id'),
                                    )))
                                    ->columnSpan(2),
                                DatePicker::make('start_date')
                                    ->label(__('app.fields.start_date'))
                                    ->live()
                                    ->required()
                                    ->afterStateUpdated(fn (Get $get, Set $set) => $set('end_date', Helpers::calculateSubscriptionEndDate(
                                        (string) $get('start_date'),
                                        (int) $get('plan_id'),
                                    ))),
                                DatePicker::make('end_date')
                                    ->label(__('app.fields.end_date'))
                                    ->disabled()
                                    ->dehydrated()
                                    ->default(fn (Get $get): string => Helpers::calculateSubscriptionEndDate(
                                        (string) $get('start_date'),
                                        (int) $get('plan_id'),
                                    )),
                                Radio::make('payment_method')
                                    ->label(__('app.fields.payment_method'))
                                    ->options(SubscriptionForm::paymentMethodOptions())
                                    ->default('cash')
                                    ->inline()
                                    ->live()
                                    ->required()
                                    ->columnSpan(2),
                                TextInput::make('discount_amount')
                                    ->label(__('app.fields.discount_amount'))
                                    ->numeric()
                                    ->default(0)
                                    ->prefix(Helpers::getCurrencySymbol())
                                    ->extraAttributes(['class' => 'verify-money-input'])
                                    ->afterStateUpdated(fn (Get $get, Set $set) => $set('discount_amount', min(max((float) $get('discount_amount'), 0), (float) Plan::find((int) $get('plan_id'))?->amount ?? 0))),
                                TextInput::make('paid_amount')
                                    ->label(__('app.fields.paid_amount'))
                                    ->numeric()
                                    ->default(0)
                                    ->prefix(Helpers::getCurrencySymbol())
                                    ->extraAttributes(['class' => 'verify-money-input']),
                            ])->columns(3)->columnSpan(3),
                    ])->columns(4),
            ]);
    }
}
