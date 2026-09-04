<?php

namespace App\Console\Commands;

use App\Helpers\Helpers;
use App\Models\Subscription;
use App\Support\AppConfig;
use App\Support\Notifications\NotificationRecipients;
use Carbon\Carbon;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class MarkSubscriptionsStatus extends Command
{
    

    protected $signature = 'gymie:subscriptions
                            {--mark-expired : Mark expired subscriptions}
                            {--mark-expiring : Mark subscriptions expiring within the configured window}';

    

    protected $description = 'Mark subscriptions as expiring or expired';

    

    public function handle(): int
    {
        $timezone = AppConfig::timezone();
        $today = Carbon::today($timezone);
        $expiringDays = Helpers::getSubscriptionExpiringDays();
        $expiringThreshold = $today->copy()->addDays($expiringDays);

        $summary = [];

        $runExpiredOnly = (bool) $this->option('mark-expired');
        $runExpiringOnly = (bool) $this->option('mark-expiring');
        $runAll = ! $runExpiredOnly && ! $runExpiringOnly;

        if ($runAll || $runExpiredOnly) {
            $expiredCount = $this->updateStatusInChunks(Subscription::query()
                ->whereDate('end_date', '<', $today)
                ->whereNotIn('status', ['expired', 'renewed'])
                ->whereDoesntHave('renewals'), 'expired');

            if ($expiredCount > 0) {
                $summary[] = "{$expiredCount} expired";
            }
        }

        if ($runAll || $runExpiredOnly) {
            $renewedCount = $this->updateStatusInChunks(Subscription::query()
                ->whereDate('end_date', '<', $today)
                ->where('status', '!=', 'renewed')
                ->whereHas('renewals'), 'renewed');

            if ($renewedCount > 0) {
                $summary[] = "{$renewedCount} renewed";
            }
        }

        if ($runAll) {
            $upcomingCount = Subscription::query()
                ->whereDate('start_date', '>', $today)
                ->where('status', '!=', 'renewed')
                ->where('status', '!=', 'upcoming')
                ->update(['status' => 'upcoming']);

            if ($upcomingCount > 0) {
                $summary[] = "{$upcomingCount} upcoming";
            }
        }

        if ($runAll || $runExpiringOnly) {
            Subscription::query()
                ->whereDate('start_date', '<=', $today)
                ->whereBetween('end_date', [$today->toDateString(), $expiringThreshold->toDateString()])
                ->where('status', '!=', 'renewed')
                ->where('status', '!=', 'expiring')
                ->where('status', '!=', 'expired')
                ->update(['status' => 'expiring']);
        }

        if ($runAll) {
            $ongoingCount = Subscription::query()
                ->whereDate('start_date', '<=', $today)
                ->where(fn ($query) => $query
                    ->whereNull('end_date')
                    ->orWhereDate('end_date', '>', $expiringThreshold))
                ->whereNotIn('status', ['ongoing', 'expired', 'renewed'])
                ->update(['status' => 'ongoing']);

            if ($ongoingCount > 0) {
                $summary[] = "{$ongoingCount} ongoing";
            }
        }

        if (empty($summary)) {
            $this->info('No subscription statuses needed updating.');

            return self::SUCCESS;
        }

        foreach ($summary as $line) {
            $this->info("• {$line}");
        }

        foreach (NotificationRecipients::resolve('subscription_status') as $recipient) {
            Notification::make()
                ->title(__('app.notifications.subscription_status_update_title'))
                ->body(__('app.notifications.subscription_status_update_body', ['summary' => implode(', ', $summary)]))
                ->info()
                ->sendToDatabase($recipient);
        }

        return self::SUCCESS;
    }

    

    private function updateStatusInChunks(Builder $query, string $status): int
    {
        $updatedCount = 0;
        $model = $query->getModel();

        $query
            ->select($model->getQualifiedKeyName())
            ->chunkById(
                500,
                
                function (Collection $subscriptions) use (&$updatedCount, $status): void {
                    $updatedCount += Subscription::query()
                        ->whereKey($subscriptions->modelKeys())
                        ->update(['status' => $status]);
                },
                column: $model->getQualifiedKeyName(),
                alias: $model->getKeyName(),
            );

        return $updatedCount;
    }
}
