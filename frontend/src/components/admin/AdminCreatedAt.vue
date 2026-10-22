<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { createChatTimeFormatter } from '../../lib/chatTime'

const props = defineProps({ value: { type: String, default: null }, now: { type: Number, required: true } })
const { locale, t } = useI18n()
const formatter = computed(() => createChatTimeFormatter(locale.value, t, { relativeStyle: 'long' }))
const exact = computed(() => formatter.value.date(props.value) ? t('adminProfiles.createdAtFormat', { date: formatter.value.date(props.value), time: formatter.value.clock(props.value) }) : '')
const label = computed(() => {
  if (!exact.value) return ''
  const elapsed = props.now - new Date(props.value).getTime()
  return elapsed >= 0 && elapsed < 86_400_000 ? formatter.value.ago(props.value, props.now) : exact.value
})
</script>

<template>
  <time v-if="label" class="admin-created-at" :datetime="value" :title="exact">{{ label }}</time>
  <span v-else :title="t('adminProfiles.createdAtUnknown')">—</span>
</template>

<style lang="scss" src="../../scss/components/admin/AdminCreatedAt.scss"></style>
