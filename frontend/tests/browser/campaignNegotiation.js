import assert from 'node:assert/strict'
import { spawn, spawnSync } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { mkdtemp, readFile, writeFile, mkdir, open } from 'node:fs/promises'
import net from 'node:net'
import os from 'node:os'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { chromium, webkit } from 'playwright'

const root = fileURLToPath(new URL('../../..', import.meta.url))
const databaseUrl = process.env.WAVE_E2E_DATABASE_URL
assert.ok(databaseUrl, 'Set WAVE_E2E_DATABASE_URL to the dedicated local wave_browser_e2e PostgreSQL database.')
const database = new URL(databaseUrl)
assert.ok(['postgresql:', 'postgres:'].includes(database.protocol))
assert.ok(['127.0.0.1', 'localhost'].includes(database.hostname))
assert.equal(database.pathname, '/wave_browser_e2e', 'Refusing to reset a regular account database.')
const port = Number(process.env.WAVE_E2E_PORT || 8027)
const hubPort = Number(process.env.WAVE_E2E_HUB_PORT || 8028)
const origin = `http://127.0.0.1:${port}`
const hubOrigin = `http://127.0.0.1:${hubPort}`
const php = process.env.PHP_BIN || 'php'
const hubBinary = process.env.MERCURE_BIN || path.join(root, 'var/mercure/mercure')
const artifacts = process.env.WAVE_E2E_ARTIFACTS || await mkdtemp(path.join(os.tmpdir(), 'wave-browser-negotiation-'))
await mkdir(artifacts, { recursive: true })
const secret = randomBytes(32).toString('hex')
const password = randomBytes(24).toString('hex')
const env = {
  ...process.env, APP_ENV: 'browser', APP_DEBUG: '0', DATABASE_URL: databaseUrl,
  APP_SECRET: secret, APP_BASE_URL: origin,
  WAVE_E2E_PASSWORD: password, WAVE_QUEUE_ENABLED: 'false', MEILISEARCH_ENABLED: 'false',
  MAILER_DSN: 'null://null', MAIL_FROM_ADDRESS: 'no-reply@wave.test', MAIL_FROM_NAME: 'Wave',
  WAVE_NOTIFICATIONS_ENABLED: 'true', WEB_PUSH_PUBLIC_KEY: '', WEB_PUSH_PRIVATE_KEY: '',
  MERCURE_URL: `${hubOrigin}/.well-known/mercure`, MERCURE_PUBLIC_URL: `${hubOrigin}/.well-known/mercure`,
  MERCURE_ISSUER: origin, MERCURE_JWT_SECRET: secret,
  MERCURE_PUBLISHER_JWT_KEY: secret, MERCURE_SUBSCRIBER_JWT_KEY: secret,
}
const bs = JSON.parse(await readFile(path.join(root, 'frontend/src/locales/bs.json'), 'utf8'))
const text = key => key.split('.').reduce((value, part) => value[part], bs)
const processes = []
const browsers = []
const failures = []
const checkpoints = []
const resources = []
let capturedForm = false
const note = step => { checkpoints.push(step); console.log(step) }

