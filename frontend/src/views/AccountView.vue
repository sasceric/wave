<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import RouterLink from '../components/shared/LocalizedLink.vue'
import { useI18n } from 'vue-i18n'
import { Plus, Trash2 } from '@lucide/vue'
import AccountAccessPanel from '../components/account/AccountAccessPanel.vue'
import AccountSidebar from '../components/account/AccountSidebar.vue'
import MultiSelect from '../components/shared/MultiSelect.vue'
import MediaUploadField from '../components/shared/MediaUploadField.vue'
import RichTextEditor from '../components/shared/RichTextEditor.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import SwitchField from '../components/shared/SwitchField.vue'
import WaveLogo from '../components/shared/WaveLogo.vue'
import CampaignCard from '../components/campaigns/CampaignCard.vue'
import { apiGet, apiRequest, formatDate, formatMoney } from '../lib/api'
import { useMarketplaceCatalog } from '../composables/useMarketplaceCatalog'
import { setCurrentUser } from '../composables/useCurrentUser'
import { useCampaignBookmarks } from '../composables/useCampaignBookmarks'
import { CURRENCIES, SOCIAL_PLATFORMS } from '../lib/marketplace'
import { localizedRouteName } from '../routePaths'

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const { categories, error: catalogError } = useMarketplaceCatalog(locale)
const { bookmarks, loadCampaignBookmarks } = useCampaignBookmarks()
const PROFILE_TABS = ['about', 'social', 'portfolio', 'packages', 'faqs']
const LEGACY_ACCOUNT_TABS = {
  applications: 'account-applications',
  offers: 'account-offers',
  inquiries: 'account-inquiries',
  bookmarks: 'account-bookmarks',
}
const user = ref(null)
const accountTab = ref(isAccountTab(route.query.tab) ? route.query.tab : 'about')
const accountSection = computed(() => route.meta.accountSection ?? 'profile')
const busy = ref(false)
const error = ref('')
const notice = ref('')
const resendingVerification = ref(false)
const visibilitySaving = ref(false)
const profile = ref(null)
const tags = ref('')
const campaigns = ref([])
const applications = ref([])
const offers = ref([])
const companyApplications = ref([])
const inquiries = ref([])
const inquiryMessages = ref({})
const inquiryMessageDrafts = ref({})
const editingCampaignId = ref(null)
const campaignForm = ref(emptyCampaign())
const offerForms = ref({})

watch(catalogError, (value) => {
  if (value) {
    error.value = value
  }
})

function emptyCampaign() {
  const deadline = new Date()
  deadline.setDate(deadline.getDate() + 28)

  return {
    title: '',
    summary: '',
    description: '',
    coverMediaId: null,
    coverImageUrl: '',
    category: 'Lifestyle',
    channelsText: 'Instagram, TikTok',
    deliverablesText: '1 short-form video',
    budgetMin: 300,
    budgetMax: 800,
    currency: 'BAM',
    location: '',
    creatorCount: 3,
    closesAt: deadline.toISOString().slice(0, 10),
    status: 'open',
  }
}

function isAccountTab(tab) {
  return typeof tab === 'string' && PROFILE_TABS.includes(tab)
}

function selectAccountTab(tab) {
  if (!isAccountTab(tab)) {
    return
  }

  accountTab.value = tab
  if (route.query.tab !== tab) {
    void router.replace({ query: { ...route.query, tab } })
  }
}

function incompleteProfileTab() {
  if (user.value.accountType !== 'creator') {
    return ''
  }
  if (!profile.value.displayName.trim()
    || profile.value.categories.length === 0
    || !profile.value.location.trim()
  ) {
    return 'about'
  }
  if (profile.value.socialProfiles.some((social) => (
    !social.platform.trim()
    || !social.handle.trim()
    || !Number.isInteger(Number(social.followers))
    || Number(social.followers) < 0
    || Number(social.followers) > 200000000
  ))) {
    return 'social'
  }
  if (profile.value.portfolio.some((item) => (
    item.type === 'video'
      ? !item.url.trim()
      : !item.mediaId && !item.url.trim()
  ))) {
    return 'portfolio'
  }
  if (profile.value.packages.some((packageItem) => (
    packageItem.title.trim().length < 3
    || packageItem.description.trim().length < 10
    || (packageItem.price !== null
      && packageItem.price !== ''
      && (!Number.isInteger(Number(packageItem.price))
        || Number(packageItem.price) < 1
        || Number(packageItem.price) > 10000000))
  ))) {
    return 'packages'
  }
  if (profile.value.faqs.some((faq) => (
    faq.question.trim().length < 3 || faq.answer.trim().length < 10
  ))) {
    return 'faqs'
  }

  return ''
}

