<script setup>
import CreditActionNotice from '../components/account/CreditActionNotice.vue'
import { refreshCredits } from '../composables/useCredits'
import AccountPage from '../components/account/AccountPage.vue'
import CardGrid from '../components/shared/CardGrid.vue'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { parsePhoneNumberFromString } from 'libphonenumber-js/min'
import RouterLink from '../components/shared/LocalizedLink.vue'
import { I18nT, useI18n } from 'vue-i18n'
import { ArrowLeft, Eye, ExternalLink, FileText, Plus, Trash2, UserRound, X } from '@lucide/vue'
import AccountAccessPanel from '../components/account/AccountAccessPanel.vue'
import AccountSidebar from '../components/account/AccountSidebar.vue'
import AccountProfileSummary from '../components/account/AccountProfileSummary.vue'
import AccountProfileCard from '../components/account/AccountProfileCard.vue'
import AccountProfileDetails from '../components/account/AccountProfileDetails.vue'
import CreatorCampaignActivity from '../components/account/CreatorCampaignActivity.vue'
import CompanyCampaignDetail from '../components/account/CompanyCampaignDetail.vue'
import CompanyCampaignList from '../components/account/CompanyCampaignList.vue'
import RequiredProfileModal from '../components/account/RequiredProfileModal.vue'
import MultiSelect from '../components/shared/MultiSelect.vue'
import DatePicker from '../components/shared/DatePicker.vue'
import { dateOnly } from '../lib/datePicker'
import TagInput from '../components/shared/TagInput.vue'
import MediaUploadField from '../components/shared/MediaUploadField.vue'
import SingleSelect from '../components/shared/SingleSelect.vue'
import PhoneNumberField from '../components/shared/PhoneNumberField.vue'
import ProfileImageField from '../components/shared/ProfileImageField.vue'
import RichTextEditor from '../components/shared/RichTextEditor.vue'
import SearchableSelect from '../components/shared/SearchableSelect.vue'
import LoadingSkeleton from '../components/shared/LoadingSkeleton.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import CampaignCard from '../components/campaigns/CampaignCard.vue'
import { apiGet, apiRequest, formatMoney } from '../lib/api'
import { useMarketplaceCatalog } from '../composables/useMarketplaceCatalog'
import { currentUser, setCurrentUser } from '../composables/useCurrentUser'
import { useCampaignBookmarks } from '../composables/useCampaignBookmarks'
import { COMPANY_SOCIAL_PLATFORMS, CURRENCIES, SOCIAL_PLATFORMS } from '../lib/marketplace'
import { creatorTypeOptions } from '../lib/creatorTypes'
import { formatInternationalPhoneNumber } from '../lib/phoneNumbers'
import { localizedRouteName } from '../routePaths'
import countries from '../data/countries.json'

const { t, locale } = useI18n()
const typeOptions = computed(() => creatorTypeOptions(t))
const route = useRoute()
const router = useRouter()
const { categories, industries: catalogIndustries, error: catalogError } = useMarketplaceCatalog(locale, { includeIndustries: true })
const companyIndustryOptions = computed(() => {
  const options = catalogIndustries?.value || []
  return [...options, ...(profile.value?.industries || []).filter((value) => !options.some((option) => option.value === value)).map((value) => ({ value, label: profile.value.industryLabels?.[profile.value.industries.indexOf(value)] || value }))]
})
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
const dashboardLoading = ref(true)
const dashboardReady = ref(false)
const accountTab = ref(isAccountTab(route.query.tab) ? route.query.tab : 'about')
const accountSection = computed(() => route.meta.accountSection ?? 'profile')
const busy = ref(false)
const error = ref('')
const notice = ref('')
const resendingVerification = ref(false)
const visibilitySaving = ref(false)
const notificationsSaving = ref(false)
const profile = ref(null)
const editingProfile = ref(false)
let profileBeforeEdit = null
const profileImageField = ref(null)
const companyCoverField = ref(null)
const profilePhoneCountry = ref('BA')
const tags = ref([])
const campaigns = ref([])
const applications = ref([])
const offers = ref([])
const offersWithConversations = computed(() => offers.value.map((offer) => ({
  ...offer,
  conversationId: applications.value.find((application) => application.id === offer.applicationId)?.conversationId ?? null,
})))
const invitations = ref([])
const companyApplications = ref([])
const inquiries = ref([])
const editingCampaignId = ref(null)
const campaignForm = ref(emptyCampaign())
const offerForms = ref({})
const applicantDialog = ref(null)
const selectedApplication = ref(null)
let noticeTimeout = null
let dashboardRequestId = 0
const campaignPage = computed(() => route.meta.accountCampaignPage ?? 'list')
const selectedCampaign = computed(() => campaigns.value.find((campaign) => campaign.slug === route.params.slug) ?? null)
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
  dashboardRequestId += 1
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
    dashboardRequestId += 1
    user.value = null
    profile.value = null
    editingProfile.value = false
    profileBeforeEdit = null
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
    categories: ['Lifestyle'],
    channels: ['Instagram', 'TikTok'],
    deliverables: [''],
    budgetMin: 300,
    budgetMax: 800,
    currency: 'BAM',
    city: '',
    countryCode: '',
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

