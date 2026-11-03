<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { ArrowRight } from '@lucide/vue'
import CardImage from '../shared/CardImage.vue'
import { formatMoney } from '../../lib/api'
import { CREATOR_PLACEHOLDER } from '../../lib/marketplace'
import { localizedRouteName } from '../../routePaths'

// Compact package card from the approved /usluge prototype: type, title, creator, price.
const props = defineProps({
  item: { type: Object, required: true },
})
const emit = defineEmits(['open'])
const { t, locale } = useI18n()
const router = useRouter()
// A real profile link keeps crawlers, new-tab and copy-link working; a plain click opens the sheet.
const href = computed(() => router.resolve({ name: localizedRouteName('creator-profile', locale.value), params: { slug: props.item.creator.slug }, hash: '#creator-packages' }).href)

function onClick(event) {
  if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return
  event.preventDefault()
  emit('open', props.item)
}

function priceLabel(item) {
  if (item.priceMode === 'agreement' || item.price === null) return t('services.agreement')
  const amount = formatMoney(item.price, item.currency || 'BAM')
  return item.priceMode === 'from' ? t('services.from', { price: amount }) : amount
}
</script>

<template>
  <a class="service-card" :href="href" aria-haspopup="dialog" @click="onClick">
    <span class="service-card__type">{{ t(`services.types.${item.serviceType}`) }}</span>
    <h3 class="service-card__title">{{ item.title }}</h3>
    <span class="service-card__creator">
      <CardImage class="service-card__avatar" :image="item.creator.avatarImage" sizes="36px" :src="item.creator.avatarUrl || CREATOR_PLACEHOLDER" alt="" />
      <span class="service-card__who">
        <strong>{{ item.creator.displayName }}</strong>
        <small>{{ [item.creator.categoryLabels?.[0], item.creator.city].filter(Boolean).join(' · ') }}</small>
      </span>
    </span>
    <span class="service-card__footer">
      <strong :class="item.priceMode === 'agreement' ? 'service-card__deal' : 'service-card__price'">{{ priceLabel(item) }}</strong>
      <span class="service-card__action">
        {{ item.priceMode === 'agreement' ? t('services.sendOffer') : t('services.order') }}
        <ArrowRight :size="15" aria-hidden="true" />
      </span>
    </span>
  </a>
</template>

<style scoped lang="scss" src="../../scss/components/services/ServicePackageCard.scss"></style>
