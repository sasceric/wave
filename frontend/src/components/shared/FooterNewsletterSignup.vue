<script setup>
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ArrowRight, Mail } from '@lucide/vue'
import StatusMessage from './StatusMessage.vue'

const props = defineProps({
  inputId: { type: String, required: true },
  loading: { type: Boolean, default: false },
  modelValue: { type: String, default: '' },
  error: { type: String, default: '' },
  // True after a successful sign-up; shows the confirmation under the field until the address changes.
  sent: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue', 'submit'])
const { t } = useI18n()
const honeypot = ref('')

function submitForm() {
  emit('submit', { website: honeypot.value })
}
</script>

<template>
  <div class="site-footer__newsletter-signup">
    <p class="site-footer__newsletter-copy">{{ t('app.footerNewsletterText') }}</p>
    <form v-form-validation class="site-footer__newsletter-form" :aria-busy="loading" @submit.prevent="submitForm">
      <label class="sr-only" :for="inputId">
        {{ t('app.footerNewsletterPlaceholder') }}
      </label>
      <span class="site-footer__newsletter-icon" aria-hidden="true">
        <Mail :size="20" />
      </span>
      <input
        :id="inputId"
        :value="modelValue"
        type="email"
        name="email"
        autocomplete="email"
        maxlength="180"
        required
        :placeholder="t('app.footerNewsletterPlaceholder')"
        @input="emit('update:modelValue', $event.target.value)"
      >
      <button
        type="submit"
        :disabled="loading"
        :aria-label="t('app.footerNewsletterSubmit')"
        :title="t('app.footerNewsletterSubmit')"
      >
        <ArrowRight :size="20" aria-hidden="true" />
      </button>
      <div class="site-footer__honeypot" aria-hidden="true">
        <label :for="`${props.inputId}-website`">
          Website
          <input
            :id="`${props.inputId}-website`"
            v-model="honeypot"
            type="text"
            name="website"
            autocomplete="off"
            tabindex="-1"
          >
        </label>
      </div>
    </form>
    <p v-if="error" class="site-footer__newsletter-error" role="alert">{{ error }}</p>
    <StatusMessage v-if="sent" class="site-footer__newsletter-sent" variant="success" :title="t('app.footerNewsletterSuccessTitle')">{{ t('app.footerNewsletterSuccessMessage') }}</StatusMessage>
    <span v-else class="site-footer__newsletter-note">{{ t('app.footerNewsletterNote') }}</span>
  </div>
</template>
