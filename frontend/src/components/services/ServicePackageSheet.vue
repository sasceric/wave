<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ArrowRight, MapPin, X } from '@lucide/vue'
import CardImage from '../shared/CardImage.vue'
import LocalizedLink from '../shared/LocalizedLink.vue'
import SkeletonBlock from '../shared/SkeletonBlock.vue'
import StatusMessage from '../shared/StatusMessage.vue'
import CreatorPackageFacts from '../creators/CreatorPackageFacts.vue'
import RatingSummary from '../reviews/RatingSummary.vue'
import { currentUser } from '../../composables/useCurrentUser'
import { apiGet, formatMoney } from '../../lib/api'
import { sendCreatorInquiry } from '../../lib/creatorInquiry'
import { trackEvent } from '../../lib/privacyMetrics'
import { CREATOR_PLACEHOLDER } from '../../lib/marketplace'
import { localizedPath } from '../../routePaths'

// Package details and ordering without leaving the list: a right-hand panel on desktop, a bottom sheet on phones.
const props = defineProps({
  item: { type: Object, default: null },
  // 'services' or 'homepage': stored with the request and sent with analytics events.
  source: { type: String, default: 'services' },
})
const emit = defineEmits(['close'])
const { t, locale } = useI18n()
const dialog = ref(null)
const creator = ref(null)
const loading = ref(false)
const loadError = ref('')
const message = ref('')
const busy = ref(false)
const sendError = ref('')
const sent = ref(false)
let request = 0

const fullPackage = computed(() => creator.value?.packages?.find(({ id }) => id === props.item?.id) || null)
const details = computed(() => ({ ...(props.item || {}), ...(fullPackage.value || {}) }))
const negotiable = computed(() => props.item?.priceMode === 'agreement' || props.item?.price === null)
const portfolio = computed(() => (creator.value?.portfolio || []).map(work => work.coverUrl).filter(Boolean).slice(0, 3))
const viewer = computed(() => currentUser.value)
const profileLink = computed(() => ({ name: 'creator-profile', params: { slug: props.item?.creator.slug } }))
const registerCompany = computed(() => ({ name: 'register', query: { accountType: 'company', returnTo: localizedPath('services', locale.value) } }))

function priceLabel(item) {
  if (!item || negotiable.value) return t('services.agreement')
  const amount = formatMoney(item.price, item.currency || 'BAM')
  return item.priceMode === 'from' ? t('services.from', { price: amount }) : amount
}

async function loadCreator(slug) {
  const id = ++request
  loading.value = true
  loadError.value = ''
  creator.value = null
  try {
    const response = await apiGet(`/creators/${encodeURIComponent(slug)}`)
    if (id === request) creator.value = response.data
  } catch (cause) {
    if (id === request) loadError.value = cause.message
  } finally {
    if (id === request) loading.value = false
  }
}

watch(() => props.item, async (item) => {
  if (!item) {
    if (dialog.value?.open) dialog.value.close()
    return
  }
  message.value = ''
  sendError.value = ''
  sent.value = false
  loadCreator(item.creator.slug)
  trackEvent('service_panel_open', eventParams(item))
  await nextTick()
  if (!dialog.value?.open) dialog.value?.showModal()
})

function eventParams(item) {
  return { service_type: item.serviceType, price_mode: item.priceMode, source: props.source }
}

function close() {
  if (busy.value) return
  if (dialog.value?.open) dialog.value.close()
}

function onCancel(event) {
  if (busy.value) event.preventDefault()
}

