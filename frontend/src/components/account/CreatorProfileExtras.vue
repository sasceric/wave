<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import MultiSelect from '../shared/MultiSelect.vue'
import SingleSelect from '../shared/SingleSelect.vue'
import DatePicker from '../shared/DatePicker.vue'
import AccountProfileCard from './AccountProfileCard.vue'
import { languageOptions } from '../../lib/creatorPresentation'
import { formatDate } from '../../lib/api'
import { Globe } from '@lucide/vue'
const profile = defineModel('profile', { type: Object, required: true })
defineProps({ editing: Boolean, embedded: Boolean })
const emit = defineEmits(['edit'])
const { t, locale } = useI18n()
const languages = computed(() => languageOptions(locale.value, t))
</script>
<template>
  <component
    :is="embedded ? 'div' : AccountProfileCard"
    :class="{ 'creator-profile-extras': !embedded }"
    v-bind="embedded ? {} : { title: t('studio.availability'), icon: Globe, editable: !editing }"
    @edit="emit('edit', 'about')"
  >
    <div v-if="editing" class="form-grid">
      <MultiSelect
        v-model="profile.presentation.languages"
        class="form-field--wide"
        :options="languages"
        :label="t('studio.languages')"
        :placeholder="t('studio.choose')"
        :search-placeholder="t('studio.search')"
        :no-results-label="t('studio.noResults')"
        :remove-label="t('account.remove')"
        :max-selections="10"
      />
      <SingleSelect
        v-model="profile.presentation.availability"
        :label="t('studio.availability')"
        :options="['unspecified','available','from','busy'].map(value => ({value, label:t(`studio.${value}`)}))"
      />
      <DatePicker
        v-if="profile.presentation.availability === 'from'"
        v-model="profile.presentation.availableFrom"
        :label="t('studio.availableFrom')"
        required
        :placeholder="t('datePicker.placeholder')"
      />
      <label class="form-field form-field--wide">
        <span>{{ t('studio.introVideo') }}</span>
        <input v-model.trim="profile.presentation.introVideoUrl" type="url" maxlength="1000" placeholder="https://" />
      </label>
    </div>
    <div v-else class="creator-studio__overview">
      <p>
        {{ t(`studio.${profile.presentation?.availability || 'unspecified'}`) }}
        <template v-if="profile.presentation?.availability === 'from'"> · {{ formatDate(profile.presentation.availableFrom) }}</template>
      </p>
      <div class="profile-details__chips">
        <span v-for="language in profile.presentation?.languages || []" :key="language">
          {{ languages.find(item => item.value === language)?.label || language }}
        </span>
      </div>
      <a v-if="profile.presentation?.introVideoUrl" :href="profile.presentation.introVideoUrl" target="_blank" rel="noopener noreferrer">
        {{ t('studio.introVideo') }}
        ↗
      </a>
    </div>
  </component>
</template>
<style scoped>
.creator-profile-extras { margin-top: 14px; }
</style>
