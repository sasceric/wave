<script setup>
import CampaignCompensation from '../components/campaigns/CampaignCompensation.vue'
import { accountLanding } from '../lib/accountAccess'
import { registrationReturn } from '../lib/registrationIntent'
import { companyTypeOptions } from '../lib/companyTypes'
import { mergeCampaignActivity } from '../lib/campaignActivity'
import CampaignCancellationAction from '../components/account/CampaignCancellationAction.vue'
import CreditActionNotice from '../components/account/CreditActionNotice.vue'
import { refreshCredits } from '../composables/useCredits'
import ConfirmationModal from '../components/shared/ConfirmationModal.vue'
import FormModal from '../components/shared/FormModal.vue'
import ReviewAction from '../components/reviews/ReviewAction.vue'
import RichTextContent from '../components/shared/RichTextContent.vue'
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
import CreatorProfileExtras from '../components/account/CreatorProfileExtras.vue'
import ProfileTabs from '../components/shared/ProfileTabs.vue'
import ProfileFaqFields from '../components/account/ProfileFaqFields.vue'
import { normalizeServices } from '../lib/agencyServices'
import AgencyProfileFields from '../components/companies/AgencyProfileFields.vue'
import CreatorStudioPanel from '../components/account/CreatorStudioPanel.vue'
import { normalizePresentation, presentationPayload } from '../lib/creatorPresentation'
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
import { COMPANY_SOCIAL_PLATFORMS, CURRENCIES, CAMPAIGN_PLATFORMS } from '../lib/marketplace'
import { creatorTypeOptions } from '../lib/creatorTypes'
import { formatInternationalPhoneNumber } from '../lib/phoneNumbers'
import { localizedPath, localizedRouteName } from '../routePaths'
import countries from '../data/countries.json'

