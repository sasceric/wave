# Privacy and marketplace operations

Owner: UD SteelCode, contact info@wave.ba. Apply least-privilege access to the
admin tools, provider accounts and protected files. This is the operating
procedure accompanying the public notices; confirm production settings rather
than treating repository defaults as evidence.

## Rights requests and complaints

1. Log receipt date, category and a minimal case reference in the protected support
   system. Do not put requests, documents or email addresses in public issues.
2. Verify identity proportionately: authenticated account access or confirmation
   through the registered address normally suffices. Do not routinely ask for ID
   scans. Assess authorized representatives separately.
3. Identify the request's scope: account/profile, owned media, messages, support,
   email records, processors or search/cache copies. Use the established deletion
   service; direct SQL deletion would bypass shared-history and file cleanup.
4. Reply without undue delay, normally within 30 days. Explain any lawful extension,
   withheld data, legal retention or restriction, and the authority complaint route.
   Export personal data securely without disclosing another participant's private
   information. Log completion and the reason for any necessary retained data.
5. Forward required erasure/correction instructions to processors and record their
   result. Review shared-message content separately when it itself identifies the
   requester: anonymizing account fields does not rewrite message bodies.

Content/rights complaints use support or the same address. Capture the disputed
URL, reason and evidence. Restrict urgent harmful content when justified, explain
the decision where permitted, allow an appeal and record its independent review.
Do not promise that account approval verifies copyright or identity.

## Processing and retention register

| Data/purpose | Basis and access | Retention/action |
| --- | --- | --- |
| Account/profile/campaign workflows | Service agreement; owner and authorized participants; public fields according to visibility | Active account; deletion service removes personal fields, tokens and owned files |
| Messages and collaboration evidence | Service agreement and legitimate interests of participants/claims | Shared history retained with anonymized account; review necessity on closure/deletion requests and restrict where appropriate |
| Support, moderation and rights cases | Requested service, safety interests or legal duty | Review at closure; retain only records needed for unresolved obligations/claims, with reason and next review date |
| Newsletter | Explicit opt-in; limited staff access | Active consent; honor withdrawal immediately; retain minimal proof/suppression as necessary for compliance |
| Profile setup tips | Proposed legitimate interest in assisting account setup | At most four setup messages; stop on completion/opt-out; do not add promotional content under this basis |
| Analytics and Meta Pixel | Separate browser consent | Off before consent; choices expire after 180 days; withdrawal stops Wave events and clears removable cookies |
| Wave application/mail logs and encrypted previews | Troubleshooting/security interests | `LOG_RETENTION_DAYS=14`; scheduled `LogCleanupTask`; preview contents stay encrypted/private |
| Completed/discarded jobs | Reliability/security interests | Existing QueueMaintenance cleanup after 30 days |
| Recovery backups | Recovery/security interests | Operator-confirmed 14 days from creation; restricted recovery use and restore reconciliation |
| Brevo delivery events/previews | Email delivery and security | Separate provider setting; record actual selected period and test removal, never assume local cleanup deletes these |
| Link previews | Messaging service | Existing successful metadata cache 24 hours; failures 5 minutes |

The criteria-based rows require an actual case review; they do not authorize
indefinite retention. Do not add a fixed legal-claims period without establishing
which jurisdiction and claim applies. Access to logs or exports is not permission
to repurpose them for marketing.

## Profile-tip assessment

Purpose: help a person finish the account they requested. Necessity: short,
bounded instructions address missing profile fields rather than advertising other
brands/campaigns. Safeguards: maximum four, no newsletter enrollment, independent
one-click opt-out, stop on completion, preference checked before sending. Balance:
users reasonably expect setup assistance but may object; honor objections and
review complaints. Counsel/operator must record approval of this assessment for
the actual recipients and content. Promotional tips require a separate assessment
and consent where applicable.

## Provider register and transfers

- Hetzner Online GmbH: production in Germany, database/files/recovery copies.
  Download the concluded DPA from the actual hosting account and restrict access.
- Brevo SAS: email addresses, recipient names and message bodies; published database
  locations France/Germany/Belgium. Retain the agreement, current subprocessors,
  actual event/preview retention, suppression and tracking settings.
- Cloudflare, Inc.: proxy/CDN/security, request metadata and public cache resources;
  global processing can include the US. Record enabled features, DPA and applicable
  transfer safeguards; verify private APIs and authenticated pages are not cached.
- Google/Apple: optional authentication. Google Analytics/YouTube and Meta: optional
  analytics/marketing or user-opened video services. Document configured accounts,
  purposes and transfer arrangements separately; browser consent is not a DPA.
- Browser push providers: delivery endpoints selected by the person's browser;
  subscriptions are private and removed/detached by existing account/device flows.

Review providers and notices when adding a service or changing a region/purpose.
Keep copies of actual safeguards and provide relevant information on request;
public vendor links alone do not establish a concluded agreement.

## Backup restore procedure

Before restoring, isolate the restored application: disable public access,
outgoing email, push, search publication and background workers. Reconcile every
account erasure, consent withdrawal and material correction since the backup's
time using a protected deletion/change register held outside the restored backup
set. Reapply deletions through the established service and clean owned files,
search indexes, queued deliveries and device subscriptions. Confirm that restored
accounts cannot sign in or resume notifications contrary to subsequent requests.
Only then reopen the application. Keep minimal completion evidence, with restricted
access and a defined review date. Do not keep deleted profile copies as that evidence.
This runbook does not create automatic backup reconciliation; the operator must
maintain the protected register and exercise the procedure before a real restore.

## Incidents

Contain access, preserve necessary evidence, rotate compromised credentials and
contact affected processors. Record timing, data, persons, consequences and
remediation. Assess authority/individual notification duties promptly under the
applicable law; where the 72-hour authority deadline applies, track it from
awareness rather than completion of the investigation. Document the notification
or reason it is not required. Keep incident details out of public repositories.

## Releasing legal changes

Archive every published locale in `config/legal/<version>.json`. Change both
`LegalPolicies::VERSION` and `config/legal_documents.json`, retain earlier
archives and test catalog equality. Do not rewrite old acceptance receipts.
Do not reuse an old legal version for substantive changes. New signup receipts
include a SHA-256 archive fingerprint, source, locale and time, without extra IP
or fingerprint collection. Existing-account notice is independently controlled
by `notifyExistingUsers`; sending a notice is not affirmative acceptance.
