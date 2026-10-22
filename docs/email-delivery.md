# Verification email delivery

An email delivered to Outlook's **Other** tab has arrived in the inbox. Other is
different from Junk/Spam, a bounce, or an undelivered message. Outlook classifies
Focused/Other using recipient interactions and message characteristics. Brevo and
Wave cannot guarantee Focused placement. The recipient can choose **Always move
to Focused** for a Wave message to train their own mailbox.

Microsoft's guidance: https://support.microsoft.com/en-us/outlook/mail/focused-inbox-for-outlook

## Checks before changing SMTP providers

1. Find the message in Brevo's transactional logs. Check delivery, bounce, block
   and deferred outcomes for the particular recipient/time. Accepted/queued in
   Wave is not proof of recipient delivery.
2. Inspect the original received message headers. Check `Authentication-Results`
   for aligned DKIM and DMARC passes and the sending domain/Return-Path. DNS
   existence alone does not prove that a particular message passed authentication.
3. In Brevo's domain settings, check the actual sending domain's authentication
   status. Public checks on 22.10.2026 found a Brevo ownership record, a DMARC
   policy, and these DKIM CNAMEs for Wave:
   - `brevo1._domainkey.wave.ba` → `b1.wave-ba.dkim.brevo.com`
   - `brevo2._domainkey.wave.ba` → `b2.wave-ba.dkim.brevo.com`
4. Confirm the production sender is an address on the authenticated domain and
   `APP_BASE_URL` points to `https://wave.ba`. Keep account verification emails
   transactional and their content/action clear. Existing custom templates are
   preserved; review saved overrides separately from default templates.

Do not add duplicate SPF/DMARC records or change policies blindly. Brevo's required
records depend on its actual domain configuration; follow the values shown there.
No DNS or Brevo account setting was changed by the reminder implementation.

Brevo authentication guidance: https://help.brevo.com/hc/en-us/articles/12163873383186-Authenticate-your-domain-with-Brevo-Brevo-code-DKIM-DMARC

## Helping an unverified account

The signed-in verification notice stays visible across pages until the server
confirms the address. It provides resend and mailbox-tab hints. Admins can also
send a manual verification reminder or a profile tip from Approvals after reviewing
the recipient in the confirmation modal. Edit their localized subjects and HTML
under Email templates (`verification_reminder`, `profile_tip`). Messages use the
recipient's language and saved name and the existing queue. A reminder creates a
verification link without an expiry or invalidating the original registration
email. Successful verification invalidates all remaining verification links.
Migration `Version20261022140000` also removes expiry from retained original
verification tokens for active, unverified accounts; deleted tokens cannot be
recovered. No reminder
was sent to a real user during development or isolated UI tests.
