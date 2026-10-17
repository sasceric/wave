<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Plus, Trash2 } from '@lucide/vue'
import AccountProfileCard from '../account/AccountProfileCard.vue'
import TagInput from '../shared/TagInput.vue'
import MultiSelect from '../shared/MultiSelect.vue'
import SingleSelect from '../shared/SingleSelect.vue'
import DatePicker from '../shared/DatePicker.vue'
import { formatDate } from '../../lib/api'
import { languageOptions } from '../../lib/creatorPresentation'
const profile = defineModel('profile', { type: Object, required: true })
defineProps({ editing: Boolean, tab: { type: String, default: 'services' } })
const emit = defineEmits(['edit'])
const { t, locale } = useI18n()
const languages = computed(() => languageOptions(locale.value, t))
const availability = computed(() => ['unspecified', 'available', 'from', 'busy'].map(value => ({ value, label: t(value === 'unspecified' ? 'studio.unspecified' : `agency.${value}`) })))
</script>
<template>
  <AccountProfileCard :title="t(tab === 'faqs' ? 'studio.tabFaq' : 'agency.services')" :editable="!editing" @edit="emit('edit', tab)">
    <template v-if="tab === 'faqs'">
      <button v-if="editing" type="button" class="profile-editor__add" :disabled="profile.faqs.length >= 10" @click="profile.faqs.push({ question: '', answer: '' })"><Plus :size="16" />{{ t('account.addFaq') }}</button>
      <article v-for="(faq, index) in profile.faqs" :key="index" class="creator-faq-editor">
        <template v-if="editing">
          <label class="form-field"><span>{{ t('account.faqQuestion') }}</span><input v-model.trim="faq.question" required minlength="3" maxlength="180" /></label>
          <label class="form-field"><span>{{ t('account.faqAnswer') }}</span><textarea v-model.trim="faq.answer" required minlength="10" maxlength="1500"></textarea></label>
          <button class="button button--outline" type="button" @click="profile.faqs.splice(index, 1)"><Trash2 :size="16" />{{ t('account.remove') }}</button>
        </template>
        <template v-else><h4>{{ faq.question }}</h4><p>{{ faq.answer }}</p></template>
      </article>
      <p v-if="!profile.faqs.length" class="creator-studio__hint">{{ t('account.notProvided') }}</p>
    </template>
    <template v-else-if="editing">
      <p class="creator-studio__hint">{{ t('agency.servicesHint') }}</p>
      <TagInput v-model="profile.services" :label="t('agency.services')" :placeholder="t('agency.servicesPlaceholder')" :remove-label="t('account.remove')" :max-tags="10" :max-length="40" :limit-label="t('account.tagsLimit', { count: 10 })" :too-long-label="t('account.tagsTooLong', { count: 40 })" />
      <div class="form-grid">
        <MultiSelect v-model="profile.presentation.languages" :options="languages" :label="t('studio.languages')" :placeholder="t('studio.languages')" :search-placeholder="t('studio.languages')" :no-results-label="t('account.notProvided')" :remove-label="t('account.remove')" />
        <SingleSelect v-model="profile.presentation.availability" :options="availability" :label="t('studio.availability')" />
        <DatePicker v-if="profile.presentation.availability === 'from'" v-model="profile.presentation.availableFrom" :label="t('agency.from')" required />
        <label class="form-field"><span>{{ t('studio.introVideo') }}</span><input v-model.trim="profile.presentation.introVideoUrl" type="url" maxlength="1000" placeholder="https://" /></label>
      </div>
    </template>
    <template v-else>
      <p>{{ t('agency.servicesHint') }}</p>
      <div class="company-profile__tags"><span v-for="service in profile.services" :key="service">{{ service }}</span></div>
      <p v-if="!profile.services.length">{{ t('account.notProvided') }}</p>
      <p>{{ t('studio.availability') }}: {{ t(!profile.presentation.availability || profile.presentation.availability === 'unspecified' ? 'studio.unspecified' : `agency.${profile.presentation.availability}`) }}<template v-if="profile.presentation.availability === 'from'"> · {{ formatDate(profile.presentation.availableFrom) }}</template></p>
      <p v-if="profile.presentation.introVideoUrl"><a :href="profile.presentation.introVideoUrl" target="_blank" rel="noopener noreferrer">{{ t('studio.introVideo') }}</a></p>
      <p v-if="profile.presentation.languages.length">{{ profile.presentation.languages.map(value => t(`studio.contentLanguages.${value}`)).join(', ') }}</p>
    </template>
  </AccountProfileCard>
</template>
<style scoped>
.company-profile__tags { display: flex; flex-wrap: wrap; gap: 8px; margin-block: 12px; }
.company-profile__tags span { padding: 6px 10px; border-radius: 999px; color: var(--forest); background: var(--surface); }
</style>
