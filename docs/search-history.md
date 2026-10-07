# Header search and search history

The header searches the selected public directory (creators, campaigns, or companies). Suggestions use the existing directory API and its public visibility checks. Selecting “See all” opens the matching localized directory with the search term in `q`.

Submitted searches (Enter, the search button, “See all”, or selecting a result) are recorded through CSRF-protected `POST /api/search/history`. Suggestions while typing are not logged. Opening results and then choosing “See all” records the same search once within that interaction. The endpoint accepts a category, a term of up to 200 characters, and a submission source. It allows 30 entries per minute per client IP; IP addresses are used only for rate limiting and are not written to the log.

Entries contain the time, term, category, language, and source. Existing logging processors redact credentials and email addresses. No account identifier is recorded. Logging failures do not block searching.

Daily files are named `var/log/search-YYYY-MM-DD.log` and follow `LOG_RETENTION_DAYS`. After the first submitted search, the file appears automatically under **Admin → Tools → Logs** in the file selector. The existing administrator access checks, log pagination, and detail dialog apply. There is no database migration or deployment job to register.