async function submit() {
  const text = message.value.trim()
  if (Array.from(text).length < 10) {
    sendError.value = t('creatorProfile.requestMessageTooShort')
    return
  }
  busy.value = true
  sendError.value = ''
  try {
    await sendCreatorInquiry(props.item.creator.slug, { packageIds: [props.item.id], message: text, source: props.source })
    sent.value = true
    trackEvent('service_order_sent', eventParams(props.item))
  } catch (cause) {
    sendError.value = cause.message
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <dialog ref="dialog" class="service-sheet" aria-labelledby="service-sheet-title" @click.self="close" @cancel="onCancel" @close="emit('close')">
    <div v-if="item" class="service-sheet__panel">
      <div class="service-sheet__handle" aria-hidden="true"></div>
      <header class="service-sheet__head">
        <span class="service-sheet__type">{{ t(`services.types.${item.serviceType}`) }}</span>
        <button class="service-sheet__close" type="button" :aria-label="t('services.sheet.close')" :disabled="busy" @click="close"><X :size="20" aria-hidden="true" /></button>
      </header>

      <div class="service-sheet__body">
        <h2 id="service-sheet-title">{{ item.title }}</h2>
        <strong :class="negotiable ? 'service-sheet__deal' : 'service-sheet__price'">{{ priceLabel(item) }}</strong>

        <div class="service-sheet__creator">
          <LocalizedLink class="service-sheet__avatar-link" :to="profileLink" tabindex="-1" aria-hidden="true">
            <CardImage class="service-sheet__avatar" :image="item.creator.avatarImage" sizes="48px" :src="item.creator.avatarUrl || CREATOR_PLACEHOLDER" alt="" />
          </LocalizedLink>
          <div>
            <LocalizedLink class="service-sheet__name" :to="profileLink">{{ item.creator.displayName }}</LocalizedLink>
            <small v-if="item.creator.city"><MapPin :size="12" aria-hidden="true" /> {{ item.creator.city }}</small>
            <SkeletonBlock v-if="loading" width="64px" />
            <RatingSummary v-else-if="creator?.rating" :rating="creator.rating" compact />
          </div>
          <LocalizedLink class="service-sheet__profile" :to="profileLink">{{ t('services.sheet.viewProfile') }} <ArrowRight :size="14" aria-hidden="true" /></LocalizedLink>
        </div>

        <div v-if="loading" class="service-sheet__loading" role="status" aria-busy="true">
          <span class="sr-only">{{ t('app.loading') }}</span>
          <SkeletonBlock />
          <SkeletonBlock width="92%" />
          <SkeletonBlock width="70%" />
          <div class="service-sheet__loading-facts">
            <SkeletonBlock v-for="index in 4" :key="index" :width="`${[62, 48, 55, 40][index - 1]}%`" />
          </div>
          <SkeletonBlock width="110px" />
          <div class="service-sheet__loading-portfolio"><SkeletonBlock v-for="index in 3" :key="index" shape="media" /></div>
        </div>
        <StatusMessage v-else-if="loadError" variant="error">{{ loadError }}</StatusMessage>
        <template v-else-if="fullPackage">
          <p v-if="details.description" class="service-sheet__description">{{ details.description }}</p>
          <CreatorPackageFacts :item="details" />
        </template>

        <section v-if="portfolio.length" class="service-sheet__portfolio" :aria-label="t('services.sheet.portfolio')">
          <h3>{{ t('services.sheet.portfolio') }}</h3>
          <div><CardImage v-for="src in portfolio" :key="src" class="service-sheet__work" :src="src" sizes="140px" alt="" /></div>
        </section>
      </div>

      <!-- Ordering stays pinned under the scrolling details, on desktop and phones. -->
      <footer class="service-sheet__order">
        <h3>{{ negotiable ? t('services.sheet.offerTitle') : t('services.sheet.orderTitle') }}</h3>
        <template v-if="sent">
          <StatusMessage>{{ t('creatorProfile.requestSent') }}</StatusMessage>
          <LocalizedLink class="button button--outline" :to="{ name: 'account-inquiries' }">{{ t('services.sheet.viewInquiries') }}</LocalizedLink>
        </template>
        <template v-else-if="!viewer">
          <p>{{ t('creatorProfile.companyOnly') }}</p>
          <div class="service-sheet__actions">
            <LocalizedLink class="button button--dark" :to="registerCompany">{{ t('services.sheet.registerCompany') }}</LocalizedLink>
            <LocalizedLink class="button button--outline" :to="{ name: 'login' }">{{ t('services.sheet.login') }}</LocalizedLink>
          </div>
        </template>
        <StatusMessage v-else-if="viewer.accountType !== 'company'" variant="empty">{{ t('creatorProfile.companyOnly') }}</StatusMessage>
        <StatusMessage v-else-if="!viewer.approved" variant="error">{{ t('creatorProfile.accountPendingApproval') }}</StatusMessage>
        <StatusMessage v-else-if="!viewer.emailVerified" variant="error">{{ t('creatorProfile.verifyEmail') }}</StatusMessage>
        <form v-else class="service-sheet__form" @submit.prevent="submit">
          <label class="form-field">
            <span>{{ t('services.sheet.messageLabel') }}</span>
            <textarea v-model="message" required minlength="10" maxlength="2000" :placeholder="t('services.sheet.messagePlaceholder')"></textarea>
          </label>
          <StatusMessage v-if="sendError" variant="error">{{ sendError }}</StatusMessage>
          <button class="button button--dark" type="submit" :disabled="busy">
            {{ busy ? t('campaignChat.sending') : negotiable ? t('services.sendOffer') : t('services.sheet.submitOrder') }}
            <ArrowRight :size="16" aria-hidden="true" />
          </button>
        </form>
      </footer>
    </div>
  </dialog>
</template>

<style scoped lang="scss" src="../../scss/components/services/ServicePackageSheet.scss"></style>
