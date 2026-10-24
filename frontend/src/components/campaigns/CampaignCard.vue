<script setup>
import CampaignCompensation from './CampaignCompensation.vue'
import PresentationDemoBadge from '../shared/PresentationDemoBadge.vue'
import CampaignHiringProgress from './CampaignHiringProgress.vue'
import CampaignApplicationRange from './CampaignApplicationRange.vue'
import '../../scss/components/campaigns/CampaignCard.scss'
import { computed, ref, useId, watch } from 'vue'
import { BadgeCheck, Bookmark, CalendarDays, MapPin } from '@lucide/vue'
import { useRoute, useRouter } from 'vue-router'
import RouterLink from '../shared/LocalizedLink.vue'
import { useI18n } from 'vue-i18n'
import { formatDate } from '../../lib/api'
import { currentUser } from '../../composables/useCurrentUser'
import { useCampaignBookmarks } from '../../composables/useCampaignBookmarks'
import { localizedRouteName } from '../../routePaths'
import ConfirmationModal from '../shared/ConfirmationModal.vue'
import CardImage from '../shared/CardImage.vue'
import { campaignPlace, CAMPAIGN_PLACEHOLDER } from '../../lib/marketplace'

const props = defineProps({
  campaign: { type: Object, required: true },
  imageSizes: { type: String, default: undefined },
})
const { t, locale } = useI18n()
const titleId = useId()
const route = useRoute()
const router = useRouter()
const { isCampaignBookmarked, loadCampaignBookmarks, toggleCampaignBookmark } = useCampaignBookmarks()
const bookmarkBusy = ref(false)
const bookmarkError = ref('')
const removeBookmarkConfirmationOpen = ref(false)
const bookmarked = computed(() => isCampaignBookmarked(props.campaign.slug))

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
      name: localizedRouteName('login', locale.value),
      query: {
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
    <RouterLink
      class="campaign-card__link"
      :to="{ name: 'campaign-detail', params: { slug: campaign.slug } }"
      :aria-labelledby="titleId"
    />
    <div class="campaign-card__cover">
      <CardImage
        class="campaign-card__cover-image"
        :image="campaign.coverImage"
        :sizes="imageSizes"
        :src="campaign.coverImageUrl || CAMPAIGN_PLACEHOLDER"
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
        @click.stop="handleBookmarkClick"
      >
        <Bookmark :size="19" stroke-width="1.8" aria-hidden="true" />
      </button>
      <div v-if="campaign.featured || campaign.isPresentationDemo" class="campaign-card__badges">
        <PresentationDemoBadge v-if="campaign.isPresentationDemo" overlay />
        <span v-if="campaign.featured" class="campaign-card__featured">{{ t('campaignCard.featured') }}</span>
      </div>
    </div>

    <div class="campaign-card__panel">
      <CampaignApplicationRange class="campaign-card__proposals" :range="campaign.applicationRange" />
      <div class="campaign-card__company">
        <span class="campaign-card__company-name">{{ campaign.company.name }}</span>
        <span
          v-if="campaign.company.verified"
          class="campaign-card__verified"
          role="img"
          :aria-label="t('campaignCard.verified')"
        ><BadgeCheck :size="16" fill="currentColor" stroke="#fffefa" :stroke-width="2" aria-hidden="true" /></span>
      </div>

      <h3 :id="titleId">{{ campaign.title }}</h3>
      <p class="campaign-card__summary">{{ campaign.summary }}</p>

      <div v-if="campaign.channels?.length" class="campaign-card__channels" :aria-label="campaign.channels.join(', ')">
        <span v-for="channel in campaign.channels" :key="channel">
          {{ channel }}
        </span>
      </div>

      <div class="campaign-card__details">
        <strong><CampaignCompensation :campaign="campaign" /></strong>
        <span class="campaign-card__location"><MapPin :size="14" aria-hidden="true" /><span>{{ campaignPlace(campaign, locale) }}</span></span>
      </div>
    </div>

    <footer class="campaign-card__footer">
      <span class="campaign-card__deadline" :aria-label="t('campaignCard.closes', { date: formatDate(campaign.closesAt) })">
        <CalendarDays :size="14" aria-hidden="true" />
        <time :datetime="campaign.closesAt">{{ formatDate(campaign.closesAt) }}</time>
      </span>
      <CampaignHiringProgress :campaign="campaign" compact />
    </footer>
    <p v-if="bookmarkError" class="campaign-card__bookmark-error" role="alert">{{ bookmarkError }}</p>
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
