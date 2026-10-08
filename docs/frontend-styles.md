# Frontend SCSS

All SCSS lives under `frontend/src/scss/`. Component and view styles mirror the
Vue folder structure: `views/MessagesView.vue` uses `scss/views/MessagesView.scss`,
and `components/shared/DirectoryFilters.vue` uses
`scss/components/shared/DirectoryFilters.scss`. Sass is a locked frontend
development dependency; Vite compiles it during development and the production build.

## Brand palette

The user reconfirmed these exact brand colors on 2026-10-08. Preserve them during
SEO, performance, and accessibility work; do not replace brand colors to improve
an audit score. Preserve the existing DM Sans, DM Serif Display, and La Belle
Aurore fonts as well.

| Role | Hex | Existing token |
| --- | --- | --- |
| Primary | `#173D36` | `--forest` |
| Accent | `#D98368` | `--coral` |
| Background | `#F6F2EA` | `--paper` |
| Surface | `#E8E2DA` | `--surface` (`--line` aliases it) |
| Secondary | `#8EA39B` | `--secondary` |
| Text | `#1F2A28` | `--ink` |

Use these shared tokens for new brand elements. Existing neutral form/card
backgrounds, muted text shades, and semantic status colors remain part of the
surrounding design; do not introduce a darker brand palette without the user's
request. The shared foundations live in `frontend/src/scss/_tokens.scss`.

## Where styles belong

- `frontend/src/scss/global.scss` imports only app-wide foundations: tokens,
  resets and typography, layout utilities, buttons, basic forms, accessibility
  helpers and the shared shimmer animation. It does not import page styles.
- `frontend/src/scss/_breakpoints.scss` provides `down()` and `up()` mixins. It
  emits no CSS on its own. Existing CSS custom properties remain runtime design
  tokens so colors and inherited themes continue working.
- `scss/App.scss` owns the header, footer, navigation, notification popover and PWA
  update notice. Route styles live under `scss/views/`; reusable component styles
  live under matching `scss/components/` subdirectories.
- `CardGrid` owns creator, campaign and company grid layouts, including homepage
  carousels and account bookmarks. Directory creator/campaign grids keep four
  desktop columns and two columns at widths up to 760px.
- `DirectoryPage`, `AccountPage` and `AdminPage` own shared page shells.
  `AdminTableFrame` owns table styling for both admin table implementations.
  `AdminMetrics` owns metric-card geometry for both real cards and their skeletons.
- `RichTextContent` owns rendered biography styling and sanitizes its HTML.
  `RichTextEditor` continues to own editor and Quill overrides.
- Existing reusable controls, cards, loading states and dialogs own their own
  SCSS. The four previously scoped styles remain scoped after extraction.

Attach styles to the component that renders the markup. For a component under
`frontend/src/components/shared/`:

```vue
<style lang="scss" src="../../scss/components/shared/ExampleComponent.scss"></style>
```

Use `scoped` when selectors must be local to that component. The migrated BEM
selectors remain unscoped deliberately: page context, shared skeleton skins and
rendered rich-text descendants rely on those selectors. Do not add `scoped` to
them without checking those relationships. New components can use scoped styles
and explicit `:deep()` for intentional child overrides.

Group related elements, modifiers, states and descendants with Sass nesting.
Keep selector order when nesting existing rules, since the cascade depends on it.
Use shared breakpoint mixins for responsive rules in the owning file:

```scss
@use '../../breakpoints';

.example-component {
  color: var(--forest);

  &__title {
    margin: 0;
  }

  &:focus-visible {
    outline: 2px solid var(--forest);
  }
}

@include breakpoints.down(760px) {
  .example-component {
    padding: 12px;
  }
}
```

Creator, campaign and company cards import their matching SCSS modules from
`scss/components/` in their script.
`DirectorySkeletonCard` imports those same modules to reserve identical geometry.
Bare imports give Vite one module identity per card skin rather than separate
external-style requests with different block indices. `AdminMultiSelect` also
attaches the existing shared `MultiSelect.scss` menu skin before its own overrides.