async function loadDashboard() {
  let pendingBookmarkRedirect = null
  try {
    const response = await apiGet('/auth/me')
    user.value = response.data
    setCurrentUser(response.data)
    profile.value = structuredClone(response.data.profile)
    if (route.query.bookmark) {
      pendingBookmarkRedirect = await completePendingBookmark(response.data)
    }
    if (user.value.accountType === 'creator') {
      if (isAccountTab(route.query.tab)) {
        accountTab.value = route.query.tab
      } else if (typeof route.query.tab === 'string' && LEGACY_ACCOUNT_TABS[route.query.tab]) {
        await router.replace({ name: localizedRouteName(LEGACY_ACCOUNT_TABS[route.query.tab], locale.value) })
        accountTab.value = 'about'
      } else {
        accountTab.value = 'about'
        if (route.query.tab !== undefined) {
          const query = { ...route.query }
          delete query.tab
          void router.replace({ query })
        }
      }
      tags.value = (profile.value.tags || []).join(', ')
      const [applicationResponse, offerResponse] = await Promise.all([
        apiGet('/me/applications'),
        apiGet('/me/offers'),
        loadCampaignBookmarks(),
      ])
      applications.value = applicationResponse.data
      offers.value = offerResponse.data
    } else {
      if (route.query.tab !== undefined) {
        const query = { ...route.query }
        delete query.tab
        void router.replace({ query })
      }
      const campaignResponse = await apiGet('/me/campaigns')
      campaigns.value = campaignResponse.data
      const applicationResponses = await Promise.all(
        campaigns.value.map((campaign) => apiGet(`/company/campaigns/${encodeURIComponent(campaign.slug)}/applications`)),
      )
      companyApplications.value = applicationResponses.flatMap((response) => response.data)
      offerForms.value = Object.fromEntries(companyApplications.value.map((application) => [
        application.id,
        { amount: application.campaign.budgetMin, message: '' },
      ]))
    }
    const inquiryResponse = await apiGet('/me/inquiries')
    inquiries.value = inquiryResponse.data
    const acceptedInquiries = inquiries.value.filter((inquiry) => inquiry.canChat)
    const messageResponses = await Promise.all(
      acceptedInquiries.map((inquiry) => apiGet(`/me/inquiries/${inquiry.id}/messages`)),
    )
    inquiryMessages.value = Object.fromEntries(acceptedInquiries.map((inquiry, index) => [
      inquiry.id,
      messageResponses[index].data,
    ]))
  } catch (cause) {
    if (cause.status === 401) {
      user.value = null
      profile.value = null
      inquiries.value = []
      inquiryMessages.value = {}
      setCurrentUser(null)
      return
    }
    error.value = cause.message
  } finally {
    if (pendingBookmarkRedirect) {
      await router.replace(pendingBookmarkRedirect)
    }
  }
}

function getSafeBookmarkReturnTo() {
  const returnTo = route.query.returnTo
  if (typeof returnTo !== 'string' || !returnTo.startsWith('/') || returnTo.startsWith('//') || returnTo.includes('\\')) {
    return null
  }

  try {
    const target = new URL(returnTo, window.location.origin)
    if (target.origin !== window.location.origin) {
      return null
    }

    return `${target.pathname}${target.search}${target.hash}`
  } catch {
    return null
  }
}

async function clearBookmarkIntent() {
  const query = { ...route.query }
  delete query.bookmark
  delete query.returnTo
  await router.replace({ query })
}

async function completePendingBookmark(account) {
  const slug = route.query.bookmark
  if (typeof slug !== 'string' || !/^[a-z0-9]+(?:-[a-z0-9]+)*$/i.test(slug)) {
    error.value = t('account.bookmarkInvalid')
    await clearBookmarkIntent()

    return null
  }
  if (account.accountType !== 'creator') {
    error.value = t('account.bookmarkCreatorOnly')
    await clearBookmarkIntent()

    return null
  }

  await apiRequest(`/campaigns/${encodeURIComponent(slug)}/bookmark`, {
    method: 'POST',
    body: {},
  })
  notice.value = t('account.bookmarkSaved')
  await loadCampaignBookmarks(true)

  return getSafeBookmarkReturnTo()
    || { name: localizedRouteName('account-bookmarks', locale.value) }
}

async function resendVerification() {
  resendingVerification.value = true
  error.value = ''
  notice.value = ''
  try {
    const response = await apiRequest('/auth/verification-email', { method: 'POST', body: {} })
    notice.value = response.message
  } catch (cause) {
    error.value = cause.message
  } finally {
    resendingVerification.value = false
  }
}

async function updateAccountVisibility(value) {
  if (!user.value || visibilitySaving.value) {
    return
  }

  visibilitySaving.value = true
  error.value = ''
  notice.value = ''
  try {
    const response = await apiRequest('/me/account-visibility', {
      method: 'PUT',
      body: { hide_my_account: value },
    })
    user.value = response.data
    setCurrentUser(response.data)
    notice.value = t('account.visibilitySaved')
  } catch (cause) {
    error.value = cause.message || t('account.visibilityError')
  } finally {
    visibilitySaving.value = false
  }
}

async function saveProfile() {
  const invalidTab = incompleteProfileTab()
  if (invalidTab) {
    selectAccountTab(invalidTab)
    error.value = t('account.profileIncomplete')
    notice.value = ''
    return
  }

  busy.value = true
  error.value = ''
  notice.value = ''
  const body = user.value.accountType === 'creator'
    ? {
        displayName: profile.value.displayName,
        category: profile.value.categories[0] || profile.value.category,
        categories: profile.value.categories,
        location: profile.value.location,
        bio: profile.value.bio,
        tagline: profile.value.tagline,
        avatarMediaId: profile.value.avatarMediaId || null,
        avatarUrl: profile.value.avatarMediaId ? null : profile.value.avatarUrl || null,
        tags: tags.value.split(',').map((tag) => tag.trim()).filter(Boolean),
        socialProfiles: profile.value.socialProfiles.map(({ platform, handle, followers }) => ({
          platform,
          handle,
          followers: Number(followers),
        })),
        portfolio: profile.value.portfolio.map((item) => ({
          id: item.id,
          type: item.type,
          title: item.title,
          platform: item.platform || 'All',
          ...(item.type === 'image' && item.mediaId
            ? { mediaId: Number(item.mediaId) }
            : { url: item.url }),
        })),
        packages: profile.value.packages.map(({ id, platform, title, description, price, currency }) => ({
          id,
          platform,
          title,
          description,
          price: price === '' || price === null ? null : Number(price),
          currency: currency || 'BAM',
        })),
        faqs: profile.value.faqs.map(({ question, answer }) => ({ question, answer })),
      }
    : {
        name: profile.value.name,
        industry: profile.value.industry,
        logoMediaId: profile.value.logoMediaId || null,
        logoUrl: profile.value.logoMediaId ? null : profile.value.logoUrl || null,
      }
  try {
    await apiRequest('/me/profile', { method: 'PUT', body })
    notice.value = t('account.profileSaved')
    await loadDashboard()
  } catch (cause) {
    error.value = cause.message
  } finally {
    busy.value = false
  }
}

