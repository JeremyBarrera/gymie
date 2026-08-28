<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Models\PlanCheckIn;
use App\Models\Subscription;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class MergeDuplicateMembers extends Command
{
    

    protected $signature = 'members:merge-duplicates
                            {--dry-run : Preview the groups and canonical records without changing anything}';

    

    protected $description = 'Find member groups sharing a non-null government ID and merge them into one record (with confirmation)';

    

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $duplicateGovernmentIds = $this->duplicateGovernmentIds();

        if ($duplicateGovernmentIds->isEmpty()) {
            $this->info('No duplicate government IDs found.');

            return self::SUCCESS;
        }

        $this->info($dryRun
            ? "Found {$duplicateGovernmentIds->count()} duplicate group(s). Preview only — nothing will be changed."
            : "Found {$duplicateGovernmentIds->count()} duplicate group(s).");

        $mergedCount = 0;

        foreach ($duplicateGovernmentIds as $governmentIdHash) {
            $members = $this->membersWithGovernmentId((string) $governmentIdHash);

            $canonical = $this->pickCanonical($members);

            $this->line('');
            $this->line("Government ID: {$canonical->government_id}");
            $this->renderGroup($members, $canonical);

            $proceed = $dryRun || $this->confirm(
                "These look like the same person. Merge everything into {$canonical->name} (#{$canonical->id})?",
                false,
            );

            if (! $proceed) {
                $this->line('Skipped.');

                continue;
            }

            $moved = $dryRun ? ['subscriptions' => 0, 'check_ins' => 0] : $this->mergeGroup($members, $canonical);
            $mergedCount += $dryRun ? 0 : count($members) - 1;

            $this->line($dryRun
                ? 'Would merge '.count($members)." members into {$canonical->name} (#{$canonical->id})."
                : 'Merged '.count($members)." members into {$canonical->name} (#{$canonical->id})"
                    ." — moved {$moved['subscriptions']} subscription(s), {$moved['check_ins']} check-in(s).");
        }

        if ($mergedCount > 0) {
            $this->line('');
            $this->info("Done. {$mergedCount} duplicate member record(s) merged.");
        }

        return self::SUCCESS;
    }

    

    private function duplicateGovernmentIds(): \Illuminate\Support\Collection
    {
        return Member::query()
            ->withoutGlobalScope('location')
            ->select('government_id_hash')
            ->whereNotNull('government_id_hash')
            ->groupBy('government_id_hash')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('government_id_hash');
    }

    

    private function membersWithGovernmentId(string $governmentIdHash): Collection
    {
        return Member::query()
            ->withoutGlobalScope('location')
            ->where('government_id_hash', $governmentIdHash)
            ->orderBy('id')
            ->get();
    }

    

    private function pickCanonical(Collection $members): Member
    {
        $activeHolder = $members->first(fn (Member $member): bool => $member->subscriptions()
            ->whereIn('status', ['ongoing', 'expiring'])
            ->exists());

        return $activeHolder ?? $members->first();
    }

    

    private function renderGroup(Collection $members, Member $canonical): void
    {
        foreach ($members as $member) {
            $this->line(sprintf(
                '  %s#%d — %s (%s) — %s — location: %s — subscriptions: %d',
                $member->id === $canonical->id ? 'CANONICAL ' : '',
                $member->id,
                $member->name,
                (string) $member->code,
                (string) ($member->contact ?? 'no contact'),
                (string) ($member->currentLocation()?->name ?? 'none'),
                $member->subscriptions()->count(),
            ));
        }
    }

    

    private function mergeGroup(Collection $members, Member $canonical): array
    {
        $moved = [
            'subscriptions' => 0,
            'check_ins' => 0,
        ];

        DB::transaction(function () use ($members, $canonical, &$moved): void {
            foreach ($members as $member) {
                if ($member->id === $canonical->id) {
                    continue;
                }

                $moved['subscriptions'] += DB::table((new Subscription)->getTable())
                    ->where('member_id', $member->id)
                    ->update(['member_id' => $canonical->id]);

                $moved['check_ins'] += DB::table((new PlanCheckIn)->getTable())
                    ->where('member_id', $member->id)
                    ->update(['member_id' => $canonical->id]);

                DB::table((new Member)->getTable())->where('id', $member->id)->delete();
            }
        });

        return $moved;
    }
}
