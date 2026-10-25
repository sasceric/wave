import assert from 'node:assert/strict'
import { test } from 'node:test'
import { incompleteProfileScore, plainTextLength, profileKind, profileStrength } from '../src/lib/profileStrength.js'
import { htmlToPlainText, paragraphsHtml, profileRequestBody } from '../src/lib/profileDraft.js'

const creator = (profile = {}) => ({ accountType: 'creator', approved: true, profileComplete: true, profile })
const company = (profile = {}) => ({ accountType: 'company', approved: true, profileComplete: true, profile })
const longText = (length) => `<p>${'a'.repeat(length)}</p>`

test('profile kind follows account and company type', () => {
  assert.equal(profileKind(creator()), 'creator')
  assert.equal(profileKind(company({ companyType: 'agency' })), 'agency')
  assert.equal(profileKind(company({ companyType: 'brand' })), 'brand')
  assert.equal(profileKind({ accountType: 'admin' }), null)
  assert.equal(profileStrength(null), null)
})

test('weights add up to 100 for every account type', () => {
  for (const user of [creator(), company({ companyType: 'agency' }), company()]) {
    const strength = profileStrength(user)
    assert.equal(strength.steps.reduce((sum, step) => sum + step.points, 0), 100)
    assert.equal(strength.score, 0)
    assert.equal(strength.level, 1)
    assert.equal(strength.next, 40)
    assert.equal(strength.missing.length, strength.steps.length)
  }
})

test('creator steps need meaningful content', () => {
  const partial = profileStrength(creator({
    avatarUrl: '/a.webp',
    bio: longText(149),
    portfolio: [{}, {}],
    socialProfiles: [{ platform: 'instagram', handle: 'wave', followers: 0 }],
    packages: [{ title: 'Reel', price: 0 }],
  }))
  assert.equal(partial.score, 20)
  assert.deepEqual(partial.missing.slice(0, 3).map((step) => step.key), ['works', 'bio', 'social'])

  const full = profileStrength(creator({
    avatarMediaId: 4,
    bio: longText(150),
    portfolio: [{}, {}, {}],
    socialProfiles: [{ platform: 'instagram', handle: 'wave', followers: 1200, engagementRate: 3.2 }],
    packages: [{ title: 'Reel', price: 150 }],
    faqs: [{ question: 'Q', answer: 'A' }],
  }))
  assert.equal(full.score, 100)
  assert.equal(full.level, 4)
  assert.equal(full.next, null)
})

test('brand and agency levels use the 40/70/100 ladder', () => {
  const brand = profileStrength(company({ logoUrl: '/l.webp', about: longText(80), socialLinks: [{ platform: 'web', url: 'https://wave.ba' }] }))
  assert.equal(brand.score, 70)
  assert.equal(brand.level, 3)
  assert.equal(brand.next, 100)
  const agency = profileStrength(company({ companyType: 'agency', logoUrl: '/l.webp', about: longText(79), services: [{ id: 1 }], packages: [{ title: 'Pack' }] }))
  assert.equal(agency.score, 40)
  assert.equal(agency.level, 2)
})

test('navigation hint only shows for active, incomplete profiles', () => {
  assert.equal(incompleteProfileScore(creator({ avatarUrl: '/a.webp' })), 20)
  assert.equal(incompleteProfileScore({ ...creator(), approved: false }), null)
  assert.equal(incompleteProfileScore({ ...creator(), profileComplete: false }), null)
  assert.equal(incompleteProfileScore({ ...creator(), isAdmin: true }), null)
  assert.equal(incompleteProfileScore(company({
    logoUrl: '/l.webp', coverUrl: '/c.webp', about: longText(90), industries: ['food'], socialLinks: [{ url: 'https://x.ba' }],
  })), null)
})

test('plain text helpers round-trip quick editor text', () => {
  assert.equal(plainTextLength('<p>Hi&nbsp;<b>there</b></p>'), 8)
  const html = paragraphsHtml('First <line>\nnext\n\nSecond & last')
  assert.equal(html, '<p>First &lt;line&gt;<br>next</p><p>Second &amp; last</p>')
  assert.equal(htmlToPlainText(html), 'First <line>\nnext\n\nSecond & last')
})

test('profile request body requires a phone', () => {
  assert.throws(() => profileRequestBody(creator(), { displayName: 'A' }), (error) => error.code === 'phone')
})

test('the quick editor draft clones a reactive session user', async () => {
  const { reactive } = await import('vue')
  const { accountProfileDraft } = await import('../src/lib/profileDraft.js')
  const user = reactive({ accountType: 'creator', phone: '+38761123456', city: 'Sarajevo', profile: { displayName: 'A', faqs: [{ question: 'Q', answer: 'A' }] } })
  const draft = accountProfileDraft(user)
  draft.faqs.push({ question: 'Q2', answer: 'A2' })
  assert.equal(user.profile.faqs.length, 1)
  assert.equal(draft.city, 'Sarajevo')
})

test('guide steps follow the score weights, and email pages stay reachable before approval', async () => {
  const { profileSteps } = await import('../src/lib/profileStrength.js')
  const { accountAccessRedirect } = await import('../src/lib/accountAccess.js')
  assert.deepEqual(profileSteps('brand').map((step) => step.key), ['logo', 'about', 'links', 'cover', 'industries'])
  assert.equal(profileSteps('agency').reduce((sum, step) => sum + step.points, 0), 100)
  assert.deepEqual(profileSteps('unknown'), [])
  const pending = { accountType: 'creator', approved: false, emailVerified: false, profileComplete: false }
  for (const name of ['account-email-preferences', 'account-profile-guide']) {
    assert.equal(accountAccessRedirect({ meta: { routeName: name } }, pending), null)
  }
})
