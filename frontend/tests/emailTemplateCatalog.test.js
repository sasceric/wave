import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { EMAIL_TEMPLATE_CATALOG, EMAIL_TEMPLATE_GROUPS, renderTemplate } from '../src/lib/emailTemplateCatalog.js'

const controller = readFileSync(new URL('../../src/Controller/Api/EmailTemplateController.php', import.meta.url), 'utf8')
const keys = [...controller.match(/TEMPLATE_KEYS = \[([\s\S]*?)\];/)[1].matchAll(/'([a-z_]+)'/g)].map((match) => match[1])

test('every admin email template has a group, recipient and frequency with labels in every language', () => {
  assert.deepEqual(Object.keys(EMAIL_TEMPLATE_CATALOG).sort(), [...keys].sort())
  for (const locale of ['bs', 'hr', 'sr', 'cnr', 'sl', 'en', 'de']) {
    const labels = JSON.parse(readFileSync(new URL(`../src/locales/${locale}.json`, import.meta.url), 'utf8')).emailTemplates
    for (const group of EMAIL_TEMPLATE_GROUPS) assert.ok(labels.groups[group], `${locale} group ${group}`)
    for (const { group, recipient, frequency } of Object.values(EMAIL_TEMPLATE_CATALOG)) {
      assert.ok(EMAIL_TEMPLATE_GROUPS.includes(group))
      assert.ok(labels.recipients[recipient], `${locale} recipient ${recipient}`)
      assert.ok(labels.frequency[frequency], `${locale} frequency ${frequency}`)
    }
  }
})

test('the live preview fills variables like the server and escapes them', () => {
  assert.equal(renderTemplate('<h1>{{ heading }}</h1><p>{{missing}}</p>', { heading: 'A & <b>' }), '<h1>A &amp; &lt;b&gt;</h1><p>{{missing}}</p>')
  assert.equal(renderTemplate('{{subject}} · {{score}}%', { subject: 'Hi', score: '35' }, false), 'Hi · 35%')
})
