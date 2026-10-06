# Frontend SCSS

Vue components and views own their styles in adjacent files, for example
`MessagesView.vue` / `MessagesView.scss` and `DirectoryFilters.vue` /
`DirectoryFilters.scss`. Sass is a locked frontend development dependency; Vite
compiles it during development and the production build.

## Where styles belong

- `frontend/src/styles/global.scss` imports only app-wide foundations: tokens,
  resets and typography, layout utilities, buttons, basic forms, accessibility
  helpers and the shared shimmer animation. It does not import page styles.
- `frontend/src/styles/_breakpoints.scss` provides `down()` and `up()` mixins. It
  emits no CSS on its own. Existing CSS custom properties remain runtime design
  tokens so colors and inherited themes continue working.
- `App.scss` owns the header, footer, navigation, notification popover and PWA
  update notice. Route styles live beside their view or reusable child.
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

Attach styles to the component that renders the markup:

```vue
<style lang="scss" src="./ExampleComponent.scss"></style>
```

Use `scoped` when selectors must be local to that component. The migrated BEM
selectors remain unscoped deliberately: page context, shared skeleton skins and
rendered rich-text descendants rely on those selectors. Do not add `scoped` to
them without checking those relationships. New components can use scoped styles
and explicit `:deep()` for intentional child overrides.

Responsive rules stay in the owning file:

```scss
@use '../../styles/breakpoints';

.example-component {
  color: var(--forest);
}

@include breakpoints.down(760px) {
  .example-component {
    padding: 12px;
  }
}
```

Creator, campaign and company cards import their adjacent SCSS in their script.
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