function addSocialProfile() {
  if (profile.value.socialProfiles.length < 5) {
    profile.value.socialProfiles.push({ platform: 'TikTok', handle: '', followers: 0 })
  }
}

function addPortfolioItem() {
  if (profile.value.portfolio.length < 4) {
    profile.value.portfolio.push({ id: '', type: 'image', url: '', title: '', platform: 'All' })
  }
}

function changePortfolioType(item) {
  item.url = ''
  item.mediaId = null
  delete item.embedUrl
}

function addPackage() {
  if (profile.value.packages.length < 12) {
    profile.value.packages.push({
      id: '',
      platform: 'Instagram',
      title: '',
      description: '',
      price: null,
      currency: 'BAM',
    })
  }
}

function addCreatorFaq() {
  if (profile.value.faqs.length < 10) {
    profile.value.faqs.push({ question: '', answer: '' })
  }
}

function handleProfileTabKeydown(event) {
  const currentIndex = PROFILE_TABS.indexOf(accountTab.value)
  let nextIndex = currentIndex

  if (event.key === 'ArrowRight') {
    nextIndex = (currentIndex + 1) % PROFILE_TABS.length
  } else if (event.key === 'ArrowLeft') {
    nextIndex = (currentIndex - 1 + PROFILE_TABS.length) % PROFILE_TABS.length
  } else if (event.key === 'Home') {
    nextIndex = 0
  } else if (event.key === 'End') {
    nextIndex = PROFILE_TABS.length - 1
  } else {
    return
  }

  event.preventDefault()
  const nextTab = PROFILE_TABS[nextIndex]
  selectAccountTab(nextTab)
  document.getElementById(`account-tab-${nextTab}`)?.focus()
}

