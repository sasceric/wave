<script setup>
import { ArrowRight, ChevronDown, Pencil, Plus } from '@lucide/vue'
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import AccountSidebar from '../components/account/AccountSidebar.vue'
import LoadingSkeleton from '../components/shared/LoadingSkeleton.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import FormModal from '../components/shared/FormModal.vue'
import ProfileTabs from '../components/shared/ProfileTabs.vue'
import SingleSelect from '../components/shared/SingleSelect.vue'
import DirectorySearch from '../components/shared/DirectorySearch.vue'
import DirectoryPagination from '../components/shared/DirectoryPagination.vue'
import RouterLink from '../components/shared/LocalizedLink.vue'
import { currentUser } from '../composables/useCurrentUser'
import { apiGet, apiRequest } from '../lib/api'
import { localizedRouteName } from '../routePaths'

const { t, locale } = useI18n()
const accessState = ref('loading')
const loading = ref(false)
const error = ref('')
const notice = ref('')
const categories = ref([])
const faqs = ref([])
const catalogBusy = ref(false)
const activeTab = ref('categories')
const search = ref('')
const status = ref('all')
const page = ref(1)
const pageSize = 10
const editor = ref(null)
const editingCategoryId = ref(null)
const editingFaqId = ref(null)
const categoryForm = ref(emptyCategory())
const faqForm = ref(emptyFaq())
const catalogLocales = ['bs', 'hr', 'sr', 'cnr', 'sl', 'en', 'de']
const tabs = computed(() => [
  { key: 'categories', label: t('moderation.categoriesTab'), count: categories.value.length },
  { key: 'faqs', label: t('moderation.faqsTab'), count: faqs.value.length },
])
const statusOptions = computed(() => [
  { value: 'all', label: t('moderation.allStatuses') },
  { value: 'active', label: t('moderation.active') },
  { value: 'inactive', label: t('moderation.inactive') },
])
const items = computed(() => activeTab.value === 'categories' ? categories.value : faqs.value)
const filteredItems = computed(() => {
  const query = search.value.trim().toLocaleLowerCase()
  return items.value.filter(item => {
    if (status.value !== 'all' && item.active !== (status.value === 'active')) return false
    const texts = [item.value, ...Object.values(item.labels || {}), ...Object.values(item.questions || {}), ...Object.values(item.answers || {})]
    return !query || texts.some(text => text?.toLocaleLowerCase().includes(query))
  })
})
const pageItems = computed(() => filteredItems.value.slice((page.value - 1) * pageSize, page.value * pageSize))
watch([activeTab, search, status], () => { page.value = 1 }, { flush: 'sync' })
watch(() => filteredItems.value.length, count => {
  page.value = Math.min(page.value, Math.max(1, Math.ceil(count / pageSize)))
})
function localizedText(texts) {
  return texts?.[locale.value] || texts?.bs || texts?.en || ''
}

function emptyCategory() {
  return {
    value: '',
    labels: { bs: '', hr: '', sr: '', cnr: '', sl: '', en: '', de: '' },
    position: 0,
    active: true,
  }
}

function emptyFaq() {
  return {
    questions: { bs: '', hr: '', sr: '', cnr: '', sl: '', en: '', de: '' },
    answers: { bs: '', hr: '', sr: '', cnr: '', sl: '', en: '', de: '' },
    position: 0,
    active: true,
  }
}

async function loadModerationCatalog() {
  loading.value = true
  error.value = ''
  notice.value = ''
  try {
    const { data: user } = await apiGet('/auth/me')
    if (!user.isModerator) {
      accessState.value = 'forbidden'
      return
    }
    const catalogResponse = await apiGet('/moderation/catalog')
    categories.value = catalogResponse.categories
    faqs.value = catalogResponse.faqs
    accessState.value = 'allowed'
  } catch (cause) {
    if (cause.status === 401) {
      accessState.value = 'anonymous'
    } else if (cause.status === 403) {
      accessState.value = 'forbidden'
    } else {
      error.value = cause.message || t('moderation.error')
      if (accessState.value === 'loading') accessState.value = 'error'
    }
  } finally {
    loading.value = false
  }
}

async function saveCategory() {
  await saveCatalogItem(
    '/moderation/catalog/categories',
    editingCategoryId.value,
    categoryForm.value,
    'categorySaved',
  )
}

async function saveFaq() {
  await saveCatalogItem(
    '/moderation/catalog/faqs',
    editingFaqId.value,
    faqForm.value,
    'faqSaved',
  )
}

