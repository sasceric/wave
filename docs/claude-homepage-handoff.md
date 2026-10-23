# Claude handoff: Wave homepage design

Date: 23.10.2026

## Task and current scope

Continue collaborating on a simpler Wave.ba homepage with an iOS/Apple feel,
especially on mobile, while preserving Wave's identity. The user asked for a
prototype, then requested improvements and a desktop view. **They have not yet
approved implementing this design in the application.**

The latest deliverable is an interactive design prototype. The Vue homepage,
backend and production site were not changed for this design exploration.
Do not interpret this handoff as authorization to replace the homepage, push,
deploy, or publish anything.

## User feedback to preserve

- Keep it simple, welcoming and easy to understand; avoid a long page of repeated
  promotional sections and too many competing actions.
- Use an iOS-like visual treatment: calm spacing, restrained borders, rounded
  surfaces, consistent controls and clear hierarchy. Keep Wave's brand.
- The user explicitly disliked the green background in the first prototype.
  The revision uses Wave's **cream background**, including when the surrounding
  viewer is in dark mode. Green is for text and primary actions, not a page wash.
- Show multiple campaigns and creators. On mobile, use swipeable rows with about
  two cards visible and a peek of the next card. Do not show only one oversized
  campaign and one creator.
- Provide a real desktop composition, not an enlarged phone. The revised desktop
  browsing design has four cards per row and a full header.
- Show logged-in navigation too: messages, notifications with unread indicators,
  account/profile menu, search, and mobile bottom navigation.
- Reuse existing components. This has been a repeated, emphatic requirement.
- Use Lucide SVG icons, especially `ArrowRight`, instead of arrow characters,
  HTML entities, emoji arrows or CSS text icons. Back navigation uses `ArrowLeft`;
  breadcrumb/sort icons use the appropriate Lucide icon.
- Keep buttons consistent in height, radius, padding and font size. The user has
  repeatedly requested 14px action text and careful mobile alignment.
- Do not alter image assets or existing thumbnail generation/crops casually.
- Do not push without an explicit new request. Earlier deployment approvals were
  for specific past changes and are not blanket authorization.

## Prototype and selected direction

Editable prototype fragment:

`/Users/suadasceric/.codex/visualizations/2026/10/05/01a10d0b-2991-7f52-b33d-0486003d3128/wave-home-ios.html`

It is a self-contained HTML fragment, approximately 191 KB, with embedded example
images and local interactions. It is a visual reference, **not production code**.
Do not paste it into Vue or use its mock data as real records.

Two alternative layouts are available:

1. **Jasan početak**: creator/company role choice, one main action, relevant
   campaign/creator previews, then a short explanation of the workflow. Desktop
   places the introductory content beside the initial preview row.
2. **Istraži prvo**: short headline, search, category selector and browsing rows.
   Campaign and creator previews remain available together; choosing companies
   also shows company examples.

The user's most recently selected alternative was **Istraži prvo**. This shows
interest, not final approval. Their last recorded controls were creator role,
companies category, logged in, desktop. Do not assume they approved every detail.

Prototype controls, supplied through the viewer's design controls:

- `Prikaz`: `Mobilni` / `Desktop`.
- `Prijavljen korisnik`: guest / logged-in header.
- `Zaobljenje kartica`: card corner radius.

Default view is desktop and logged in. At narrow available widths it naturally
uses the mobile composition. Both alternatives use the cream palette.

The revision includes four campaign examples and four creator/agency examples,
native horizontal scrolling, previous/next controls, fixed card image regions,
two reserved campaign-title lines, and whole-card click targets. Some example
profiles use initials as missing-photo placeholders. They are fictional examples,
not instructions to change real users' names, photos or records.

Logged-in examples include a notification list, mark-as-read interaction, message
list, conversation preview and account menu. These are local prototype states,
not a replacement for the existing inbox, authentication or unread-count logic.

Optional local review images, if still available:

- `/private/tmp/wave-home-desktop-revised.png`
- `/private/tmp/wave-home-mobile-revised.png`

The prototype preview server was stopped after testing. The actual app preview
was left at `http://127.0.0.1:5197/`.

## Brand and existing implementation

Repository: `/Users/suadasceric/Projects/wave`

Read these first:

- `/Users/suadasceric/Projects/wave/AGENTS.md`
- `/Users/suadasceric/Projects/wave/docs/project-handoff.md`
- `/Users/suadasceric/Projects/wave/docs/frontend-styles.md`

The architecture is a Symfony JSON API with a Vue SPA in a single deployment.
Frontend source is in `frontend/`, compiled into Symfony's `public/`. Do not add
Twig page views, a separate frontend deployment, or a new UI framework.