async function assertFree(candidate) {
  await new Promise((resolve, reject) => {
    const server = net.createServer()
    server.once('error', reject)
    server.listen(candidate, '127.0.0.1', () => server.close(resolve))
  })
}
function runPhp(args) {
  const result = spawnSync(php, args, { cwd: root, env, encoding: 'utf8' })
  if (result.status !== 0) throw new Error(result.stderr || result.stdout || 'PHP fixture setup failed.')
  return result.stdout.trim()
}
async function start(command, args, logName) {
  const log = await open(path.join(artifacts, logName), 'w', 0o600)
  resources.push(log)
  const child = spawn(command, args, { cwd: root, env, stdio: ['ignore', log.fd, log.fd] })
  processes.push(child)
  child.on('error', error => failures.push(error.message))
  return child
}
async function waitForHttp(url) {
  for (let attempt = 0; attempt < 100; attempt++) {
    if (processes.some(child => child.exitCode !== null)) throw new Error(`A test service stopped. See ${artifacts}.`)
    try { if ((await fetch(url)).ok) return } catch { /* Wait for our own service to bind. */ }
    await new Promise(resolve => setTimeout(resolve, 100))
  }
  throw new Error(`Service did not become ready: ${url}`)
}
async function login(page, email) {
  await page.goto(`${origin}/racun`)
  await page.locator('input[autocomplete="email"]').waitFor()
  const consent = page.getByRole('button', { name: text('legal.rejectOptional'), exact: true })
  if (await consent.isVisible()) await consent.click()
  await page.locator('input[autocomplete="email"]').fill(email)
  await page.locator('input[autocomplete="current-password"]').fill(password)
  const response = page.waitForResponse(response => response.url().includes('/api/auth/login') && response.request().method() === 'POST')
  await page.locator('form').filter({ has: page.locator('input[autocomplete="current-password"]') }).locator('button[type="submit"]').click()
  assert.equal((await response).status(), 200)
  await page.locator('input[autocomplete="current-password"]').waitFor({ state: 'hidden' })
}
async function api(page, route, body) {
  return page.evaluate(async ({ route, body }) => {
    const token = await fetch('/api/auth/csrf').then(response => response.json())
    const response = await fetch(`/api${route}`, body === undefined ? {} : {
      method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token.csrfToken }, body: JSON.stringify(body),
    })
    return { status: response.status, payload: await response.json() }
  }, { route, body })
}
async function sendMessage(sender, recipient, message) {
  await sender.locator('.campaign-messages__composer-field input').fill(message)
  await sender.locator('.campaign-messages__send').click()
  await recipient.locator('.campaign-messages__message').getByText(message, { exact: true }).waitFor()
}
async function offer(company, amount, dueDate) {
  await company.locator('.campaign-messages__details button').filter({ hasText: text('hiring.hire') }).click()
  const dialog = company.locator('dialog[open]')
  await dialog.locator('input[type="number"]').fill(String(amount))
  const dateLayout = await dialog.locator('.date-picker').evaluate(field => {
    const input = field.querySelector('input')
    const label = field.querySelector('label')
    const star = label.querySelector('.validation-required')
    return { padding: parseFloat(input.ownerDocument.defaultView.getComputedStyle(input).paddingLeft), labelTop: label.getBoundingClientRect().top, starTop: star.getBoundingClientRect().top }
  })
  assert.ok(dateLayout.padding >= 36, 'Calendar icon must not overlap the input text.')
  assert.ok(Math.abs(dateLayout.labelTop - dateLayout.starTop) < 5, 'Required marker must stay on the label line.')
  await dialog.locator('.date-picker input').click()
  if (!capturedForm) {
    await company.screenshot({ path: path.join(artifacts, 'hire-form-desktop.png') })
    await company.setViewportSize({ width: 375, height: 900 })
    await dialog.locator('.date-picker .dp--menu').waitFor()
    await company.screenshot({ path: path.join(artifacts, 'hire-form-mobile.png') })
    const bounds = await dialog.locator('.date-picker .dp--menu').boundingBox()
    assert.ok(bounds.x >= 0 && bounds.x + bounds.width <= 375, 'Calendar must fit the mobile viewport.')
    await company.setViewportSize({ width: 1440, height: 1000 })
    capturedForm = true
  }
  for (let month = 0; month < 24 && !await dialog.locator(`#dp-${dueDate}`).isVisible(); month++) {
    await dialog.getByRole('button', { name: text('datePicker.nextMonth'), exact: true }).click()
  }
  await dialog.locator(`#dp-${dueDate}`).click()
  await dialog.locator('textarea').fill(`Agreed browser test scope for ${amount} KM, one video and five photographs.`)
  const response = company.waitForResponse(response => /\/api\/company\/(conversations|inquiries)\/\d+\/hire/.test(response.url()) && response.request().method() === 'POST')
  await dialog.getByRole('button', { name: text('hiring.sendOffer'), exact: true }).click()
  const sent = await response
  assert.equal(sent.status(), 201, await sent.text())
  await dialog.waitFor({ state: 'hidden' })
  return (await sent.json()).data
}
async function cancelCollaboration(sender, recipient, reason) {
  const trigger = sender.locator('.campaign-messages__details .campaign-cancel-action')
  const layout = await trigger.evaluate(button => {
    const parent = button.parentElement
    const view = button.ownerDocument.defaultView
    const style = view.getComputedStyle(parent)
    return { width: button.getBoundingClientRect().width, parentWidth: parent.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight), marginTop: parseFloat(view.getComputedStyle(button).marginTop) }
  })
  assert.ok(Math.abs(layout.width - layout.parentWidth) < 2)
  assert.ok(layout.marginTop >= 10)
  await trigger.click()
  const dialog = sender.locator('dialog[open]')
  await dialog.getByRole('button', { name: text('hiring.confirmCancellation'), exact: true }).click()
  assert.equal(await dialog.isVisible(), true, 'An empty cancellation reason must not submit.')
  await dialog.locator('textarea').fill(reason)
  const response = sender.waitForResponse(response => /\/api\/me\/(offers|inquiry-offers)\/\d+\/cancel/.test(response.url()) && response.request().method() === 'POST')
  await dialog.getByRole('button', { name: text('hiring.confirmCancellation'), exact: true }).click()
  assert.equal((await response).status(), 200)
  await dialog.waitFor({ state: 'hidden' })
  await recipient.locator('.campaign-messages__message p').filter({ hasText: reason }).waitFor()
  await sender.locator('.campaign-messages__composer-field input[disabled]').waitFor()
  await recipient.locator('.campaign-messages__composer-field input[disabled]').waitFor()
}
async function respond(creator, decision) {
  const key = decision === 'accept' ? 'hiring.acceptOffer' : 'hiring.declineOffer'
  await creator.locator('.campaign-messages__offer-confirmation').getByRole('button', { name: text(key), exact: true }).click()
  const dialog = creator.locator('dialog[open]')
  await dialog.getByText(new RegExp(text('hiring.dueDate'))).waitFor()
  await dialog.getByRole('button', { name: text('hiring.confirm'), exact: true }).click()
  await dialog.waitFor({ state: 'hidden' })
}