async function saveCatalogItem(basePath, id, body, messageKey) {
  if (catalogBusy.value) return
  catalogBusy.value = true
  error.value = ''
  notice.value = ''
  try {
    await apiRequest(id ? `${basePath}/${id}` : basePath, {
      method: id ? 'PUT' : 'POST',
      body,
    })
    editor.value = null
    resetCategoryForm()
    resetFaqForm()
    notice.value = t(`moderation.${messageKey}`)
    const response = await apiGet('/moderation/catalog')
    categories.value = response.categories
    faqs.value = response.faqs
  } catch (cause) {
    error.value = cause.message || t('moderation.error')
  } finally {
    catalogBusy.value = false
  }
}

function editCategory(category) {
  error.value = ''
  notice.value = ''
  editingCategoryId.value = category.id
  categoryForm.value = {
    value: category.value,
    labels: { ...emptyCategory().labels, ...category.labels },
    position: category.position,
    active: category.active,
  }
  editor.value = 'category'
}

function editFaq(faq) {
  error.value = ''
  notice.value = ''
  editingFaqId.value = faq.id
  faqForm.value = {
    questions: { ...emptyFaq().questions, ...faq.questions },
    answers: { ...emptyFaq().answers, ...faq.answers },
    position: faq.position,
    active: faq.active,
  }
  editor.value = 'faq'
}

function addItem() {
  error.value = ''
  notice.value = ''
  resetCategoryForm()
  resetFaqForm()
  const list = items.value
  const position = Math.min(1000, list.length ? Math.max(...list.map(item => item.position)) + 1 : 0)
  if (activeTab.value === 'categories') categoryForm.value.position = position
  else faqForm.value.position = position
  editor.value = activeTab.value === 'categories' ? 'category' : 'faq'
}

function closeEditor() {
  if (catalogBusy.value) return
  editor.value = null
  error.value = ''
  resetCategoryForm()
  resetFaqForm()
}

function revealInvalid(field) {
  const section = field.closest('details')
  if (section) section.open = true
}

function resetCategoryForm() {
  editingCategoryId.value = null
  categoryForm.value = emptyCategory()
}

function resetFaqForm() {
  editingFaqId.value = null
  faqForm.value = emptyFaq()
}

watch(locale, loadModerationCatalog)
onMounted(loadModerationCatalog)
</script>

