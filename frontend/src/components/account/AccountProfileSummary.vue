<script setup>
import PresentationDemoBadge from '../shared/PresentationDemoBadge.vue'
import { computed, ref, watch } from 'vue'
import PublicUrlAction from './PublicUrlAction.vue'
import PasswordChangeAction from './PasswordChangeAction.vue'
import RatingSummary from '../reviews/RatingSummary.vue'
import { loadCurrentUser } from '../../composables/useCurrentUser'
import { apiGet, formatMoney } from '../../lib/api'
import { Camera, ExternalLink, Handshake, MapPin, MessageCircleQuestion, Images, UsersRound } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import LocalizedLink from '../shared/LocalizedLink.vue'
import CreditBalanceLink from './CreditBalanceLink.vue'
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
defineEmits(['edit', 'select-tab', 'visibility', 'notifications', 'url-changed'])
const { t } = useI18n()
const financial = ref(null)
const financialError = ref('')
let financialGeneration = 0
async function loadFinancial() {
  const generation = ++financialGeneration
  financial.value = null
  financialError.value = ''
  if (!props.user.approved) return
  try {
    const response = await apiGet('/me/collaboration-summary')
    if (generation !== financialGeneration) return
    if (response.userId !== props.user.id) {
      await loadCurrentUser()
      return
    }
    financial.value = response.data
  } catch (cause) { if (generation === financialGeneration) financialError.value = cause.message }
}
watch(() => [props.user.id, props.user.approved], loadFinancial, { immediate: true })
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
      <div class="account-profile-summary__portrait">
        <div class="account-profile-summary__avatar">
          <img v-if="image" :src="image" :alt="name" />
          <WaveLogo v-else mark />
          <button type="button" :aria-label="t('account.changeProfileImage')" @click="$emit('edit')"><Camera :size="19" aria-hidden="true" /></button>
        </div>
        <CreditBalanceLink v-if="user.approved || user.isAdmin" class="account-profile-summary__credits" />
      </div>
      <div class="account-profile-summary__copy">
      <PresentationDemoBadge v-if="user.isPresentationDemo" compact />
        <p class="eyebrow">{{ creator ? t('account.creatorProfile') : t('account.companyProfile') }}</p>
        <h2>{{ name || t('account.title') }}</h2>
        <p class="account-profile-summary__email">{{ user.email }}</p>
        <p v-if="location" class="account-profile-summary__location"><MapPin :size="18" aria-hidden="true" />{{ location }}</p>
        <RatingSummary v-if="financial" :rating="financial.rating" />
        <div v-if="financial" class="account-profile-summary__financial">
          <span>{{ t(creator ? 'reviews.earnings' : 'reviews.spending') }}</span>
          <strong v-for="total in financial.totals" :key="total.currency">{{ formatMoney(total.amount, total.currency) }}</strong>
          <strong v-if="!financial.totals.length">0</strong>
          <small>{{ t('reviews.financialHint') }}</small>
        </div>
        <p v-if="financialError" class="account-profile-summary__financial-error" role="status">{{ financialError }} <button type="button" @click="loadFinancial">{{ t('reviews.retry') }}</button></p>
        <LocalizedLink v-if="profile.slug" class="account-profile-summary__public" :to="{ name: creator ? 'creator-profile' : 'company-profile', params: { slug: profile.slug } }">
          {{ t('account.viewPublicProfile') }}<ExternalLink :size="15" aria-hidden="true" />
        </LocalizedLink>
      </div>
    </div>
    <div v-if="creator" class="account-profile-summary__metrics">
      <button v-for="metric in metrics" :key="metric.tab" type="button" @click="$emit('select-tab', metric.tab)">
        <span class="account-profile-summary__metric-icon"><component :is="metric.icon" :size="22" aria-hidden="true" /></span>
        <strong>{{ metric.count }}</strong><span>{{ metric.label }}</span>
      </button>
    </div>
    <div class="account-profile-summary__visibility">
      <SwitchField :model-value="user.hide_my_account" :label="t('account.hideAccount')" :description="t(user.approved ? 'account.hideAccountHint' : 'account.visibilityPendingHint')" :disabled="visibilitySaving || !user.approved || user.profileComplete === false || !user.emailVerified" @update:model-value="$emit('visibility', $event)" />
      <SwitchField :model-value="user.notificationsEnabled === false ? 0 : 1" :label="t('app.notifications')" :description="t('account.notificationsHint')" :disabled="notificationsSaving || !user.emailVerified" @update:model-value="$emit('notifications', $event === 1)" />
      <div class="account-profile-summary__actions">
        <PasswordChangeAction :email="user.email" row />
        <PublicUrlAction :profile="profile" :kind="creator ? 'creator' : 'company'" row @changed="$emit('url-changed', $event)" />
      </div>
    </div>
  </section>
</template>

<style lang="scss" src="../../scss/components/account/AccountProfileSummary.scss"></style>
