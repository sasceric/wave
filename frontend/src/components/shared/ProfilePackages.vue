<script setup>
import { MessageCircle } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import CreatorPackageFacts from '../creators/CreatorPackageFacts.vue'
import { formatMoney } from '../../lib/api'
defineProps({ items: { type: Array, required: true }, canRequest: Boolean, agency: Boolean, compact: Boolean, title: { type: String, default: '' } })
defineEmits(['request'])
const { t } = useI18n()
</script>
<template>
  <section class="profile-content-section profile-packages" :class="{ 'profile-packages--compact': compact }">
    <div class="profile-section-heading"><h2>{{ title || t('studio.myServices') }}</h2></div>
    <div class="profile-package-grid">
      <article v-for="item in items" :key="item.id || item.profileKey" class="profile-package" :class="{ 'profile-package--negotiation': item.isNegotiationOption }">
        <div class="profile-package__details">
          <div class="profile-package__heading"><h3>{{ item.title }}</h3><strong>{{ item.priceMode === 'from' ? t('studio.priceFrom') : '' }} {{ item.isNegotiationOption ? t('creatorProfile.priceByAgreement') : !item.price ? t('creatorProfile.priceOnRequest') : formatMoney(item.price, item.currency) }}</strong></div>
          <p v-if="item.description" class="profile-package__description">{{ item.description }}</p>
          <CreatorPackageFacts v-if="!item.isNegotiationOption" :item="item" :agency="agency" />
          <button v-if="canRequest" class="profile-package__request" type="button" :aria-label="`${t('creatorProfile.requestPackage')}: ${item.title}`" @click="$emit('request', item)"><MessageCircle :size="16" aria-hidden="true" />{{ t('creatorProfile.requestPackage') }}</button>
        </div>
      </article>
    </div>
    <p v-if="!items.length">{{ t('account.notProvided') }}</p>
  </section>
</template>
<style lang="scss" src="../../scss/components/shared/ProfilePackages.scss"></style>
