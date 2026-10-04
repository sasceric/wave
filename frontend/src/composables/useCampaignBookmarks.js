import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { currentUser } from './useCurrentUser'
import { apiGet, apiRequest } from '../lib/api'

const bookmarks = ref([])
let loadedForUserId = null
let inFlight = null

watch(() => currentUser.value?.id, (userId, previousUserId) => {
  if (userId === previousUserId) {
    return
  }

  bookmarks.value = []
  loadedForUserId = null
  inFlight = null
})

export function useCampaignBookmarks() {
  const { t } = useI18n()

  async function loadCampaignBookmarks(force = false) {
    const user = currentUser.value
    if (!user || user.accountType !== 'creator') {
      bookmarks.value = []
      loadedForUserId = null

      return []
    }
    if (!force && loadedForUserId === user.id) {
      return bookmarks.value
    }
    if (inFlight?.userId === user.id) {
      return inFlight.promise
    }

    const userId = user.id
    const promise = apiGet('/me/bookmarks').then(({ data }) => {
      if (currentUser.value?.id === userId) {
        bookmarks.value = data
        loadedForUserId = userId
      }

      return data
    })
    inFlight = { userId, promise }

    try {
      return await promise
    } finally {
      if (inFlight?.promise === promise) {
        inFlight = null
      }
    }
  }

  function isCampaignBookmarked(slug) {
    return bookmarks.value.some((campaign) => campaign.slug === slug)
  }

  async function toggleCampaignBookmark(campaign) {
    const user = currentUser.value
    if (!user || user.accountType !== 'creator') {
      throw new Error('Only creator accounts can bookmark campaigns.')
    }

    await loadCampaignBookmarks()
    if (currentUser.value?.id !== user.id) {
      throw new Error(t('account.bookmarkSessionChanged'))
    }

    const wasBookmarked = isCampaignBookmarked(campaign.slug)
    const method = wasBookmarked ? 'DELETE' : 'POST'
    const response = await apiRequest(`/campaigns/${encodeURIComponent(campaign.slug)}/bookmark`, {
      method,
      ...(method === 'POST' ? { body: {} } : {}),
    })

    if (currentUser.value?.id !== user.id) {
      return !wasBookmarked
    }

    if (wasBookmarked) {
      bookmarks.value = bookmarks.value.filter((item) => item.slug !== campaign.slug)
    } else {
      bookmarks.value = [response.data, ...bookmarks.value.filter((item) => item.slug !== campaign.slug)]
    }

    return !wasBookmarked
  }

  return { bookmarks, isCampaignBookmarked, loadCampaignBookmarks, toggleCampaignBookmark }
}
