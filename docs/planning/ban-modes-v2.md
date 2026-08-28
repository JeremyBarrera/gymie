# Ban Modes — V2 Planning (Not Implemented)

This document captures the requirement for banning needs two modes, as requested in the override/check-in flow follow-up. **Do not implement code for this yet** — this is a planning note to ensure the requirement is not lost.

## Requirement

Banning a member should support two distinct modes:

1.  **Ban from a specific location**
    *   The ban is scoped to a single location (the staff member's current location at the time of banning).
    *   The ban popup/modal should be preloaded to the staff member's current location (i.e., default to `LocationAccess::firstAccessibleLocationId(Auth::user())` or `TenantContext` location).
    *   The member is blocked from check-in at that location only, but remains eligible at other locations.

2.  **Ban from all locations, including future ones**
    *   The ban applies globally to the tenant, covering all current locations and any locations created in the future.
    *   This is a stronger action and should be clearly distinguished in the UI (e.g., "Ban from all locations" vs "Ban from this location").

## Permission Scoping

This must be permission-scoped, with separate permissions for each action:

*   **Who can do a global ban:** Requires a specific permission (e.g., `ban:global` or `Member:banGlobal`), likely restricted to Owner or a senior role. Not every staff member who can do a location-scoped ban should be able to do a global ban.
*   **Who is allowed to lift a ban:** Similarly, lifting a ban (unbanning) should be permission-scoped, and the permission to lift a global ban should be separate from lifting a location-scoped ban. For example, a location manager might be able to lift a location ban for their location, but only an Owner can lift a global ban.

The current `Member::checkInBlocker()` only checks `status === 'banned'` globally. V2 will need to handle location-scoped status, likely via a new `member_bans` table or a `bans` JSON column with `location_id` (null for global) and `created_by`, `reason`, `expires_at`, etc., and update the `checkInBlocker()` and `serviceStatesForMember()` logic to be location-aware.

## UI Considerations

*   The ban modal/popup should have a location selector (defaulted to current location) and a toggle or radio for "This location only" vs "All locations".
*   The `CheckInActivity` and member view should clearly indicate which type of ban is active and where it applies.

## Out of Scope for V1

Do not implement any of the above code, migrations, or UI changes as part of the current override/check-in flow fixes. This doc is for V2 planning only.

*Captured: 2026-08-28 per follow-up request to document ban mode requirements.*
