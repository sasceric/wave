<script setup>
import { computed, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ArrowRight } from '@lucide/vue'
import FormModal from './FormModal.vue'
import SegmentedControl from './SegmentedControl.vue'
import SingleSelect from './SingleSelect.vue'
import StatusMessage from './StatusMessage.vue'
import SuccessModal from './SuccessModal.vue'
import { apiRequest } from '../../lib/api'

defineProps({ open: { type: Boolean, required: true } })
const emit = defineEmits(['update:open'])
const { t } = useI18n()
const emptyForm = () => ({ role: 'creator', name: '', email: '', interest: 'collaboration', message: '', trap: '' })
const form = reactive(emptyForm())
const sending = ref(false)
const error = ref('')
const successOpen = ref(false)
const successMessage = ref('')
const roles = computed(() => [
  { value: 'creator', label: t('home.contactRoleCreator') },
  { value: 'brand', label: t('home.contactRoleBrand') },
  { value: 'agency', label: t('home.contactRoleAgency') },
])
const interests = computed(() => ['collaboration', 'campaign', 'partnership', 'other'].map(value => ({
  value,
  label: t(`home.contactInterest${value.charAt(0).toUpperCase() + value.slice(1)}`),
})))

function setOpen(value) {
  if (!sending.value) emit('update:open', value)
}

async function send() {
  sending.value = true
  error.value = ''
  try {
    const response = await apiRequest('/contact', { method: 'POST', body: { ...form } })
    successMessage.value = response.message || t('home.contactSuccess')
    Object.assign(form, emptyForm())
    emit('update:open', false)
    successOpen.value = true
  } catch (cause) {
    error.value = cause.status ? cause.message : t('home.contactNetworkError')
  } finally {
    sending.value = false
  }
}
</script>

<template>
  <FormModal
    :open="open"
    :title="t('home.contactFormTitle')"
    :description="`${t('home.contactFormIntro')} ${t('home.contactResponseTime')}`"
    :busy="sending"
    @update:open="setOpen"
    @submit="send"
  >
    <div class="contact-form-modal__honeypot" aria-hidden="true">
      <label for="contact-extra">Extra note</label>
      <input id="contact-extra" v-model="form.trap" type="text" tabindex="-1" autocomplete="off" />
    </div>
    <div class="contact-form-modal__role">
      <span>{{ t('home.contactRoleLabel') }}</span>
      <SegmentedControl v-model="form.role" :options="roles" :label="t('home.contactRoleLabel')" block />
    </div>
    <label>
      <span>{{ t('home.contactName') }}</span>
      <input v-model.trim="form.name" type="text" required minlength="2" maxlength="120" autocomplete="name" :placeholder="t('home.contactNamePlaceholder')" :disabled="sending" />
    </label>
    <label>
      <span>{{ t('home.contactEmail') }}</span>
      <input v-model.trim="form.email" type="email" required maxlength="180" autocomplete="email" :placeholder="t('home.contactEmailPlaceholder')" :disabled="sending" />
    </label>
    <SingleSelect v-model="form.interest" :label="t('home.contactInterest')" :options="interests" required :disabled="sending" />
    <label>
      <span>{{ t('home.contactMessage') }}</span>
      <textarea v-model.trim="form.message" required minlength="20" maxlength="4000" rows="4" :placeholder="t('home.contactMessagePlaceholder')" :disabled="sending"></textarea>
      <small class="contact-form-modal__meta">{{ t('home.contactMessageMinimum', { count: 20 }) }} · {{ form.message.length }} / 4000</small>
    </label>
    <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
    <template #actions="{ close }">
      <button class="button button--tonal" type="button" :disabled="sending" @click="close">{{ t('home.contactCancel') }}</button>
      <button class="button button--dark" type="submit" :disabled="sending">
        {{ sending ? t('home.contactSending') : t('home.contactSubmit') }}
        <ArrowRight :size="16" aria-hidden="true" />
      </button>
    </template>
  </FormModal>
  <SuccessModal
    v-if="successOpen"
    v-model:open="successOpen"
    :title="t('home.contactFeedbackSuccessTitle')"
    :message="successMessage"
    :close-label="t('home.contactFeedbackDismiss')"
  />
</template>

<style scoped lang="scss" src="../../scss/components/shared/ContactFormModal.scss"></style>
