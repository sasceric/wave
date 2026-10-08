# Country SEO keyword plan

Research and implementation reviewed on 2026-10-08. Country directories and
practical guides are implemented locally. Recommendations reflect search
intent and local terminology, not measured keyword volumes or guaranteed ranks.
Search Console and Keyword Planner data were not available for this review.

## Priority phrases

Start with country + influencers + collaboration: this matches a company looking
for people to hire. Use list/directory and platform terms naturally in the page
intro and relevant headings, rather than repeating every spelling in the title.

| Country | Primary title proposal | Supporting query families |
| --- | --- | --- |
| Bosnia and Herzegovina | Influenseri u Bosni i Hercegovini za saradnju \| Wave | influenseri BiH; lista influensera BiH; UGC kreatori BiH; Instagram i TikTok influenseri u Bosni |
| Croatia | Influenceri u Hrvatskoj za suradnju \| Wave | hrvatski influenceri; popis influencera; influenceri za suradnju; UGC kreatori Hrvatska |
| Serbia | Influenseri u Srbiji za saradnju \| Wave | srpski influenseri; lista influensera Srbije; influenseri za saradnju; UGC kreatori Srbija |
| Montenegro | Influenseri u Crnoj Gori za saradnju \| Wave | crnogorski influenseri; lista influensera Crna Gora; influenseri za saradnju; UGC kreatori Crna Gora |
| Slovenia | Vplivneži v Sloveniji za sodelovanje \| Wave | slovenski vplivneži; seznam vplivnežev; sodelovanje z vplivneži; UGC ustvarjalci Slovenija |

These are editorial recommendations. Regional marketplace terminology supports
using both influencer and UGC terms, while Slovenian industry material uses
vplivneži and ustvarjalci. See [Influexus](https://influexus.com/),
[Influee's Croatian directory](https://influee.co/hr/influenceri/zemlje/hrvatska),
and [IAB Slovenia](https://www.iab.si/post/creator-marketing-v-sloveniji-%C4%8Das-je-za-naslednji-skupni-korak).

Do not advertise “best,” verified audience statistics, or follower totals unless
Wave can substantiate them. A directory of available creators is more useful for
this product than a fabricated celebrity ranking.

## Country paths and current routes

One Symfony/Vue deployment serves the five country directories, with complete
translations in all six catalogs. Primary URLs are:

| Market | Primary country directory |
| --- | --- |
| Bosnia and Herzegovina | `/influenseri/bosna-i-hercegovina` |
| Croatia | `/hr/influenceri/hrvatska` |
| Serbia | `/rs/influenseri/srbija` |
| Montenegro | `/me/influenseri/crna-gora` |
| Slovenia | `/si/vplivnezi/slovenija` |

The generic `/kreatori` directory and its translations continue to show the full
marketplace. Country directories lock the country filter to the profile owner's
country code; other filters, search and lazy pagination work within that country.
Only approved, visible profiles are included. No country is guessed from a city
name, and imported ownerless profiles are not assigned an invented country.

`config/localized_route_prefixes.json` separates URL prefixes from translation
identifiers. Serbian, Montenegrin and Slovenian URLs now use `/rs`, `/me` and `/si`;
internal locale codes remain `sr`, `cnr` and `sl`. Every recognized old `/sr`,
`/cnr` and `/sl` URL permanently redirects, preserving query parameters. Vue
navigation, language switching, OAuth redirects and email links use the same
canonical prefix mappings.

Country pages have self canonicals and reciprocal localized versions for the
same country, with `bs-BA`, `hr-HR`, `sr-RS`, `sr-ME`, `sl-SI`, `en`,
and `x-default`. These are language/audience alternates of the same country page;
a Bosnia page is not advertised as the alternate of a Croatia page. Standard
public pages retain language alternates. All alternates use absolute URLs.

A country with no public creator profiles receives `noindex` and no alternate or
structured-data links, is excluded from both sitemap generators, and is hidden
from country discovery links. It becomes indexable automatically when a public
profile is available. Invalid country and guide slugs return HTTP 404.

Google recommends distinct locale URLs and clear visible localized content.
Language/country alternates help it choose the appropriate version. The `.ba`
domain is already a Bosnia country signal, so Croatian/Serbian/other regional
content needs particularly clear country relevance and local links. Paths alone
are not a ranking guarantee. See [Google's multi-regional guidance](https://developers.google.com/search/docs/specialty/international/managing-multi-regional-sites).

## Implemented content and discovery

Country directories have localized influencer/UGC titles, H1s, descriptions,
real creator cards, portfolio/package profile links, selection guidance and
links to practical guides. Home and creator-directory discovery links only
show countries with public profiles. The footer links to the guide index.

Three guides are translated into all six languages:

- `find-creators`: match audience, country and platform; review portfolios; propose a collaboration.
- `choose-package`: compare deliverables, actual creator prices, revisions and usage scope; send an inquiry.
- `campaign-brief`: specify content, budget, timing and creator count; review applications and discuss the plan.

Guide indexes use localized `/vodici`, `/si/vodniki` and `/en/guides` paths.

Creator guides are also implemented at `/vodici-za-kreatore`, with localized
Slovenian `/si/vodniki-za-ustvarjalce` and English `/en/creator-guides` routes.
Their articles cover `create-profile`, `offer-packages` and `apply-to-campaigns`.
The standalone `/kako-funkcionise` page explains both creator and company
workflows, with `/si/kako-deluje` and `/en/how-it-works` variants. All use the
existing locale prefixes, canonical/alternate machinery, server-readable copy
and sitemap entries. Footer navigation links directly to the new pages.
Every article has an Article main entity. Country pages have CollectionPage and
ItemList metadata containing actual public profile URLs. Symfony emits the
visible country/guide content and crawlable links in the initial HTML; Vue then
renders the interactive page from the same catalogs. A no-JavaScript visitor can
read that initial content. No Twig pages or second deployment were introduced.

Both the dynamic and production cached/partitioned sitemap include the guides,
populated country directories, canonical prefixes and reciprocal alternates.

## Release and ongoing SEO work

1. Deploy the frontend build and backend changes together. Keep `APP_BASE_URL`
   set to `https://wave.ba` in production.
2. In Admin → Tools, run `SitemapGenerateTask` after deployment to replace the
   previously cached sitemap. Its hourly schedule subsequently maintains it.
   Verify `/sitemap.xml` and the linked immutable parts contain the new paths.
3. Verify Search Console ownership using the existing configuration/DNS setup.
   Submit `https://wave.ba/sitemap.xml` and inspect the primary country pages and
   guides. This requires access to the site's Search Console property and was
   not completed as part of local development.
4. Measure impressions, queries, clicks and conversions by country. Adjust copy
   using actual Search Console evidence and obtain relevant local creator/brand
   links. No keyword volume or ranking position is claimed by this implementation.
5. Add city/platform/category landing pages only when real inventory supports
   useful distinct pages. Avoid indexing arbitrary filter combinations or
   generating empty pages solely to repeat a search phrase.

City-specific pages and externally acquired links remain ongoing editorial work,
not fabricated content or automatic publication. The technical country and guide
implementation is complete; production deployment and Search Console verification
remain release actions.
