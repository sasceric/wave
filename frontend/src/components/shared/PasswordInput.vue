<script setup>
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Eye, EyeOff } from '@lucide/vue'

// Password input with an eye button that shows what was typed. Other attributes (autocomplete,
// required, minlength…) go to the input, so it drops into any <label> in forms and modals.
defineOptions({ inheritAttrs: false })
const model = defineModel({ type: String, default: '' })
const { t } = useI18n()
const visible = ref(false)
</script>

<template>
  <span class="password-input">
    <input v-model="model" v-bind="$attrs" :type="visible ? 'text' : 'password'" />
    <button type="button" class="password-input__reveal" :aria-label="visible ? t('auth.hidePassword') : t('auth.showPassword')" :aria-pressed="visible" @click="visible = !visible">
      <EyeOff v-if="visible" :size="17" aria-hidden="true" />
      <Eye v-else :size="17" aria-hidden="true" />
    </button>
  </span>
</template>

<style lang="scss" src="../../scss/components/shared/PasswordInput.scss"></style>
