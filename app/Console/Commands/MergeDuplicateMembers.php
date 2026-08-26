<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Models\PlanCheckIn;
use App\Models\Subscription;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Merge duplicate member records that share a non-null government ID.
 *
 * One government ID belongs to one person, so a group of members sharing it
 * is almost certainly the same person registered multiple times (typically
 * at different locations). This command finds those groups and, with an
 * explicit confirmation per group, merges everything into a single canonical
 * record: subscriptions move over (their invoices follow because they are
 * subscription-linked, not member-linked) and check-ins move over, then the
 * remaining records are permanently deleted.
 *
 * Canonical-record rule (documented so merges stay predictable):
 *  - the member that currently holds an ongoing/expiring subscription
 *    (exactly one such member wins), otherwise
 *  - the oldest record (lowest id).
 *
 * Merging is NEVER automatic — a duplicate can be a data-entry mistake
 * (someone else's ID) and merging it would be destructive, so every group
 * is confirmed by an operator first. Use --dry-run to preview.
 *
 * Run this before the migration that adds the unique government ID index
 * (2026_08_19_000001), which aborts when duplicates still exist — including
 * soft-deleted duplicates, which must be permanently deleted from the trash.
 */
class MergeDuplicateMembers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'members:merge-duplicates
                            {--dry-run : Preview the groups and canonical records without changing anything}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Find member groups sharing a non-null government ID and merge them into one record (with confirmation)';

    /**
     * Execute the console command.
     */
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

    /**
     * @return \Illuminate\Support\Collection<int, string>
     */
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

    /**
     * @return Collection<int, Member>
     */
    private function membersWithGovernmentId(string $governmentIdHash): Collection
    {
        return Member::query()
            ->withoutGlobalScope('location')
            ->where('government_id_hash', $governmentIdHash)
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, Member>  $members
     */
    private function pickCanonical(Collection $members): Member
    {
        $activeHolder = $members->first(fn (Member $member): bool => $member->subscriptions()
            ->whereIn('status', ['ongoing', 'expiring'])
            ->exists());

        return $activeHolder ?? $members->first();
    }

    /**
     * @param  Collection<int, Member>  $members
     */
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

    /**
     * Move everything onto the canonical record and permanently delete the
     * duplicates. Direct table updates are used on purpose: the data is
     * being re-parented, not changed, and model events (e.g. the
     * All-Locations upgrade guard on Subscription::saving) must not run
     * against records that still exist as duplicates mid-merge.
     *
     * @param  Collection<int, Member>  $members
     * @return array{subscriptions: int, check_ins: int}
     */
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
