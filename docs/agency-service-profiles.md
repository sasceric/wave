# Agencies as clients and service providers

An agency remains a `ROLE_COMPANY` account with a single public company URL under
`/kompanije/{slug}`. Choosing Agency adds optional services, languages, availability,
portfolio, packages and FAQs to the existing company editor. Existing company
fields, logos, covers, campaign publishing and creator hiring remain unchanged.
There are no mandatory personal creator or audience fields for agencies.

Services are structured company records (`company.services` JSON): stable ID,
title, short description, included deliverables, optional platforms and project /
monthly / both collaboration type. Prices and delivery times remain in packages.
Migration `Version20261017210000` converts existing service labels into titled
records without inventing descriptions, including studios currently set back to
Brand. Old API clients may still submit service strings. Omitting services keeps
saved records; sending an empty list removes them. Type changes preserve content;
account anonymization clears it. No extra service images are stored.

The account uses `ProfileTabs`, `ProfileFaqFields`, `AccountProfileCard` and the
existing studio editor for both creator and agency accounts. Public profiles share
`ProfileTabs` section navigation, `ProfilePackages`, `ProfileFaqs` and `CreatorWorks`,
including mobile scrolling, keyboard controls, package actions and FAQ appearance.
Agency public sections remain visible together, like creator sections; review
role selection still separates provider/client feedback.

Agencies may select a saved service/package when applying. It fills editable scope
and delivery terms; package price is copied only for the campaign currency. The
submitted proposal is a snapshot of those edited terms, so later profile changes
do not rewrite existing applications.

## Collaboration model

`AgencyProfiles` reuses the existing `Creator` studio/collaboration participant
model internally. The `creator.agency_profile` flag distinguishes these private
service participants from individual creators. A participant is created when an
agency saves its studio, applies, receives an invitation or receives an inquiry.
It has no public creator profile: directories, country SEO, home selections,
admin creator lists, indexing and both sitemap generators exclude these records.
Application and inbox resources retain compatible `creator` fields but include
`isAgency`, `ownerId` and `profileRoute: company-profile`; the displayed name, logo
and slug always come from the actual company.

`ApiAccess::requireProvider` allows creators and agency companies to use provider
workflows without assigning `ROLE_CREATOR` to a company. New applications require
the currently active Agency type. Existing private collaboration history remains
accessible after a type change; switching back restores studio data. Only the
company's normal required fields determine approval/profile completion.

Agencies may apply to any open campaign except their own. They can optionally
include proposed price (in the campaign currency), services/deliverables, timeline
and team. The offer accepted later remains the authoritative agreed amount.
Duplicate, closed, unapproved, unverified, blocked and ownership protections are
preserved. Brands cannot apply as providers.

Company owners can invite agencies using `agencyId` at the existing campaign
invitation endpoint. Agency package/general inquiries use
`POST /api/companies/{slug}/inquiries`. Self invitations/inquiries are rejected.
Shortlisting, chat, offers, cancellation, campaign completion and mutual reviews
use the existing collaboration workflow and notification infrastructure.

## One inbox and two review contexts

An agency's inbox queries both participant sides, using the authenticated user's
ownership per thread. Client/provider identity is independent of account type;
offer acceptance, message alignment and campaign links follow the thread side.
Unread counters and participant access checks remain owner scoped.

Public agency reviews have separate Provider and Client tabs. Both query the
same existing review records with an explicit recipient-side filter, including
counts, averages, completion counts and pagination. Blind publication and the
14-day window remain unchanged. Review schema keeps the combined organization
summary and identifies company authors as Organizations. Fictional reviews remain
excluded from schema.

The dashboard retains company campaign metrics and adds separate provider
application/offer/active counts and earnings. It does not mix provider earnings
with the agency's client-side spending.

## Media and rollout

Agencies upload portfolio assets through the existing `creator-portfolio` folder
with company ownership and the provider capability check. Studio media
associations participate in the same reference checks and post-commit deletion
queue as individual portfolios. Removing projects/gallery media deletes unused
files and thumbnails. Changing company type preserves optional studio data;
it does not destroy projects or ongoing collaboration history.

Deploy normally, including Doctrine migrations `Version20261017120000` and
`Version20261017210000`. The first adds
`creator.agency_profile` and `campaign_application.proposal` with safe defaults;
existing account, profile, application and media data is preserved. No seed or
manual production data conversion is required.

Validation covers agency application through completion/reviews, simultaneous
client/provider inquiries and inbox roles, invitations, capability changes,
self/brand/approval restrictions, additive PostgreSQL/SQLite migration behavior,
and the existing account/marketplace/review/media regression suites. The account
editor and public profile use the shared components and all seven locale catalogs.
