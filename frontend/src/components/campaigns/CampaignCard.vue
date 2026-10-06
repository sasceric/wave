<script setup>
import { computed, ref, watch } from 'vue'
import { ArrowRight, Bookmark, Camera, CirclePlay, MapPin, Megaphone, Music2 } from '@lucide/vue'
import { useRoute, useRouter } from 'vue-router'
import RouterLink from '../shared/LocalizedLink.vue'
import { useI18n } from 'vue-i18n'
import { formatDate, formatMoney } from '../../lib/api'
import { currentUser } from '../../composables/useCurrentUser'
import { useCampaignBookmarks } from '../../composables/useCampaignBookmarks'
import { localizedRouteName } from '../../routePaths'
import ConfirmationModal from '../shared/ConfirmationModal.vue'
import CardImage from '../shared/CardImage.vue'

const props = defineProps({
  campaign: { type: Object, required: true },
  imageSizes: { type: String, default: undefined },
})
const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const { isCampaignBookmarked, loadCampaignBookmarks, toggleCampaignBookmark } = useCampaignBookmarks()
const bookmarkBusy = ref(false)
const bookmarkError = ref('')
const removeBookmarkConfirmationOpen = ref(false)
const bookmarked = computed(() => isCampaignBookmarked(props.campaign.slug))
const coverVariant = computed(() => {
  const category = String(props.campaign.category || '')
    .normalize('NFD')
    .replace(/\p{Diacritic}/gu, '')
    .toLowerCase()

  if (/beauty|ljepot|lepot/.test(category)) return 'beauty'
  if (/fashion|moda/.test(category)) return 'fashion'
  if (/food|hran|kulinar/.test(category)) return 'food'
  if (/travel|putovanj|potovan/.test(category)) return 'travel'
  if (/wellness|zdrav|dobrobit/.test(category)) return 'wellness'

  return 'lifestyle'
})

const platformIcons = {
  Instagram: Camera,
  TikTok: Music2,
  YouTube: CirclePlay,
}

function platformIcon(platform) {
  return platformIcons[platform] || Megaphone
}

watch(() => currentUser.value?.id, () => {
  if (currentUser.value?.accountType === 'creator') {
    loadCampaignBookmarks().catch((cause) => {
      bookmarkError.value = cause.message
    })
  }
}, { immediate: true })

async function handleBookmarkClick() {
  bookmarkError.value = ''
  if (!currentUser.value) {
    await router.push({
      name: localizedRouteName('account', locale.value),
      query: {
        mode: 'login',
        bookmark: props.campaign.slug,
        returnTo: route.fullPath,
      },
    })
    return
  }
  if (currentUser.value.accountType !== 'creator') {
    return
  }

  if (bookmarked.value) {
    removeBookmarkConfirmationOpen.value = true
    return
  }

  await updateBookmark()
}

async function updateBookmark() {
  bookmarkBusy.value = true
  try {
    await toggleCampaignBookmark(props.campaign)
    return true
  } catch (cause) {
    bookmarkError.value = cause.message
    return false
  } finally {
    bookmarkBusy.value = false
  }
}

async function confirmRemoveBookmark() {
  if (await updateBookmark()) {
    removeBookmarkConfirmationOpen.value = false
  }
}
</script>

<template>
  <article class="campaign-card">
    <div class="campaign-card__cover" :class="`campaign-card__cover--${coverVariant}`">
      <CardImage
        v-if="campaign.coverImage || campaign.coverImageUrl"
        class="campaign-card__cover-image"
        :image="campaign.coverImage"
        :sizes="imageSizes"
        :src="campaign.coverImageUrl"
        alt=""
      />
      <div class="campaign-card__cover-brand">
        <CardImage
          v-if="campaign.company.logoImage || campaign.company.logoUrl"
          :image="campaign.company.logoImage"
          :src="campaign.company.logoUrl"
          :alt="campaign.company.name"
          sizes="38px"
        />
        <span v-else>{{ campaign.company.name.slice(0, 1) }}</span>
      </div>
      <button
        v-if="!currentUser || currentUser.accountType === 'creator'"
        class="campaign-card__bookmark"
        :class="{ 'is-active': bookmarked }"
        type="button"
        :aria-label="t(bookmarked ? 'campaignCard.removeBookmark' : 'campaignCard.addBookmark')"
        :aria-pressed="bookmarked"
        :disabled="bookmarkBusy"
        @click="handleBookmarkClick"
      >
        <Bookmark :size="19" stroke-width="1.8" aria-hidden="true" />
      </button>
      <span v-if="campaign.featured" class="campaign-card__featured">
        {{ t('campaignCard.featured') }}
      </span>
    </div>

    <div class="campaign-card__panel">
      <RouterLink
        class="campaign-card__company"
        :to="{ name: 'company-profile', params: { slug: campaign.company.slug } }"
      >
        {{ campaign.company.name }}
        <span
          v-if="campaign.company.verified"
          class="campaign-card__verified"
          :aria-label="t('campaignCard.verified')"
        >✓</span>
      </RouterLink>

      <h3>{{ campaign.title }}</h3>
      <p class="campaign-card__summary">{{ campaign.summary }}</p>

      <div v-if="campaign.channels?.length" class="campaign-card__channels" :aria-label="campaign.channels.join(', ')">
        <span v-for="channel in campaign.channels" :key="channel">
          <component :is="platformIcon(channel)" :size="14" stroke-width="2" aria-hidden="true" />
          {{ channel }}
        </span>
      </div>

      <div class="campaign-card__details">
        <strong>{{ formatMoney(campaign.budgetMin, campaign.currency) }}–{{ formatMoney(campaign.budgetMax, campaign.currency) }}</strong>
        <span><MapPin :size="14" aria-hidden="true" />{{ campaign.location }}</span>
      </div>
    </div>

    <div class="campaign-card__footer">
      <span>{{ t('campaignCard.spotsCloses', { count: campaign.creatorCount, date: formatDate(campaign.closesAt) }) }}</span>
      <RouterLink
        :to="{ name: 'campaign-detail', params: { slug: campaign.slug } }"
        class="campaign-card__cta button button--dark"
      >
        {{ t('campaignCard.viewBrief') }}
        <ArrowRight :size="16" aria-hidden="true" />
      </RouterLink>
    </div>
    <p v-if="bookmarkError" class="campaign-card__bookmark-error" role="alert">
      {{ bookmarkError }}
    </p>
    <ConfirmationModal
      :open="removeBookmarkConfirmationOpen"
      :title="t('campaignCard.removeBookmarkTitle')"
      :message="t('campaignCard.removeBookmarkMessage')"
      :confirm-label="t('campaignCard.confirmRemoveBookmark')"
      :cancel-label="t('campaignCard.cancelRemoveBookmark')"
      :loading="bookmarkBusy"
      @update:open="removeBookmarkConfirmationOpen = $event"
      @confirm="confirmRemoveBookmark"
    />
  </article>
</template>
