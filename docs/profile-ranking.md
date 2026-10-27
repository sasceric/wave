# Recommended profile ranking

Public creator and company directories use this order when `sort=recommended`;
the public Vue directories select it by default. The homepage uses the same
ranking for featured creators and companies. Explicit name/newest/campaign-count sorts retain their
existing behavior.

1. Currently featured profiles, with configured positions ordered from lowest to
   highest. Unpositioned featured profiles follow; ties use the ranking below.
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
campaigns separately. Each selected row has an optional positive position
(1–1,000,000). A blank position uses the usual ranking after positioned featured
items. Duplicate positions are allowed and use the existing tie-breakers.
Removing featured status clears its position.

The homepage renders up to **12 active, publicly eligible featured items per
section**. The companies section has an admin on/off switch, disabled by default.
When off, its heading and entire rail are hidden and company cards are not queried. It does not fill vacancies with ordinary profiles
or campaigns. The first twelve in the same recommended order are selected before
hydration; directories retain every featured item and their existing filters and
pagination. Positions apply to recommended directory order; explicit sorts such
as newest/name/closing retain their meaning. The legacy creator mode field remains
accepted by the API, but the homepage uses featured-only selection.

All three homepage rows use the existing CSS scroll-snap rail with desktop arrow
controls and mobile swipe. Every card and link is rendered after the initial
homepage response, without requiring a slide click. Only images use native lazy
loading and responsive sources. There is no autoplay, carousel library or duplicate
slide markup. Card payloads omit full portfolios and campaign descriptions; related
media and company industries are fetched together, and counts/ratings are batched.

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
configured expiry dates. Run `Version20261027170000` for nullable
`featured_position` columns and partial indexes on all three entity tables; its
rollback refuses to discard configured positions.

Coverage: `RecommendedProfilesTest` exercises the ranking, role separation,
public review visibility, homepage consistency, checklist parity, daily cursor
rotation, and admin expiry validation. Frontend tests cover selection drafts,
expiry serialization, successful/failed saves and navigation warnings.
