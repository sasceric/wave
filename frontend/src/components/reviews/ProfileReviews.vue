<script setup>
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import RatingSummary from './RatingSummary.vue'
import StatusMessage from '../shared/StatusMessage.vue'
import { apiGet, formatDate } from '../../lib/api'
const props = defineProps({ kind: { type: String, required: true }, slug: { type: String, required: true } })
const { t, locale } = useI18n()
const rows = ref([])
const summary = ref({})
const next = ref(null)
const loading = ref(false)
const error = ref('')
let generation = 0
async function load(reset = false) {
  if (loading.value && !reset) return
  const current = reset ? ++generation : generation
  if (reset) { rows.value = []; next.value = null; summary.value = {} }
  loading.value = true
  error.value = ''
  try {
    const result = await apiGet(`/${props.kind}/${encodeURIComponent(props.slug)}/reviews${next.value ? `?before=${next.value}` : ''}`)
    if (current !== generation) return
    rows.value.push(...result.data)
    summary.value = result.summary
    next.value = result.meta.nextCursor
  } catch (cause) { if (current === generation) error.value = cause.message }
  finally { if (current === generation) loading.value = false }
}
watch(() => [props.kind, props.slug, locale.value], () => load(true), { immediate: true })
</script>
<template>
  <section class="profile-reviews" :aria-label="t('reviews.title')" :aria-busy="loading">
    <header><h2>{{ t('reviews.title') }}</h2><RatingSummary :rating="summary" /></header>
    <p class="profile-reviews__hint">{{ t('reviews.ratingHint', { count: summary.reviewCount || 0 }) }}</p>
    <StatusMessage v-if="error" variant="error">{{ error }} <button type="button" @click="load(!rows.length)">{{ t('reviews.retry') }}</button></StatusMessage>
    <p v-else-if="!rows.length">{{ t(loading ? 'reviews.loading' : 'reviews.empty') }}</p>
    <article v-for="review in rows" :key="review.id">
      <div><strong>{{ review.authorName }}</strong><span>{{ review.rating.toFixed(1) }}/5 · <time :datetime="review.createdAt">{{ formatDate(review.createdAt) }}</time></span></div>
      <small>{{ review.campaignTitle }}</small><p v-if="review.comment">{{ review.comment }}</p>
    </article>
    <button v-if="next" class="button button--outline" type="button" :disabled="loading" @click="load()">{{ t('reviews.more') }}</button>
  </section>
</template>
<style scoped lang="scss">
.profile-reviews { border: 1px solid #e2e6df; border-radius: 16px; padding: 24px; background: #fffefa; margin: 24px 0; min-width: 0;
  header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; }
  h2 { margin: 0; color: var(--forest); font-size: 25px; }
  > p { font-size: 13px; color: var(--muted); line-height: 1.6; }
  article { border-top: 1px solid #e2e6df; padding: 18px 0; font-size: 13px; }
  article > div { display: flex; justify-content: space-between; flex-wrap: wrap; gap: 8px; }
  article span, small { color: var(--muted); font-size: 12px; }
  small { display: block; margin-top: 6px; }
  article p { white-space: pre-wrap; overflow-wrap: anywhere; line-height: 1.7; margin: 10px 0 0; }
}
</style>
