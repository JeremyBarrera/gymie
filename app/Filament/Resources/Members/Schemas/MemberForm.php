<?php

namespace App\Filament\Resources\Members\Schemas;

use App\Filament\Forms\Components\CameraUploadField;
use App\Filament\Schemas\SubscriptionSaleSchema;
use App\Helpers\Helpers;
use App\Models\Member;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
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
                        Repeater::make('sales')
                            ->label(__('app.titles.membership_plan'))
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->minItems(1)
                            ->defaultItems(1)
                            ->reorderable(false)
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => isset($state['plan_id']) && is_numeric($state['plan_id']) ? (\App\Models\Plan::find((int) $state['plan_id'])?->name) : null)
                            ->schema(SubscriptionSaleSchema::fields())
                            ->columns(1),
                    ])->columns(4),
            ]);
    }
}