function startProfileEdit(tab) {
  if (isAccountTab(tab)) selectAccountTab(tab)
  if (editingProfile.value) return
  profileBeforeEdit = {
    profile: JSON.parse(JSON.stringify(profile.value)),
    tags: [...tags.value],
    phoneCountry: profilePhoneCountry.value,
  }
  error.value = ''
  notice.value = ''
  editingProfile.value = true
}

function cancelProfileEdit() {
  if (busy.value || !profileBeforeEdit) return

  profile.value = profileBeforeEdit.profile
  tags.value = profileBeforeEdit.tags
  profilePhoneCountry.value = profileBeforeEdit.phoneCountry
  profileBeforeEdit = null
  editingProfile.value = false
  error.value = ''
  notice.value = ''
}

async function handleAuthenticated(authenticatedUser) {
  document.activeElement?.blur()
  await loadDashboard(authenticatedUser)
  await nextTick()
  // Login replaces this view in place, so the login form's scroll position survives.
  window.scrollTo({ top: 0, left: 0, behavior: 'instant' })
}

function incompleteProfileTab() {
  if (user.value.accountType === 'creator'
    && (!profile.value.displayName.trim()
      || profile.value.categories.length === 0
      || !profile.value.city.trim()
      || !profile.value.countryCode
      || !profile.value.phone.trim())
  ) {
    return 'about'
  }
  if (user.value.accountType === 'company'
    && (!profile.value.name.trim()
      || !(profile.value.industries || []).length
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

async function loadDashboard(authenticatedUser = null) {
  const requestId = ++dashboardRequestId
  dashboardLoading.value = true
  if (!user.value) dashboardReady.value = false
  let pendingBookmarkRedirect = null
  try {
    const response = authenticatedUser ? { data: authenticatedUser } : await apiGet('/auth/me')
    if (requestId !== dashboardRequestId) return
    user.value = response.data
    setCurrentUser(response.data)
    profile.value = structuredClone(response.data.profile)
    if (response.data.profile) {
      profile.value = {
        ...profile.value,
        ...(response.data.accountType === 'company' ? { industries: profile.value.industries || [profile.value.industry].filter(Boolean) } : {}),
        ...(response.data.accountType === 'company' ? { socialLinks: profile.value.socialLinks || [] } : {}),
        ...(response.data.accountType === 'creator' ? { birthday: profile.value.birthday || '', creatorTypes: profile.value.creatorTypes || [] } : {}),
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
      if (requestId !== dashboardRequestId) return
    }
    if (user.value.accountType === 'creator') {
      if (isAccountTab(route.query.tab)) {
        accountTab.value = route.query.tab
      } else if (typeof route.query.tab === 'string' && LEGACY_ACCOUNT_TABS[route.query.tab]) {
        await router.replace({ name: localizedRouteName(LEGACY_ACCOUNT_TABS[route.query.tab], locale.value) })
        if (requestId !== dashboardRequestId) return
        accountTab.value = 'about'
      } else {
        accountTab.value = 'about'
        if (route.query.tab !== undefined) {
          const query = { ...route.query }
          delete query.tab
          void router.replace({ query })
        }
      }
      tags.value = [...(profile.value.tags || [])]
      if (canAccessMarketplace) {
        const [applicationResponse, offerResponse, invitationResponse] = await Promise.all([
          apiGet('/me/applications'),
          apiGet('/me/offers'),
          apiGet('/me/invitations'),
          loadCampaignBookmarks(),
        ])
        if (requestId !== dashboardRequestId) return
        applications.value = applicationResponse.data
        offers.value = offerResponse.data
        invitations.value = invitationResponse.data
      } else {
        applications.value = []
        offers.value = []
        invitations.value = []
      }
    } else {
      if (route.query.tab !== undefined) {
        const query = { ...route.query }
        delete query.tab
        void router.replace({ query })
      }
      if (canAccessMarketplace) {
        const campaignResponse = await apiGet('/me/campaigns')
        if (requestId !== dashboardRequestId) return
        campaigns.value = campaignResponse.data
        const applicationResponses = await Promise.all(
          campaigns.value.map((campaign) => apiGet(`/company/campaigns/${encodeURIComponent(campaign.slug)}/applications`)),
        )
        if (requestId !== dashboardRequestId) return
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
      } else {
        campaigns.value = []
        companyApplications.value = []
      }
    }
    if (canAccessMarketplace) {
      const inquiryResponse = await apiGet('/me/inquiries')
      if (requestId !== dashboardRequestId) return
      inquiries.value = inquiryResponse.data
    } else {
      inquiries.value = []
    }
  } catch (cause) {
    if (requestId !== dashboardRequestId) return
    if (cause.status === 401) {
      user.value = null
      profile.value = null
      inquiries.value = []
      setCurrentUser(null)
      return
    }
    error.value = cause.message
  } finally {
    if (requestId === dashboardRequestId) {
      dashboardLoading.value = false
      dashboardReady.value = true
      if (pendingBookmarkRedirect) {
        await router.replace(pendingBookmarkRedirect)
      }
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

async function updateNotificationPreference(enabled) {
  if (!user.value || notificationsSaving.value) return
  notificationsSaving.value = true
  error.value = ''
  try {
    const response = await apiRequest('/me/notifications/settings', { method: 'PUT', body: { enabled } })
    user.value = response.data
    setCurrentUser(response.data)
  } catch (cause) {
    error.value = cause.message
  } finally {
    notificationsSaving.value = false
  }
}

async function saveProfile() {
  if (busy.value) return

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

  const imageChanges = []
  let profileSaved = false
  const body = isCreator
    ? {
        displayName: profile.value.displayName,
        category: profile.value.categories[0] || profile.value.category,
        categories: profile.value.categories,
        creatorTypes: profile.value.creatorTypes,
        birthday: profile.value.birthday || null,
        phone: normalizedPhone,
        city: profile.value.city.trim(),
        countryCode: profile.value.countryCode,
        bio: profile.value.bio,
        tagline: profile.value.tagline,
        avatarMediaId: profile.value.avatarMediaId || null,
        avatarUrl: profile.value.avatarMediaId ? null : profile.value.avatarUrl || null,
        tags: [...tags.value],
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
        industries: profile.value.industries,
        coverMediaId: profile.value.coverMediaId || null,
        about: profile.value.about || '',
        city: profile.value.city?.trim() || null,
        countryCode: profile.value.countryCode || null,
        phone: normalizedPhone,
        socialLinks: (profile.value.socialLinks || []).map(({ platform, url }) => ({ platform, url })),
      }
  try {
    const fields = [
      { field: profileImageField.value, id: isCreator ? 'avatarMediaId' : 'logoMediaId', url: isCreator ? 'avatarUrl' : 'logoUrl' },
      ...(!isCreator ? [{ field: companyCoverField.value, id: 'coverMediaId', url: 'coverUrl' }] : []),
    ]
    for (const entry of fields) {
      const change = await entry.field?.prepareSave()
      if (change) imageChanges.push({ ...entry, change })
      body[entry.id] = change ? change.mediaId : profile.value[entry.id] || null
      if (entry.id !== 'coverMediaId') body[entry.url] = change ? change.url : profile.value[entry.id] ? null : profile.value[entry.url] || null
    }
    await apiRequest('/me/profile', { method: 'PUT', body })
    profileSaved = true
    for (const { id, url, change } of imageChanges) {
      profile.value[id] = change.mediaId
      profile.value[url] = change.url
      if (change.previousMediaId) {
        try {
          await apiRequest(`/media/${change.previousMediaId}`, { method: 'DELETE' })
        } catch (cause) {
          error.value = `${t('account.profileImageCleanupFailed')} ${cause.message}`
        }
      }
    }
    notice.value = t('account.profileSaved')
    await loadDashboard()
  } catch (cause) {
    if (!profileSaved) {
      for (const { field, change } of imageChanges) {
        if (!change.uploadedMediaId) continue
        try {
          await field?.rollback(change)
        } catch (cleanupCause) {
          error.value = `${cause.message} ${t('account.profileImageCleanupFailed')} ${cleanupCause.message}`
        }
      }
    }
    if (!error.value) {
      error.value = cause.message
    }
  } finally {
    if (profileSaved) {
      for (const { field } of imageChanges) field?.commit()
      editingProfile.value = false
      profileBeforeEdit = null
    }
    busy.value = false
  }
}

function addSocialProfile() {
  if (profile.value.socialProfiles.length < 5) {
    profile.value.socialProfiles.push({ platform: 'TikTok', handle: '', followers: 0 })
  }
}

function addCompanySocialLink() {
  profile.value.socialLinks ||= []
  if (profile.value.socialLinks.length < COMPANY_SOCIAL_PLATFORMS.length) {
    profile.value.socialLinks.push({ platform: COMPANY_SOCIAL_PLATFORMS.find((platform) => !profile.value.socialLinks.some((link) => link.platform === platform)) || 'Website', url: '' })
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
    categories: campaign.categories || [campaign.category],
    channels: [...campaign.channels],
    deliverables: [...campaign.deliverables],
    budgetMin: campaign.budgetMin,
    budgetMax: campaign.budgetMax,
    currency: campaign.currency || 'BAM',
    city: campaign.city || '',
    countryCode: campaign.countryCode || '',
    creatorCount: campaign.creatorCount,
    closesAt: campaign.closesAt.slice(0, 10),
    status: campaign.status,
  }
}

function cancelCampaignEditing() {
  setCampaignForm()
}

async function saveCampaign() {
  if (busy.value) return
  busy.value = true
  error.value = ''
  notice.value = ''
  const body = {
    title: campaignForm.value.title,
    summary: campaignForm.value.summary,
    description: campaignForm.value.description,
    coverMediaId: campaignForm.value.coverMediaId,
    categories: campaignForm.value.categories,
    channels: [...campaignForm.value.channels],
    deliverables: campaignForm.value.deliverables.map((item) => item.trim()),
    budgetMin: Number(campaignForm.value.budgetMin),
    budgetMax: Number(campaignForm.value.budgetMax),
    currency: campaignForm.value.currency,
    city: campaignForm.value.city,
    countryCode: campaignForm.value.countryCode,
    creatorCount: Number(campaignForm.value.creatorCount),
    closesAt: campaignForm.value.closesAt,
    status: campaignForm.value.status,
  }
  const path = editingCampaignId.value ? `/company/campaigns/${editingCampaignId.value}` : '/company/campaigns'
  try {
    const response = await apiRequest(path, { method: editingCampaignId.value ? 'PUT' : 'POST', body })
    const savedCampaign = response.data
    refreshCredits().catch(() => {})
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
    const fieldErrors = (cause.fields || [])
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

async function editListedCampaign(campaign) {
  await router.push({ name: localizedRouteName('account-campaign-detail', locale.value), params: { slug: campaign.slug } })
  await nextTick()
  setCampaignForm(campaign)
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
  if (busy.value) return
  busy.value = true
  try {
    await runAction(`/me/offers/${offer.id}/respond`, { decision })
  } finally {
    busy.value = false
  }
}

async function respondToInvitation(invitation, decision) {
  if (busy.value) return
  busy.value = true
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
  } finally {
    busy.value = false
  }
}

async function respondToInquiry(inquiry, decision) {
  if (busy.value) return
  busy.value = true
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
  } finally {
    busy.value = false
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
  <AccountPage v-if="dashboardLoading && !dashboardReady" class="account-page account-page--dashboard page-width">
    <AccountSidebar v-if="currentUser" :user="currentUser" />
    <LoadingSkeleton variant="account" :content="accountSection === 'campaigns' && campaignPage === 'create' ? 'profile' : accountSection" />
  </AccountPage>
  <AccountAccessPanel v-else-if="!user" @authenticated="handleAuthenticated" />

  <AccountPage v-else class="account-page account-page--dashboard page-width" :class="{ 'account-page--editing': editingProfile && accountSection === 'profile' }" :aria-busy="dashboardLoading">
    <header v-if="accountSection === 'profile'" class="account-welcome" :class="{ 'account-welcome--editing': editingProfile }">
      <div>
        <button v-if="editingProfile" class="account-welcome__back" type="button" :disabled="busy" @click="cancelProfileEdit"><ArrowLeft :size="18" aria-hidden="true" />{{ t('account.back') }}</button>
        <h1 class="account-welcome__title"><template v-if="editingProfile">{{ t('account.editProfile') }}</template><I18nT v-else keypath="account.welcomeBack" scope="global"><template #name><span>{{ (user.accountType === 'creator' ? profile?.displayName : profile?.name) || t('account.title') }}</span></template></I18nT></h1>
        <p>{{ editingProfile ? t('account.profileEditorIntro') : t('account.welcomeIntro') }}</p>
      </div>
      <RouterLink v-if="profile?.slug" class="account-welcome__public" :to="{ name: user.accountType === 'creator' ? 'creator-profile' : 'company-profile', params: { slug: profile.slug } }"><Eye :size="21" aria-hidden="true" />{{ t('account.viewPublicProfile') }}<ExternalLink :size="15" aria-hidden="true" /></RouterLink>
    </header>
    <AccountProfileSummary v-if="profile && !editingProfile && accountSection === 'profile'" :user="user" :profile="profile" :country-options="countryOptions" :visibility-saving="visibilitySaving" :notifications-saving="notificationsSaving" @notifications="updateNotificationPreference" @edit="startProfileEdit('about')" @select-tab="selectAccountTab" @visibility="updateAccountVisibility" />
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
      <AccountSidebar :user="user" :profile="profile" />
      <main class="account-content">
      <div class="account-grid">
      <form v-form-validation
        v-if="accountSection === 'profile'"
        class="profile-form profile-form--wide"
        @submit.prevent="saveProfile"
      >
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

        <AccountProfileDetails
          v-if="!editingProfile"
          :profile="profile"
          :account-type="user.accountType"
          :tab="accountTab"
          :country-options="countryOptions"
          :categories="categories"
          @edit="startProfileEdit"
        />
        <div v-if="editingProfile" class="profile-edit-layout">
        <template v-if="user.accountType === 'creator'">
          <section
            v-if="accountTab === 'about'"
            id="account-panel-about"
            class="profile-tab-panel"
            role="tabpanel"
            aria-labelledby="account-tab-about"
          >
            <AccountProfileCard :title="t('account.basicInformation')" :icon="UserRound">
            <div class="profile-edit-avatar">
              <ProfileImageField
                ref="profileImageField"
                compact
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
            <div class="form-grid profile-edit-basics">
              <label class="form-field form-field--wide">
                <span>{{ t('auth.name') }}</span>
                <input v-model.trim="profile.displayName" required minlength="2" maxlength="120" autocomplete="name" />
              </label>
              <MultiSelect required
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
              <MultiSelect
                v-model="profile.creatorTypes"
                :options="typeOptions"
                :label="t('creatorTypes.label')"
                :placeholder="t('creatorTypes.select')"
                :search-placeholder="t('creatorTypes.search')"
                :no-results-label="t('creatorTypes.empty')"
                :remove-label="t('account.remove')"
                :helper-text="t('creatorTypes.hint')"
              />

              <SearchableSelect required
                :model-value="profile.countryCode || ''"
                :options="countryOptions"
                :label="t('auth.country')"
                :placeholder="t('auth.selectCountry')"
                :search-placeholder="t('companyDirectory.countryPlaceholder')"
                :no-results-label="t('auth.noCountriesFound')"
                @update:model-value="updateProfileCountry"
              />
              <label class="form-field">
                <span>{{ t('auth.city') }}</span>
                <input v-model.trim="profile.city" required maxlength="70" autocomplete="address-level2" />
              </label>
              <DatePicker
                v-model="profile.birthday"
                :label="t('account.birthday')"
                :placeholder="t('datePicker.placeholder')"
                :helper-text="t('account.birthdayPrivate')"
                min="1900-01-01"
                :max="dateOnly()"
                :disabled="busy"
              />
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
            </AccountProfileCard>
            <AccountProfileCard :title="t('account.additionalInformation')" :icon="FileText">
            <div class="form-grid">
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
              <TagInput
                v-model="tags"
                class="form-field--wide"
                :label="t('account.tags')"
                :placeholder="t('account.tagsPlaceholder')"
                :remove-label="t('account.remove')"
                :limit-label="t('account.tagsLimit', { count: 10 })"
                :too-long-label="t('account.tagsTooLong', { count: 40 })"
                :max-tags="10"
                :max-length="40"
                :disabled="busy"
              />
            </div>
            </AccountProfileCard>
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
            <article v-for="(social, index) in profile.socialProfiles" :key="index" class="social-editor">
              <div class="social-editor__top">
                <strong>{{ social.platform }}</strong>
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
              <SingleSelect v-model="social.platform" :label="t('account.platform')" :options="SOCIAL_PLATFORMS.map(value => ({ value, label: value }))" required />
              <label class="form-field">
                <span>{{ t('account.handle') }}</span>
                <input v-model.trim="social.handle" required maxlength="120" />
              </label>
              <label class="form-field">
                <span>{{ t('account.followers') }}</span>
                <input v-model.number="social.followers" type="number" min="0" max="200000000" required />
              </label>
            </article>
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
                <SingleSelect v-model="item.type" :label="t('account.mediaType')" :options="[{ value: 'image', label: t('account.image') }, { value: 'video', label: t('account.video') }]" @update:model-value="changePortfolioType(item)" />
                <SingleSelect v-model="item.platform" :label="t('account.mediaPlatform')" :options="[{ value: 'All', label: t('account.allPlatforms') }, ...SOCIAL_PLATFORMS.map(value => ({ value, label: value }))]" />
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
              <SingleSelect v-model="packageItem.platform" :label="t('account.platform')" :options="SOCIAL_PLATFORMS.map(value => ({ value, label: value }))" />
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
              <SingleSelect v-model="packageItem.currency" :label="t('account.currency')" :options="CURRENCIES.map(value => ({ value, label: value }))" />
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
          <div class="company-profile-images">
            <ProfileImageField
              ref="profileImageField"
              folder="company-logo"
              :model-value="profile.logoMediaId"
              :preview-url="profile.logoUrl || ''"
              :alt="profile.name"
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
            <div><h3>{{ t('account.companyCover') }}</h3>
            <ProfileImageField
              class="company-cover-field"
              ref="companyCoverField"
              folder="company-cover"
              :model-value="profile.coverMediaId"
              :preview-url="profile.coverUrl || ''"
              :alt="profile.name"
              :add-label="t('account.addCompanyCover')"
              :change-label="t('account.changeCompanyCover')"
              :remove-label="t('account.removeCompanyCover')"
              :helper-text="t('account.profileImageSaveHint')"
              :remove-title="t('account.removeCompanyCoverTitle')"
              :remove-message="t('account.removeCompanyCoverMessage')"
              :confirm-label="t('account.remove')"
              :cancel-label="t('account.richTextCancel')"
              :disabled="busy || !user.approved"
            />
            </div>
          </div>
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
            <MultiSelect required
              v-model="profile.industries"
              :options="companyIndustryOptions"
              :label="t('auth.industry')"
              :placeholder="t('companyDirectory.selectIndustries')"
              :search-placeholder="t('companyDirectory.searchIndustries')"
              :no-results-label="t('companyDirectory.noIndustries')"
              :remove-label="t('account.remove')"
              :max-selections="20"
            />
            <SearchableSelect required
              class="form-field--wide"
              :model-value="profile.countryCode || ''"
              :options="countryOptions"
              :label="t('auth.country')"
              :placeholder="t('auth.selectCountry')"
              :search-placeholder="t('companyDirectory.countryPlaceholder')"
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
          <section class="company-social-editor">
            <div class="profile-editor__heading">
              <div>
                <h3>{{ t('account.companySocialLinks') }}</h3>
                <small>{{ t('account.companySocialLinksHint') }}</small>
              </div>
              <button
                class="profile-editor__add"
                type="button"
                :disabled="(profile.socialLinks || []).length >= COMPANY_SOCIAL_PLATFORMS.length"
                @click="addCompanySocialLink"
              >
                <Plus :size="16" aria-hidden="true" />
                {{ t('account.addSocialLink') }}
              </button>
            </div>
            <div v-if="!(profile.socialLinks || []).length" class="profile-editor__empty">{{ t('account.noSocialLinks') }}</div>
            <div v-for="(link, index) in profile.socialLinks" :key="index" class="social-editor company-social-editor__row">
              <SingleSelect v-model="link.platform" :label="t('account.platform')" :options="COMPANY_SOCIAL_PLATFORMS.map(value => ({ value, label: value }))" required />
              <label class="form-field form-field--wide">
                <span>{{ t('account.socialLinkUrl') }}</span>
                <input v-model.trim="link.url" type="url" required maxlength="500" placeholder="https://" />
              </label>
              <button
                class="profile-editor__remove"
                type="button"
                :aria-label="`${t('account.remove')}: ${link.platform}`"
                @click="profile.socialLinks.splice(index, 1)"
              >
                <Trash2 :size="15" aria-hidden="true" />
                <span>{{ t('account.remove') }}</span>
              </button>
            </div>
          </section>
        </section>
          <footer class="profile-form__footer">
            <button class="button button--outline" type="button" :disabled="busy" @click="cancelProfileEdit">{{ t('account.richTextCancel') }}</button>
            <button class="button button--dark" type="submit" :disabled="busy">{{ t('account.saveProfile') }}</button>
          </footer>
        </div>
      </form>

      <CreatorCampaignActivity
        v-if="accountSection === 'applications' && user.accountType === 'creator'"
        kind="applications"
        :items="applications"
        :can-respond="user.emailVerified && user.approved"
      />
      <CreatorCampaignActivity
        v-if="accountSection === 'invitations' && user.accountType === 'creator'"
        kind="invitations"
        :items="invitations"
        :can-respond="user.emailVerified && user.approved"
        :responding="busy || dashboardLoading"
        @respond="respondToInvitation"
      />
      <CreatorCampaignActivity
        v-if="accountSection === 'offers' && user.accountType === 'creator'"
        :items="offersWithConversations"
        :can-respond="user.emailVerified && user.approved"
        :responding="busy || dashboardLoading"
        @respond="respondToOffer"
      />

      <section
        v-if="accountSection === 'bookmarks' && user.accountType === 'creator'"
        class="form-card account-activity-panel account-bookmarks"
      >
        <h1>{{ t('account.bookmarks') }}</h1>
        <CardGrid v-if="bookmarks.length" kind="campaign" layout="bookmarks">
          <CampaignCard v-for="campaign in bookmarks" :key="campaign.id" :campaign="campaign" />
        </CardGrid>
        <StatusMessage v-else variant="empty">{{ t('account.noBookmarks') }}</StatusMessage>
      </section>

      <CompanyCampaignList
        v-if="user.accountType !== 'creator' && accountSection === 'campaigns' && campaignPage === 'list'"
        :campaigns="campaigns"
        @edit="editListedCampaign"
      />

      <template v-if="user.accountType !== 'creator' && accountSection === 'campaigns'">
        <RouterLink v-if="campaignPage === 'detail'" class="account-campaign-back account-activity-panel" :to="{ name: localizedRouteName('account-campaigns', locale) }">
          <ArrowLeft :size="18" aria-hidden="true" />{{ t('account.backToCampaigns') }}
        </RouterLink>
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
          <form v-form-validation class="form-stack" @submit.prevent="saveCampaign">
            <label class="form-field"><span>{{ t('account.campaignTitle') }}</span><input v-model.trim="campaignForm.title" required minlength="5" maxlength="160" /></label>
            <label class="form-field"><span>{{ t('account.summary') }}</span><input v-model.trim="campaignForm.summary" required minlength="10" maxlength="220" /></label>
            <div class="form-field"><span>{{ t('account.description') }}</span><RichTextEditor v-model="campaignForm.description" :label="t('account.description')" :placeholder="t('account.campaignDescriptionPlaceholder')" required :minlength="20" :maxlength="8000" /></div>
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
              <MultiSelect v-model="campaignForm.categories" :label="t('auth.category')" :options="categories" :placeholder="t('categories.all')" :search-placeholder="t('creatorDirectory.searchCategories')" :remove-label="t('account.remove')" required />
              <SearchableSelect
                v-model="campaignForm.countryCode"
                :label="t('auth.country')"
                :options="countryOptions"
                :placeholder="t('auth.selectCountry')"
                :search-placeholder="t('companyDirectory.countryPlaceholder')"
                :no-results-label="t('auth.noCountriesFound')"
                required
              />
              <label class="form-field"><span>{{ t('auth.city') }}</span><input v-model.trim="campaignForm.city" required minlength="2" maxlength="120" /></label>
            </div>
            <MultiSelect
              v-model="campaignForm.channels"
              :options="SOCIAL_PLATFORMS.map(value => ({ value, label: value }))"
              :label="t('account.channels')"
              :placeholder="t('account.selectChannels')"
              :search-placeholder="t('creatorDirectory.searchPlatforms')"
              :no-results-label="t('creatorDirectory.noPlatforms')"
              :remove-label="t('account.remove')"
              required
            />
            <AccountProfileCard :title="t('account.deliverables')" :icon="FileText">
              <div class="campaign-deliverables">
                <div v-for="(item, index) in campaignForm.deliverables" :key="index" class="form-field campaign-deliverables__item">
                  <div class="campaign-deliverables__heading">
                    <label :for="`campaign-deliverable-${index}`">{{ t('account.deliverableItem', { number: index + 1 }) }}</label>
                    <button
                      class="profile-editor__remove"
                      type="button"
                      :disabled="campaignForm.deliverables.length === 1"
                      :aria-label="`${t('account.remove')}: ${t('account.deliverableItem', { number: index + 1 })}`"
                      @click="campaignForm.deliverables.splice(index, 1)"
                    >
                      <Trash2 :size="15" aria-hidden="true" />
                      <span>{{ t('account.remove') }}</span>
                    </button>
                  </div>
                  <input :id="`campaign-deliverable-${index}`" v-model.trim="campaignForm.deliverables[index]" required maxlength="180" :placeholder="t('account.deliverablePlaceholder')" />
                </div>
                <button class="profile-editor__add" type="button" :disabled="campaignForm.deliverables.length >= 10" @click="campaignForm.deliverables.push('')"><Plus :size="16" aria-hidden="true" />{{ t('account.addDeliverable') }}</button>
              </div>
            </AccountProfileCard>
            <div class="form-grid">
              <label class="form-field"><span>{{ t('account.budgetMin') }}</span><input v-model.number="campaignForm.budgetMin" type="number" min="1" max="10000000" required /></label>
              <label class="form-field"><span>{{ t('account.budgetMax') }}</span><input v-model.number="campaignForm.budgetMax" type="number" min="1" max="10000000" required /></label>
              <SingleSelect v-model="campaignForm.currency" :label="t('account.currency')" :options="CURRENCIES.map(value => ({ value, label: value }))" />
              <label class="form-field"><span>{{ t('account.creatorCount') }}</span><input v-model.number="campaignForm.creatorCount" type="number" min="1" max="100" required /></label>
              <DatePicker v-model="campaignForm.closesAt" :label="t('account.closesAt')" required />
            </div>
            <SingleSelect v-if="editingCampaignId" v-model="campaignForm.status" :label="t('account.status')" :options="[{ value: 'open', label: t('account.open') }, { value: 'closed', label: t('account.closed') }, { value: 'finished', label: t('account.finished') }]" />
            <CreditActionNotice v-if="!editingCampaignId" action="campaign" />
            <div class="button-row">
              <button class="button button--dark" type="submit" :disabled="busy || !user.emailVerified || !user.approved">
                {{ editingCampaignId ? t('account.updateCampaign') : t('account.publish') }}
                <span aria-hidden="true">↗</span>
              </button>
            </div>
          </form>
        </section>

        <CompanyCampaignDetail
          v-else-if="campaignPage === 'detail' && selectedCampaign"
          :campaign="selectedCampaign"
          :applications="selectedCampaignApplications"
          @edit="setCampaignForm"
          @view-application="openApplicant"
        />
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
              <span v-if="selectedApplication.creator.city">{{ selectedApplication.creator.city }}</span>
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
          <form v-if="selectedApplication.status === 'shortlisted'" v-form-validation class="form-stack company-applicant-card__offer" @submit.prevent="sendOffer(selectedApplication)">
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
              type="submit"
              :disabled="!user.emailVerified || !user.approved"
            >
              {{ t('account.sendOffer') }}
            </button>
          </form>
          <div v-else-if="selectedApplication.offer" class="offer-summary">
            <strong>{{ formatMoney(selectedApplication.offer.amount, selectedApplication.campaign.currency) }}</strong>
            <p>{{ selectedApplication.offer.message }}</p>
          </div>
        </div>
      </dialog>
      <CreatorCampaignActivity
        v-if="accountSection === 'inquiries'"
        kind="inquiries"
        :items="inquiries"
        :can-respond="user.emailVerified && user.approved"
        :responding="busy || dashboardLoading"
        @respond="respondToInquiry"
      />
      </div>
      </main>
    </div>
    <RequiredProfileModal
      v-if="profile && !user.profileComplete"
      :user="user"
      :profile="profile"
      :categories="categories"
      :industry-options="companyIndustryOptions"
      :country-options="countryOptions"
      :phone-country="profilePhoneCountry"
      @update:profile="profile = $event"
      :busy="busy"
      :error="error"
      @update:phone-country="profilePhoneCountry = $event"
      @save="saveProfile"
    />
  </AccountPage>
</template>

<style lang="scss" src="../scss/views/AccountView.scss"></style>
