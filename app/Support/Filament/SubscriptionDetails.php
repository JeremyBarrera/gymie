<?php

namespace App\Support\Filament;

use App\Models\Subscription;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Illuminate\Support\HtmlString;

class SubscriptionDetails
{
    /**
     * The shared subscription detail card: member, plan, service and date
     * rows with the subscription status badge beside the section heading.
     *
     * @param  callable(mixed): ?Subscription  $subscriptionFor
     */
    public static function section(callable $subscriptionFor): Section
    {
        return Section::make()
            ->heading(function ($record) use ($subscriptionFor): HtmlString {
                if ($subscriptionFor($record)?->status === null) {
                    return new HtmlString(e(__('app.ui.details')));
                }

                return new HtmlString(
                    e(__('app.ui.details')).' '.GlobalSearchBadge::status($subscriptionFor($record)->status),
                );
            })
            ->columns(6)
            ->schema(self::rows($subscriptionFor));
    }

    /**
     * The shared member → plan → service → start/end entry rows.
     *
     * @param  callable(mixed): ?Subscription  $subscriptionFor
     * @return non-empty-list<TextEntry>
     */
    public static function rows(callable $subscriptionFor): array
    {
        return [
            TextEntry::make('subscription_member')
                ->label(__('app.fields.member'))
                ->columnSpan(3)
                ->color('success')
                ->state(function ($record) use ($subscriptionFor): ?string {
                    $member = $subscriptionFor($record)?->member;

                    return $member ? "{$member->code} – {$member->name}" : null;
                }),
            TextEntry::make('subscription_plan')
                ->label(__('app.fields.plan'))
                ->columnSpan(3)
                ->state(function ($record) use ($subscriptionFor): ?string {
                    $plan = $subscriptionFor($record)?->plan;

                    return $plan ? "{$plan->code} – {$plan->name}" : null;
                }),
            TextEntry::make('subscription_service')
                ->label(__('app.fields.service'))
                ->columnSpan(2)
                ->state(fn ($record) => $subscriptionFor($record)?->plan?->services?->map->name?->implode(', '))
                ->hidden(fn ($record): bool => ! $subscriptionFor($record)?->plan?->services?->isNotEmpty()),
            TextEntry::make('subscription_start_date')
                ->label(__('app.fields.start_date'))
                ->columnSpan(2)
                ->date()
                ->state(fn ($record) => $subscriptionFor($record)?->start_date),
            TextEntry::make('subscription_end_date')
                ->label(__('app.fields.end_date'))
                ->columnSpan(2)
                ->date()
                ->state(fn ($record) => $subscriptionFor($record)?->end_date),
        ];
    }
}
