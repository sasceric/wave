<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { Star } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import RatingSummary from './RatingSummary.vue'
import DirectoryPagination from '../shared/DirectoryPagination.vue'
import StatusMessage from '../shared/StatusMessage.vue'
import { apiGet, formatDate } from '../../lib/api'
const props = defineProps({ kind: { type: String, required: true }, slug: { type: String, required: true } })
const { t, locale } = useI18n()
const rows = ref([])
const summary = ref({})
const page = ref(1)
const total = ref(0)
const heading = ref(null)
const loading = ref(false)
const error = ref('')
const initials = name => (name || 'Wave').split(/\s+/).slice(0, 2).map(word => word[0]).join('').toLocaleUpperCase(locale.value)
const showing = computed(() => t('reviews.showing', { from: total.value ? (page.value - 1) * 10 + 1 : 0, to: Math.min(page.value * 10, total.value), total: total.value }))
let generation = 0
async function load() {
  const current = ++generation
  loading.value = true
  error.value = ''
  rows.value = []
  try {
    const result = await apiGet(`/${props.kind}/${encodeURIComponent(props.slug)}/reviews?page=${page.value}`)
    if (current !== generation) return
    rows.value = result.data
    summary.value = result.summary
    total.value = result.meta.total ?? result.summary.reviewCount ?? 0
    page.value = result.meta.page ?? page.value
  } catch (cause) { if (current === generation) error.value = cause.message }
  finally { if (current === generation) loading.value = false }
}
async function changePage(value) {
  page.value = value
  await load()
  await nextTick()
  heading.value?.focus({ preventScroll: true })
  heading.value?.scrollIntoView({ block: 'start', behavior: 'smooth' })
}
watch(() => [props.kind, props.slug, locale.value], () => { page.value = 1; load() }, { immediate: true })
onBeforeUnmount(() => { ++generation })
</script>
<template>
  <section class="profile-reviews" :aria-label="t('reviews.title')" :aria-busy="loading">
    <header><div><h2 ref="heading" tabindex="-1">{{ t('reviews.title') }}</h2><p>{{ t('reviews.ratingHint', { count: summary.reviewCount || 0 }) }}</p></div><RatingSummary :rating="summary" /></header>
    <StatusMessage v-if="error" variant="error">{{ error }} <button type="button" @click="load">{{ t('reviews.retry') }}</button></StatusMessage>
    <p v-else-if="loading" role="status">{{ t('reviews.loading') }}</p>
    <p v-else-if="!rows.length">{{ t('reviews.empty') }}</p>
    <div v-else class="profile-reviews__list">
      <article v-for="review in rows" :key="review.id">
        <div class="profile-reviews__identity"><span class="profile-reviews__avatar" aria-hidden="true">{{ initials(review.authorName) }}</span><div><strong>{{ review.authorName }}</strong><small>{{ review.campaignTitle }}</small></div><time :datetime="review.createdAt">{{ formatDate(review.createdAt) }}</time></div>
        <div class="profile-reviews__stars" :aria-label="t('reviews.stars', { count: review.rating })"><Star v-for="star in 5" :key="star" :size="14" :fill="star <= review.rating ? 'currentColor' : 'none'" aria-hidden="true" /><strong>{{ review.rating.toFixed(1) }}</strong></div>
        <p v-if="review.comment">{{ review.comment }}</p>
      </article>
    </div>
    <DirectoryPagination v-if="!loading && total" :page="page" :page-size="10" :total="total" :show-page-size="false" :show-single-page="true" :status-label="showing" @update:page="changePage" />
  </section>
</template>
<style scoped lang="scss">
.profile-reviews { border: 1px solid #e2e6df; border-radius: 16px; padding: 24px; background: #fffefa; margin: 24px 0; min-width: 0;
  header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; margin-bottom: 20px; }
  h2 { margin: 0; color: var(--forest); font-size: 25px; scroll-margin-top: 100px; }
  header p, > p { font-size: 12px; color: var(--muted); line-height: 1.6; margin: 8px 0 0; }
  &__list { display: grid; gap: 12px; }
  article { border: 1px solid #e2e6df; border-radius: 12px; padding: 18px; font-size: 13px; }
  &__identity { display: flex; align-items: center; gap: 12px; min-width: 0; > div { flex: 1; min-width: 0; } strong { font-weight: 600; } }
  &__avatar { flex: 0 0 38px; height: 38px; display: grid; place-items: center; border-radius: 50%; color: var(--forest); background: #eef1eb; font-weight: 600; }
  time, small { color: var(--muted); font-size: 11px; }
  small { display: block; margin-top: 4px; overflow-wrap: anywhere; }
  &__stars { display: flex; align-items: center; gap: 3px; color: var(--forest); margin-top: 14px; strong { margin-left: 5px; font-size: 12px; } }
  article p { white-space: pre-wrap; overflow-wrap: anywhere; line-height: 1.7; margin: 10px 0 0; }
  @media (max-width: 760px) { padding: 16px; article { padding: 14px; } time { align-self: flex-start; } }
}
</style>