<template>
  <div class="moderation-layout">
    <AccountSidebar v-if="currentUser && (currentUser.isModerator || currentUser.isAdmin)" :user="currentUser" />
    <section class="moderation-page moderation-page--dashboard page-width">
      <header class="moderation-heading">
        <p class="eyebrow">{{ t('moderation.eyebrow') }}</p>
        <h1>{{ t('moderation.title') }}</h1>
        <p>{{ t('moderation.description') }}</p>
      </header>

      <StatusMessage v-if="error && !editor" variant="error">{{ error }}</StatusMessage>
      <StatusMessage v-if="notice">{{ notice }}</StatusMessage>
      <LoadingSkeleton v-if="loading || accessState === 'loading'" variant="account" :label="t('moderation.loading')" />
      <div v-else-if="accessState === 'anonymous'" class="moderation-access">
        <p>{{ t('moderation.signIn') }}</p>
        <RouterLink class="button button--dark" :to="{ name: localizedRouteName('login', locale) }">{{ t('auth.signIn') }} <ArrowRight class="link-arrow" :size="16" aria-hidden="true" /></RouterLink>
      </div>
      <StatusMessage v-else-if="accessState === 'forbidden'">{{ t('moderation.noAccess') }}</StatusMessage>
      <button v-else-if="accessState === 'error'" class="button button--outline" type="button" @click="loadModerationCatalog">{{ t('directoryLoading.retry') }}</button>
      <div v-else-if="accessState === 'allowed'" class="catalog-management">
        <div class="catalog-toolbar">
          <ProfileTabs v-model="activeTab" :items="tabs" :label="t('moderation.catalogTab')" id-prefix="catalog" />
          <button class="button button--dark" type="button" @click="addItem">
            <Plus :size="16" aria-hidden="true" />
            {{ t(activeTab === 'categories' ? 'moderation.addCategory' : 'moderation.addFaq') }}
          </button>
        </div>
        <section :id="`catalog-panel-${activeTab}`" class="catalog-panel" role="tabpanel" :aria-labelledby="`catalog-tab-${activeTab}`">
          <div class="catalog-filters">
            <DirectorySearch v-model="search" :label="t('moderation.searchCatalog')" :placeholder="t('moderation.searchCatalog')" />
            <SingleSelect v-model="status" :options="statusOptions" :label="t('moderation.statusFilter')" :show-label="false" />
          </div>
          <p class="catalog-count" role="status">{{ t('moderation.results', { count: pageItems.length, total: filteredItems.length }) }}</p>
          <div v-if="filteredItems.length" class="catalog-list">
            <article v-for="item in pageItems" :key="item.id" class="catalog-row">
              <div class="catalog-row__text">
                <h2>{{ activeTab === 'categories' ? localizedText(item.labels) || item.value : localizedText(item.questions) }}</h2>
                <p>{{ activeTab === 'categories' ? item.value : localizedText(item.answers) }}</p>
              </div>
              <span class="catalog-status" :class="{ 'catalog-status--inactive': !item.active }">{{ t(item.active ? 'moderation.active' : 'moderation.inactive') }}</span>
              <button class="catalog-edit" type="button" :aria-label="`${t('moderation.edit')}: ${activeTab === 'categories' ? localizedText(item.labels) || item.value : localizedText(item.questions)}`" @click="activeTab === 'categories' ? editCategory(item) : editFaq(item)">
                <Pencil :size="16" aria-hidden="true" />
                <span>{{ t('moderation.edit') }}</span>
              </button>
            </article>
          </div>
          <StatusMessage v-else variant="empty">{{ t('moderation.noResults') }}</StatusMessage>
          <DirectoryPagination v-if="filteredItems.length > pageSize" v-model:page="page" :page-size="pageSize" :total="filteredItems.length" :show-page-size="false" />
        </section>
      </div>
    </section>

    <FormModal :open="editor === 'category'" :title="t(editingCategoryId ? 'moderation.editCategory' : 'moderation.addCategory')" :busy="catalogBusy" wide @update:open="!$event && closeEditor()" @submit="saveCategory">
      <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
      <fieldset class="catalog-editor" :disabled="catalogBusy">
        <div class="catalog-editor__meta">
          <label class="form-field">
            <span>{{ t('moderation.categoryValue') }}</span>
            <input v-model.trim="categoryForm.value" required minlength="2" maxlength="80" :disabled="Boolean(editingCategoryId)" />
          </label>
          <label class="form-field">
            <span>{{ t('moderation.position') }}</span>
            <input v-model.number="categoryForm.position" type="number" min="0" max="1000" required />
          </label>
        </div>
        <div class="catalog-labels">
          <label v-for="language in catalogLocales" :key="language" class="form-field">
            <span>{{ t('moderation.labelFor') }} <b>{{ language.toUpperCase() }}</b></span>
            <input v-model.trim="categoryForm.labels[language]" :required="language !== 'de'" minlength="2" maxlength="80" />
          </label>
        </div>
        <label v-if="editingCategoryId" class="catalog-active">
          <input v-model="categoryForm.active" type="checkbox" />
          <span>{{ t('moderation.active') }}</span>
        </label>
      </fieldset>
      <template #actions>
        <button class="button button--outline" type="button" :disabled="catalogBusy" @click="closeEditor">{{ t('account.cancelEdit') }}</button>
        <button class="button button--dark" type="submit" :disabled="catalogBusy">{{ t('moderation.saveCatalog') }}</button>
      </template>
    </FormModal>

    <FormModal :open="editor === 'faq'" :title="t(editingFaqId ? 'moderation.editFaq' : 'moderation.addFaq')" :busy="catalogBusy" wide @update:open="!$event && closeEditor()" @submit="saveFaq" @invalid="revealInvalid">
      <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
      <fieldset class="catalog-editor" :disabled="catalogBusy">
        <div class="catalog-editor__meta">
          <label class="form-field">
            <span>{{ t('moderation.position') }}</span>
            <input v-model.number="faqForm.position" type="number" min="0" max="1000" required />
          </label>
          <label v-if="editingFaqId" class="catalog-active">
            <input v-model="faqForm.active" type="checkbox" />
            <span>{{ t('moderation.active') }}</span>
          </label>
        </div>
        <details v-for="language in catalogLocales" :key="`${editingFaqId}-${language}`" class="catalog-language" :open="language === (catalogLocales.includes(locale) ? locale : 'bs')">
          <summary>{{ language.toUpperCase() }} <span>{{ faqForm.questions[language] || t('moderation.questionFor') }}</span><ChevronDown :size="16" aria-hidden="true" /></summary>
          <div class="catalog-language__fields">
            <label class="form-field">
              <span>{{ t('moderation.questionFor') }} {{ language.toUpperCase() }}</span>
              <input v-model.trim="faqForm.questions[language]" :required="language !== 'de'" minlength="2" maxlength="180" />
            </label>
            <label class="form-field">
              <span>{{ t('moderation.answerFor') }} {{ language.toUpperCase() }}</span>
              <textarea v-model.trim="faqForm.answers[language]" :required="language !== 'de'" minlength="10" maxlength="2000" rows="4"></textarea>
            </label>
          </div>
        </details>
      </fieldset>
      <template #actions>
        <button class="button button--outline" type="button" :disabled="catalogBusy" @click="closeEditor">{{ t('account.cancelEdit') }}</button>
        <button class="button button--dark" type="submit" :disabled="catalogBusy">{{ t('moderation.saveCatalog') }}</button>
      </template>
    </FormModal>
  </div>
</template>

<style lang="scss" src="../scss/views/ModerationView.scss"></style>
