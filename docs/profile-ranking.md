# Recommended profile ranking

Public creator and company directories use this order when `sort=recommended`;
the public Vue directories select it by default. The homepage uses the same
ranking for creators. Explicit name/newest/campaign-count sorts retain their
existing behavior.

1. Currently featured profiles.
2. Review quality adjusted for the number of public reviews.
3. Number of distinct completed campaigns.
4. Profile strength, descending from 100 to 0.
5. A deterministic daily rotation for profiles equal on all preceding values,
   then the profile ID as a final tie-breaker.

Review quality uses a prior of three reviews at 4/5:
`(sum of public ratings + 12) / (public review count + 3)`.
It is stored only in the query result, rounded down to six decimal places for
cursor ordering. Profiles without public reviews receive zero ranking quality
and display **Novo** (localized), rather than zero stars. Displayed star averages
are the actual public averages, without this adjustment.

Creators and agencies use their provider reviews and completed work; brands use
their client reviews and finished campaigns. Only reviews passing the existing
reciprocal-review / 14-day publication rule contribute. Agency studio profiles
are excluded from the creator directory and homepage creator picks.

Profile strength follows the existing account checklist and its weights; it
measures completeness, not verified ability. `ProfileStrengthSql` projects the
checklist into PostgreSQL so ordering happens before pagination. Ranking queries
do not fetch every entity into PHP. Visibility, blocking, search and filters
still apply before ranking, including to featured profiles.

Rotation changes at midnight UTC. Signed recommendation cursors retain the
first page's UTC timestamp and day, so continuing the same traversal after
midnight does not reshuffle tied profiles. Live profile/review changes can still
affect a traversal; this is not a database snapshot.

## Manual featuring

The admin **Početna stranica** page selects creators, companies/agencies and
campaigns separately. The creator section can show only featured creators or
fill its places with other recommended creators. Company picks affect the
company directory; the homepage currently has no company section. Campaigns
retain their featured/newest ordering.

Featured creator and company profiles can have an optional final date. The date
is inclusive in UTC: selecting `2026-11-01` stores `2026-11-02 00:00:00` UTC.
An empty date means no expiry. Expired picks remain visible to the admin for
renewal/removal, but public badges, featured filters and ranking stop treating
them as featured automatically. No background worker is needed. Removing a
profile from the selection clears its expiry.

Selection and date edits are drafts until **Sačuvaj**. All three selectors use
the shared searchable `MultiSelect` used for countries, with optional thumbnails
and server search/pagination, loading 20 candidates per batch as the options list
approaches its bottom. Failed saves retain edits for retry.

## Deployment

Run `Version20261026180000` before serving the updated backend. It adds nullable
`featured_until` columns to `creator` and `company`; existing featured profiles
remain featured without an end date. The down migration refuses to discard
configured expiry dates.

Coverage: `RecommendedProfilesTest` exercises the ranking, role separation,
public review visibility, homepage consistency, checklist parity, daily cursor
rotation, and admin expiry validation. Frontend tests cover selection drafts,
expiry serialization, successful/failed saves and navigation warnings.
