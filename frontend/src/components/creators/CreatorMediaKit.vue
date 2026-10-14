<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { FileText, Printer } from '@lucide/vue'
import SwitchField from '../shared/SwitchField.vue'
import MultiSelect from '../shared/MultiSelect.vue'
import FormModal from '../shared/FormModal.vue'
import CreatorMediaKitPaper from './CreatorMediaKitPaper.vue'
import { apiGet } from '../../lib/api'
import { currentUser } from '../../composables/useCurrentUser'
const props = defineProps({ profile: { type: Object, required: true } })
const { t } = useI18n()
const paper = ref(null)
const previewOpen = ref(false)
const options = ref({ audience: 1, packages: 1, prices: 1, reviews: 1 })
const summary = ref(null)
const selectedIds = ref([])
const publicWorks = computed(() => (props.profile.portfolio || []).filter(item => item.publicPermission !== false).map((item, index) => ({ ...item, key: item.id || `work-${index}` })))
const selectedWorks = computed(() => publicWorks.value.filter(item => selectedIds.value.includes(item.key)))
watch(() => props.profile.id, () => {
    previewOpen.value = false
    selectedIds.value = publicWorks.value.slice(0, 2).map(item => item.key)
}, { immediate: true })
watch(() => publicWorks.value.map(item => item.key).join('|'), () => {
    const allowed = new Set(publicWorks.value.map(item => item.key))
    selectedIds.value = selectedIds.value.filter(id => allowed.has(id))
})
let generation = 0
let frame = null
watch(() => [currentUser.value?.id, currentUser.value?.approved], async () => {
    const token = ++generation
    const ownerId = currentUser.value?.id
    summary.value = null
    if (!currentUser.value?.approved)
        return
    try {
        const result = await apiGet('/me/collaboration-summary')
        if (token === generation && ownerId === currentUser.value?.id && result.userId === ownerId)
            summary.value = result.data.rating
    }
    catch { /* An optional rating never blocks creating a presentation. */ }
}, { immediate: true })
onBeforeUnmount(() => { ++generation; frame?.remove(); })
const logoText = computed(() => t('studio.presentationTitle'))
async function print() {
    if (!paper.value)
        return
    frame?.remove()
    frame = document.createElement('iframe')
    frame.setAttribute('aria-hidden', 'true')
    frame.style.cssText = 'position:fixed;width:1px;height:1px;opacity:0;pointer-events:none'
    const title = document.createElement('title')
    title.textContent = logoText.value
    const content = paper.value.cloneNode(true)
    content.querySelectorAll('button').forEach(button => button.remove())
    // Clone Vue-rendered DOM, never interpolate unescaped profile data into markup.
    frame.srcdoc = `<!doctype html><html><head><meta charset="utf-8">${title.outerHTML}<style>@page{size:A4;margin:16mm}*{box-sizing:border-box}body{font:13px/1.7 Arial,sans-serif;color:#173D36;background:#fffefa}h3{font:28px/1.2 Georgia,serif;margin:0 0 5px}p,figure{margin:0}p{color:#63766f}.creator-kit-paper{padding:24px;border:1px solid #dce2d9;border-radius:12px;background:#f6f2ea}.creator-kit-paper__identity{display:flex;gap:16px;align-items:center;margin-bottom:16px}.creator-kit-paper__identity img{width:72px;height:86px;object-fit:cover;border-radius:40px 40px 14px 14px}.creator-kit-paper__section{margin-top:14px}.creator-kit-paper__images{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:16px 0}.creator-kit-paper__images img{width:100%;height:160px;object-fit:cover;border-radius:8px}figure{break-inside:avoid}figcaption{padding:20px;background:#e5ebe2}.creator-kit-paper__url{margin-top:16px;color:#173D36}</style></head><body>${content.outerHTML}</body></html>`
    const target = frame
    target.onload = async () => {
        if (!target.contentWindow) return
        await Promise.all([...target.contentDocument.images].map(image => image.decode().catch(() => {})))
        if (frame !== target) return
        target.contentWindow.focus()
        target.contentWindow.print()
    }
    document.body.append(target)
}
</script>
<template>
  <section class="creator-kit">
    <header class="creator-kit__heading">
      <h2>{{ t('studio.presentationForCompanies') }}</h2>
      <p>{{ t('studio.presentationIntro') }}</p>
    </header>
    <div class="creator-kit__grid">
      <div class="creator-kit__options">
        <h3>{{ t('studio.presentationIncludes') }}</h3>
        <SwitchField
          v-for="key in ['audience', 'packages', 'prices', 'reviews']"
          :key="key"
          v-model="options[key]"
          :disabled="key === 'prices' && !options.packages"
          :label="t(`studio.include_${key}`)"
        />
        <MultiSelect
          v-model="selectedIds"
          :options="publicWorks.map(item => ({ value: item.key, label: item.title }))"
          :label="t('studio.chooseWorks')"
          :placeholder="t('studio.choose')"
          :search-placeholder="t('studio.search')"
          :no-results-label="t('studio.noResults')"
          :remove-label="t('account.remove')"
        />
        <button class="button button--dark" type="button" @click="previewOpen = true">
          <FileText :size="17" aria-hidden="true" />
          {{ t('studio.previewPresentation') }}
        </button>
        <p class="creator-kit__privacy">{{ t('studio.exportPrivacy') }}</p>
      </div>
      <div ref="paper" class="creator-kit__preview">
        <CreatorMediaKitPaper :profile="profile" :options="options" :works="selectedWorks" :rating="summary" />
      </div>
    </div>
    <FormModal v-model:open="previewOpen" :title="t('studio.previewPresentation')" wide>
      <CreatorMediaKitPaper :profile="profile" :options="options" :works="selectedWorks" :rating="summary" />
      <p class="creator-kit__privacy">{{ t('studio.exportPrivacy') }}</p>
      <template #actions="{ close }">
        <button class="button button--outline" type="button" @click="close">{{ t('reviews.close') }}</button>
        <button class="button button--dark" type="button" @click="print">
          <Printer :size="17" aria-hidden="true" />
          {{ t('studio.print') }}
        </button>
      </template>
    </FormModal>
  </section>
</template>
<style scoped lang="scss">
.creator-kit {
  &__heading { margin-bottom: 18px; h2 { font-size: 27px; color: var(--forest); margin: 0; } p { margin: 6px 0 0; font-size: 12px; color: var(--muted); line-height: 1.7; } }
  &__grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 24px; align-items: start; padding: 22px; border: 1px solid #dce2d9; border-radius: 14px; background: #fffefa; }
  &__options :deep(.switch-field) { min-width: 0; max-width: none; padding: 0; border: 0; border-radius: 0; background: transparent; }
  &__options :deep(.switch-field__label) { font-size: 12px; font-weight: 400; }
  &__options { display: grid; gap: 14px; min-width: 0; h3 { margin: 0 0 2px; font-family: inherit; font-size: 14px; color: var(--forest); } .button { justify-self: start; min-height: 40px; padding: 10px 14px; font-size: 12px; } }
  &__preview { min-width: 0; }
  &__privacy { margin: 0; font-size: 12px; color: var(--muted); line-height: 1.7; }
  @media (max-width: 760px) { &__grid { grid-template-columns: minmax(0, 1fr); padding: 16px; gap: 22px; } &__options .button { justify-self: stretch; } }
}
</style>
