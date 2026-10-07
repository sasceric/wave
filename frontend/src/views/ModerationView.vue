<script setup>
import { onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import AccountSidebar from '../components/account/AccountSidebar.vue'
import LoadingSkeleton from '../components/shared/LoadingSkeleton.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import { currentUser } from '../composables/useCurrentUser'
import { apiGet, apiRequest } from '../lib/api'

const { t, locale } = useI18n()
const accessState = ref('loading')
const loading = ref(false)
const error = ref('')
const notice = ref('')
const categories = ref([])
const faqs = ref([])
const catalogBusy = ref(false)
const editingCategoryId = ref(null)
const editingFaqId = ref(null)
const categoryForm = ref(emptyCategory())
const faqForm = ref(emptyFaq())
const catalogLocales = ['bs', 'hr', 'sr', 'cnr', 'sl', 'en']

function emptyCategory() {
  return {
    value: '',
    labels: { bs: '', hr: '', sr: '', cnr: '', sl: '', en: '' },
    position: 0,
    active: true,
  }
}

function emptyFaq() {
  return {
    questions: { bs: '', hr: '', sr: '', cnr: '', sl: '', en: '' },
    answers: { bs: '', hr: '', sr: '', cnr: '', sl: '', en: '' },
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
  if (!error.value) {
    editingCategoryId.value = null
    categoryForm.value = emptyCategory()
  }
}

async function saveFaq() {
  await saveCatalogItem(
    '/moderation/catalog/faqs',
    editingFaqId.value,
    faqForm.value,
    'faqSaved',
  )
  if (!error.value) {
    editingFaqId.value = null
    faqForm.value = emptyFaq()
  }
}

async function saveCatalogItem(basePath, id, body, messageKey) {
  catalogBusy.value = true
  error.value = ''
  notice.value = ''
  try {
    await apiRequest(id ? `${basePath}/${id}` : basePath, {
      method: id ? 'PUT' : 'POST',
      body,
    })
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
  editingCategoryId.value = category.id
  categoryForm.value = structuredClone(category)
}

function editFaq(faq) {
  editingFaqId.value = faq.id
  faqForm.value = structuredClone(faq)
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
    <AccountSidebar
      v-if="currentUser && (currentUser.isModerator || currentUser.isAdmin)"
      :user="currentUser"
    />
    <section class="moderation-page moderation-page--dashboard page-width">
      <header class="moderation-heading">
        <p class="eyebrow">{{ t('moderation.eyebrow') }}</p>
        <h1>{{ t('moderation.title') }}</h1>
        <p>{{ t('moderation.description') }}</p>
      </header>

      <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
      <StatusMessage v-if="notice">{{ notice }}</StatusMessage>

      <LoadingSkeleton v-if="loading || accessState === 'loading'" variant="account" :label="t('moderation.loading')" />
      <div v-else-if="accessState === 'anonymous'" class="moderation-access">
        <p>{{ t('moderation.signIn') }}</p>
        <RouterLink class="button button--dark" to="/account">{{ t('auth.signIn') }} <span aria-hidden="true">↗</span></RouterLink>
      </div>
      <StatusMessage v-else-if="accessState === 'forbidden'">
        {{ t('moderation.noAccess') }}
      </StatusMessage>
      <div v-else-if="accessState === 'allowed'" class="moderation-content">
        <div class="catalog-management">
          <section class="form-card">
            <div class="catalog-section-heading">
              <div>
                <p class="eyebrow">{{ t('moderation.catalogTab') }}</p>
                <h2>{{ editingCategoryId ? t('moderation.editCategory') : t('moderation.addCategory') }}</h2>
              </div>
              <button v-if="editingCategoryId" class="text-link" type="button" @click="resetCategoryForm">{{ t('account.cancelEdit') }}</button>
            </div>
            <form v-form-validation class="catalog-form" @submit.prevent="saveCategory">
              <label class="form-field">
                <span>{{ t('moderation.categoryValue') }}</span>
                <input v-model.trim="categoryForm.value" required minlength="2" maxlength="80" :disabled="Boolean(editingCategoryId)" />
              </label>
              <label v-for="language in catalogLocales" :key="language" class="form-field">
                <span>{{ t('moderation.labelFor') }} {{ language.toUpperCase() }}</span>
                <input v-model.trim="categoryForm.labels[language]" required minlength="2" maxlength="80" />
              </label>
              <label class="form-field">
                <span>{{ t('moderation.position') }}</span>
                <input v-model.number="categoryForm.position" type="number" min="0" max="1000" required />
              </label>
              <label v-if="editingCategoryId" class="catalog-active">
                <input v-model="categoryForm.active" type="checkbox" />
                {{ t('moderation.active') }}
              </label>
              <div class="catalog-form__actions">
                <button class="button button--dark" type="submit" :disabled="catalogBusy">{{ t('moderation.saveCatalog') }}</button>
              </div>
            </form>
            <div class="catalog-list">
              <article v-for="categoryItem in categories" :key="categoryItem.id" class="catalog-row">
                <div>
                  <strong>{{ categoryItem.value }}</strong>
                  <span>{{ categoryItem.labels.bs }} · {{ categoryItem.labels.hr }} · {{ categoryItem.labels.sr }} · {{ categoryItem.labels.sl }} · {{ categoryItem.labels.en }}</span>
                </div>
                <div class="catalog-row__actions">
                  <span class="status-pill">{{ categoryItem.active ? t('moderation.active') : t('moderation.inactive') }}</span>
                  <button class="text-link" type="button" @click="editCategory(categoryItem)">{{ t('moderation.edit') }}</button>
                </div>
              </article>
            </div>
          </section>

          <section class="form-card">
            <div class="catalog-section-heading">
              <div>
                <p class="eyebrow">{{ t('moderation.catalogTab') }}</p>
                <h2>{{ editingFaqId ? t('moderation.editFaq') : t('moderation.addFaq') }}</h2>
              </div>
              <button v-if="editingFaqId" class="text-link" type="button" @click="resetFaqForm">{{ t('account.cancelEdit') }}</button>
            </div>
            <form v-form-validation class="catalog-form" @submit.prevent="saveFaq">
              <template v-for="language in catalogLocales" :key="language">
                <label class="form-field">
                  <span>{{ t('moderation.questionFor') }} {{ language.toUpperCase() }}</span>
                  <input v-model.trim="faqForm.questions[language]" required minlength="2" maxlength="180" />
                </label>
                <label class="form-field">
                  <span>{{ t('moderation.answerFor') }} {{ language.toUpperCase() }}</span>
                  <textarea v-model.trim="faqForm.answers[language]" required minlength="10" maxlength="2000"></textarea>
                </label>
              </template>
              <label class="form-field">
                <span>{{ t('moderation.position') }}</span>
                <input v-model.number="faqForm.position" type="number" min="0" max="1000" required />
              </label>
              <label v-if="editingFaqId" class="catalog-active">
                <input v-model="faqForm.active" type="checkbox" />
                {{ t('moderation.active') }}
              </label>
              <div class="catalog-form__actions">
                <button class="button button--dark" type="submit" :disabled="catalogBusy">{{ t('moderation.saveCatalog') }}</button>
              </div>
            </form>
            <div class="catalog-list">
              <article v-for="faq in faqs" :key="faq.id" class="catalog-row">
                <div>
                  <strong>{{ faq.questions.bs }}</strong>
                  <span>{{ faq.questions.en }}</span>
                </div>
                <div class="catalog-row__actions">
                  <span class="status-pill">{{ faq.active ? t('moderation.active') : t('moderation.inactive') }}</span>
                  <button class="text-link" type="button" @click="editFaq(faq)">{{ t('moderation.edit') }}</button>
                </div>
              </article>
            </div>
          </section>
        </div>
      </div>
    </section>
  </div>
</template>

<style lang="scss" src="../scss/views/ModerationView.scss"></style>
