<script setup>
import { computed } from 'vue'
import { Camera, ExternalLink, Handshake, MapPin, MessageCircleQuestion, Pencil, Images, UsersRound } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import LocalizedLink from '../shared/LocalizedLink.vue'
import SwitchField from '../shared/SwitchField.vue'
import WaveLogo from '../shared/WaveLogo.vue'
import { CREATOR_PLACEHOLDER } from '../../lib/marketplace'

const props = defineProps({
  user: { type: Object, required: true },
  profile: { type: Object, required: true },
  countryOptions: { type: Array, default: () => [] },
  visibilitySaving: { type: Boolean, default: false },
  notificationsSaving: { type: Boolean, default: false },
})
defineEmits(['edit', 'select-tab', 'visibility', 'notifications'])
const { t } = useI18n()
const creator = computed(() => props.user.accountType === 'creator')
const name = computed(() => creator.value ? props.profile.displayName : props.profile.name)
const image = computed(() => creator.value ? props.profile.avatarUrl || CREATOR_PLACEHOLDER : props.profile.logoUrl)
const country = computed(() => props.countryOptions.find((item) => item.value === props.profile.countryCode)?.label)
const location = computed(() => [props.profile.city, country.value].filter(Boolean).join(', '))
const metrics = computed(() => [
  { tab: 'social', icon: UsersRound, label: t('account.socialProfiles'), count: props.profile.socialProfiles?.length || 0 },
  { tab: 'portfolio', icon: Images, label: t('account.portfolio'), count: `${props.profile.portfolio?.length || 0}/4` },
  { tab: 'packages', icon: Handshake, label: t('account.packages'), count: props.profile.packages?.length || 0 },
  { tab: 'faqs', icon: MessageCircleQuestion, label: t('account.creatorFaqs'), count: props.profile.faqs?.length || 0 },
])
</script>

<template>
  <section class="account-profile-summary" :class="{ 'account-profile-summary--company': !creator }">
    <div class="account-profile-summary__identity">
      <div class="account-profile-summary__avatar">
        <img v-if="image" :src="image" :alt="name" />
        <WaveLogo v-else mark />
        <button type="button" :aria-label="t('account.changeProfileImage')" @click="$emit('edit')"><Camera :size="19" aria-hidden="true" /></button>
      </div>
      <div class="account-profile-summary__copy">
        <p class="eyebrow">{{ creator ? t('account.creatorProfile') : t('account.companyProfile') }}</p>
        <h2>{{ name || t('account.title') }}</h2>
        <p class="account-profile-summary__email">{{ user.email }}</p>
        <p v-if="location" class="account-profile-summary__location"><MapPin :size="18" aria-hidden="true" />{{ location }}</p>
        <LocalizedLink v-if="profile.slug" class="account-profile-summary__public" :to="{ name: creator ? 'creator-profile' : 'company-profile', params: { slug: profile.slug } }">
          {{ t('account.viewPublicProfile') }}<ExternalLink :size="15" aria-hidden="true" />
        </LocalizedLink>
      </div>
      <button class="account-profile-summary__edit" type="button" @click="$emit('edit')"><Pencil :size="15" aria-hidden="true" />{{ t('account.editProfile') }}</button>
    </div>
    <div v-if="creator" class="account-profile-summary__metrics">
      <button v-for="metric in metrics" :key="metric.tab" type="button" @click="$emit('select-tab', metric.tab)">
        <span class="account-profile-summary__metric-icon"><component :is="metric.icon" :size="22" aria-hidden="true" /></span>
        <strong>{{ metric.count }}</strong><span>{{ metric.label }}</span>
      </button>
    </div>
    <div class="account-profile-summary__visibility">
      <SwitchField :model-value="user.hide_my_account" :label="t('account.hideAccount')" :description="t('account.hideAccountHint')" :disabled="visibilitySaving" @update:model-value="$emit('visibility', $event)" />
      <SwitchField :model-value="user.notificationsEnabled === false ? 0 : 1" :label="t('app.notifications')" :description="t('account.notificationsHint')" :disabled="notificationsSaving || !user.emailVerified" @update:model-value="$emit('notifications', $event === 1)" />
    </div>
  </section>
</template>

<style lang="scss" src="../../scss/components/account/AccountProfileSummary.scss"></style>
