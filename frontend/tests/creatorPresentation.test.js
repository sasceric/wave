import assert from 'node:assert/strict'
import test from 'node:test'
import { emptyWork, normalizePresentation, presentationPayload } from '../src/lib/creatorPresentation.js'

test('new works are public by default and an explicit private choice survives saving', () => {
  assert.equal(emptyWork().publicPermission, true)
  const profile = normalizePresentation({ portfolio: [{ ...emptyWork(), publicPermission: false }] })
  assert.equal(profile.portfolio[0].publicPermission, false)
  assert.equal(presentationPayload(profile).portfolio[0].publicPermission, false)
})

test('legacy profiles keep their public work and unknown metrics stay empty', () => {
  const profile = normalizePresentation({ portfolio:[{id:'legacy',type:'image',url:'/image.webp'}], socialProfiles:[{platform:'Instagram',followers:0}], packages:[{title:'Custom',price:null}] })
  assert.equal(profile.portfolio[0].publicPermission,true)
  assert.equal(profile.socialProfiles[0].averageViews,null)
  assert.equal(profile.packages[0].priceMode,'agreement')
  assert.deepEqual(profile.presentation.languages,[])
})
test('extended payload preserves dates and sends nullable numeric values correctly', () => {
  const profile=normalizePresentation({portfolio:[],packages:[{price:250,priceMode:'agreement',deliveryDays:'5',revisions:'0',usage:'ads',usageDays:'30'}],socialProfiles:[{platform:'Spotify',handle:'show',followers:'',averageViews:'120',lastUpdated:'2026-09-01',audienceCountries:[{countryCode:'BA',percent:'65.5'}]}]})
  const payload=presentationPayload(profile)
  assert.equal(payload.socialProfiles[0].followers,null)
  assert.equal(payload.socialProfiles[0].averageViews,120)
  assert.equal(payload.socialProfiles[0].lastUpdated,'2026-09-01')
  assert.equal(payload.socialProfiles[0].audienceCountries[0].percent,65.5)
  assert.equal(payload.packages[0].price,null)
  assert.equal(payload.packages[0].revisions,0)
  assert.equal(payload.packages[0].usageDays,30)
})

test('legacy media and original post become separate reference rows without losing delivery data', () => {
  const original = { id: 'legacy-work', type: 'image', url: '/old.webp', platform: 'Instagram', postUrl: 'https://instagram.com/p/original/', media: [{ type: 'video', url: 'https://youtu.be/abcdefghijk', embedUrl: 'https://www.youtube-nocookie.com/embed/abcdefghijk' }], deliverables: [{ quantity: 3, format: 'photo', duration: null }] }
  const profile = normalizePresentation({ portfolio: [original] })
  assert.equal(profile.portfolio[0].references.length, 3)
  assert.equal(profile.portfolio[0].references[0].legacyImage, true)
  assert.equal(profile.portfolio[0].references[1].platform, 'Instagram')
  const saved = presentationPayload(profile).portfolio[0]
  assert.deepEqual(saved.references.map(row => row.url), ['/old.webp', 'https://youtu.be/abcdefghijk', 'https://instagram.com/p/original/'])
  assert.deepEqual(saved.deliverables, original.deliverables)
  assert.equal(original.references, undefined)
  assert.equal(saved.url, '/old.webp')
})

test('reference uploads preserve post URLs and numeric IDs, without duplicating the legacy primary media', () => {
  const profile = normalizePresentation({ portfolio: [{ type: 'image', url: '/old.webp', media: [{ type: 'video', url: 'https://youtu.be/abcdefghijk' }], references: [{ type: 'image', platform: 'Facebook', mediaId: '12', imageUrl: '/uploads/work.webp', url: 'https://facebook.com/posts/123' }, { type: 'video', platform: 'TikTok', url: 'https://www.tiktok.com/@creator/video/123' }] }] })
  const work = presentationPayload(profile).portfolio[0]
  assert.equal(work.references.length, 2)
  assert.equal(work.references[0].mediaId, 12)
  assert.equal(work.references[0].url, 'https://facebook.com/posts/123')
  assert.equal('imageUrl' in work.references[0], false)
  assert.equal(work.references[1].platform, 'TikTok')
})