try {
  await assertFree(port)
  await assertFree(hubPort)
  runPhp(['bin/console', 'cache:clear', '--no-warmup'])
  const fixture = JSON.parse(runPhp(['tests/Browser/seed-campaign-negotiation.php']))
  const caddyfile = path.join(artifacts, 'Caddyfile')
  await writeFile(caddyfile, `{
  auto_https off
  admin off
}
http://127.0.0.1:${hubPort} {
  bind 127.0.0.1
  mercure {
    issuer ${origin} {
      publisher {
        jwt {env.MERCURE_PUBLISHER_JWT_KEY} HS256
      }
      subscriber {
        jwt {env.MERCURE_SUBSCRIBER_JWT_KEY} HS256
      }
    }
    cookie_name mercureAuthorization
    resource_identifier ${hubOrigin}/.well-known/mercure
    cors_origins ${origin}
    transport bolt {
      path ${path.join(artifacts, 'mercure.db')}
      size 1000
    }
  }
  respond / "Browser test Mercure"
}
`)
  await start(hubBinary, ['run', '--config', caddyfile, '--adapter', 'caddyfile'], 'mercure.log')
  await start(php, ['-d', 'variables_order=EGPCS', '-S', `127.0.0.1:${port}`, '-t', 'public', 'public/router.php'], 'backend.log')
  await waitForHttp(hubOrigin)
  await waitForHttp(`${origin}/api/auth/csrf`)
  const chromeOptions = process.env.WAVE_E2E_CHROME_PATH ? { executablePath: process.env.WAVE_E2E_CHROME_PATH } : { channel: 'chrome' }
  const chrome = await chromium.launch({ headless: true, ...chromeOptions })
  browsers.push(chrome)
  const safariEngine = await webkit.launch({ headless: true })
  browsers.push(safariEngine)
  const companyContext = await chrome.newContext({ viewport: { width: 1440, height: 1000 }, serviceWorkers: 'block' })
  const creatorContext = await safariEngine.newContext({ viewport: { width: 1440, height: 1000 }, serviceWorkers: 'block' })
  const company = await companyContext.newPage()
  const creator = await creatorContext.newPage()
  for (const [role, page] of [['company', company], ['creator', creator]]) {
    page.setDefaultTimeout(15000)
    page.on('pageerror', error => failures.push(`${role}: ${error.message}`))
    page.on('response', response => { if (response.url().startsWith(`${origin}/api/`) && response.status() >= 500) failures.push(`${role}: HTTP ${response.status()} ${new URL(response.url()).pathname}`) })
    await page.route('**/*', route => {
      // No third-party resources, email or external services are needed for this regression.
      const url = new URL(route.request().url())
      return [origin, hubOrigin].includes(url.origin) || ['data:', 'blob:'].includes(url.protocol) ? route.continue() : route.abort()
    })
  }
  await login(company, 'browser-company@example.test')
  await login(creator, 'browser-creator@example.test')
  assert.equal((await api(company, '/auth/session')).payload.data.id, fixture.companyId)
  assert.equal((await api(creator, '/auth/session')).payload.data.id, fixture.creatorId)
  note('Separate authenticated sessions: company in Chrome, creator in WebKit.')
  await creator.goto(`${origin}/kampanje/${fixture.campaignSlug}`)
  await creator.getByRole('button', { name: new RegExp(text('account.apply')) }).click()
  await creator.locator('#application-message').fill('I would like to create the video and photographs for this campaign.')
  const appliedResponse = creator.waitForResponse(response => response.url().includes(`/api/campaigns/${fixture.campaignSlug}/applications`) && response.request().method() === 'POST')
  await creator.getByRole('button', { name: new RegExp(text('account.sendApplication')) }).click()
  const applied = await appliedResponse
  assert.equal(applied.status(), 201, await applied.text())
  const application = (await applied.json()).data
  await company.goto(`${origin}/racun/kampanje/${fixture.campaignSlug}`)
  await company.getByRole('button', { name: 'Browser Creator', exact: true }).waitFor()
  const consent = company.getByRole('button', { name: text('legal.rejectOptional'), exact: true })
  if (await consent.isVisible()) await consent.click()
  await company.getByRole('button', { name: 'Browser Creator', exact: true }).click()
  assert.equal(await company.locator('dialog[open] input[type="number"]').count(), 0)
  await company.getByRole('button', { name: text('hiring.acceptApplication'), exact: true }).click()
  await company.waitForURL(url => url.searchParams.has('conversation'))
  const conversationId = Number(new URL(company.url()).searchParams.get('conversation'))
  assert.ok(conversationId)
  const afterAcceptance = await api(company, `/company/campaigns/${fixture.campaignSlug}/applications`)
  assert.equal(afterAcceptance.payload.data[0].offer, null)
  assert.equal(afterAcceptance.payload.data[0].campaign.hiredCount, 0)
  note('Application acceptance opens chat with no amount or offer and no hired count.')
  await creator.goto(`${origin}/poruke?conversation=${conversationId}`)
  await creator.locator('.campaign-messages__composer-field input').waitFor()
  await sendMessage(company, creator, 'Company: let us discuss the scope first.')
  await sendMessage(creator, company, 'Creator: one video and five photographs.')
  note('Both browsers receive negotiation messages live without reloading.')
  const dueDate = new Date(Date.now() + 14 * 86400000).toISOString().slice(0, 10)
  const firstOffer = await offer(company, 300, dueDate)
  assert.equal(firstOffer.dueDate, dueDate)
  await creator.locator('.campaign-messages__offer-confirmation').waitFor()
  await respond(creator, 'reject')
  await sendMessage(creator, company, 'Creator: I declined, can we agree on 400 KM?')
  await sendMessage(company, creator, 'Company: yes, let us revise the offer.')
  note('Creator declines 300 KM and both browsers keep chatting without a permission error.')
  const revisedOffer = await offer(company, 400, dueDate)
  assert.notEqual(firstOffer.id, revisedOffer.id)
  assert.equal(revisedOffer.campaign.hiredCount, 0)
  await creator.locator('.campaign-messages__offer-confirmation').waitFor()
  const stale = await api(creator, `/me/offers/${firstOffer.id}/respond`, { decision: 'accept' })
  assert.equal(stale.status, 409)
  await respond(creator, 'accept')
  await company.locator('.campaign-messages__message p').filter({ hasText: /potvrđen/ }).waitFor()
  await sendMessage(creator, company, 'Creator: accepted, I will deliver by the agreed date.')
  const confirmed = await api(company, `/company/campaigns/${fixture.campaignSlug}/applications`)
  assert.equal(confirmed.payload.data[0].id, application.id)
  assert.equal(confirmed.payload.data[0].campaign.hiredCount, 1)
  assert.equal(confirmed.payload.data[0].offer.id, revisedOffer.id)
  assert.equal(confirmed.payload.data[0].offer.amount, 400)
  assert.equal(confirmed.payload.data[0].offer.dueDate, dueDate)
  const history = await api(creator, `/me/conversations/${conversationId}/messages`)
  assert.deepEqual(history.payload.data.filter(message => message.event).map(message => [message.event.type, message.event.amount]), [['offer_sent', 300], ['offer_declined', 300], ['offer_sent', 400], ['hired', 400]])
  note('Revised 400 KM offer is confirmed, old offer stays declined, one hired creator and complete history.')
  const progressFont = await company.locator('.campaign-messages__details .campaign-hiring-progress').evaluate(element => parseFloat(element.ownerDocument.defaultView.getComputedStyle(element).fontSize))
  assert.ok(progressFont <= 14, 'Sidebar hiring count must use compact body text.')
  for (const [role, page] of [['company-chrome', company], ['creator-webkit', creator]]) {
    await page.screenshot({ path: path.join(artifacts, `${role}.png`), fullPage: true })
  }
  await cancelCollaboration(company, creator, 'Local browser test completed; cancelling this test collaboration.')
  assert.equal((await api(company, `/company/campaigns/${fixture.campaignSlug}/applications`)).payload.data[0].campaign.hiredCount, 0)
  note('Cancellation requires a reason, preserves it live for both participants and makes both chats read-only.')

  const created = await api(company, '/creators/browser-creator/inquiries', { other: true, packageIds: [], message: 'Let us discuss a direct collaboration in chat.' })
  assert.equal(created.status, 201)
  const inquiryId = created.payload.data.id
  assert.equal(created.payload.data.proposedAmount, null)
  assert.equal((await api(creator, `/me/inquiries/${inquiryId}/decision`, { decision: 'accept' })).status, 200)
  await company.goto(`${origin}/poruke?inquiry=${inquiryId}`)
  await creator.goto(`${origin}/poruke?inquiry=${inquiryId}`)
  await sendMessage(company, creator, 'Company: direct collaboration, let us agree on the price first.')
  await sendMessage(creator, company, 'Creator: ready to discuss direct collaboration terms.')
  const directFirst = await offer(company, 300, dueDate)
  await creator.locator('.campaign-messages__offer-confirmation').waitFor()
  await respond(creator, 'reject')
  await sendMessage(creator, company, 'Creator: direct offer declined; can we revise it to 400 KM?')
  const directRevised = await offer(company, 400, dueDate)
  assert.notEqual(directFirst.id, directRevised.id)
  await creator.locator('.campaign-messages__offer-confirmation').waitFor()
  await respond(creator, 'accept')
  await company.locator('.campaign-messages__details .campaign-cancel-action').waitFor()
  await cancelCollaboration(creator, company, 'Local direct collaboration test completed; cancelling the test engagement.')
  const directHistory = await api(company, `/me/inquiries/${inquiryId}/messages`)
  assert.deepEqual(directHistory.payload.data.filter(message => message.event).map(message => [message.event.type, message.event.amount]), [['offer_sent', 300], ['offer_declined', 300], ['offer_sent', 400], ['hired', 400], ['cancelled', 400]])
  note('Direct inquiry also negotiates, declines, revises, confirms and cancels through the same shared forms and datepicker.')
  assert.deepEqual(failures, [])
  await writeFile(path.join(artifacts, 'result.json'), JSON.stringify({ passed: true, checkpoints, engines: ['Chrome', 'WebKit'], mockedApi: false, realtime: true }, null, 2))
  console.log(`PASS. Screenshots and service logs: ${artifacts}`)
} catch (error) {
  for (const [index, browser] of browsers.entries()) {
    for (const context of browser.contexts()) for (const page of context.pages()) {
      await page.screenshot({ path: path.join(artifacts, `failure-${index}.png`), fullPage: true }).catch(() => {})
      await writeFile(path.join(artifacts, `failure-${index}.txt`), await page.locator('body').innerText().catch(() => '')).catch(() => {})
    }
  }
  console.error(`FAIL. Artifacts: ${artifacts}`)
  if (failures.length) console.error(failures)
  throw error
} finally {
  await Promise.all(browsers.map(browser => browser.close()))
  await Promise.all(processes.map(child => new Promise(resolve => {
    if (child.exitCode !== null) return resolve()
    child.once('exit', resolve)
    child.kill('SIGTERM')
  })))
  await Promise.all(resources.map(resource => resource.close()))
}
