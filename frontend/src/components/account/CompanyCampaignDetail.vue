<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import { ArrowRight, CalendarDays, MapPin, Megaphone, MessageCircle, Users } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import AccountProfileCard from './AccountProfileCard.vue'
import CampaignHiringProgress from '../campaigns/CampaignHiringProgress.vue'
import CardImage from '../shared/CardImage.vue'
import DirectoryPagination from '../shared/DirectoryPagination.vue'
import DirectorySearch from '../shared/DirectorySearch.vue'
import LocalizedLink from '../shared/LocalizedLink.vue'
import RichTextContent from '../shared/RichTextContent.vue'
import StatusMessage from '../shared/StatusMessage.vue'
import { formatDate, formatMoney } from '../../lib/api'
import { createChatTimeFormatter } from '../../lib/chatTime'
import { campaignPlace, CAMPAIGN_PLACEHOLDER, CREATOR_PLACEHOLDER } from '../../lib/marketplace'

const props = defineProps({
  campaign: { type: Object, required: true },
  applications: { type: Array, default: () => [] },
})
const emit = defineEmits(['edit', 'view-application'])
const { t, locale } = useI18n()
const query = ref('')
const page = ref(1)
const heading = ref(null)
const pageSize = 10
const timeFormatter = computed(() => createChatTimeFormatter(locale.value, t))
const campaignAreas = computed(() => props.campaign.categoryLabels?.length
  ? props.campaign.categoryLabels
  : props.campaign.categories?.length ? props.campaign.categories : [props.campaign.categoryLabel || props.campaign.category].filter(Boolean))
const filteredApplications = computed(() => {
  const search = query.value.trim().toLocaleLowerCase(locale.value)
  return props.applications.filter(({ creator, message }) => !search || [
    creator.displayName, creator.categoryLabel || creator.category, creator.city, message,
  ].some(value => (value || '').toLocaleLowerCase(locale.value).includes(search)))
})
const pageApplications = computed(() => filteredApplications.value.slice((page.value - 1) * pageSize, page.value * pageSize))

watch(query, () => { page.value = 1 })
watch(() => props.campaign.slug, () => { query.value = ''; page.value = 1 })
watch(() => filteredApplications.value.length, (count) => {
  page.value = Math.min(page.value, Math.max(1, Math.ceil(count / pageSize)))
})
watch(page, async () => {
  await nextTick()
  heading.value?.focus({ preventScroll: true })
  heading.value?.scrollIntoView({ block: 'start' })
})

function statusGroup(application) {
  if (application.status === 'accepted') return 'accepted'
  if (['rejected', 'offer_declined'].includes(application.status)) return 'rejected'
  return 'pending'
}

function statusLabel(application) {
  return t(`account.${application.status === 'offer_declined' ? 'offerDeclined' : application.status}`)
}

function chatRoute(application) {
  return { name: 'messages', query: { conversation: application.conversationId } }
}
</script>