function setCampaignForm(campaign = null) {
  if (!campaign) {
    editingCampaignId.value = null
    campaignForm.value = emptyCampaign()
    return
  }
  editingCampaignId.value = campaign.id
  campaignForm.value = {
    title: campaign.title,
    summary: campaign.summary,
    description: campaign.description,
    coverMediaId: campaign.coverMediaId || null,
    coverImageUrl: campaign.coverImageUrl || '',
    category: campaign.category,
    channelsText: campaign.channels.join(', '),
    deliverablesText: campaign.deliverables.join(', '),
    budgetMin: campaign.budgetMin,
    budgetMax: campaign.budgetMax,
    currency: campaign.currency || 'BAM',
    location: campaign.location,
    creatorCount: campaign.creatorCount,
    closesAt: campaign.closesAt.slice(0, 10),
    status: campaign.status,
  }
  document.querySelector('.account-campaign-form')?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

async function saveCampaign() {
  busy.value = true
  error.value = ''
  notice.value = ''
  const body = {
    title: campaignForm.value.title,
    summary: campaignForm.value.summary,
    description: campaignForm.value.description,
    coverMediaId: campaignForm.value.coverMediaId,
    category: campaignForm.value.category,
    channels: campaignForm.value.channelsText.split(',').map((item) => item.trim()).filter(Boolean),
    deliverables: campaignForm.value.deliverablesText.split(',').map((item) => item.trim()).filter(Boolean),
    budgetMin: Number(campaignForm.value.budgetMin),
    budgetMax: Number(campaignForm.value.budgetMax),
    currency: campaignForm.value.currency,
    location: campaignForm.value.location,
    creatorCount: Number(campaignForm.value.creatorCount),
    closesAt: campaignForm.value.closesAt,
    status: campaignForm.value.status,
  }
  const path = editingCampaignId.value ? `/company/campaigns/${editingCampaignId.value}` : '/company/campaigns'
  try {
    await apiRequest(path, { method: editingCampaignId.value ? 'PUT' : 'POST', body })
    notice.value = t('account.campaignSaved')
    setCampaignForm()
    await loadDashboard()
  } catch (cause) {
    const fieldErrors = cause.fields
      .filter((field) => typeof field === 'string' && t(`account.campaignFieldErrors.${field}`) !== `account.campaignFieldErrors.${field}`)
      .map((field) => t(`account.campaignFieldErrors.${field}`))
    error.value = fieldErrors.length
      ? `${cause.message} ${fieldErrors.join(' ')}`
      : cause.message
  } finally {
    busy.value = false
  }
}

async function rejectApplication(application) {
  await runAction(`/company/applications/${application.id}/reject`, {})
}

async function sendOffer(application) {
  await runAction(`/company/applications/${application.id}/offer`, {
    amount: Number(offerForms.value[application.id].amount),
    message: offerForms.value[application.id].message,
  })
}

async function respondToOffer(offer, decision) {
  await runAction(`/me/offers/${offer.id}/respond`, { decision })
}

async function respondToInquiry(inquiry, decision) {
  await runAction(`/me/inquiries/${inquiry.id}/decision`, { decision })
}

async function sendInquiryMessage(inquiry) {
  const body = inquiryMessageDrafts.value[inquiry.id]?.trim()
  if (!body) {
    return
  }
  try {
    await apiRequest(`/me/inquiries/${inquiry.id}/messages`, {
      method: 'POST',
      body: { body },
    })
    inquiryMessageDrafts.value[inquiry.id] = ''
    await loadDashboard()
  } catch (cause) {
    error.value = cause.message
  }
}

async function runAction(path, body) {
  error.value = ''
  notice.value = ''
  try {
    await apiRequest(path, { method: 'POST', body })
    notice.value = t('account.actionSaved')
    await loadDashboard()
  } catch (cause) {
    error.value = cause.message
  }
}

function statusLabel(status) {
  const key = status === 'offer_declined' ? 'offerDeclined' : status

  return t(`account.${key}`)
}

watch(() => route.query.tab, (tab) => {
  if (user.value?.accountType !== 'creator') {
    return
  }

  if (isAccountTab(tab)) {
    accountTab.value = tab
  } else if (typeof tab === 'string' && LEGACY_ACCOUNT_TABS[tab]) {
    void router.replace({ name: localizedRouteName(LEGACY_ACCOUNT_TABS[tab], locale.value) })
  } else {
    accountTab.value = 'about'
    if (tab !== undefined) {
      const query = { ...route.query }
      delete query.tab
      void router.replace({ query })
    }
  }
})

watch([user, accountSection], ([account, section]) => {
  if (!account) {
    return
  }

  const unavailableForRole = account.accountType === 'creator'
    ? section === 'campaigns'
    : section === 'offers' || section === 'bookmarks'

  if (unavailableForRole) {
    void router.replace({ name: localizedRouteName('account', locale.value) })
  }
})

watch(locale, () => {
  if (user.value) loadDashboard()
})
onMounted(loadDashboard)
</script>

<template>
  <AccountAccessPanel v-if="!user" @authenticated="loadDashboard" />

  <section v-else class="account-page account-page--dashboard page-width">
    <div class="account-heading">
      <div class="account-heading__identity">
        <div
          class="account-heading__avatar"
          :class="user.accountType === 'creator' ? 'account-heading__avatar--creator' : 'account-heading__avatar--company'"
        >
          <img
            v-if="user.accountType === 'creator' && profile?.avatarUrl"
            :src="profile.avatarUrl"
            :alt="profile.displayName"
          />
          <img
            v-else-if="user.accountType !== 'creator' && profile?.logoUrl"
            :src="profile.logoUrl"
            :alt="profile.name"
          />
          <WaveLogo v-else mark />
        </div>
        <div class="account-heading__copy">
          <p class="eyebrow">{{ user.accountType === 'creator' ? t('account.creatorProfile') : t('account.companyProfile') }}</p>
          <h1>{{ (user.accountType === 'creator' ? profile?.displayName : profile?.name) || t('account.title') }}</h1>
          <p class="account-heading__email">{{ user.email }}</p>
          <RouterLink
            v-if="profile?.slug"
            class="text-link account-heading__profile-link"
            :to="user.accountType === 'creator' ? { name: 'creator-profile', params: { slug: profile.slug } } : { name: 'company-profile', params: { slug: profile.slug } }"
          >
            {{ t('account.viewPublicProfile') }} ↗
          </RouterLink>
        </div>
      </div>
      <div class="account-heading__actions">
        <SwitchField
          :model-value="user.hide_my_account"
          :label="t('account.hideAccount')"
          :description="t('account.hideAccountHint')"
          :disabled="visibilitySaving"
          @update:model-value="updateAccountVisibility"
        />
      </div>
    </div>
    <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
    <StatusMessage v-else-if="notice">{{ notice }}</StatusMessage>
    <div v-if="!user.emailVerified" class="verification-banner">
      <p>{{ t('account.emailUnverified') }}</p>
      <button class="button button--outline" type="button" :disabled="resendingVerification" @click="resendVerification">{{ t('account.resendVerification') }}</button>
    </div>
    <div v-else-if="!user.approved" class="verification-banner" role="status">
      <p>{{ t('account.approvalPendingNotice') }}</p>
    </div>

    <div class="account-layout">
      <AccountSidebar :user="user" />
      <main class="account-content">
      <div class="account-grid">
      <form
        v-if="accountSection === 'profile'"
        class="form-card profile-form profile-form--wide"
        @submit.prevent="saveProfile"
      >
        <header class="profile-form__header">
          <div>
            <p class="eyebrow">{{ user.accountType === 'creator' ? t('account.creatorProfile') : t('account.companyProfile') }}</p>
            <h2>{{ user.accountType === 'creator' ? t('account.creatorProfile') : t('account.companyProfile') }}</h2>
            <p>{{ t('account.profileEditorIntro') }}</p>
          </div>
          <button class="button button--dark profile-form__save" type="submit" :disabled="busy">
            {{ t('account.saveProfile') }}
            <span aria-hidden="true">↗</span>
          </button>
        </header>

        <nav
          v-if="user.accountType === 'creator'"
          class="profile-tabs"
          role="tablist"
          :aria-label="t('account.creatorProfile')"
          @keydown="handleProfileTabKeydown"
        >
          <button
            id="account-tab-about"
            type="button"
            role="tab"
            :aria-selected="accountTab === 'about'"
            :tabindex="accountTab === 'about' ? 0 : -1"
            @click="selectAccountTab('about')"
          >{{ t('account.profileBasics') }}</button>
          <button
            id="account-tab-social"
            type="button"
            role="tab"
            :aria-selected="accountTab === 'social'"
            :tabindex="accountTab === 'social' ? 0 : -1"
            @click="selectAccountTab('social')"
          >{{ t('account.socialProfiles') }} <span>{{ profile.socialProfiles.length }}</span></button>
          <button
            id="account-tab-portfolio"
            type="button"
            role="tab"
            :aria-selected="accountTab === 'portfolio'"
            :tabindex="accountTab === 'portfolio' ? 0 : -1"
            @click="selectAccountTab('portfolio')"
          >{{ t('account.portfolio') }} <span>{{ profile.portfolio.length }}/4</span></button>
          <button
            id="account-tab-packages"
            type="button"
            role="tab"
            :aria-selected="accountTab === 'packages'"
            :tabindex="accountTab === 'packages' ? 0 : -1"
            @click="selectAccountTab('packages')"
          >{{ t('account.packages') }} <span>{{ profile.packages.length }}</span></button>
          <button
            id="account-tab-faqs"
            type="button"
            role="tab"
            :aria-selected="accountTab === 'faqs'"
            :tabindex="accountTab === 'faqs' ? 0 : -1"
            @click="selectAccountTab('faqs')"
          >{{ t('account.creatorFaqs') }} <span>{{ profile.faqs.length }}</span></button>
        </nav>

        <template v-if="user.accountType === 'creator'">
          <section
            v-if="accountTab === 'about'"
            id="account-panel-about"
            class="profile-tab-panel"
            role="tabpanel"
            aria-labelledby="account-tab-about"
          >
            <div class="form-grid">
              <label class="form-field form-field--wide">
                <span>{{ t('auth.name') }}</span>
                <input v-model.trim="profile.displayName" required maxlength="120" autocomplete="name" />
              </label>
              <MultiSelect
                v-model="profile.categories"
                :options="categories"
                :label="t('auth.category')"
                :placeholder="t('account.selectCategories')"
                :search-placeholder="t('account.searchCategories')"
                :no-results-label="t('account.noCategoriesFound')"
                :remove-label="t('account.remove')"
                :helper-text="t('account.categoriesHint')"
                :max-selections="5"
              />
              <label class="form-field">
                <span>{{ t('auth.location') }}</span>
                <input v-model.trim="profile.location" required maxlength="120" autocomplete="address-level2" />
              </label>
              <label class="form-field form-field--wide">
                <span>{{ t('account.tagline') }}</span>
                <input v-model.trim="profile.tagline" maxlength="180" />
              </label>
              <div class="form-field form-field--wide">
                <span>{{ t('account.bio') }}</span>
                <RichTextEditor
                  v-model="profile.bio"
                  :label="t('account.bio')"
                  :placeholder="t('account.bioPlaceholder')"
                  :maxlength="1500"
                />
              </div>
              <label class="form-field form-field--wide">
                <span>{{ t('account.tags') }}</span>
                <input v-model="tags" maxlength="500" />
              </label>
              <div class="form-field--wide">
                <MediaUploadField
                  v-model="profile.avatarMediaId"
                  folder="creator-avatar"
                  :preview-url="profile.avatarUrl"
                  @uploaded="profile.avatarUrl = $event.url"
                />
              </div>
            </div>
          </section>

          <section
            v-else-if="accountTab === 'social'"
            id="account-panel-social"
            class="profile-tab-panel"
            role="tabpanel"
            aria-labelledby="account-tab-social"
          >
            <div class="profile-editor__heading">
              <div>
                <h3>{{ t('account.socialProfiles') }}</h3>
                <small>{{ t('account.socialMetricsHint') }}</small>
              </div>
              <button
                class="profile-editor__add"
                type="button"
                :disabled="profile.socialProfiles.length >= 5"
                @click="addSocialProfile"
              >
                <Plus :size="16" aria-hidden="true" />
                {{ t('account.addSocial') }}
              </button>
            </div>
            <div v-if="!profile.socialProfiles.length" class="profile-editor__empty">{{ t('account.noSocialProfiles') }}</div>
            <div v-for="(social, index) in profile.socialProfiles" :key="index" class="social-editor">
              <label class="form-field">
                <span>{{ t('account.platform') }}</span>
                <select v-model="social.platform" required>
                  <option v-for="platform in SOCIAL_PLATFORMS" :key="platform" :value="platform">{{ platform }}</option>
                </select>
              </label>
              <label class="form-field">
                <span>{{ t('account.handle') }}</span>
                <input v-model.trim="social.handle" required maxlength="120" />
              </label>
              <label class="form-field">
                <span>{{ t('account.followers') }}</span>
                <input v-model.number="social.followers" type="number" min="0" max="200000000" required />
              </label>
              <button
                class="profile-editor__remove"
                type="button"
                :aria-label="`${t('account.remove')}: ${social.platform}`"
                @click="profile.socialProfiles.splice(index, 1)"
              >
                <Trash2 :size="15" aria-hidden="true" />
                <span>{{ t('account.remove') }}</span>
              </button>
            </div>
          </section>

          <section
            v-else-if="accountTab === 'portfolio'"
            id="account-panel-portfolio"
            class="profile-tab-panel"
            role="tabpanel"
            aria-labelledby="account-tab-portfolio"
          >
            <div class="profile-editor__heading">
              <div>
                <h3>{{ t('account.portfolio') }}</h3>
                <small>{{ t('account.portfolioHint') }}</small>
              </div>
              <button
                class="profile-editor__add"
                type="button"
                :disabled="profile.portfolio.length >= 4"
                @click="addPortfolioItem"
              >
                <Plus :size="16" aria-hidden="true" />
                {{ t('account.addPortfolioItem') }}
              </button>
            </div>
            <div v-if="!profile.portfolio.length" class="profile-editor__empty">{{ t('account.noPortfolioItems') }}</div>
            <article v-for="(item, index) in profile.portfolio" :key="item.id || index" class="portfolio-editor">
              <div class="portfolio-editor__top">
                <strong>{{ item.title || t('account.mediaTitle') }}</strong>
                <button
                  class="profile-editor__remove"
                  type="button"
                  :aria-label="`${t('account.remove')}: ${item.title || t('account.mediaTitle')}`"
                  @click="profile.portfolio.splice(index, 1)"
                >
                  <Trash2 :size="15" aria-hidden="true" />
                  <span>{{ t('account.remove') }}</span>
                </button>
              </div>
              <div class="form-grid">
                <label class="form-field">
                  <span>{{ t('account.mediaType') }}</span>
                  <select v-model="item.type" @change="changePortfolioType(item)">
                    <option value="image">{{ t('account.image') }}</option>
                    <option value="video">{{ t('account.video') }}</option>
                  </select>
                </label>
                <label class="form-field">
                  <span>{{ t('account.mediaPlatform') }}</span>
                  <select v-model="item.platform">
                    <option value="All">{{ t('account.allPlatforms') }}</option>
                    <option v-for="platform in SOCIAL_PLATFORMS" :key="platform" :value="platform">{{ platform }}</option>
                  </select>
                </label>
              </div>
              <label class="form-field">
                <span>{{ t('account.mediaTitle') }}</span>
                <input v-model.trim="item.title" maxlength="120" />
              </label>
              <MediaUploadField
                v-if="item.type === 'image'"
                v-model="item.mediaId"
                folder="creator-portfolio"
                :preview-url="item.url"
                @uploaded="item.url = $event.url"
              />
              <label v-else class="form-field">
                <span>{{ t('account.mediaUrl') }}</span>
                <input v-model.trim="item.url" type="url" required placeholder="https://" maxlength="1000" />
              </label>
            </article>
          </section>

          <section
            v-else-if="accountTab === 'packages'"
            id="account-panel-packages"
            class="profile-tab-panel"
            role="tabpanel"
            aria-labelledby="account-tab-packages"
          >
            <div class="profile-editor__heading">
              <div>
                <h3>{{ t('account.packages') }}</h3>
                <small>{{ t('account.packagesHint') }}</small>
              </div>
              <button
                class="profile-editor__add"
                type="button"
                :disabled="profile.packages.length >= 12"
                @click="addPackage"
              >
                <Plus :size="16" aria-hidden="true" />
                {{ t('account.addPackage') }}
              </button>
            </div>
            <div v-if="!profile.packages.length" class="profile-editor__empty">{{ t('account.noPackages') }}</div>
            <article v-for="(packageItem, index) in profile.packages" :key="packageItem.id || index" class="package-editor">
              <div class="portfolio-editor__top">
                <strong>{{ packageItem.title || t('account.packageTitle') }}</strong>
                <button
                  class="profile-editor__remove"
                  type="button"
                  :aria-label="`${t('account.remove')}: ${packageItem.title || t('account.packageTitle')}`"
                  @click="profile.packages.splice(index, 1)"
                >
                  <Trash2 :size="15" aria-hidden="true" />
                  <span>{{ t('account.remove') }}</span>
                </button>
              </div>
              <label class="form-field">
                <span>{{ t('account.platform') }}</span>
                <select v-model="packageItem.platform">
                  <option v-for="platform in SOCIAL_PLATFORMS" :key="platform" :value="platform">{{ platform }}</option>
                </select>
              </label>
              <label class="form-field">
                <span>{{ t('account.packageTitle') }}</span>
                <input v-model.trim="packageItem.title" required minlength="3" maxlength="120" />
              </label>
              <label class="form-field">
                <span>{{ t('account.packageDescription') }}</span>
                <textarea v-model.trim="packageItem.description" required minlength="10" maxlength="1200"></textarea>
              </label>
              <label class="form-field">
                <span>{{ t('account.packagePrice') }}</span>
                <input v-model="packageItem.price" type="number" min="1" max="10000000" />
              </label>
              <label class="form-field">
                <span>{{ t('account.currency') }}</span>
                <select v-model="packageItem.currency">
                  <option v-for="currency in CURRENCIES" :key="currency" :value="currency">
                    {{ currency }}
                  </option>
                </select>
              </label>
            </article>
          </section>

          <section
            v-else-if="accountTab === 'faqs'"
            id="account-panel-faqs"
            class="profile-tab-panel"
            role="tabpanel"
            aria-labelledby="account-tab-faqs"
          >
            <div class="profile-editor__heading">
              <div>
                <h3>{{ t('account.creatorFaqs') }}</h3>
                <small>{{ t('account.creatorFaqsHint') }}</small>
              </div>
              <button
                class="profile-editor__add"
                type="button"
                :disabled="profile.faqs.length >= 10"
                @click="addCreatorFaq"
              >
                <Plus :size="16" aria-hidden="true" />
                {{ t('account.addFaq') }}
              </button>
            </div>
            <div v-if="!profile.faqs.length" class="profile-editor__empty">{{ t('account.noCreatorFaqs') }}</div>
            <article v-for="(faq, index) in profile.faqs" :key="index" class="creator-faq-editor">
              <div class="portfolio-editor__top">
                <strong>{{ faq.question || t('account.faqQuestion') }}</strong>
                <button
                  class="profile-editor__remove"
                  type="button"
                  :aria-label="`${t('account.remove')}: ${faq.question || t('account.faqQuestion')}`"
                  @click="profile.faqs.splice(index, 1)"
                >
                  <Trash2 :size="15" aria-hidden="true" />
                  <span>{{ t('account.remove') }}</span>
                </button>
              </div>
              <label class="form-field">
                <span>{{ t('account.faqQuestion') }}</span>
                <input v-model.trim="faq.question" required minlength="3" maxlength="180" />
              </label>
              <label class="form-field">
                <span>{{ t('account.faqAnswer') }}</span>
                <textarea v-model.trim="faq.answer" required minlength="10" maxlength="1500"></textarea>
              </label>
            </article>
          </section>
        </template>
        <section v-else class="profile-tab-panel">
          <label class="form-field">
            <span>{{ t('account.companyName') }}</span>
            <input v-model.trim="profile.name" required maxlength="120" autocomplete="organization" />
          </label>
          <label class="form-field">
            <span>{{ t('auth.industry') }}</span>
            <input v-model.trim="profile.industry" required maxlength="100" />
          </label>
          <MediaUploadField
            v-model="profile.logoMediaId"
            folder="company-logo"
            :preview-url="profile.logoUrl"
            @uploaded="profile.logoUrl = $event.url"
          />
        </section>
      </form>

      <section
        v-if="accountSection === 'applications' && user.accountType === 'creator'"
        id="account-panel-applications"
        class="form-card account-activity-panel"
      >
        <h2>{{ t('account.myApplications') }}</h2>
        <StatusMessage v-if="!applications.length" variant="empty">
          {{ t('account.noApplications') }}
        </StatusMessage>
        <article v-for="application in applications" :key="application.id" class="dashboard-card">
          <div class="dashboard-card__heading">
            <RouterLink :to="{ name: 'campaign-detail', params: { slug: application.campaign.slug } }">{{ application.campaign.title }}</RouterLink>
            <span class="status-pill">{{ statusLabel(application.status) }}</span>
          </div>
          <p>{{ application.message }}</p>
          <div v-if="application.offer" class="offer-summary">
            <strong>{{ t('account.offerAmount') }}: {{ formatMoney(application.offer.amount, application.campaign.currency) }}</strong>
            <p>{{ application.offer.message }}</p>
          </div>
        </article>
      </section>
      <section
        v-if="accountSection === 'offers' && user.accountType === 'creator'"
        id="account-panel-offers"
        class="form-card account-activity-panel"
      >
        <h2>{{ t('account.myOffers') }}</h2>
        <StatusMessage v-if="!offers.length" variant="empty">
          {{ t('account.noOffers') }}
        </StatusMessage>
        <article v-for="offer in offers" :key="offer.id" class="dashboard-card">
          <div class="dashboard-card__heading">
            <RouterLink :to="{ name: 'campaign-detail', params: { slug: offer.campaign.slug } }">{{ offer.campaign.title }}</RouterLink>
            <span class="status-pill">{{ statusLabel(offer.status) }}</span>
          </div>
          <strong>{{ formatMoney(offer.amount, offer.campaign.currency) }}</strong>
          <p>{{ offer.message }}</p>
          <div v-if="offer.status === 'pending'" class="button-row">
            <button class="button button--dark" type="button" :disabled="!user.emailVerified || !user.approved" @click="respondToOffer(offer, 'accept')">{{ t('account.accept') }}</button>
            <button class="button button--outline" type="button" :disabled="!user.emailVerified || !user.approved" @click="respondToOffer(offer, 'reject')">{{ t('account.reject') }}</button>
          </div>
        </article>
      </section>

      <section
        v-if="accountSection === 'bookmarks' && user.accountType === 'creator'"
        class="form-card account-activity-panel"
      >
        <h2>{{ t('account.bookmarks') }}</h2>
        <div v-if="bookmarks.length" class="campaign-grid account-bookmarks-grid">
          <CampaignCard v-for="campaign in bookmarks" :key="campaign.id" :campaign="campaign" />
        </div>
        <StatusMessage v-else variant="empty">{{ t('account.noBookmarks') }}</StatusMessage>
      </section>

      <template v-if="user.accountType !== 'creator' && accountSection === 'campaigns'">
        <section class="form-card account-campaign-form">
          <h2>{{ editingCampaignId ? t('account.editCampaign') : t('account.createCampaign') }}</h2>
          <form class="form-stack" @submit.prevent="saveCampaign">
            <label class="form-field"><span>{{ t('account.campaignTitle') }}</span><input v-model.trim="campaignForm.title" required minlength="5" maxlength="160" /></label>
            <label class="form-field"><span>{{ t('account.summary') }}</span><input v-model.trim="campaignForm.summary" required minlength="10" maxlength="220" /></label>
            <label class="form-field"><span>{{ t('account.description') }}</span><textarea v-model.trim="campaignForm.description" required minlength="20" maxlength="8000"></textarea></label>
            <div class="form-field form-field--wide">
              <span>{{ t('account.campaignCover') }}</span>
              <MediaUploadField
                v-model="campaignForm.coverMediaId"
                folder="campaign-cover"
                :preview-url="campaignForm.coverImageUrl"
                :optional="true"
                @uploaded="campaignForm.coverImageUrl = $event.url"
              />
            </div>
            <div class="form-grid">
              <label class="form-field">
                <span>{{ t('auth.category') }}</span>
                <select v-model="campaignForm.category">
                  <option
                    v-for="category in categories"
                    :key="category.value"
                    :value="category.value"
                  >
                    {{ category.label }}
                  </option>
                </select>
              </label>
              <label class="form-field"><span>{{ t('campaignDetail.location') }}</span><input v-model.trim="campaignForm.location" required maxlength="120" /></label>
            </div>
            <label class="form-field"><span>{{ t('account.channels') }}</span><input v-model="campaignForm.channelsText" required /></label>
            <label class="form-field"><span>{{ t('account.deliverables') }}</span><input v-model="campaignForm.deliverablesText" required /></label>
            <div class="form-grid">
              <label class="form-field"><span>{{ t('account.budgetMin') }}</span><input v-model.number="campaignForm.budgetMin" type="number" min="1" max="10000000" required /></label>
              <label class="form-field"><span>{{ t('account.budgetMax') }}</span><input v-model.number="campaignForm.budgetMax" type="number" min="1" max="10000000" required /></label>
              <label class="form-field">
                <span>{{ t('account.currency') }}</span>
                <select v-model="campaignForm.currency">
                  <option v-for="currency in CURRENCIES" :key="currency" :value="currency">
                    {{ currency }}
                  </option>
                </select>
              </label>
              <label class="form-field"><span>{{ t('account.creatorCount') }}</span><input v-model.number="campaignForm.creatorCount" type="number" min="1" max="100" required /></label>
              <label class="form-field"><span>{{ t('account.closesAt') }}</span><input v-model="campaignForm.closesAt" type="date" required /></label>
            </div>
            <label v-if="editingCampaignId" class="form-field"><span>{{ t('account.status') }}</span><select v-model="campaignForm.status"><option value="open">{{ t('account.open') }}</option><option value="closed">{{ t('account.closed') }}</option></select></label>
            <div class="button-row">
              <button class="button button--dark" type="submit" :disabled="busy || !user.emailVerified || !user.approved">{{ editingCampaignId ? t('account.updateCampaign') : t('account.publish') }} <span aria-hidden="true">↗</span></button>
              <button v-if="editingCampaignId" class="button button--outline" type="button" @click="setCampaignForm()">{{ t('account.cancelEdit') }}</button>
            </div>
          </form>
        </section>
        <section class="form-card account-activity-panel">
          <h2>{{ t('account.campaigns') }}</h2>
          <StatusMessage v-if="!campaigns.length" variant="empty">
            {{ t('account.noCampaigns') }}
          </StatusMessage>
          <article v-for="campaign in campaigns" :key="campaign.id" class="dashboard-card">
            <div class="dashboard-card__heading">
              <RouterLink :to="{ name: 'campaign-detail', params: { slug: campaign.slug } }">{{ campaign.title }}</RouterLink>
              <span class="status-pill">{{ t(`account.${campaign.status}`) }}</span>
            </div>
            <p>{{ campaign.summary }}</p>
            <button class="text-link" type="button" @click="setCampaignForm(campaign)">{{ t('account.editCampaign') }} ↗</button>
          </article>
        </section>
      </template>
      <section
        v-if="user.accountType !== 'creator' && accountSection === 'applications'"
        class="form-card account-activity-panel"
      >
        <h2>{{ t('account.companyApplications') }}</h2>
        <StatusMessage v-if="!companyApplications.length" variant="empty">
          {{ t('account.noApplications') }}
        </StatusMessage>
        <article v-for="application in companyApplications" :key="application.id" class="dashboard-card">
          <div class="dashboard-card__heading">
            <RouterLink :to="{ name: 'creator-profile', params: { slug: application.creator.slug } }">{{ application.creator.displayName }}</RouterLink>
            <span class="status-pill">{{ statusLabel(application.status) }}</span>
          </div>
          <p class="dashboard-card__subheading">{{ application.campaign.title }}</p>
          <p><strong>{{ t('account.yourMessage') }}</strong><br />{{ application.message }}</p>
          <LocalizedLink
            v-if="application.status !== 'rejected'"
            class="button button--outline"
            :to="{
              name: 'messages',
              query: {
                campaign: application.campaign.slug,
                creatorId: application.creator.id,
                creatorSlug: application.creator.slug,
              },
            }"
          >{{ t('campaignChat.messageApplicant') }}</LocalizedLink>
          <div v-if="application.status === 'pending'" class="form-stack">
            <label class="form-field"><span>{{ t('account.offerAmount') }} ({{ application.campaign.currency }})</span><input v-model.number="offerForms[application.id].amount" type="number" :min="application.campaign.budgetMin" :max="application.campaign.budgetMax" required /></label>
            <label class="form-field"><span>{{ t('account.offerMessage') }}</span><textarea v-model.trim="offerForms[application.id].message" required minlength="10" maxlength="1500"></textarea></label>
            <div class="button-row">
              <button class="button button--dark" type="button" :disabled="!user.emailVerified || !user.approved || !offerForms[application.id].message.trim()" @click="sendOffer(application)">{{ t('account.sendOffer') }}</button>
              <button class="button button--outline" type="button" :disabled="!user.emailVerified || !user.approved" @click="rejectApplication(application)">{{ t('account.reject') }}</button>
            </div>
          </div>
          <div v-else-if="application.offer" class="offer-summary">
            <strong>{{ formatMoney(application.offer.amount, application.campaign.currency) }}</strong>
            <p>{{ application.offer.message }}</p>
          </div>
        </article>
      </section>
      <section
        v-if="accountSection === 'inquiries'"
        id="account-panel-inquiries"
        class="form-card account-inquiries"
      >
        <h2>{{ t('account.directRequests') }}</h2>
        <StatusMessage v-if="!inquiries.length" variant="empty">
          {{ t('account.noDirectRequests') }}
        </StatusMessage>
        <article v-for="inquiry in inquiries" :key="inquiry.id" class="dashboard-card inquiry-card">
          <div class="dashboard-card__heading">
            <strong>{{ user.accountType === 'creator' ? inquiry.company.name : inquiry.creator.displayName }}</strong>
            <span class="status-pill">{{ t(`account.inquiry${inquiry.status[0].toUpperCase()}${inquiry.status.slice(1)}`) }}</span>
          </div>
          <p v-if="inquiry.packageTitle" class="dashboard-card__subheading">{{ inquiry.packageTitle }}</p>
          <p v-if="!inquiry.canChat">{{ inquiry.message }}</p>
          <p v-if="inquiry.proposedAmount || inquiry.listedPrice" class="inquiry-card__price">
            {{ t('account.proposedPrice') }}:
            {{ formatMoney(inquiry.proposedAmount ?? inquiry.listedPrice, inquiry.proposedAmount ? inquiry.currency : inquiry.listedPriceCurrency) }}
          </p>
          <div v-if="user.accountType === 'creator' && inquiry.status === 'pending'" class="button-row">
            <button class="button button--dark" type="button" :disabled="!user.emailVerified || !user.approved" @click="respondToInquiry(inquiry, 'accept')">{{ t('account.accept') }}</button>
            <button class="button button--outline" type="button" :disabled="!user.emailVerified || !user.approved" @click="respondToInquiry(inquiry, 'reject')">{{ t('account.reject') }}</button>
          </div>
          <div v-if="inquiry.canChat" class="inquiry-chat">
            <div class="inquiry-chat__messages">
              <p v-for="message in inquiryMessages[inquiry.id] || []" :key="message.id" :class="{ 'inquiry-chat__message--mine': message.senderRole === user.accountType }" class="inquiry-chat__message">
                {{ message.body }}
              </p>
            </div>
            <form class="inquiry-chat__form" @submit.prevent="sendInquiryMessage(inquiry)">
              <label class="form-field">
                <span class="sr-only">{{ t('account.writeMessage') }}</span>
                <textarea v-model="inquiryMessageDrafts[inquiry.id]" required maxlength="2000" :placeholder="t('account.writeMessage')"></textarea>
              </label>
              <button class="button button--dark" type="submit">{{ t('account.sendMessage') }}</button>
            </form>
          </div>
        </article>
      </section>
      </div>
      </main>
    </div>
  </section>
</template>
