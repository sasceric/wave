<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Plus, Trash2 } from '@lucide/vue'
import SingleSelect from '../shared/SingleSelect.vue'
import MediaUploadField from '../shared/MediaUploadField.vue'
import { emptyReference, REFERENCE_PLATFORMS, referenceImage } from '../../lib/creatorPresentation'

const items = defineModel('items', { type: Array, required: true })
const { t } = useI18n()
const platforms = computed(() => REFERENCE_PLATFORMS.map(value => ({ value, label: value === 'All' ? t('creatorProfile.allPlatforms') : value === 'Other' ? t('studio.otherPlatform') : value })))
function uploaded(row, image) {
  if (row.legacyImage || row.url === referenceImage(row)) row.url = ''
  row.imageUrl = image.url
  row.legacyImage = false
}
function changeType(row) { row.mediaId = null; row.imageUrl = ''; row.legacyImage = false; delete row.embedUrl }
</script>

<template>
  <section class="work-references-editor">
    <header>
      <div><h3>{{ t('studio.references') }}</h3><p>{{ t('studio.referencesHint') }}</p></div>
      <button class="profile-editor__add" type="button" :disabled="items.length >= 12" @click="items.push(emptyReference())">
        <Plus :size="16" aria-hidden="true" />{{ t('studio.addReference') }}
      </button>
    </header>
    <article v-for="(row, index) in items" :key="index" class="work-references-editor__card">
      <header><h4>{{ t('studio.referenceNumber', { number: index + 1 }) }}</h4><button class="creator-studio__icon" type="button" :disabled="items.length === 1" :aria-label="t('account.remove')" @click="items.splice(index, 1)"><Trash2 :size="17" aria-hidden="true" /></button></header>
      <div class="work-references-editor__fields">
        <SingleSelect v-model="row.platform" :label="t('account.platform')" :options="platforms" />
        <SingleSelect v-model="row.type" :label="t('account.mediaType')" :options="[{ value: 'image', label: t('account.image') }, { value: 'video', label: t('account.video') }]" @update:model-value="changeType(row)" />
        <label class="form-field work-references-editor__url">
          <span>{{ t('studio.referenceUrl') }}</span>
          <input v-model.trim="row.url" :type="row.legacyImage ? 'text' : 'url'" :required="!row.mediaId" maxlength="1000" placeholder="https://" />
        </label>
      </div>
      <details v-if="row.type === 'image'" :open="Boolean(row.mediaId || row.legacyImage)">
        <summary>{{ t('studio.referenceUpload') }}</summary>
        <MediaUploadField v-model="row.mediaId" folder="creator-portfolio" :preview-url="referenceImage(row)" optional @uploaded="uploaded(row, $event)" />
      </details>
    </article>
  </section>
</template>

<style scoped lang="scss">
.work-references-editor {
  display: grid; gap: 12px; margin: 20px 0; min-width: 0;
  header { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
  > header { flex-wrap: wrap; }
  h3, h4 { margin: 0; color: var(--forest); font-family: inherit; font-size: 14px; font-weight: 600; }
  p { margin: 6px 0 0; max-width: 64ch; color: var(--muted-text); font-size: 13px; line-height: 1.5; }
  &__card { display: grid; gap: 14px; min-width: 0; padding: 16px; border: 1px solid var(--line); border-radius: 14px; background: var(--paper); }
  &__fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
  &__url { grid-column: 1 / -1; }
  .form-field { min-width: 0; margin: 0; }
  summary { padding: 4px 0; color: var(--forest); font-size: 13px; cursor: pointer; }
  details :deep(.media-upload-field) { margin-top: 12px; }
  @media (max-width: 480px) { &__card { padding: 12px; } &__fields { grid-template-columns: minmax(0, 1fr); } > header .profile-editor__add { width: 100%; justify-content: center; } }
}
</style>
