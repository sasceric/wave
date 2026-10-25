# Structured data and German localization

Audit and implementation: 2026-10-16. JSON-LD describes public, visible content;
it does not expose private account information or grant marketplace access.

## Page coverage

| Page | Schema.org representation |
| --- | --- |
| Homepage | `WebSite`, publisher `Organization`, `WebPage`, and the five visible general questions as `FAQPage` |
| Creator/company/campaign directories | `CollectionPage`; Vue adds `ItemList` from the results actually loaded, including subsequent result pages |
| Country creator directory | Existing `CollectionPage` and creator `ItemList`, with localized country content |
| Creator profile | `ProfilePage` with a `Person` and their offered `Service`; packages use `OfferCatalog`/`Offer`/`Service`; creator ratings remain in the visible UI, outside review-snippet markup |
| Creator portfolio and questions | Public images use `ImageGallery.image` URL values; populated creator questions use `FAQPage`/`Question`/`Answer` |
| Company profile | `ProfilePage` with an `Organization`, public reviews/aggregate rating, and topics in `knowsAbout` |
| Open campaign | `CreativeWork` describing the collaboration brief |
| Guide indexes and articles | `CollectionPage`/`ItemList` and `Article`; retained after SPA navigation for both creator and company guides |
| Support | `ContactPage` |
| Indexable pages other than home | `BreadcrumbList`, with directory parents for profile/detail pages |

Generic filtered directory lists are populated after their API response, not
invented in the initial HTML. Profile entities come from the shared
`ProfileStructuredData` factory, used by both initial HTML and the profile API.
The Vue SEO helper keeps the graph current during navigation and review pagination.
Initial HTML and SPA updates serialize one object with a string `@context` and
an `@graph` array. A top-level JSON-LD array is valid, but triggers Safari's
metadata reader error `r["@context"].toLowerCase`; the object wrapper preserves
the same entities while avoiding that browser bug. Review pagination accepts
the older array format during deployment and writes the object format back.
Private routes, unavailable profiles and non-public/closed campaign history
remain noindex and do not receive public entity markup.

## Reviews, prices and privacy

- Reviews follow the existing double-blind publication rules and completed,
  accepted collaboration requirements. Unpublished feedback is never included.
- Fictional presentation accounts and reviews from those accounts are omitted.
  If an aggregate includes demo feedback, that aggregate is omitted as well.
- Eligible company review nodes match the displayed review page; the aggregate
  covers all published reviews. Ratings use the actual 1–5 scale and author type.
- Google review snippets do not support `Person` or `Service` as the reviewed
  item, even though Schema.org permits ratings on `Service`. Creator profiles
  omit `Review` and `AggregateRating` markup; visible reviews and scores remain
  available through the regular UI/API. Company feedback belongs to `Organization`.
  Review pagination cleans legacy creator rating markup rather than restoring it.
- `Organization.industry` was invalid: `industry` belongs to `JobPosting`.
  Company topics now use `knowsAbout`.
- Fixed package prices use `price` and currency. Starting prices use
  `PriceSpecification.minPrice`. Negotiable packages have no invented price.
- Portfolio images without public permission are excluded. User email, phone,
  birth date, private audience information and earnings are not added.

`VideoObject` is intentionally omitted until reliable required video metadata
(such as upload date and thumbnail) is available. Campaigns are not employment
job postings, and creator services are not relabeled as products to obtain stars.
Schema.org validity does not guarantee a Google rich result: Google supports a
smaller set of types and applies its own eligibility rules.

## Link previews

Initial Symfony HTML and Vue navigation use the same image policy: campaigns use
their uploaded cover, creators use their avatar, and brands/agencies use their
logo. When that image is absent, all pages fall back to `/images/share.webp`, the
homepage artwork. App icons and the square Wave mark are not share-image fallbacks.
Both Open Graph and Twitter metadata use absolute image URLs and the large-image
card format. Sharing does not require a session; existing public-media access
checks still apply. Messaging apps can retain a previously fetched preview after
deployment, so an old conversation is not proof that new metadata was ignored.

