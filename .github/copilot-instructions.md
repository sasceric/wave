# Wave coding instructions

## Reusable UI and design consistency

- Before implementing a UI pattern, inspect existing views and shared components. Reuse the existing component when it fits; when a pattern is needed across views, create a reusable Vue component and use it consistently instead of duplicating markup and behavior.
- Keep new components aligned with Wave's existing colors, typography, spacing, interaction states, accessibility, and responsive layouts. Prefer the existing design tokens and styles over one-off values.
- Avoid both duplicated UI and components that add indirection without reuse value.

## Code quality and formatting

- Keep all new and modified code well formatted and consistent with the surrounding files and `.editorconfig`.
- Write code and markup across readable, logically grouped lines. Do not compress logic, declarations, or templates into one-line code.
- Preserve existing architecture: Symfony serves the Vue SPA and JSON API in one deployment. Do not add Twig views or split the frontend and backend into separate projects.
- Keep user-facing interface copy in the existing Bosnian, Croatian, Serbian (Latin), Slovenian, and English translation catalogs.

## Required validation

- For code changes, always run PHPStan for PHP and ESLint for frontend JavaScript/Vue, along with the relevant tests or build.
- Use the repository's configured commands and address findings caused by the change. Do not claim a check passed unless it ran successfully.
- If PHPStan or ESLint is not installed or configured, state that explicitly as a validation blocker; do not silently skip it or substitute an unrelated check.
