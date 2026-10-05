<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { parsePhoneNumberFromString } from 'libphonenumber-js/min'
import RouterLink from '../components/shared/LocalizedLink.vue'
import { useI18n } from 'vue-i18n'
import { Eye, MessageCircle, Plus, Trash2, X } from '@lucide/vue'
import AccountAccessPanel from '../components/account/AccountAccessPanel.vue'
import AccountSidebar from '../components/account/AccountSidebar.vue'
import RequiredProfileModal from '../components/account/RequiredProfileModal.vue'
import MultiSelect from '../components/shared/MultiSelect.vue'
import MediaUploadField from '../components/shared/MediaUploadField.vue'
import PhoneNumberField from '../components/shared/PhoneNumberField.vue'
import ProfileImageField from '../components/shared/ProfileImageField.vue'
import RichTextEditor from '../components/shared/RichTextEditor.vue'
import SearchableSelect from '../components/shared/SearchableSelect.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import SwitchField from '../components/shared/SwitchField.vue'
import WaveLogo from '../components/shared/WaveLogo.vue'
import CampaignCard from '../components/campaigns/CampaignCard.vue'
import DirectoryPagination from '../components/shared/DirectoryPagination.vue'
import { apiGet, apiRequest, formatDate, formatMoney } from '../lib/api'
import { useMarketplaceCatalog } from '../composables/useMarketplaceCatalog'
import { currentUser, setCurrentUser } from '../composables/useCurrentUser'
import { useCampaignBookmarks } from '../composables/useCampaignBookmarks'
import { CURRENCIES, SOCIAL_PLATFORMS } from '../lib/marketplace'
import { formatInternationalPhoneNumber } from '../lib/phoneNumbers'
import { localizedRouteName } from '../routePaths'
import countries from '../data/countries.json'

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const { categories, error: catalogError } = useMarketplaceCatalog(locale)
const { bookmarks, loadCampaignBookmarks } = useCampaignBookmarks()
const countryDisplayLocale = computed(() => {
  if (locale.value === 'cnr') return 'bs'
  if (locale.value === 'sr') return 'sr-Latn'

  return locale.value
})
const countryOptions = computed(() => {
  const countryNames = new Intl.DisplayNames([countryDisplayLocale.value], { type: 'region' })

  return countries
    .map((code) => ({ value: code, label: countryNames.of(code) || code }))
    .sort((first, second) => first.label.localeCompare(second.label, countryDisplayLocale.value))
})
const PROFILE_TABS = ['about', 'social', 'portfolio', 'packages', 'faqs']
const LEGACY_ACCOUNT_TABS = {
  applications: 'account-applications',
  offers: 'account-offers',
  inquiries: 'account-inquiries',
  bookmarks: 'account-bookmarks',
  invitations: 'account-invitations',
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
const profileImageField = ref(null)
const profilePhoneCountry = ref('BA')
const tags = ref('')
const campaigns = ref([])
const applications = ref([])
const offers = ref([])
const invitations = ref([])
const companyApplications = ref([])
const inquiries = ref([])
const editingCampaignId = ref(null)
const campaignForm = ref(emptyCampaign())
const companyCampaignPage = ref(1)
const companyCampaignPageSize = ref(10)
const offerForms = ref({})
const applicantDialog = ref(null)
const selectedApplication = ref(null)
let noticeTimeout = null
const campaignPage = computed(() => route.meta.accountCampaignPage ?? 'list')
const selectedCampaign = computed(() => campaigns.value.find((campaign) => campaign.slug === route.params.slug) ?? null)
const paginatedCompanyCampaigns = computed(() => {
  const start = (companyCampaignPage.value - 1) * companyCampaignPageSize.value

  return campaigns.value.slice(start, start + companyCampaignPageSize.value)
})
const selectedCampaignApplications = computed(() => selectedCampaign.value
  ? companyApplications.value.filter((application) => application.campaign.slug === selectedCampaign.value.slug)
  : [])

watch(notice, (message) => {
  if (noticeTimeout) {
    window.clearTimeout(noticeTimeout)
    noticeTimeout = null
  }
  if (!message) return

  noticeTimeout = window.setTimeout(() => {
    notice.value = ''
    noticeTimeout = null
  }, 4000)
})

onBeforeUnmount(() => {
  if (noticeTimeout) {
    window.clearTimeout(noticeTimeout)
  }
})

watch(catalogError, (value) => {
  if (value) {
    error.value = value
  }
})

watch(currentUser, (authenticatedUser) => {
  if (!authenticatedUser) {
    user.value = null
    profile.value = null
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

function updateProfileCountry(countryCode) {
  profile.value.countryCode = countryCode
  profilePhoneCountry.value = countryCode || 'BA'
}

function incompleteProfileTab() {
  if (user.value.accountType === 'creator'
    && (!profile.value.displayName.trim()
      || profile.value.categories.length === 0
      || !profile.value.location.trim()
      || !profile.value.city.trim()
      || !profile.value.countryCode
      || !profile.value.phone.trim())
  ) {
    return 'about'
  }
  if (user.value.accountType === 'company'
    && (!profile.value.name.trim()
      || !profile.value.industry.trim()
      || !profile.value.city.trim()
      || !profile.value.countryCode
      || !profile.value.phone.trim())
  ) {
    return 'about'
  }
  if (user.value.accountType !== 'creator') {
    return ''
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
    if (response.data.profile) {
      profile.value = {
        ...profile.value,
        phone: response.data.phone || '',
        city: response.data.city || '',
        countryCode: response.data.countryCode || '',
      }
      profilePhoneCountry.value = parsePhoneNumberFromString(profile.value.phone)?.country
        || profile.value.countryCode
        || 'BA'
    }
    const canAccessMarketplace = response.data.approved || response.data.isAdmin
    if (route.query.bookmark && canAccessMarketplace) {
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
      applications.value = []
      offers.value = []
      invitations.value = []
      if (canAccessMarketplace) {
        const [applicationResponse, offerResponse, invitationResponse] = await Promise.all([
          apiGet('/me/applications'),
          apiGet('/me/offers'),
          apiGet('/me/invitations'),
          loadCampaignBookmarks(),
        ])
        applications.value = applicationResponse.data
        offers.value = offerResponse.data
        invitations.value = invitationResponse.data
      }
    } else {
      if (route.query.tab !== undefined) {
        const query = { ...route.query }
        delete query.tab
        void router.replace({ query })
      }
      campaigns.value = []
      companyApplications.value = []
      if (canAccessMarketplace) {
        const campaignResponse = await apiGet('/me/campaigns')
        campaigns.value = campaignResponse.data
        const applicationResponses = await Promise.all(
          campaigns.value.map((campaign) => apiGet(`/company/campaigns/${encodeURIComponent(campaign.slug)}/applications`)),
        )
        companyApplications.value = applicationResponses.flatMap((response) => response.data)
        if (selectedApplication.value) {
          selectedApplication.value = companyApplications.value.find(
            (application) => application.id === selectedApplication.value.id,
          ) ?? selectedApplication.value
        }
        offerForms.value = Object.fromEntries(companyApplications.value.map((application) => [
          application.id,
          { amount: application.campaign.budgetMin, message: '' },
        ]))
      }
    }
    if (canAccessMarketplace) {
      const inquiryResponse = await apiGet('/me/inquiries')
      inquiries.value = inquiryResponse.data
    } else {
      inquiries.value = []
    }
  } catch (cause) {
    if (cause.status === 401) {
      user.value = null
      profile.value = null
      inquiries.value = []
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
  const isCreator = user.value.accountType === 'creator'
  const enteredPhone = profile.value.phone.trim()
  const normalizedPhone = enteredPhone
    ? formatInternationalPhoneNumber(enteredPhone, profilePhoneCountry.value)
    : null
  if (!normalizedPhone) {
    error.value = t('auth.phoneInvalid')
    busy.value = false
    return
  }

  let imageChange = null
  let profileSaved = false
  const body = isCreator
    ? {
        displayName: profile.value.displayName,
        category: profile.value.categories[0] || profile.value.category,
        categories: profile.value.categories,
        location: profile.value.location,
        phone: normalizedPhone,
        city: profile.value.city.trim(),
        countryCode: profile.value.countryCode,
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
        about: profile.value.about || '',
        city: profile.value.city?.trim() || null,
        countryCode: profile.value.countryCode || null,
        phone: normalizedPhone,
      }
  try {
    imageChange = await profileImageField.value?.prepareSave()
    if (imageChange) {
      if (isCreator) {
        body.avatarMediaId = imageChange.mediaId
        body.avatarUrl = imageChange.url
      } else {
        body.logoMediaId = imageChange.mediaId
        body.logoUrl = imageChange.url
      }
    } else if (isCreator) {
      body.avatarMediaId = profile.value.avatarMediaId || null
      body.avatarUrl = profile.value.avatarMediaId ? null : profile.value.avatarUrl || null
    } else {
      body.logoMediaId = profile.value.logoMediaId || null
      body.logoUrl = profile.value.logoMediaId ? null : profile.value.logoUrl || null
    }

    await apiRequest('/me/profile', { method: 'PUT', body })
    profileSaved = true
    if (imageChange) {
      if (isCreator) {
        profile.value.avatarMediaId = imageChange.mediaId
        profile.value.avatarUrl = imageChange.url
      } else {
        profile.value.logoMediaId = imageChange.mediaId
        profile.value.logoUrl = imageChange.url
      }
    }
    if (imageChange?.previousMediaId) {
      try {
        await apiRequest(`/media/${imageChange.previousMediaId}`, { method: 'DELETE' })
      } catch (cause) {
        error.value = `${t('account.profileImageCleanupFailed')} ${cause.message}`
        notice.value = t('account.profileSaved')
        return
      }
    }
    profileImageField.value?.commit()
    notice.value = t('account.profileSaved')
    await loadDashboard()
  } catch (cause) {
    if (!profileSaved && imageChange?.uploadedMediaId) {
      try {
        await profileImageField.value?.rollback(imageChange)
      } catch (cleanupCause) {
        error.value = `${cause.message} ${t('account.profileImageCleanupFailed')} ${cleanupCause.message}`
      }
    }
    if (!error.value) {
      error.value = cause.message
    }
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
}

function cancelCampaignEditing() {
  setCampaignForm()
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
    const response = await apiRequest(path, { method: editingCampaignId.value ? 'PUT' : 'POST', body })
    const savedCampaign = response.data
    const isNewCampaign = !editingCampaignId.value
    notice.value = t('account.campaignSaved')
    setCampaignForm()
    await loadDashboard()
    if (isNewCampaign && savedCampaign?.slug) {
      await router.replace({
        name: localizedRouteName('account-campaign-detail', locale.value),
        params: { slug: savedCampaign.slug },
      })
    }
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

async function shortlistApplication(application) {
  await runAction(`/company/applications/${application.id}/shortlist`, {})
}

function applicationCount(campaign) {
  return companyApplications.value.filter((application) => application.campaign.slug === campaign.slug).length
}

function openApplicant(application) {
  selectedApplication.value = application
  applicantDialog.value?.showModal()
}

function closeApplicant() {
  applicantDialog.value?.close()
  selectedApplication.value = null
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

async function respondToInvitation(invitation, decision) {
  error.value = ''
  notice.value = ''
  try {
    const response = await apiRequest(`/me/invitations/${invitation.id}/respond`, {
      method: 'POST',
      body: { decision },
    })
    notice.value = t('account.invitationResponseSaved')
    await loadDashboard()
    if (response.conversation?.id) {
      await router.push({
        name: localizedRouteName('messages', locale.value),
        query: { conversation: response.conversation.id },
      })
    }
  } catch (cause) {
    error.value = cause.message
  }
}

async function respondToInquiry(inquiry, decision) {
  error.value = ''
  notice.value = ''
  try {
    await apiRequest(`/me/inquiries/${inquiry.id}/decision`, {
      method: 'POST',
      body: { decision },
    })
    notice.value = t('account.actionSaved')
    await loadDashboard()
    if (decision === 'accept') {
      await router.push({
        name: localizedRouteName('messages', locale.value),
        query: { inquiry: inquiry.id },
      })
    }
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
    : section === 'offers' || section === 'bookmarks' || section === 'applications'

  if (unavailableForRole) {
    void router.replace({ name: localizedRouteName('account', locale.value) })
  }
})

watch(() => [route.meta.accountCampaignPage, route.params.slug], ([page]) => {
  if (page === 'create') {
    setCampaignForm()
  } else {
    editingCampaignId.value = null
  }

  if (applicantDialog.value?.open) {
    closeApplicant()
  }
})

watch(campaigns, () => {
  companyCampaignPage.value = 1
})

watch([campaigns, () => route.params.slug], ([campaignList, slug]) => {
  if (user.value?.accountType === 'creator' || typeof slug !== 'string' || campaignList.length === 0) {
    return
  }

  if (!campaignList.some((campaign) => campaign.slug === slug)) {
    void router.replace({ name: localizedRouteName('account-campaigns', locale.value) })
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
      <p>{{ user.profileComplete ? t('account.approvalPendingNotice') : t('account.profileIncomplete') }}</p>
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

        <div class="profile-image-editor">
          <ProfileImageField
            ref="profileImageField"
            :folder="user.accountType === 'creator' ? 'creator-avatar' : 'company-logo'"
            :model-value="user.accountType === 'creator' ? profile.avatarMediaId : profile.logoMediaId"
            :preview-url="user.accountType === 'creator' ? profile.avatarUrl : profile.logoUrl"
            :alt="user.accountType === 'creator' ? profile.displayName : profile.name"
            :add-label="t('account.addProfileImage')"
            :change-label="t('account.changeProfileImage')"
            :remove-label="t('account.removeProfileImage')"
            :helper-text="t('account.profileImageSaveHint')"
            :remove-title="t('account.profileImageRemoveTitle')"
            :remove-message="t('account.profileImageRemoveMessage')"
            :confirm-label="t('account.remove')"
            :cancel-label="t('account.richTextCancel')"
            :disabled="busy || !user.approved"
          />
        </div>

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
              <SearchableSelect
                :model-value="profile.countryCode || ''"
                :options="countryOptions"
                :label="t('auth.country')"
                :placeholder="t('auth.selectCountry')"
                :search-placeholder="t('auth.searchCountry')"
                :no-results-label="t('auth.noCountriesFound')"
                @update:model-value="updateProfileCountry"
              />
              <label class="form-field">
                <span>{{ t('auth.city') }}</span>
                <input v-model.trim="profile.city" required maxlength="70" autocomplete="address-level2" />
              </label>
              <PhoneNumberField
                v-model="profile.phone"
                v-model:country-code="profilePhoneCountry"
                :label="t('auth.phone')"
                :placeholder="t('auth.phonePlaceholder')"
                :country-label="t('auth.phoneCountry')"
                :country-placeholder="t('auth.selectCountry')"
                :country-search-placeholder="t('auth.searchPhoneCountry')"
                :no-countries-found-label="t('auth.noPhoneCountriesFound')"
              />
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
          <div class="form-grid">
            <label class="form-field">
              <span>{{ t('account.companyName') }}</span>
              <input
                v-model.trim="profile.name"
                required
                maxlength="120"
                autocomplete="organization"
                :placeholder="t('auth.companyNamePlaceholder')"
              />
            </label>
            <label class="form-field">
              <span>{{ t('auth.industry') }}</span>
              <input
                v-model.trim="profile.industry"
                required
                maxlength="100"
                :placeholder="t('auth.industryPlaceholder')"
              />
            </label>
            <SearchableSelect
              class="form-field--wide"
              :model-value="profile.countryCode || ''"
              :options="countryOptions"
              :label="t('auth.country')"
              :placeholder="t('auth.selectCountry')"
              :search-placeholder="t('auth.searchCountry')"
              :no-results-label="t('auth.noCountriesFound')"
              @update:model-value="updateProfileCountry"
            />
            <label class="form-field">
              <span>{{ t('auth.city') }}</span>
              <input
                v-model.trim="profile.city"
                required
                maxlength="70"
                autocomplete="address-level2"
                :placeholder="t('auth.cityPlaceholder')"
              />
            </label>
            <PhoneNumberField
              v-model="profile.phone"
              v-model:country-code="profilePhoneCountry"
              :label="t('auth.phone')"
              :placeholder="t('auth.phonePlaceholder')"
              :country-label="t('auth.phoneCountry')"
              :country-placeholder="t('auth.selectCountry')"
              :country-search-placeholder="t('auth.searchPhoneCountry')"
              :no-countries-found-label="t('auth.noPhoneCountriesFound')"
            />
          </div>
          <div class="form-field form-field--wide company-about-field">
            <span>{{ t('account.companyAbout') }}</span>
            <RichTextEditor
              v-model="profile.about"
              :label="t('account.companyAbout')"
              :placeholder="t('account.companyAboutPlaceholder')"
              :maxlength="1500"
            />
          </div>
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
        <div v-else class="application-list">
          <article v-for="application in applications" :key="application.id" class="application-row">
            <div class="application-row__main">
              <div class="application-row__heading">
                <RouterLink
                  class="application-row__title"
                  :to="{ name: 'campaign-detail', params: { slug: application.campaign.slug } }"
                >
                  {{ application.campaign.title }}
                </RouterLink>
                <span class="status-pill">{{ statusLabel(application.status) }}</span>
              </div>
              <div class="application-row__meta">
                <span>{{ application.campaign.company.name }}</span>
                <time :datetime="application.createdAt">{{ formatDate(application.createdAt) }}</time>
              </div>
              <p class="application-row__message">{{ application.message }}</p>
              <div v-if="application.offer" class="application-row__offer">
                <strong>{{ formatMoney(application.offer.amount, application.campaign.currency) }}</strong>
                <span>{{ application.offer.message }}</span>
              </div>
            </div>
            <RouterLink
              v-if="application.conversationId"
              class="button button--outline application-row__chat"
              :to="{
                name: localizedRouteName('messages', locale),
                query: { conversation: application.conversationId },
              }"
            >
              <MessageCircle :size="15" aria-hidden="true" />
              {{ t('account.openChat') }}
            </RouterLink>
          </article>
        </div>
      </section>
      <section
        v-if="accountSection === 'invitations' && user.accountType === 'creator'"
        id="account-panel-invitations"
        class="form-card account-activity-panel"
      >
        <h2>{{ t('account.campaignInvitations') }}</h2>
        <StatusMessage v-if="!invitations.length" variant="empty">
          {{ t('account.noCampaignInvitations') }}
        </StatusMessage>
        <article v-for="invitation in invitations" :key="invitation.id" class="dashboard-card">
          <div class="dashboard-card__heading">
            <RouterLink :to="{ name: 'campaign-detail', params: { slug: invitation.campaign.slug } }">
              {{ invitation.campaign.title }}
            </RouterLink>
            <span class="status-pill">{{ statusLabel(invitation.status) }}</span>
          </div>
          <p v-if="invitation.company" class="dashboard-card__subheading">{{ invitation.company.name }}</p>
          <p>{{ invitation.message }}</p>
          <div v-if="invitation.status === 'pending'" class="button-row">
            <button class="button button--dark" type="button" :disabled="!user.emailVerified || !user.approved" @click="respondToInvitation(invitation, 'accept')">
              {{ t('account.acceptInvitation') }}
            </button>
            <button class="button button--outline" type="button" :disabled="!user.emailVerified || !user.approved" @click="respondToInvitation(invitation, 'decline')">
              {{ t('account.declineInvitation') }}
            </button>
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

      <section
        v-if="user.accountType !== 'creator' && accountSection === 'campaigns' && campaignPage === 'list'"
        class="form-card account-activity-panel"
      >
        <header class="company-campaigns__header">
          <div>
            <p class="eyebrow">{{ t('account.companyProfile') }}</p>
            <h2>{{ t('account.campaigns') }}</h2>
          </div>
          <RouterLink
            class="button button--dark"
            :to="{ name: localizedRouteName('account-campaign-create', locale) }"
          >
            <Plus :size="16" aria-hidden="true" />
            {{ t('account.addCampaign') }}
          </RouterLink>
        </header>
        <StatusMessage v-if="!campaigns.length" variant="empty">
          {{ t('account.noCampaigns') }}
        </StatusMessage>
        <div v-else class="company-campaign-list" role="list">
          <article
            v-for="campaign in paginatedCompanyCampaigns"
            :key="campaign.id"
            class="company-campaign-row"
            role="listitem"
          >
            <div class="company-campaign-row__main">
              <RouterLink
                class="company-campaign-row__title"
                :to="{ name: localizedRouteName('account-campaign-detail', locale), params: { slug: campaign.slug } }"
              >
                {{ campaign.title }}
              </RouterLink>
              <p>{{ campaign.summary }}</p>
            </div>
            <span class="status-pill">{{ t(`account.${campaign.status}`) }}</span>
            <span class="company-campaign-row__applicants">
              {{ t('account.campaignApplicants') }}: <strong>{{ applicationCount(campaign) }}</strong>
            </span>
            <RouterLink
              class="button button--outline company-campaign-row__view"
              :to="{ name: localizedRouteName('account-campaign-detail', locale), params: { slug: campaign.slug } }"
            >
              {{ t('account.viewCampaign') }}
            </RouterLink>
          </article>
        </div>
        <DirectoryPagination
          :page="companyCampaignPage"
          :page-size="companyCampaignPageSize"
          :page-sizes="[10, 25, 50]"
          :total="campaigns.length"
          @update:page="companyCampaignPage = $event"
          @update:page-size="companyCampaignPageSize = $event"
        />
      </section>

      <template v-if="user.accountType !== 'creator' && accountSection === 'campaigns'">
        <section
          v-if="campaignPage === 'create' || editingCampaignId"
          class="form-card account-activity-panel account-campaign-form"
        >
          <header class="company-campaigns__header">
            <div>
              <p class="eyebrow">{{ t('account.campaigns') }}</p>
              <h2>{{ editingCampaignId ? t('account.editCampaign') : t('account.createCampaign') }}</h2>
            </div>
            <button
              v-if="editingCampaignId && selectedCampaign"
              class="button button--outline"
              type="button"
              @click="cancelCampaignEditing"
            >
              {{ t('account.cancelEdit') }}
            </button>
            <RouterLink
              v-else
              class="button button--outline"
              :to="{ name: localizedRouteName('account-campaigns', locale) }"
            >
              {{ t('account.cancelEdit') }}
            </RouterLink>
          </header>
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
                  <option v-for="category in categories" :key="category.value" :value="category.value">
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
            <label v-if="editingCampaignId" class="form-field">
              <span>{{ t('account.status') }}</span>
              <select v-model="campaignForm.status">
                <option value="open">{{ t('account.open') }}</option>
                <option value="closed">{{ t('account.closed') }}</option>
              </select>
            </label>
            <div class="button-row">
              <button class="button button--dark" type="submit" :disabled="busy || !user.emailVerified || !user.approved">
                {{ editingCampaignId ? t('account.updateCampaign') : t('account.publish') }}
                <span aria-hidden="true">↗</span>
              </button>
            </div>
          </form>
        </section>

        <template v-else-if="campaignPage === 'detail' && selectedCampaign">
          <section class="form-card account-activity-panel account-campaign-detail">
            <header class="company-campaigns__header">
              <div>
                <p class="eyebrow">{{ t('account.campaignDetails') }}</p>
                <h2>{{ selectedCampaign.title }}</h2>
              </div>
              <div class="button-row">
                <span class="status-pill">{{ t(`account.${selectedCampaign.status}`) }}</span>
                <button class="button button--outline" type="button" @click="setCampaignForm(selectedCampaign)">
                  {{ t('account.editCampaign') }}
                </button>
              </div>
            </header>
            <img
              v-if="selectedCampaign.coverImageUrl"
              class="company-campaign-detail__cover"
              :src="selectedCampaign.coverImageUrl"
              :alt="selectedCampaign.title"
            />
            <p class="company-campaign-detail__summary">{{ selectedCampaign.summary }}</p>
            <p class="company-campaign-detail__description">{{ selectedCampaign.description }}</p>
            <dl class="company-campaign-detail__facts">
              <div><dt>{{ t('auth.category') }}</dt><dd>{{ selectedCampaign.category }}</dd></div>
              <div><dt>{{ t('campaignDetail.location') }}</dt><dd>{{ selectedCampaign.location }}</dd></div>
              <div><dt>{{ t('account.budgetMin') }} – {{ t('account.budgetMax') }}</dt><dd>{{ formatMoney(selectedCampaign.budgetMin, selectedCampaign.currency) }} – {{ formatMoney(selectedCampaign.budgetMax, selectedCampaign.currency) }}</dd></div>
              <div><dt>{{ t('account.creatorCount') }}</dt><dd>{{ selectedCampaign.creatorCount }}</dd></div>
              <div><dt>{{ t('account.closesAt') }}</dt><dd>{{ formatDate(selectedCampaign.closesAt) }}</dd></div>
              <div><dt>{{ t('account.channels') }}</dt><dd>{{ selectedCampaign.channels.join(', ') }}</dd></div>
              <div><dt>{{ t('account.deliverables') }}</dt><dd>{{ selectedCampaign.deliverables.join(', ') }}</dd></div>
            </dl>
          </section>

          <section class="form-card account-activity-panel company-applicants">
            <header class="company-campaigns__header">
              <div>
                <p class="eyebrow">{{ selectedCampaign.title }}</p>
                <h2>{{ t('account.campaignApplicants') }}</h2>
              </div>
              <span class="company-applicants__count">{{ selectedCampaignApplications.length }}</span>
            </header>
            <StatusMessage v-if="!selectedCampaignApplications.length" variant="empty">
              {{ t('account.noCampaignApplications') }}
            </StatusMessage>
            <div v-else class="company-applicants__list" role="list">
              <article
                v-for="application in selectedCampaignApplications"
                :key="application.id"
                class="company-applicant-row"
                role="listitem"
              >
                <button
                  class="company-applicant-row__identity"
                  type="button"
                  @click="openApplicant(application)"
                >
                  <img
                    v-if="application.creator.avatarUrl"
                    :src="application.creator.avatarUrl"
                    :alt="application.creator.displayName"
                  />
                  <span v-else>{{ application.creator.displayName.slice(0, 1) }}</span>
                  <span class="company-applicant-row__creator">
                    <strong>{{ application.creator.displayName }}</strong>
                    <small>
                      {{ application.creator.categoryLabel || application.creator.category }}
                      <template v-if="application.creator.location"> · {{ application.creator.location }}</template>
                    </small>
                  </span>
                </button>
                <div class="company-applicant-card__statuses">
                  <span class="status-pill">{{ statusLabel(application.status) }}</span>
                  <span v-if="application.status === 'accepted'" class="status-pill company-applicant-card__hired">
                    {{ t('account.hired') }}
                  </span>
                </div>
                <button class="button button--outline company-applicant-row__view" type="button" @click="openApplicant(application)">
                  <Eye :size="15" aria-hidden="true" />
                  {{ t('account.viewApplication') }}
                </button>
              </article>
            </div>
          </section>
        </template>
        <StatusMessage v-else-if="campaignPage === 'detail'" variant="empty">
          {{ t('account.noCampaigns') }}
        </StatusMessage>
      </template>

      <dialog
        ref="applicantDialog"
        class="company-applicant-dialog"
        @close="selectedApplication = null"
        @click.self="closeApplicant"
      >
        <div v-if="selectedApplication" class="company-applicant-dialog__content">
          <header class="company-applicant-dialog__header">
            <div>
              <p class="eyebrow">{{ t('account.applicantDetails') }}</p>
              <h2>{{ selectedApplication.creator.displayName }}</h2>
            </div>
            <button class="company-applicant-dialog__close" type="button" :aria-label="t('account.closeApplicantDetails')" @click="closeApplicant">
              <X :size="19" aria-hidden="true" />
            </button>
          </header>
          <div class="company-applicant-dialog__profile">
            <img
              v-if="selectedApplication.creator.avatarUrl"
              :src="selectedApplication.creator.avatarUrl"
              :alt="selectedApplication.creator.displayName"
            />
            <div>
              <strong>{{ selectedApplication.creator.categoryLabel || selectedApplication.creator.category }}</strong>
              <span v-if="selectedApplication.creator.location">{{ selectedApplication.creator.location }}</span>
            </div>
          </div>
          <p v-if="selectedApplication.creator.bio" class="company-applicant-dialog__bio">
            {{ selectedApplication.creator.bio }}
          </p>
          <div class="company-applicant-dialog__message">
            <strong>{{ t('account.yourMessage') }}</strong>
            <p>{{ selectedApplication.message }}</p>
          </div>
          <div class="company-applicant-dialog__actions">
            <button
              v-if="selectedApplication.status === 'pending'"
              class="button button--dark"
              type="button"
              :disabled="!user.emailVerified || !user.approved"
              @click="shortlistApplication(selectedApplication)"
            >
              {{ t('account.accept') }}
            </button>
            <RouterLink
              v-if="['shortlisted', 'offered', 'accepted'].includes(selectedApplication.status)"
              class="button button--outline"
              :to="{
                name: localizedRouteName('messages', locale),
                query: {
                  campaign: selectedApplication.campaign.slug,
                  creatorId: selectedApplication.creator.id,
                  creatorSlug: selectedApplication.creator.slug,
                },
              }"
              @click="closeApplicant"
            >
              {{ t('campaignChat.messageApplicant') }}
            </RouterLink>
            <RouterLink
              class="button button--outline"
              :to="{ name: localizedRouteName('creator-profile', locale), params: { slug: selectedApplication.creator.slug } }"
              @click="closeApplicant"
            >
              {{ t('account.viewPublicProfile') }} ↗
            </RouterLink>
          </div>
          <div v-if="selectedApplication.status === 'shortlisted'" class="form-stack company-applicant-card__offer">
            <label class="form-field">
              <span>{{ t('account.offerAmount') }} ({{ selectedApplication.campaign.currency }})</span>
              <input
                v-model.number="offerForms[selectedApplication.id].amount"
                type="number"
                :min="selectedApplication.campaign.budgetMin"
                :max="selectedApplication.campaign.budgetMax"
                required
              />
            </label>
            <label class="form-field">
              <span>{{ t('account.offerMessage') }}</span>
              <textarea v-model.trim="offerForms[selectedApplication.id].message" required minlength="10" maxlength="1500"></textarea>
            </label>
            <button
              class="button button--dark"
              type="button"
              :disabled="!user.emailVerified || !user.approved || !offerForms[selectedApplication.id]?.message.trim()"
              @click="sendOffer(selectedApplication)"
            >
              {{ t('account.sendOffer') }}
            </button>
          </div>
          <div v-else-if="selectedApplication.offer" class="offer-summary">
            <strong>{{ formatMoney(selectedApplication.offer.amount, selectedApplication.campaign.currency) }}</strong>
            <p>{{ selectedApplication.offer.message }}</p>
          </div>
        </div>
      </dialog>
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
          <p v-if="inquiry.selectedPackages?.length" class="dashboard-card__subheading">
            <span v-for="(selection, index) in inquiry.selectedPackages" :key="index">
              {{ index ? ' · ' : '' }}{{ selection.type === 'service'
                ? t('creatorProfile.servicePackage')
                : selection.type === 'other'
                  ? t('creatorProfile.somethingElse')
                  : selection.title }}
            </span>
          </p>
          <p v-else-if="inquiry.packageTitle" class="dashboard-card__subheading">{{ inquiry.packageTitle }}</p>
          <p v-if="!inquiry.canChat">{{ inquiry.message }}</p>
          <p v-if="inquiry.proposedAmount || inquiry.listedPrice" class="inquiry-card__price">
            {{ t('account.proposedPrice') }}:
            {{ formatMoney(inquiry.proposedAmount ?? inquiry.listedPrice, inquiry.proposedAmount ? inquiry.currency : inquiry.listedPriceCurrency) }}
          </p>
          <div v-if="user.accountType === 'creator' && inquiry.status === 'pending'" class="button-row">
            <button class="button button--dark" type="button" :disabled="!user.emailVerified || !user.approved" @click="respondToInquiry(inquiry, 'accept')">{{ t('account.accept') }}</button>
            <button class="button button--outline" type="button" :disabled="!user.emailVerified || !user.approved" @click="respondToInquiry(inquiry, 'reject')">{{ t('account.reject') }}</button>
          </div>
          <RouterLink
            v-if="inquiry.canChat"
            class="button button--outline inquiry-chat-link"
            :to="{
              name: localizedRouteName('messages', locale),
              query: { inquiry: inquiry.id },
            }"
          >
            <MessageCircle :size="15" aria-hidden="true" />
            {{ t('account.openChat') }}
          </RouterLink>
        </article>
      </section>
      </div>
      </main>
    </div>
    <RequiredProfileModal
      v-if="profile && !user.profileComplete"
      :user="user"
      :profile="profile"
      :categories="categories"
      :country-options="countryOptions"
      :phone-country="profilePhoneCountry"
      :busy="busy"
      :error="error"
      @update:phone-country="profilePhoneCountry = $event"
      @save="saveProfile"
    />
  </section>
</template>