<template>
  <div class="company-campaign-detail account-activity-panel">
    <AccountProfileCard :title="t('account.campaignDetails')" :icon="Megaphone" editable @edit="emit('edit', campaign)">
      <div class="company-campaign-detail__overview">
        <div class="company-campaign-detail__cover"><CardImage :image="campaign.coverImage" :src="campaign.coverImageUrl || CAMPAIGN_PLACEHOLDER" :alt="campaign.title" sizes="(max-width: 760px) 90vw, 320px" /></div>
        <div class="company-campaign-detail__content">
          <p class="company-campaign-detail__category">{{ campaignAreas.join(', ') }}</p>
          <h1>{{ campaign.title }}</h1>
          <p class="company-campaign-detail__summary">{{ campaign.summary }}</p>
          <RichTextContent :html="campaign.description" />
          <dl class="company-campaign-detail__meta">
            <div v-if="campaignPlace(campaign, locale)"><dt class="sr-only">{{ t('campaignDetail.location') }}</dt><dd><MapPin :size="19" aria-hidden="true" />{{ campaignPlace(campaign, locale) }}</dd></div>
            <div><dt class="sr-only">{{ t('account.creatorCount') }}</dt><dd><Users :size="19" aria-hidden="true" /><CampaignHiringProgress :campaign="campaign" /></dd></div>
            <div v-if="campaign.closesAt"><CalendarDays :size="19" aria-hidden="true" /><dt>{{ t('account.closesAt') }}</dt><dd><time :datetime="campaign.closesAt">{{ formatDate(campaign.closesAt) }}</time></dd></div>
          </dl>
        </div>
        <dl class="company-campaign-detail__facts">
          <div><dt>{{ t('account.budgetMin') }} – {{ t('account.budgetMax') }}</dt><dd>{{ formatMoney(campaign.budgetMin, campaign.currency) }} – {{ formatMoney(campaign.budgetMax, campaign.currency) }}</dd></div>
          <div><dt>{{ t('campaignDetail.channels') }}</dt><dd>{{ campaign.channels.join(', ') }}</dd></div>
          <div><dt>{{ t('account.deliverables') }}</dt><dd><ul><li v-for="(item, index) in campaign.deliverables" :key="index">{{ item }}</li></ul></dd></div>
        </dl>
      </div>
    </AccountProfileCard>

    <section class="creator-offers company-campaign-applicants" aria-labelledby="company-campaign-applicants-title">
      <header class="creator-offers__heading">
        <h2 id="company-campaign-applicants-title" ref="heading" tabindex="-1">{{ t('account.campaignApplicants') }}</h2>
        <p>{{ t('account.campaignApplicantsIntro') }}</p>
      </header>
      <div class="creator-offers__toolbar">
        <DirectorySearch v-model="query" :label="t('account.searchApplications')" :placeholder="t('account.searchApplications')" />
      </div>
      <StatusMessage v-if="!applications.length" variant="empty">{{ t('account.noCampaignApplications') }}</StatusMessage>
      <StatusMessage v-else-if="!filteredApplications.length" variant="empty">{{ t('account.activityNoResults') }}</StatusMessage>
      <div v-else class="creator-offers__table-wrap">
        <table class="creator-offers__table">
          <caption class="sr-only">{{ t('account.campaignApplicants') }}</caption>
          <thead><tr>
            <th scope="col">{{ t('auth.creator') }}</th>
            <th scope="col">{{ t('account.applicationDate') }}</th>
            <th scope="col">{{ t('account.activityStatus') }}</th>
            <th scope="col" class="creator-offers__action-cell">{{ t('account.activityActions') }}</th>
          </tr></thead>
          <tbody>
            <tr v-for="application in pageApplications" :key="application.id">
              <td class="creator-offers__campaign">
                <div class="creator-offers__campaign-identity">
                  <CardImage :image="application.creator.avatarImage" :src="application.creator.avatarUrl || CREATOR_PLACEHOLDER" alt="" sizes="48px" />
                  <div><button type="button" @click="emit('view-application', application)">{{ application.creator.displayName }}</button><p>{{ application.creator.categoryLabel || application.creator.category }}<template v-if="application.creator.city"> · {{ application.creator.city }}</template></p></div>
                </div>
              </td>
              <td><span class="creator-offers__mobile-label">{{ t('account.applicationDate') }}</span><span v-if="application.createdAt" class="creator-offers__deadline"><CalendarDays :size="17" aria-hidden="true" /><time :datetime="application.createdAt">{{ formatDate(application.createdAt) }}</time></span><small v-if="application.createdAt" class="company-campaign-applicants__time">{{ timeFormatter.ago(application.createdAt, Date.now()) }}</small></td>
              <td><span class="creator-offer__status" :class="`creator-offer__status--${statusGroup(application)}`"><i :class="`activity-dot activity-dot--${statusGroup(application)}`" aria-hidden="true"></i>{{ statusLabel(application) }}</span></td>
              <td class="creator-offers__action-cell"><div class="creator-offers__actions">
                <LocalizedLink v-if="application.conversationId" class="button button--outline" :to="chatRoute(application)"><MessageCircle :size="16" aria-hidden="true" />{{ t('account.activityChat') }}</LocalizedLink>
                <button class="button button--dark" type="button" :aria-label="`${t('account.viewApplication')}: ${application.creator.displayName}`" @click="emit('view-application', application)">{{ t('account.viewApplication') }}<ArrowRight :size="16" aria-hidden="true" /></button>
              </div></td>
            </tr>
          </tbody>
        </table>
      </div>
      <DirectoryPagination v-if="filteredApplications.length" v-model:page="page" :page-size="pageSize" :show-page-size="false" :show-single-page="true" :total="filteredApplications.length" :status-label="t('account.activityShown', { shown: pageApplications.length, total: filteredApplications.length })" />
    </section>
  </div>
</template>

<style lang="scss" src="../../scss/components/account/CreatorCampaignActivity.scss"></style>
<style scoped lang="scss" src="../../scss/components/account/CompanyCampaignDetail.scss"></style>