## Search Console review (2026-10-25)

Reviewed the notifications and affected URL examples in the authenticated console:

- Review snippets: validation passed, zero invalid items. Image Metadata: all four
  historic optional-field warnings now have zero affected items.
- Robots exclusions point to account, login and registration URLs. Keep these
  private areas blocked. Search pages and search-query URLs intentionally remain
  `noindex, follow` and are not in the current sitemap.
- The campaign `/kampanje/kafa-ritual-price-iz-komsiluka`, previously reported as
  noindex, currently returns 200 with `index, follow` and a self canonical.
  `/kompanije/dn-kozmetik` and `/kreatori/lejla-hadzic` currently return 404 and
  are absent from the sitemap; do not expose unavailable profiles to clear a report.
- Old localized creator/campaign detail examples now redirect to their public
  directories. For the Montenegrin application guide, Google selected the Bosnian
  version of the same content. The localized guide still permits indexing and
  declares its own canonical; this is Google's duplicate-content selection, not a
  crawl/access failure.
- The live sitemap contains 166 URLs and none of the inspected obsolete profile,
  authentication, search or malformed `/[object Object]` URLs. The malformed path
  is a historic 404; it was not reproduced in the current campaign HTML.

No blanket indexing validation was submitted for intentionally excluded URLs.
Google's reports reflect prior crawls and do not clear immediately after a change.

References: [Schema.org review domains](https://schema.org/review),
[Service](https://schema.org/Service), [industry](https://schema.org/industry),
[Google profile pages](https://developers.google.com/search/docs/appearance/structured-data/profile-page),
and [Google review snippets](https://developers.google.com/search/docs/appearance/structured-data/review-snippet).
Safari compatibility: [Apple bug 255764](https://bugs.webkit.org/show_bug.cgi?id=255764).

## Portfolio image rights

Portfolio galleries use the Schema.org `image` property with absolute image URLs.
The saved permission to publish a portfolio item does not establish who took its
photos, who owns their copyright, or which reuse license applies. Do not infer
these from the account owner or assign Wave as the copyright owner.

The previous `ImageObject` nodes included an assumed `creator`, which triggered
Google's optional Image Metadata recommendations for `copyrightNotice`, `license`,
`acquireLicensePage` and `creditText`. Ordinary gallery markup retains the public
images without making unsupported attribution/licensing claims. It does not
request Google's image licensing enhancement; image indexing does not require
that enhancement. Add detailed image rights markup only when the corresponding
verified information and actual licensing pages are available.

After deployment, test an affected profile's live HTML with Rich Results Test and
request revalidation in Search Console. Existing reports can persist until Google
recrawls the changed pages; deployment alone does not clear the report instantly.

References: [Schema.org image](https://schema.org/image),
[Google image metadata](https://developers.google.com/search/docs/appearance/structured-data/image-license-metadata).

## German

German is the seventh locale (`de`, language tag `de`, Open Graph `de_DE`).
`frontend/src/locales/de.json` contains all interface messages;
`config/translations/de.json` contains 251 API and email messages. German is
lazy-loaded, available in language selection, date pickers, admin email templates
and catalog editing. Key/placeholder parity and message compilation are tested.

Directory, guide, account and other translated routes use `/de/`, for example
`/de/kreative` and `/de/konto/uebersicht`. Creator, company and campaign detail
URLs remain shared across languages, with no German slug copies. Canonicals,
hreflang, structured data, both sitemap generators and redirect aliases include
the new locale where applicable.

Migration `Version20261016160000` adds missing German labels for the 48 standard
marketplace areas. It preserves existing labels, custom categories and saved
German translations. Custom untranslated categories/questions fall back to their
existing English content, then Bosnian. German catalog fields are optional so
older custom items remain editable. User-authored profiles, briefs, messages and
reviews are not automatically translated.

Deploy the backend, built frontend, route configuration, translation files and
migration data together. See [deployment](deployment.md). No new service,
environment variable or scheduled task is required.