When several views need a layout or control, reuse its component and stylesheet.
Keep page-specific descendant overrides in that page. Avoid duplicating whole
style files or adding a global import of every feature.

## Loading and caching

Routes already use dynamic Vue imports. `build.cssCodeSplit: true` keeps feature
CSS in those routes' dependency chunks. Vite loads an asynchronous chunk's CSS
before evaluating it; no manual stylesheet loader is needed.
See [Vite CSS code splitting](https://vite.dev/guide/features.html#css-code-splitting).

Vue ownership does not mean a separate HTTP file for every SCSS source: Vite
combines and shares modules as needed. Shared controls used by the app shell load
initially; page-specific chat, table and authentication styles do not. Loaded
styles may stay in the SPA after navigation, so use component class names and
explicit context selectors rather than broad page-level element selectors.

The existing PWA still precaches all built static chunks for offline navigation.
Consequently this change reduces render-critical CSS, but the first service-worker
installation still downloads other page assets in the background. Sass itself
does not make CSS smaller, and these byte counts are not a measured page-speed
percentage.

## Migration verification

The original 5,308-line stylesheet is preserved byte-for-byte in
[`style-backup/wave-2026-10-06.css`](style-backup/wave-2026-10-06.css), outside the
frontend build. It is a reference only and must not be imported or edited.

The migration produced 54 component/view SCSS files and eight foundation/mixin
partials. A Sass + PostCSS comparison matched all 2,099 original selector rules,
declarations, importance flags and media/keyframe contexts, with no missing or
extra rules. The four scoped blocks were also preserved separately.

The subsequent relocation into `scss/` verified that all 63 Sass source files
compile to identical CSS before and after the move. No SCSS remains alongside
Vue files; component and view stylesheet paths match their Vue paths.
Related rules in 58 stylesheets now use Sass nesting for BEM elements/modifiers,
states and descendants. A second Sass + PostCSS comparison verified that all 63
compiled stylesheets retain the same selectors, declarations, at-rule contexts
and cascade order. Runtime CSS custom properties remain in place for inherited
themes; isolated base rules do not need artificial nesting or Sass variables.

Local production-build measurements, excluding external font CSS:

| CSS | Before | After |
| --- | ---: | ---: |
| Initial entry stylesheet, minified | 192,189 bytes | 42,627 bytes |
| Initial entry stylesheet, gzip | 34,950 bytes | 8,930 bytes |
| Homepage styles including route dependencies, minified | 192,189 bytes | 66,699 bytes |
| Homepage styles including route dependencies, gzip | 34,950 bytes | 15,697 bytes |

The build emitted 24 CSS chunks. Initial CSS did not contain chat-composer,
admin-table or registration selectors. Chrome checks covered four desktop/two
mobile directory columns, account display/edit/cancel and mobile chat. At a
428×430 viewport the composer input and Send button both stayed within the
viewport, with 16px input text. This checks responsive geometry, not a native iOS
keyboard. Existing chat keyboard regression tests remain unchanged.

Validation commands:

```sh
npm --prefix frontend ci
npm --prefix frontend run lint
npm --prefix frontend test
npm --prefix frontend run build
git diff --check
```

ESLint, all 102 frontend tests and the production/PWA build passed. The final
test run used Node 24.19 with `--test-concurrency=1` after the documented Node 22
VM-module runner intermittently crashed with `SIGSEGV`; the production build
passed on Node 22.20. No PHP or database code changed, so backend tests and
PHPStan were not needed for this stylesheet migration.

## Deploying

Use the normal deployment workflow. `npm ci` installs Sass from the lockfile and
`npm run build` produces hashed assets and the updated service worker under
Symfony's `public/`. No environment variables, database migrations or worker
changes are required. Installed PWAs should accept the app's update prompt and
reload; a cached older app shell may keep using its older CSS until that update.
