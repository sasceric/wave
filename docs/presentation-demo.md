# Fictional presentation profiles

`app:seed-presentation-demo` upserts three ordinary participant accounts:

| Account | Email | Kind |
| --- | --- | --- |
| Amina Kovač | amina.kovac@wave.ba | Creator |
| DN kozmetik | dnkozmetik@wave.ba | Brand |
| Nova Digital | nova.digital@wave.ba | Agency |

The seed provides two audience channels, four portfolio projects, three packages,
three FAQ entries, four finished collaborations with accepted offers and eight
reciprocal reviews, read message history and two open example campaigns. Content
is Bosnian. Social links use reserved example domains; fictional creator handles
are displayed without outbound social links. It does not send emails, push
notifications or live chat events. No operator role or credits are granted.

`User.presentationDemoKey` identifies the accounts independently of editable
names, emails and URLs. `Campaign.presentationDemoKey` does the same for campaign
upserts. Apply `Version20261014120000` and `Version20261014121000` first.
These keys are server-managed, excluded from profile input, and exposed only as
an `isPresentationDemo` boolean. Public profiles, directory cards, campaigns,
reviews, account summaries and presentation previews show the localized
“Prototip · izmišljeni podaci” badge. Ordinary accounts have no badge.

## Run

From the application root, as the same owner that runs the normal console:

```sh
php8.4 bin/console doctrine:migrations:migrate --env=prod --no-interaction
php8.4 bin/console app:seed-presentation-demo --env=prod --dry-run
php8.4 bin/console app:seed-presentation-demo --env=prod
```

The final command asks for a hidden password (minimum 8 characters). The operator
supplies the requested shared password; it is never embedded in code, logs or this
file. For non-interactive use, supply `WAVE_PRESENTATION_PASSWORD` through a temporary
process environment and use `--no-interaction`. Do not keep it in the server's
permanent environment. Passwords are stored through Symfony's password hasher.

Rerunning refreshes demo profile content, but preserves existing passwords,
visibility, approval, locale and changed URLs. `--reset-passwords` explicitly
resets only the marked showcase accounts. Hide each profile using its normal
account switch; rerunning the seed will not reveal it again. Deleted showcase
accounts are never silently resurrected. A real account occupying a requested
email causes preflight to stop, without adopting it or changing its password.
Existing non-demo URL collisions receive the next available suffix through the
normal public-slug reservation service.

The import uses a database transaction, with cleanup of newly stored files on
rollback. Compressed source images live in `resources/presentation-demo/`; they are
imported via the existing `MediaStorage` service into owner-scoped avatar/logo,
cover and portfolio folders. Repeated imports reuse those media. Standard account
and file deletion policies apply. A missing existing media file stops the import
for repair instead of creating orphaned replacements.

This is a deliberate console operation, not a migration side effect, cron job or
auto-run deployment seed. Deploying the code alone does not create demo accounts.

## Studio Luma agency showcase

The approved **Usluge u fokusu** design has a separate, explicit import:

```sh
php8.4 bin/console app:seed-agency-showcase --env=prod --dry-run
php8.4 bin/console app:seed-agency-showcase --env=prod --no-interaction
```

`resources/presentation-demo/agency-luma.json` contains the prototype's Bosnian
copy: three structured services, three portfolio projects, two packages (from
650/1,200 BAM), three FAQs, public channels, languages and availability. The
logo and cover use owned imported media, alongside the existing demonstration
project photos. The intro video remains empty, as it was unconnected in the
prototype. Reserved example links never impersonate real social profiles.

The import creates Studio Luma and its marked fictional counterparties. Existing
marked Amina/DN accounts are reused without rewriting their profiles or account
settings; any conflicting unmarked email stops preflight. Nova Digital is not
replaced. Three additional hidden demo creators supply the example campaign's
five real application rows, rather than assigning an invented application total.
One accepted offer gives that campaign its `1/3` hired count. The other open
campaign has no applications. Both close on 10 November 2026.

Eight finished collaborations represent five provider and three client projects.
Six received reviews reproduce the prototype: four provider reviews averaging
4.8 and two client reviews averaging 5.0; combined, 4.8 from six reviews. Reciprocal
demo feedback follows the existing publication rules. All involved accounts,
campaigns and feedback retain their fictional-data markers and existing schema/
analytics exclusions. No emails, chat events or push notifications are sent.

Runs are transactional and repeatable: passwords, public URLs, approval,
visibility, existing reviews and campaign history are preserved. New accounts
receive random passwords unless `WAVE_PRESENTATION_PASSWORD` is supplied for that
run; no password is logged. Ordinary deployment never runs this seed. For an
intentional production run through GitHub Actions, manually dispatch **Deploy
production** with `seed_agency_showcase` enabled. It deploys, runs preflight and
imports the showcase using the existing production SSH credentials.