Preserve the brand tokens and fonts:

| Role | Value |
| --- | --- |
| Primary / forest | `#173D36` |
| Accent / coral | `#D98368` |
| Page / paper | `#F6F2EA` |
| Surface | `#E8E2DA` |
| Secondary | `#8EA39B` |
| Text | `#1F2A28` |
| Fonts | DM Sans, DM Serif Display, existing La Belle Aurore accents |

Main integration points:

- `/Users/suadasceric/Projects/wave/frontend/src/views/HomeView.vue`
- `/Users/suadasceric/Projects/wave/frontend/src/scss/views/HomeView.scss`
- `/Users/suadasceric/Projects/wave/frontend/src/App.vue` — real shared header,
  search, account controls, notification/message state and mobile navigation.
- `/Users/suadasceric/Projects/wave/frontend/src/scss/App.scss`
- `/Users/suadasceric/Projects/wave/frontend/src/scss/_tokens.scss`
- `/Users/suadasceric/Projects/wave/frontend/src/components/shared/CardGrid.vue`
- `/Users/suadasceric/Projects/wave/frontend/src/scss/components/shared/CardGrid.scss`
- `/Users/suadasceric/Projects/wave/frontend/src/components/campaigns/CampaignCard.vue`
- `/Users/suadasceric/Projects/wave/frontend/src/components/creators/CreatorCard.vue`
- `/Users/suadasceric/Projects/wave/frontend/src/components/companies/CompanyCard.vue`
- `/Users/suadasceric/Projects/wave/frontend/src/components/shared/RegistrationPrompt.vue`
- `/Users/suadasceric/Projects/wave/frontend/src/components/shared/ProfileTabs.vue`
- `/Users/suadasceric/Projects/wave/frontend/src/components/shared/FormModal.vue`
- `/Users/suadasceric/Projects/wave/frontend/src/components/shared/LocalizedLink.vue`
- `/Users/suadasceric/Projects/wave/frontend/src/components/shared/MessagesPanel.vue`
- `/Users/suadasceric/Projects/wave/frontend/src/components/shared/NotificationsPanel.vue`
- `/Users/suadasceric/Projects/wave/frontend/src/composables/useCurrentUser.js`

`HomeView.vue` already loads the public homepage through `apiGet('/homepage')`.
Keep its real data, featured/latest behavior, loading, error and empty states.
Reuse `CardGrid` for homepage-specific carousel behavior without changing directory
and bookmark layouts. Shared card changes need checking outside the homepage.

Production navigation must continue using the real account state, inbox panels,
permissions, unread counts and realtime behavior from `App.vue`. Do not recreate
these in the homepage, simulate them, or change the signed-in experience merely
to match the mock header.

Preserve localized links, clean login/registration routes, consent-controlled
tracking, accessibility, SEO and account restrictions. New interface copy belongs
in all seven current catalogs: `bs`, `hr`, `sr`, `sl`, `en`, `cnr`, `de`.
Any contact/newsletter/FAQ simplification needs a deliberate placement decision;
the prototype omitting a block is not approval to delete working functionality.

## Validation and working state

Prototype checks completed:

- JavaScript syntax check with Node.
- Desktop and narrow mobile visual review.
- Creator/company role switching and category switching.
- Carousel navigation and enabled/disabled previous button behavior.
- Message and notification previews, notification read-state update, account menu.
- Corrected image containment so portraits do not overlap card text.

This was tested in the local in-app browser at different widths, not on a physical
iPhone/Safari. No application tests were rerun for the prototype because no app
source was changed. Do not claim that production homepage implementation is done.

At handoff preparation, Git had only pre-existing untracked `.DS_Store` and
`output/`; preserve them. This handoff itself is a new documentation file.
Check current Git status again before any work; the user may make concurrent edits.

If implementation is subsequently requested, run:

```sh
npm --prefix frontend run lint
npm --prefix frontend test
npm --prefix frontend run build
```

Use the project's documented Node runtime. PHP 8.4 is available under
`/opt/homebrew/opt/php@8.4/bin`. For PHP changes, also run relevant backend tests
and `composer analyse`; database tests must use an isolated test database.
Never run schema-dropping tests against the user's local account database.

For any approved implementation, review guest/logged-in mobile and desktop states,
slider touch/keyboard behavior, long translated labels, image consistency and
loading/empty/error states. Preserve normal directories and profile pages.

## Next step

Review the revised **Istraži prvo** direction with the user and continue from their
feedback. If they ask to apply it, translate the approved design into the existing
Vue components and styles. Keep work local and reviewable; do not push or deploy
unless they explicitly request it.
