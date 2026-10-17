<script setup>
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Briefcase, ListChecks, Pencil, Trash2 } from '@lucide/vue'
import AccountProfileCard from '../account/AccountProfileCard.vue'
import ProfileCollectionCard from '../account/ProfileCollectionCard.vue'
import AgencyServiceCard from './AgencyServiceCard.vue'
import MultiSelect from '../shared/MultiSelect.vue'
import SingleSelect from '../shared/SingleSelect.vue'
import DatePicker from '../shared/DatePicker.vue'
import { formatDate } from '../../lib/api'
import { languageOptions } from '../../lib/creatorPresentation'
import { AGENCY_PLATFORMS, newService } from '../../lib/agencyServices'
const profile = defineModel('profile', { type: Object, required: true })
const props = defineProps({ editing: Boolean })
const emit = defineEmits(['edit'])
const selectedId = ref(null)
const { t, locale } = useI18n()
const languages = computed(() => languageOptions(locale.value, t))
const platforms = AGENCY_PLATFORMS.map(value => ({ value, label: value }))
const engagements = computed(() => ['project', 'monthly', 'both'].map(value => ({ value, label: t(`agency.engagement_${value}`) })))
const availability = computed(() => ['unspecified', 'available', 'from', 'busy'].map(value => ({ value, label: t(value === 'unspecified' ? 'studio.unspecified' : `agency.${value}`) })))
function editService(id) {
  selectedId.value = selectedId.value === id && props.editing ? null : id
  if (!props.editing) emit('edit', 'services')
}
function addService() {
  const service = newService()
  profile.value.services.push(service)
  selectedId.value = service.id
}
function revealInvalidService() {
  selectedId.value = profile.value.services.find(item => !item.title.trim() || item.deliverables.some(value => !value.trim()))?.id || selectedId.value
}
defineExpose({ revealInvalidService })
</script>
<template>
  <div class="agency-service-list">
    <ProfileCollectionCard
      :title="t('agency.services')"
      :icon="Briefcase"
      :hint="t('agency.structuredServicesHint')"
      :empty="!profile.services.length"
      :empty-message="t('agency.noServices')"
      :editing="editing"
      :add-label="t('agency.addService')"
      :add-disabled="profile.services.length >= 10"
      @add="addService"
      @edit="emit('edit', 'services')"
    >
      <div class="agency-service-list">
        <AgencyServiceCard v-for="(service, index) in profile.services" :key="service.id" :service="service">
          <template #actions><div class="agency-service-editor__actions">
            <button class="button button--outline" type="button" :aria-label="`${t('account.edit')}: ${service.title || t('agency.serviceTitle')}`" :aria-expanded="editing && selectedId === service.id" @click="editService(service.id)"><Pencil :size="17" aria-hidden="true" /><span>{{ t('account.edit') }}</span></button>
            <button v-if="editing" class="profile-editor__remove" type="button" :aria-label="`${t('account.remove')}: ${service.title || t('agency.serviceTitle')}`" @click="profile.services.splice(index, 1)"><Trash2 :size="16" aria-hidden="true" /><span>{{ t('account.remove') }}</span></button>
          </div></template>
          <template v-if="editing && selectedId === service.id" #default>
            <div class="agency-service-editor">
              <label class="form-field"><span>{{ t('agency.serviceTitle') }}</span><input v-model.trim="service.title" required minlength="1" maxlength="120" /></label>
              <label class="form-field"><span>{{ t('agency.serviceDescription') }}</span><textarea v-model.trim="service.description" maxlength="800" rows="3"></textarea></label>
              <ProfileCollectionCard
                :title="t('agency.serviceIncludes')"
                :icon="ListChecks"
                :empty="!service.deliverables.length"
                :empty-message="t('agency.noDeliverables')"
                editing
                :add-label="t('agency.addDeliverable')"
                :add-disabled="service.deliverables.length >= 10"
                @add="service.deliverables.push('')"
              >
                <label v-for="(_, itemIndex) in service.deliverables" :key="itemIndex" class="agency-service-editor__deliverable"><span class="sr-only">{{ t('agency.deliverableNumber', { number: itemIndex + 1 }) }}</span><input v-model.trim="service.deliverables[itemIndex]" required maxlength="180" /><button class="profile-editor__remove" type="button" :aria-label="t('account.remove')" @click="service.deliverables.splice(itemIndex, 1)"><Trash2 :size="16" aria-hidden="true" /></button></label>
              </ProfileCollectionCard>
              <MultiSelect v-model="service.platforms" :options="platforms" :label="t('agency.servicePlatforms')" :placeholder="t('agency.servicePlatforms')" :search-placeholder="t('agency.servicePlatforms')" :no-results-label="t('account.notProvided')" :remove-label="t('account.remove')" />
              <SingleSelect v-model="service.engagement" :options="engagements" :label="t('agency.engagement')" />
            </div>
          </template>
        </AgencyServiceCard>
      </div>
    </ProfileCollectionCard>
    <AccountProfileCard :title="t('studio.availability')" :editable="!editing" @edit="emit('edit', 'services')">
      <div v-if="editing" class="form-grid">
        <MultiSelect v-model="profile.presentation.languages" :options="languages" :label="t('studio.languages')" :placeholder="t('studio.languages')" :search-placeholder="t('studio.languages')" :no-results-label="t('account.notProvided')" :remove-label="t('account.remove')" />
        <SingleSelect v-model="profile.presentation.availability" :options="availability" :label="t('studio.availability')" />
        <DatePicker v-if="profile.presentation.availability === 'from'" v-model="profile.presentation.availableFrom" :label="t('agency.from')" required />
        <label class="form-field"><span>{{ t('studio.introVideo') }}</span><input v-model.trim="profile.presentation.introVideoUrl" type="url" maxlength="1000" placeholder="https://" /></label>
      </div>
      <template v-else>
        <p>{{ t('studio.availability') }}: {{ t(!profile.presentation.availability || profile.presentation.availability === 'unspecified' ? 'studio.unspecified' : `agency.${profile.presentation.availability}`) }}<template v-if="profile.presentation.availability === 'from'"> · {{ formatDate(profile.presentation.availableFrom) }}</template></p>
        <p v-if="profile.presentation.introVideoUrl"><a :href="profile.presentation.introVideoUrl" target="_blank" rel="noopener noreferrer">{{ t('studio.introVideo') }}</a></p>
        <p v-if="profile.presentation.languages.length">{{ profile.presentation.languages.map(value => t(`studio.contentLanguages.${value}`)).join(', ') }}</p>
      </template>
    </AccountProfileCard>
  </div>
</template>
