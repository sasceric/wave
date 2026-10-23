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
