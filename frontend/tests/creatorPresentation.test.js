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
