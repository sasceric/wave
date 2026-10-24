<script setup>
import { updateProfileReviewSchema } from '../../lib/seo'
import PresentationDemoBadge from '../shared/PresentationDemoBadge.vue'
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { Star } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import DirectoryPagination from '../shared/DirectoryPagination.vue'
import StatusMessage from '../shared/StatusMessage.vue'
import { apiGet, formatDate } from '../../lib/api'
const props = defineProps({ kind: { type: String, required: true }, slug: { type: String, required: true }, reviewRole: { type: String, default: '' }, title: { type: String, default: '' }, description: { type: String, default: '' } })
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
    const result = await apiGet(`/${props.kind}/${encodeURIComponent(props.slug)}/reviews?page=${page.value}${props.reviewRole ? `&role=${props.reviewRole}` : ''}`)
    if (current !== generation) return
    rows.value = result.data
    summary.value = result.summary
    total.value = result.meta.total ?? result.summary.reviewCount ?? 0
    page.value = result.meta.page ?? page.value
    await nextTick()
    if (current === generation && !props.reviewRole) updateProfileReviewSchema(props.kind, props.slug, rows.value)
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
watch(() => [props.kind, props.slug, props.reviewRole, locale.value], () => { page.value = 1; load() }, { immediate: true })
onBeforeUnmount(() => { ++generation })
</script>
<template>
  <section class="profile-reviews" :aria-label="t('reviews.title')" :aria-busy="loading">
    <header class="profile-reviews__heading">
      <div>
        <h2 ref="heading" tabindex="-1">{{ title || t('reviews.title') }}</h2>
        <p>{{ description || t('studio.reviewsIntro') }}</p>
      </div>
      <div v-if="$slots.filters" class="profile-reviews__filters"><slot name="filters" /></div>
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
        <article v-for="review in rows" :id="`review-${review.id}`" :key="review.id">
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
@use '../../scss/public-profile-layout';

// Reviews from the profile prototype: a score card beside a list of review cards.
.profile-reviews {
  display: grid; gap: 16px; min-width: 0; margin: 24px 0;
  &__heading { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 12px 16px; h2 { @include public-profile-layout.section-title; scroll-margin-top: 150px; } p { @include public-profile-layout.section-intro; } }
  &__filters { min-width: 0; max-width: 100%; :deep(.profile-tabs) { margin: 0; } }
  &__surface { display: grid; grid-template-columns: 220px minmax(0, 1fr); align-items: start; gap: 16px;
    > * { grid-column: 2; min-width: 0; }
    > p { margin: 0; padding: 22px; border: 1px dashed var(--hairline); border-radius: 24px; color: var(--muted-text); font-size: 14px; }
  }
  &__summary { @include public-profile-layout.card; grid-column: 1; grid-row: span 3; display: grid; align-content: start; gap: 4px; padding: 22px;
    p { margin: 2px 0 0; color: var(--muted-text); font-size: 13px; }
  }
  &__average { color: var(--forest); font: 400 56px/1 var(--serif); }
  &__stars { display: flex; align-items: center; gap: 2px; margin-top: 6px; color: var(--coral); }
  &__list { display: grid; gap: 12px; }
  article { @include public-profile-layout.card; display: grid; gap: 8px; padding: 20px 22px; }
  &__identity { display: flex; align-items: center; gap: 12px; min-width: 0; > div { flex: 1; min-width: 0; } h3 { margin: 0; color: var(--ink); font-family: inherit; font-size: 15px; font-weight: 600; } }
  &__avatar { display: grid; flex: 0 0 40px; place-items: center; height: 40px; border-radius: 50%; color: var(--forest); background: var(--surface); font-size: 14px; font-weight: 600; }
  &__demo { justify-self: start; }
  &__badge { padding: 4px 10px; border-radius: var(--radius-pill); background: var(--surface); color: var(--forest); font-size: 12px; white-space: nowrap; }
  small { display: block; margin-top: 2px; color: var(--muted-text); font-size: 13px; overflow-wrap: anywhere; }
  blockquote { margin: 0; font-size: 15px; line-height: 1.6; white-space: pre-wrap; overflow-wrap: anywhere; }
  &__footer { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 8px; color: var(--muted-text); font-size: 13px; }
  :deep(.directory-pagination) { grid-column: 1 / -1; flex-wrap: wrap; justify-content: space-between; gap: 10px; margin-top: 0; padding: 4px 0 0; border: 0; border-radius: 0; background: transparent; }
  :deep(.directory-pagination__status) { flex-basis: auto; order: 0; font-size: 13px; text-align: left; }
  :deep(.directory-pagination__controls), :deep(.directory-pagination__pages) { gap: 5px; }
  :deep(.directory-pagination__controls button) { width: 36px; height: 36px; min-width: 36px; border: 0; border-radius: 50%; color: var(--forest); background: var(--surface); }
  :deep(.directory-pagination__controls button[aria-current=page]) { color: #fffefa; background: var(--forest); font-weight: 600; }
  @media (max-width: 760px) {
    &__surface { grid-template-columns: minmax(0, 1fr); > * { grid-column: 1; } }
    &__summary { grid-row: auto; grid-template-columns: auto minmax(0, 1fr); align-items: center; column-gap: 16px; }
    &__average { grid-row: span 2; }
    :deep(.directory-pagination__controls button) { width: 44px; height: 44px; min-width: 44px; }
  }
}
</style>
