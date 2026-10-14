<script setup>
import PresentationDemoBadge from '../shared/PresentationDemoBadge.vue'
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { Star } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
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
const number = value => new Intl.NumberFormat(locale.value === 'bs' || locale.value === 'cnr' ? 'hr' : locale.value, { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(value)
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
    <header class="profile-reviews__heading">
      <h2 ref="heading" tabindex="-1">{{ t('reviews.title') }}</h2>
      <p>{{ t('studio.reviewsIntro') }}</p>
    </header>
    <div class="profile-reviews__surface">
      <div class="profile-reviews__summary">
        <strong class="profile-reviews__average">{{ summary.average == null ? '—' : number(summary.average) }}</strong>
        <div>
          <div class="profile-reviews__stars" :aria-label="t('reviews.stars', { count: summary.average || 0 })">
            <Star
              v-for="star in 5"
              :key="star"
              :size="14"
              :fill="star <= Math.round(summary.average || 0) ? 'currentColor' : 'none'"
              aria-hidden="true"
            />
          </div>
          <p>{{ t('reviews.ratingHint', { count: summary.reviewCount || 0 }) }}</p>
        </div>
      </div>
      <StatusMessage v-if="error" variant="error">
        {{ error }}
        <button type="button" @click="load">{{ t('reviews.retry') }}</button>
      </StatusMessage>
      <p v-else-if="loading" role="status">{{ t('reviews.loading') }}</p>
      <p v-else-if="!rows.length">{{ t('reviews.empty') }}</p>
      <div v-else class="profile-reviews__list" aria-live="polite">
        <article v-for="review in rows" :key="review.id">
          <div class="profile-reviews__identity">
            <span class="profile-reviews__avatar" aria-hidden="true">{{ initials(review.authorName) }}</span>
            <div>
              <h3>{{ review.authorName }}</h3>
              <small>{{ review.campaignTitle }}</small>
            </div>
            <span class="profile-reviews__badge">{{ t('account.finished') }}</span>
          </div>
          <PresentationDemoBadge v-if="review.isPresentationDemo" class="profile-reviews__demo" compact />
          <blockquote v-if="review.comment">{{ review.comment }}</blockquote>
          <div class="profile-reviews__footer">
            <span>{{ number(review.rating) }} / 5</span>
            <time :datetime="review.createdAt">{{ formatDate(review.createdAt) }}</time>
          </div>
        </article>
      </div>
      <DirectoryPagination
        v-if="!loading && total"
        :page="page"
        :page-size="10"
        :total="total"
        :show-page-size="false"
        :show-single-page="true"
        :show-arrows="false"
        :status-label="showing"
        @update:page="changePage"
      />
    </div>
  </section>
</template>
<style scoped lang="scss">
.profile-reviews {
  margin: 24px 0; min-width: 0;
  &__heading { margin-bottom: 14px; h2 { margin: 0; color: var(--forest); font-size: 25px; scroll-margin-top: 100px; } p { margin: 5px 0 0; font-size: 12px; color: var(--muted); } }
  &__surface { padding: 18px; border: 1px solid #dce2d9; border-radius: 14px; background: #fffefa; > p { font-size: 12px; color: var(--muted); } }
  &__summary { display: flex; align-items: center; gap: 14px; padding-bottom: 14px; margin-bottom: 3px; border-bottom: 1px solid #dce2d9; p { margin: 4px 0 0; font-size: 11px; color: var(--muted); } }
  &__average { font: 400 34px/1.2 var(--serif); color: var(--forest); }
  &__stars { display: flex; align-items: center; gap: 3px; color: var(--coral-text); }
  &__list { display: grid; }
  article { padding: 16px 0; border-bottom: 1px solid #dce2d9; &:last-child { border: 0; padding-bottom: 0; } }
  &__identity { display: flex; align-items: center; gap: 10px; min-width: 0; > div { flex: 1; min-width: 0; } h3 { margin: 0; color: var(--forest); font-family: inherit; font-size: 12px; font-weight: 600; } }
  &__avatar { display: grid; place-items: center; flex: 0 0 38px; height: 38px; border-radius: 10px; color: var(--forest); background: #e5ebe2; font: 400 16px/1 var(--serif); }
  article:nth-child(even) &__avatar { background: #f3e6de; }
  &__demo { margin-top: 10px; }
  &__badge { padding: 4px 9px; border-radius: 30px; background: #e5ebe2; color: var(--forest); font-size: 11px; white-space: nowrap; }
  small { display: block; margin-top: 3px; font-size: 11px; color: var(--muted); overflow-wrap: anywhere; }
  blockquote { margin: 12px 0 9px; font-size: 13px; line-height: 1.7; white-space: pre-wrap; overflow-wrap: anywhere; }
  &__footer { display: flex; justify-content: space-between; flex-wrap: wrap; gap: 8px; color: var(--muted); font-size: 11px; }
  :deep(.directory-pagination) { flex-wrap: wrap; justify-content: space-between; gap: 10px; margin-top: 0; padding: 14px 0 0; border: 0; border-radius: 0; background: transparent; }
  :deep(.directory-pagination__status) { flex-basis: auto; order: 0; font-size: 11px; text-align: left; }
  :deep(.directory-pagination__controls), :deep(.directory-pagination__pages) { gap: 5px; }
  :deep(.directory-pagination__controls button) { width: 32px; height: 32px; min-width: 32px; border: 1px solid #dce2d9; border-radius: 7px; color: var(--forest); background: #fffefa; }
  :deep(.directory-pagination__controls button[aria-current=page]) { border-color: var(--forest); color: #fffefa; background: var(--forest); font-weight: 500; }
  @media (max-width: 760px) { &__surface { padding: 16px; } :deep(.directory-pagination__controls button) { width: 44px; height: 44px; min-width: 44px; } }
}
</style>