const { t, tm, rt, locale } = useI18n()
const tagSuggestions = computed(() => tm('studio.tagSuggestions').map(message => rt(message)))
const companyTypes = computed(() => companyTypeOptions(t))
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
const PROFILE_TABS = ['services', 'about', 'social', 'portfolio', 'packages', 'faqs', 'kit']
const STUDIO_TABS = ['social', 'portfolio', 'packages', 'kit']
const LEGACY_ACCOUNT_TABS = {
  applications: 'account-applications',
  offers: 'account-applications',
  inquiries: 'account-inquiries',
  bookmarks: 'account-bookmarks',
  invitations: 'account-applications',
}
const user = ref(null)
const dashboardLoading = ref(true)
const dashboardReady = ref(false)
const accountTab = ref(isAccountTab(route.query.tab) ? route.query.tab : 'about')
const accountSection = computed(() => route.meta.accountSection ?? 'profile')
const busy = ref(false)
const error = ref('')
const notice = ref('')
const visibilitySaving = ref(false)
const notificationsSaving = ref(false)
const profile = ref(null)
const editingProfile = ref(false)
const agency = computed(() => profile.value?.companyType === 'agency')
const provider = computed(() => user.value?.accountType === 'creator' || user.value?.canOfferServices || user.value?.hasServiceHistory)
const profileTabs = computed(() => [
  { key: 'about', label: t(agency.value ? 'agency.about' : 'studio.tabAbout') },
  ...(agency.value ? [{ key: 'services', label: t('agency.services'), count: profile.value?.services?.length || 0 }] : [{ key: 'social', label: t('studio.tabNetworks'), count: profile.value?.socialProfiles?.length || 0 }]),
  { key: 'portfolio', label: t('studio.tabWorks'), count: `${profile.value?.portfolio?.length || 0}/4` },
  { key: 'packages', label: t('studio.tabPackages'), count: profile.value?.packages?.length || 0 },
  { key: 'faqs', label: t('studio.tabFaq'), count: profile.value?.faqs?.length || 0 },
  ...(!agency.value ? [{ key: 'kit', label: t('studio.tabPresentation') }] : []),
])
const studioPanelTab = computed(() => user.value?.accountType === 'creator' ? STUDIO_TABS.includes(accountTab.value) : agency.value && ['portfolio', 'packages'].includes(accountTab.value))
const agencyFields = ref(null)
let profileBeforeEdit = null
const profileImageField = ref(null)
const companyCoverField = ref(null)
const profilePhoneCountry = ref('BA')
const tags = ref([])
const creatorStudio = ref(null)
const campaigns = ref([])
const statusChange = ref(null)
const cancellationReason = ref('')
const statusError = ref('')
watch(statusChange, value => { if (value) { cancellationReason.value = ''; statusError.value = '' } })
const reviewId = computed(() => {
  const value = Number(route.query.review)
  return Number.isSafeInteger(value) && value > 0 ? value : null
})
async function closeReviewLink() {
  const query = { ...route.query }
  delete query.review
  await router.replace({ query })
}
async function changeCampaignStatus() {
  if (busy.value || !statusChange.value) return
  busy.value = true
  error.value = ''
  try {
    await apiRequest(`/company/campaigns/${statusChange.value.campaign.id}/status`, { method: 'PUT', body: { status: statusChange.value.status, ...(statusChange.value.status === 'cancelled' ? { reason: cancellationReason.value.trim() } : {}) } })
    statusChange.value = null
    await loadDashboard()
  } catch (cause) {
    if (statusChange.value?.status === 'cancelled') statusError.value = cause.message
    else { error.value = cause.message; statusChange.value = null }
  }
  finally { busy.value = false }
}
const applications = ref([])
const offers = ref([])
const invitations = ref([])
const campaignActivity = computed(() => mergeCampaignActivity(applications.value, invitations.value, offers.value))
const companyApplications = ref([])
const inquiries = ref([])
const editingCampaignId = ref(null)
const editingCampaign = computed(() => campaigns.value.find(campaign => campaign.id === editingCampaignId.value))
const campaignForm = ref(emptyCampaign())
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
  if (!authenticatedUser || authenticatedUser.id !== user.value?.id) {
    dashboardRequestId += 1
    applications.value = []
    offers.value = []
    invitations.value = []
    campaigns.value = []
    companyApplications.value = []
    inquiries.value = []
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
    compensationType: 'paid',
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
  const pendingReturn = registrationReturn(authenticatedUser)
  if (pendingReturn && !route.query.bookmark) {
    await router.replace(pendingReturn)
    return
  }
  if (authenticatedUser && !route.query.bookmark && !route.query.returnTo) await router.replace({ name: localizedRouteName(accountLanding(authenticatedUser), locale.value) })
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
  if (user.value.accountType !== 'creator' && !agency.value) {
    return ''
  }
  if (agency.value && profile.value.services.some(item => !item.title.trim() || item.deliverables.some(value => !value.trim()))) return 'services'
  if (profile.value.socialProfiles.some((social) => (
    !social.platform.trim()
    || !social.handle.trim()
    || (social.followers != null && social.followers !== '' && !Number.isInteger(Number(social.followers)))
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

async function loadDashboard(authenticatedUser = null, { sectionOnly = false } = {}) {
  const requestId = ++dashboardRequestId
  dashboardLoading.value = true
  if (!user.value || sectionOnly) dashboardReady.value = false
  if (sectionOnly) error.value = ''
  let pendingBookmarkRedirect = null
  try {
    const response = sectionOnly ? { data: user.value } : authenticatedUser ? { data: authenticatedUser } : await apiGet('/auth/session')
    if (requestId !== dashboardRequestId) return
    if (!response.data) {
      user.value = null
      profile.value = null
      inquiries.value = []
      setCurrentUser(null)
      return
    }
    if (!sectionOnly) {
      user.value = response.data
      setCurrentUser(response.data)
      const supportReturn = getSafeBookmarkReturnTo()
      const supportPaths = [localizedPath('account-support', locale.value)]
      if (response.data.isAdmin) supportPaths.push(localizedPath('admin-support', locale.value))
      if (!route.query.bookmark && supportReturn && supportPaths.includes(new URL(supportReturn, window.location.origin).pathname)) {
        // Reporting account problems must remain possible before profile completion.
        await router.replace(supportReturn)
        return
      }
      profile.value = structuredClone(response.data.profile)
      if (response.data.profile) {
        profile.value = {
          ...profile.value,
          ...(response.data.accountType === 'company' ? { companyType: profile.value.companyType || 'brand', industries: profile.value.industries || [profile.value.industry].filter(Boolean) } : {}),
          ...(response.data.accountType === 'company' ? { socialLinks: profile.value.socialLinks || [] } : {}),
          ...(response.data.accountType === 'creator' ? { birthday: profile.value.birthday || '', creatorTypes: profile.value.creatorTypes || [] } : {}),
          phone: response.data.phone || '',
          city: response.data.city || '',
          countryCode: response.data.countryCode || '',
        }
        normalizePresentation(profile.value)
        profile.value.faqs ||= []
        profile.value.services = normalizeServices(profile.value.services)
        profilePhoneCountry.value = parsePhoneNumberFromString(profile.value.phone)?.country
          || profile.value.countryCode
          || 'BA'
      }
    }
    if (route.query.edit === '1' && profile.value && !editingProfile.value) {
      startProfileEdit(isAccountTab(route.query.tab) ? route.query.tab : 'about')
      void router.replace({ query: { ...route.query, edit: undefined } })
    }
    const canAccessMarketplace = response.data.approved || response.data.isAdmin
    if (route.query.bookmark && canAccessMarketplace) {
      pendingBookmarkRedirect = await completePendingBookmark(response.data)
      if (requestId !== dashboardRequestId) return
    }
    if (provider.value) {
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
      if (!sectionOnly) tags.value = [...(profile.value.tags || [])]
      if (canAccessMarketplace && accountSection.value === 'applications') {
        const [applicationResponse, offerResponse, invitationResponse] = await Promise.all([
          apiGet('/me/applications'),
          apiGet('/me/offers'),
          apiGet('/me/invitations'),
        ])
        if (requestId !== dashboardRequestId) return
        applications.value = applicationResponse.data
        offers.value = offerResponse.data
        invitations.value = invitationResponse.data
      } else if (!canAccessMarketplace) {
        applications.value = []
        offers.value = []
        invitations.value = []
      }
      if (canAccessMarketplace && accountSection.value === 'bookmarks') await loadCampaignBookmarks()
    }
    if (user.value.accountType === 'company') {
      if (!agency.value && route.query.tab !== undefined) {
        const query = { ...route.query }
        delete query.tab
        void router.replace({ query })
      }
      if (canAccessMarketplace && accountSection.value === 'campaigns' && campaignPage.value !== 'create') {
        const campaignResponse = await apiGet('/me/campaigns')
        if (requestId !== dashboardRequestId) return
        campaigns.value = campaignResponse.data
        const applicationResponses = await Promise.all(
          (campaignPage.value === 'detail' ? campaigns.value.filter(campaign => campaign.slug === route.params.slug) : []).map((campaign) => apiGet(`/company/campaigns/${encodeURIComponent(campaign.slug)}/applications`)),
        )
        if (requestId !== dashboardRequestId) return
        companyApplications.value = applicationResponses.flatMap((response) => response.data)
        if (selectedApplication.value) {
          selectedApplication.value = companyApplications.value.find(
            (application) => application.id === selectedApplication.value.id,
          ) ?? selectedApplication.value
        }
      } else if (!canAccessMarketplace) {
        campaigns.value = []
        companyApplications.value = []
      }
    }
    if (canAccessMarketplace && accountSection.value === 'inquiries') {
      const inquiryResponse = await apiGet('/me/inquiries')
      if (requestId !== dashboardRequestId) return
      inquiries.value = inquiryResponse.data
    } else if (!canAccessMarketplace) {
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
  if (!account.canOfferServices && account.accountType !== 'creator') {
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

async function updateAccountVisibility(value) {
  if (!user.value || !user.value.approved || !user.value.emailVerified || user.value.profileComplete === false || visibilitySaving.value) {
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
    if (invalidTab === 'services') {
      await nextTick()
      agencyFields.value?.revealInvalidService()
    }
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
        ...presentationPayload(profile.value),
        faqs: profile.value.faqs.map(({ question, answer }) => ({ question, answer })),
      }
    : {
        name: profile.value.name,
        companyType: profile.value.companyType,
        industries: profile.value.industries,
        coverMediaId: profile.value.coverMediaId || null,
        about: profile.value.about || '',
        city: profile.value.city?.trim() || null,
        countryCode: profile.value.countryCode || null,
        phone: normalizedPhone,
        ...(agency.value ? { ...presentationPayload(profile.value), services: profile.value.services, faqs: profile.value.faqs } : {}),
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
    }
    notice.value = t('account.profileSaved')
    await loadDashboard()
  } catch (cause) {
    if (!profileSaved) {
      for (const { field, change } of imageChanges) {
        if (!change.uploadedMediaId) continue
        try {
          await field?.rollback(change)
        } catch {
          error.value = `${cause.message} ${t('account.profileImageRollbackFailed')}`
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

function addCompanySocialLink() {
  profile.value.socialLinks ||= []
  if (profile.value.socialLinks.length < COMPANY_SOCIAL_PLATFORMS.length) {
    profile.value.socialLinks.push({ platform: COMPANY_SOCIAL_PLATFORMS.find((platform) => !profile.value.socialLinks.some((link) => link.platform === platform)) || 'Website', url: '' })
  }
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
    compensationType: campaign.compensationType || 'paid',
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
    compensationType: campaignForm.value.compensationType,
    budgetMin: campaignForm.value.compensationType === 'voluntary' ? 0 : Number(campaignForm.value.budgetMin),
    budgetMax: campaignForm.value.compensationType === 'voluntary' ? 0 : Number(campaignForm.value.budgetMax),
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
  if (busy.value) return
  busy.value = true
  error.value = ''
  try {
    const response = await apiRequest(`/company/applications/${application.id}/shortlist`, { method: 'POST', body: {} })
    closeApplicant()
    await router.push({ name: localizedRouteName('messages', locale.value), query: { conversation: response.conversationId } })
  } catch (cause) {
    error.value = cause.message
  } finally { busy.value = false }
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

async function respondToCampaignActivity(item, decision) {
  if (item.offer?.status === 'pending') await respondToOffer(item.offer, decision)
  else if (item.source === 'invitation') await respondToInvitation(item.invitation, decision)
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
  if (!provider.value) {
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
    : !provider.value && (section === 'offers' || section === 'bookmarks' || section === 'applications')

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

watch(() => [accountSection.value, campaignPage.value, route.params.slug], () => {
  if (user.value) void loadDashboard(null, { sectionOnly: true })
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
    <AccountProfileSummary v-if="profile && !editingProfile && accountSection === 'profile'" :user="user" :profile="profile" :country-options="countryOptions" :visibility-saving="visibilitySaving" :notifications-saving="notificationsSaving" @url-changed="loadDashboard()" @notifications="updateNotificationPreference" @edit="startProfileEdit('about')" @select-tab="selectAccountTab" @visibility="updateAccountVisibility" />
    <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
    <StatusMessage v-else-if="notice">{{ notice }}</StatusMessage>
    <div v-if="user.emailVerified && !user.approved" class="verification-banner" role="status">
      <p>{{ user.profileComplete ? t('account.approvalPendingNotice') : t('account.profileIncomplete') }}</p>
    </div>
    <div v-else-if="user.emailVerified && user.hide_my_account" class="verification-banner" role="status">
      <p>{{ t('account.profileApprovedHiddenNotice') }}</p>
    </div>

    <div class="account-layout">
      <AccountSidebar :user="user" :profile="profile" />
      <main class="account-content">
      <div class="account-grid">
      <form v-form-validation
        v-if="accountSection === 'profile'"
        class="profile-form profile-form--wide"
        @submit.prevent="saveProfile"
        @wave-invalid="creatorStudio?.revealInvalidField($event.detail.field)"
      >
        <ProfileTabs v-if="user.accountType === 'creator' || agency" :model-value="accountTab" :items="profileTabs" id-prefix="account" :label="t('studio.profileSections')" @update:model-value="selectAccountTab" />
        <AgencyProfileFields v-if="agency && accountTab === 'services'" ref="agencyFields" v-model:profile="profile" :editing="editingProfile" @edit="startProfileEdit" />
        <ProfileFaqFields v-if="accountTab === 'faqs' && (agency || user.accountType === 'creator')" v-model="profile.faqs" :editing="editingProfile" @edit="startProfileEdit" />
        <CreatorStudioPanel ref="creatorStudio" v-if="studioPanelTab" v-model:profile="profile" :tab="accountTab" :editing="editingProfile" @edit="startProfileEdit" />
        <AccountProfileDetails
          v-else-if="!editingProfile && !((agency && accountTab === 'services') || accountTab === 'faqs')"
          :profile="profile"
          :account-type="user.accountType"
          :tab="accountTab"
          :country-options="countryOptions"
          :categories="categories"
          @edit="startProfileEdit"
        />
        <div v-if="editingProfile && !studioPanelTab && !((agency && accountTab === 'services') || accountTab === 'faqs')" class="profile-edit-layout">
        <template v-if="user.accountType === 'creator'">
          <section
            v-if="accountTab === 'about'"
            id="account-panel-about"
            class="profile-tab-panel"
            role="tabpanel"
            aria-labelledby="account-tab-about"
          >
            <AccountProfileCard :title="t('studio.introduction')" :icon="UserRound">
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
                :disabled="busy"
              />
            </div>
            <div class="form-grid profile-edit-basics">
              <label class="form-field form-field--wide">
                <span>{{ t('auth.name') }}</span>
                <input v-model.trim="profile.displayName" required minlength="1" maxlength="120" autocomplete="name" />
              </label>
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
            </div>
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
              <div class="form-field--wide profile-tag-suggestions">
                <button v-for="suggestion in tagSuggestions" :key="suggestion" type="button" :disabled="busy || tags.length >= 10 || tags.includes(suggestion)" @click="tags.push(suggestion)">{{ suggestion }}</button>
              </div>
            </div>
            <CreatorProfileExtras v-model:profile="profile" editing embedded />
            </AccountProfileCard>
            <AccountProfileCard :title="t('studio.privateDetails')" :icon="UserRound">
              <p class="creator-studio__hint creator-studio__privacy">{{ t('studio.privateDetailsHint') }}</p>
              <div class="form-grid">
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
              :disabled="busy"
            />
            <div><h3>{{ t('account.companyCover') }}</h3>
            <ProfileImageField
              class="company-cover-field"
              ref="companyCoverField"
              folder="company-cover"
              :company-type="profile.companyType"
              :model-value="profile.coverMediaId"
              :preview-url="profile.coverUrl || ''"
              :alt="profile.name"
              :add-label="t('account.addCompanyCover')"
              :change-label="t('account.changeCompanyCover')"
              :remove-label="t('account.removeCompanyCover')"
              :helper-text="`${t('account.coverUploadHint')} ${t('account.coverSaveHint')}`"
              :remove-title="t('account.removeCompanyCoverTitle')"
              :remove-message="t('account.removeCompanyCoverMessage')"
              :confirm-label="t('account.remove')"
              :cancel-label="t('account.richTextCancel')"
              :disabled="busy"
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
            <SingleSelect v-model="profile.companyType" :label="t('companyTypes.label')" :options="companyTypes" required />
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
        </div>
          <footer v-if="editingProfile" class="profile-form__footer">
            <button class="button button--outline" type="button" :disabled="busy" @click="cancelProfileEdit">{{ t('account.richTextCancel') }}</button>
            <button class="button button--dark" type="submit" :disabled="busy">{{ t('account.saveProfile') }}</button>
          </footer>
      </form>

      <CreatorCampaignActivity
        v-if="accountSection === 'applications' && provider"
        kind="activity"
        :initial-status="typeof route.query.status === 'string' ? route.query.status : 'all'"
        :initial-query="typeof route.query.q === 'string' ? route.query.q : ''"
        :items="campaignActivity"
        :can-respond="user.emailVerified && user.approved"
        :responding="busy || dashboardLoading"
        @cancelled="loadDashboard()"
        @respond="respondToCampaignActivity"
      />

      <section
        v-if="accountSection === 'bookmarks' && provider"
        class="form-card account-activity-panel account-bookmarks"
      >
        <h1>{{ t('account.bookmarks') }}</h1>
        <CardGrid v-if="bookmarks.length" kind="campaign" layout="bookmarks">
          <CampaignCard v-for="campaign in bookmarks" :key="campaign.id" :campaign="campaign" />
        </CardGrid>
        <StatusMessage v-else variant="empty">{{ t('account.noBookmarks') }}</StatusMessage>
      </section>

      <CompanyCampaignList
        :initial-status="typeof route.query.status === 'string' ? route.query.status : 'all'"
        v-if="user.accountType !== 'creator' && accountSection === 'campaigns' && campaignPage === 'list'"
        :campaigns="campaigns"
        @edit="editListedCampaign"
        @status="(campaign, status) => { statusChange = { campaign, status } }"
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
              <label class="form-field"><span>{{ t('auth.city') }}</span><input v-model.trim="campaignForm.city" required minlength="1" maxlength="120" /></label>
            </div>
            <MultiSelect
              v-model="campaignForm.channels"
              :options="CAMPAIGN_PLATFORMS.map(value => ({ value, label: value }))"
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
            <SingleSelect v-model="campaignForm.compensationType" :disabled="Boolean(editingCampaign?.appliedCreatorIds?.length || editingCampaign?.invitedCreatorIds?.length || ['finished', 'cancelled'].includes(editingCampaign?.status))" :label="t('compensation.type')" :options="[{ value: 'paid', label: t('compensation.paid') }, { value: 'voluntary', label: t('compensation.voluntaryLabel') }]" required />
            <StatusMessage v-if="campaignForm.compensationType === 'voluntary'">{{ t('compensation.voluntaryHint') }}</StatusMessage>
            <p v-if="editingCampaign?.appliedCreatorIds?.length || editingCampaign?.invitedCreatorIds?.length" class="muted">{{ t('compensation.locked') }}</p>
            <div class="form-grid">
              <label v-if="campaignForm.compensationType !== 'voluntary'" class="form-field"><span>{{ t('account.budgetMin') }}</span><input v-model.number="campaignForm.budgetMin" type="number" min="1" max="10000000" required /></label>
              <label v-if="campaignForm.compensationType !== 'voluntary'" class="form-field"><span>{{ t('account.budgetMax') }}</span><input v-model.number="campaignForm.budgetMax" type="number" min="1" max="10000000" required /></label>
              <SingleSelect v-if="campaignForm.compensationType !== 'voluntary'" :disabled="Boolean(editingCampaign?.hiredCount)" v-model="campaignForm.currency" :label="t('account.currency')" :options="CURRENCIES.map(value => ({ value, label: value }))" />
              <label class="form-field"><span>{{ t('account.creatorCount') }}</span><input v-model.number="campaignForm.creatorCount" type="number" min="1" max="100" required /></label>
              <DatePicker v-model="campaignForm.closesAt" :label="t('account.closesAt')" required />
            </div>
            <SingleSelect v-if="editingCampaignId" :disabled="['finished', 'cancelled'].includes(editingCampaign?.status)" v-model="campaignForm.status" :label="t('account.status')" :options="[{ value: 'open', label: t('account.open') }, { value: 'closed', label: t('account.closed') }, { value: 'finished', label: t('account.finished') }, ...(editingCampaign?.status === 'cancelled' ? [{ value: 'cancelled', label: t('account.cancelled') }] : [])]" />
            <StatusMessage v-if="editingCampaignId && campaignForm.status === 'finished'" variant="info">{{ t('reviews.finishHint') }}</StatusMessage>
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
        class="company-applicant-dialog wave-modal"
        @close="selectedApplication = null"
        @click.self="closeApplicant"
       aria-modal="true">
        <div v-if="selectedApplication" class="company-applicant-dialog__content wave-modal__content">
          <header class="company-applicant-dialog__header">
            <div>
              <p class="eyebrow">{{ t(selectedApplication.source === 'invitation' ? 'hiring.invitationDetails' : 'account.applicantDetails') }}</p>
              <h2 class="wave-modal__title">{{ selectedApplication.creator.displayName }}<span v-if="selectedApplication.creator.isAgency" class="status-badge">{{ t('companyTypes.options.agency') }}</span></h2>
            </div>
            <button class="company-applicant-dialog__close wave-modal__close" type="button" :aria-label="t('account.closeApplicantDetails')" @click="closeApplicant">
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
          <RichTextContent v-if="selectedApplication.creator.bio" class="company-applicant-dialog__bio" :html="selectedApplication.creator.bio" />
          <div class="company-applicant-dialog__message">
            <strong>{{ t(selectedApplication.source === 'invitation' ? 'hiring.invitationMessage' : 'account.yourMessage') }}</strong>
            <p>{{ selectedApplication.message }}</p>
          </div>
          <section v-if="selectedApplication.creator.isAgency && Object.keys(selectedApplication.proposal || {}).length" class="company-profile__card">
            <h3>{{ t('agency.proposal') }}</h3>
            <p v-if="selectedApplication.proposal.amount"><strong>{{ formatMoney(selectedApplication.proposal.amount, selectedApplication.campaign.currency) }}</strong></p>
            <p v-for="key in ['scope', 'timeline', 'team']" :key="key"><strong v-if="selectedApplication.proposal[key]">{{ t(`agency.${key}`) }}: </strong>{{ selectedApplication.proposal[key] }}</p>
          </section>
          <StatusMessage v-if="selectedApplication.readOnly" variant="info">{{ t(selectedApplication.blocked ? 'safety.blockedHistory' : 'campaignChat.deletedAccountNotice') }}</StatusMessage>
          <div class="company-applicant-dialog__actions">
            <CampaignCancellationAction v-if="selectedApplication.offer?.status === 'accepted' && selectedApplication.campaign.status !== 'finished' && !selectedApplication.readOnly" :offer-id="selectedApplication.offer.id" @cancelled="closeApplicant(); loadDashboard()" />
            <button
              v-if="selectedApplication.status === 'pending' && !selectedApplication.readOnly && selectedApplication.campaign.status === 'open'"
              class="button button--dark"
              type="button"
              :disabled="busy || !user.emailVerified || !user.approved"
              @click="shortlistApplication(selectedApplication)"
            >
              {{ t('hiring.acceptApplication') }}
            </button>
            <RouterLink
              v-if="!selectedApplication.readOnly && (['shortlisted', 'offer_declined', 'offered', 'accepted'].includes(selectedApplication.status) || selectedApplication.status === 'invited' && selectedApplication.conversationId)"
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
              v-if="!selectedApplication.creator.deleted"
              class="button button--outline"
              :to="{ name: localizedRouteName(selectedApplication.creator.profileRoute || 'creator-profile', locale), params: { slug: selectedApplication.creator.slug } }"
              @click="closeApplicant"
            >
              {{ t('account.viewPublicProfile') }} ↗
            </RouterLink>
          </div>
          <p v-if="selectedApplication.status === 'pending'" class="muted">{{ t('hiring.acceptApplicationHint') }}</p>
          <div v-if="selectedApplication.offer" class="offer-summary">
            <strong><CampaignCompensation :campaign="selectedApplication.campaign" :amount="selectedApplication.offer.amount" /></strong>
            <p v-if="selectedApplication.offer.dueDate">{{ t('hiring.deliveryDue', { date: formatDate(selectedApplication.offer.dueDate) }) }}</p>
            <p>{{ selectedApplication.offer.message }}</p>
          </div>
        </div>
      </dialog>
      <CreatorCampaignActivity
        @cancelled="loadDashboard()"
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
    <ReviewAction v-if="reviewId && user.approved && user.emailVerified" :key="reviewId" :application-id="reviewId" auto-open hide-trigger @closed="closeReviewLink" />
    <ConfirmationModal :open="Boolean(statusChange) && statusChange.status !== 'cancelled'" :title="t(statusChange?.status === 'cancelled' ? 'hiring.cancelCampaign' : statusChange?.status === 'finished' ? 'reviews.finishCampaign' : 'reviews.closeCampaign')" :message="t(statusChange?.status === 'cancelled' ? 'hiring.cancelCampaignHint' : statusChange?.status === 'finished' ? 'reviews.finishHint' : 'reviews.closeCampaignHint')" :confirm-label="t(statusChange?.status === 'cancelled' ? 'hiring.cancelCampaign' : statusChange?.status === 'finished' ? 'reviews.finishCampaign' : 'reviews.closeCampaign')" :cancel-label="t('credits.cancel')" :loading="busy" @update:open="!$event && (statusChange = null)" @confirm="changeCampaignStatus" />
    <FormModal :open="statusChange?.status === 'cancelled'" :title="t('hiring.cancelCampaign')" :description="t('hiring.cancelCampaignHint')" :busy="busy" @update:open="!$event && (statusChange = null)" @submit="changeCampaignStatus">
      <label class="form-field"><span>{{ t('hiring.cancellationReason') }}</span><textarea v-model.trim="cancellationReason" required minlength="10" maxlength="1000" :placeholder="t('hiring.cancellationReasonHint')" /><small>{{ t('hiring.cancellationReasonHint') }}</small></label>
      <StatusMessage v-if="statusError" variant="error">{{ statusError }}</StatusMessage>
      <template #actions="{ close }"><button type="button" class="button button--outline" :disabled="busy" @click="close">{{ t('safety.cancel') }}</button><button type="submit" class="button button--dark" :disabled="busy">{{ t('hiring.cancelCampaign') }}</button></template>
    </FormModal>
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
