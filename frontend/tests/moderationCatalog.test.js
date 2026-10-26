import assert from 'node:assert/strict'
import test from 'node:test'
import { setupView } from './setupView.js'

const category = { id: 1, value: 'Cycling', labels: { bs: 'Biciklizam', en: 'Cycling' }, position: 10, active: true }
const faq = { id: 2, questions: { bs: 'Kako početi?', en: 'How do I start?' }, answers: { bs: 'Napravi svoj profil.', en: 'Create your profile.' }, position: 20, active: true }

async function setupCatalog({ failure = false } = {}) {
  const requests = []
  const catalog = { categories: [structuredClone(category), { ...structuredClone(category), id: 3, value: 'Other', active: false }], faqs: [structuredClone(faq)] }
  const view = await setupView('../src/views/ModerationView.vue', {
    '../lib/api': {
      apiGet: async path => path === '/auth/me' ? { data: { isModerator: true } } : catalog,
      apiRequest: async (path, options) => {
        requests.push({ path, ...options })
        if (failure) throw new Error('Save failed')
        return { data: options.body }
      },
    },
  }, {}, { structuredClone })
  await view.state.loadModerationCatalog()
  return { ...view, requests }
}

test('editing reactive catalog records copies translations without cloning a Vue proxy or changing the list', async () => {
  const { state } = await setupCatalog()
  state.editCategory(state.categories.value[0])
  assert.equal(state.editor.value, 'category')
  assert.equal(state.editingCategoryId.value, 1)
  assert.equal(state.categoryForm.value.labels.de, '')
  state.categoryForm.value.labels.bs = 'Unsaved edit'
  assert.equal(state.categories.value[0].labels.bs, 'Biciklizam')
  state.closeEditor()
  assert.equal(state.editor.value, null)
  assert.equal(state.editingCategoryId.value, null)

  state.editFaq(state.faqs.value[0])
  assert.equal(state.editor.value, 'faq')
  state.faqForm.value.questions.bs = 'Draft question'
  state.faqForm.value.answers.en = 'Draft answer'
  assert.equal(state.faqs.value[0].questions.bs, faq.questions.bs)
  assert.equal(state.faqs.value[0].answers.en, faq.answers.en)
  assert.equal(state.faqForm.value.questions.de, '')
  assert.equal(state.faqForm.value.answers.de, '')
})

test('catalog search covers translations and FAQ answers, combined with the active status filter', async () => {
  const { state } = await setupCatalog()
  state.search.value = '  CYCLING '
  assert.equal(state.filteredItems.value.length, 2)
  state.status.value = 'inactive'
  assert.deepEqual(Array.from(state.filteredItems.value, item => item.id), [3])
  state.activeTab.value = 'faqs'
  state.status.value = 'all'
  state.search.value = 'create your profile'
  assert.deepEqual(Array.from(state.filteredItems.value, item => item.id), [2])
  state.search.value = 'missing'
  assert.equal(state.filteredItems.value.length, 0)
})

test('catalog pages stay compact and reset when searching or changing sections', async () => {
  const { state } = await setupCatalog()
  state.categories.value = Array.from({ length: 24 }, (_, index) => ({ ...category, id: index, value: `Area ${index}` }))
  assert.equal(state.pageItems.value.length, 10)
  state.page.value = 3
  assert.deepEqual(Array.from(state.pageItems.value, item => item.id), [20, 21, 22, 23])
  state.search.value = 'Area 23'
  assert.equal(state.page.value, 1)
  assert.deepEqual(Array.from(state.pageItems.value, item => item.id), [23])
  state.search.value = ''
  state.page.value = 2
  state.activeTab.value = 'faqs'
  assert.equal(state.page.value, 1)
  assert.equal(state.pageItems.value.length, 1)
})

test('category and FAQ edits use their existing protected update APIs and close only after a successful save', async () => {
  for (const kind of ['category', 'faq']) {
    const { state, requests } = await setupCatalog()
    if (kind === 'category') {
      state.editCategory(state.categories.value[0])
      state.categoryForm.value.labels.bs = 'Changed category'
      await state.saveCategory()
      assert.equal(requests[0].body.labels.bs, 'Changed category')
    } else {
      state.editFaq(state.faqs.value[0])
      state.faqForm.value.answers.bs = 'Changed answer'
      await state.saveFaq()
      assert.equal(requests[0].body.answers.bs, 'Changed answer')
    }
    assert.equal(requests[0].method, 'PUT')
    assert.equal(requests[0].path, kind === 'category' ? '/moderation/catalog/categories/1' : '/moderation/catalog/faqs/2')
    assert.equal(state.editor.value, null)
    assert.equal(state.catalogBusy.value, false)
  }
  const { state } = await setupCatalog({ failure: true })
  state.editCategory(state.categories.value[0])
  state.categoryForm.value.labels.bs = 'Keep my draft'
  await state.saveCategory()
  assert.equal(state.editor.value, 'category')
  assert.equal(state.categoryForm.value.labels.bs, 'Keep my draft')
  assert.equal(state.error.value, 'Save failed')
})

test('adding opens a fresh draft after the last position and invalid FAQ fields open their language section', async () => {
  const { state, requests } = await setupCatalog()
  state.addItem()
  assert.equal(state.editor.value, 'category')
  assert.equal(state.categoryForm.value.position, 11)
  state.categoryForm.value = structuredClone(category)
  await state.saveCategory()
  assert.equal(requests[0].method, 'POST')
  assert.equal(requests[0].path, '/moderation/catalog/categories')
  state.activeTab.value = 'faqs'
  state.addItem()
  assert.equal(state.editor.value, 'faq')
  assert.equal(state.faqForm.value.position, 21)
  const section = { open: false }
  state.revealInvalid({ closest: selector => selector === 'details' ? section : null })
  assert.equal(section.open, true)
})
