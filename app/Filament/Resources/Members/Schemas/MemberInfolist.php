<?php

namespace App\Filament\Resources\Members\Schemas;

use App\Models\Member;
use App\Support\Dates\DeviceDateFormat;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

class MemberInfolist
{
    

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make()
                    ->heading(function (Member $record): HtmlString {
                        $status = $record->status;

                        if ($status === null) {
                            return new HtmlString(e(__('app.ui.details')));
                        }

                        $html = Blade::render(
                            '<x-filament::badge class="inline-flex ml-2" :color="$color">
                                {{ $label }}
                            </x-filament::badge>',
                            [
                                'color' => $status->getColor(),
                                'label' => $status->getLabel(),
                            ]
                        );

                        return new HtmlString(e(__('app.ui.details')).' '.$html);
                    })
                    ->schema([
                        ViewEntry::make('photo')
                            ->hiddenLabel()
                            ->view('filament.infolists.components.photo-zoom')
                            ->columnSpan(1),
                        Group::make()
                            ->schema([
                                TextEntry::make('code')
                                    ->label(__('app.fields.member_code')),
                                TextEntry::make('name')->label(__('app.fields.name')),
                                TextEntry::make('gender')->label(__('app.fields.gender')),
                                TextEntry::make('email')->label(__('app.fields.email')),
                                TextEntry::make('government_id')->label(__('app.fields.government_id')),
                                TextEntry::make('contact')->label(__('app.fields.contact')),
                                TextEntry::make('emergency_contact')->label(__('app.fields.emergency_contact'))->placeholder(__('app.placeholders.na')),
                                TextEntry::make('dob')
                                    ->label(__('app.fields.dob'))
                                    ->date(DeviceDateFormat::date()),
                                TextEntry::make('goal')
                                    ->label(__('app.fields.goal'))
                                    ->placeholder(__('app.placeholders.na')),
                                TextEntry::make('health_issue')
                                    ->label(__('app.fields.health_issues'))
                                    ->placeholder(__('app.placeholders.na')),
                                TextEntry::make('ban_reason')
                                    ->label(__('app.members.ban_reason'))
                                    ->visible(fn (Member $record): bool => $record->status?->value === 'banned'
                                        && filled($record->ban_reason))
                                    ->placeholder(__('app.placeholders.na')),
                            ])->columnSpan(4)->columns(3)->extraAttributes(['class' => 'ps-4']),
                    ])->columns(5),

            ]);
    }
}
