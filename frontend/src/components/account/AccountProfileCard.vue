<script setup>
import { Pencil } from '@lucide/vue'
import { useI18n } from 'vue-i18n'

defineProps({
  title: { type: String, required: true },
  icon: { type: [Object, Function], default: null },
  editable: { type: Boolean, default: false },
})
defineEmits(['edit'])
const { t } = useI18n()
</script>

<template>
  <section class="account-profile-card">
    <header class="account-profile-card__heading">
      <h3><component :is="icon" v-if="icon" :size="23" aria-hidden="true" />{{ title }}</h3>
      <button v-if="editable" class="account-profile-card__edit" type="button" :aria-label="`${t('account.edit')}: ${title}`" @click="$emit('edit')">
        <Pencil :size="17" aria-hidden="true" /><span>{{ t('account.edit') }}</span>
      </button>
    </header>
    <slot />
  </section>
</template>

<style lang="scss" src="../../scss/components/account/AccountProfileCard.scss"></style>
