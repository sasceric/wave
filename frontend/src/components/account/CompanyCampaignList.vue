<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import { CalendarDays, Camera, CirclePlay, FileText, MapPin, Megaphone, Music2, Pencil, Plus } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import AdminRowActions from '../admin/AdminRowActions.vue'
import CampaignHiringProgress from '../campaigns/CampaignHiringProgress.vue'
import CardImage from '../shared/CardImage.vue'
import DirectoryPagination from '../shared/DirectoryPagination.vue'
import DirectorySearch from '../shared/DirectorySearch.vue'
import LocalizedLink from '../shared/LocalizedLink.vue'
import StatusMessage from '../shared/StatusMessage.vue'
import { formatDate, formatMoney } from '../../lib/api'
import { campaignPlace, CAMPAIGN_PLACEHOLDER } from '../../lib/marketplace'

const props = defineProps({ campaigns: { type: Array, default: () => [] } })
const emit = defineEmits(['edit'])
const { t, locale } = useI18n()
const query = ref('')
const statusFilter = ref('all')
const page = ref(1)
const heading = ref(null)
const pageSize = 10
const filters = computed(() => ['all', 'open', 'closed', 'finished'].map(value => ({
  value, label: t(value === 'all' ? 'account.activityAll' : `account.${value}`),
  count: value === 'all' ? props.campaigns.length : props.campaigns.filter(campaign => campaign.status === value).length,
})))
function areas(campaign) {
  return campaign.categoryLabels?.length ? campaign.categoryLabels.join(', ')
    : campaign.categories?.length ? campaign.categories.join(', ') : campaign.categoryLabel || campaign.category || ''
}
const filteredCampaigns = computed(() => {
  const search = query.value.trim().toLocaleLowerCase(locale.value)
  return props.campaigns.filter(campaign => (statusFilter.value === 'all' || campaign.status === statusFilter.value)
    && (!search || [campaign.title, campaign.summary, areas(campaign), campaign.city, ...campaign.channels].some(value => (value || '').toLocaleLowerCase(locale.value).includes(search))))
})
const pageCampaigns = computed(() => filteredCampaigns.value.slice((page.value - 1) * pageSize, page.value * pageSize))
watch([query, statusFilter], () => { page.value = 1 })
watch(() => filteredCampaigns.value.length, count => {
  page.value = Math.min(page.value, Math.max(1, Math.ceil(count / pageSize)))
})
watch(page, async () => {
  await nextTick()
  heading.value?.focus({ preventScroll: true })
  heading.value?.scrollIntoView({ block: 'start' })
})
function detailRoute(campaign) {
  return { name: 'account-campaign-detail', params: { slug: campaign.slug } }
}
function platformIcon(platform) {
  return { Instagram: Camera, TikTok: Music2, YouTube: CirclePlay }[platform] || Megaphone
}
function deadlineLabel(campaign) {
  const timestamp = new Date(campaign.closesAt).getTime()
  if (!Number.isFinite(timestamp)) return ''
  const remaining = timestamp - Date.now()
  return remaining <= 0 ? t('account.campaignDeadlinePassed') : t('account.campaignDeadlineInDays', { count: Math.ceil(remaining / 86400000) })
}
</script>

<template>
  <section class="creator-offers company-campaign-list account-activity-panel" aria-labelledby="company-campaign-list-title">
    <header class="creator-offers__heading company-campaign-list__heading">
      <div><h1 id="company-campaign-list-title" ref="heading" tabindex="-1">{{ t('account.campaigns') }}</h1><p>{{ t('account.companyCampaignsIntro') }}</p></div>
      <LocalizedLink class="button button--dark" :to="{ name: 'account-campaign-create' }"><Plus :size="18" aria-hidden="true" />{{ t('account.addCampaign') }}</LocalizedLink>
    </header>
    <div class="creator-offers__toolbar">
      <div class="creator-offers__filters" role="group" :aria-label="t('account.activityStatus')">
        <button v-for="filter in filters" :key="filter.value" type="button" :class="{ 'is-active': statusFilter === filter.value }" :aria-pressed="statusFilter === filter.value" @click="statusFilter = filter.value"><i class="activity-dot" :class="`company-campaign-list__dot--${filter.value}`" aria-hidden="true"></i>{{ filter.label }}<span>{{ filter.count }}</span></button>
      </div>
      <DirectorySearch v-model="query" :label="t('account.searchCompanyCampaigns')" :placeholder="t('account.searchCompanyCampaigns')" />
    </div>
    <StatusMessage v-if="!campaigns.length" variant="empty">{{ t('account.noCampaigns') }}</StatusMessage>
    <StatusMessage v-else-if="!filteredCampaigns.length" variant="empty">{{ t('account.activityNoResults') }}</StatusMessage>
    <div v-else class="creator-offers__table-wrap">
      <table class="creator-offers__table">
        <caption class="sr-only">{{ t('account.campaigns') }}</caption>
        <thead><tr>
          <th scope="col">{{ t('adminDashboard.campaign') }}</th>
          <th scope="col">{{ t('auth.category') }}</th>
          <th scope="col">{{ t('campaignChat.budget') }}</th>
          <th scope="col">{{ t('account.closesAt') }}</th>
          <th scope="col">{{ t('account.hiredCreators') }}</th>
          <th scope="col">{{ t('account.activityStatus') }}</th>
          <th scope="col">{{ t('account.activityActions') }}</th>
        </tr></thead>
        <tbody>
          <tr v-for="campaign in pageCampaigns" :key="campaign.id">
            <td class="company-campaign-list__campaign">
              <div class="company-campaign-list__identity">
                <div class="company-campaign-list__cover"><CardImage :image="campaign.coverImage" :src="campaign.coverImageUrl || CAMPAIGN_PLACEHOLDER" alt="" sizes="110px" /><span class="company-campaign-list__cover-status"><i class="activity-dot" :class="`company-campaign-list__dot--${campaign.status}`" aria-hidden="true"></i>{{ t(`account.${campaign.status}`) }}</span></div>
                <div class="company-campaign-list__copy">
                  <LocalizedLink :to="detailRoute(campaign)">{{ campaign.title }}</LocalizedLink>
                  <p>{{ campaign.summary }}</p>
                  <div class="company-campaign-list__meta"><span v-for="channel in campaign.channels" :key="channel"><component :is="platformIcon(channel)" :size="15" aria-hidden="true" />{{ channel }}</span></div>
                  <div class="company-campaign-list__meta"><span v-if="campaignPlace(campaign, locale)"><MapPin :size="16" aria-hidden="true" />{{ campaign.city || campaignPlace(campaign, locale) }}</span><span v-if="campaign.deliverables?.length"><FileText :size="16" aria-hidden="true" />{{ campaign.deliverables.join(', ') }}</span></div>
                </div>
              </div>
            </td>
            <td><span class="creator-offers__mobile-label">{{ t('auth.category') }}</span>{{ areas(campaign) }}</td>
            <td class="creator-offers__budget"><span class="creator-offers__mobile-label">{{ t('campaignDetail.budget') }}</span><strong>{{ formatMoney(campaign.budgetMin, campaign.currency) }} – {{ formatMoney(campaign.budgetMax, campaign.currency) }}</strong></td>
            <td><span class="creator-offers__mobile-label">{{ t('account.closesAt') }}</span><span v-if="campaign.closesAt" class="creator-offers__deadline"><CalendarDays :size="17" aria-hidden="true" /><time :datetime="campaign.closesAt">{{ formatDate(campaign.closesAt) }}</time></span><small class="company-campaign-list__deadline">{{ deadlineLabel(campaign) }}</small></td>
            <td class="company-campaign-list__hired"><span class="creator-offers__mobile-label">{{ t('account.hiredCreators') }}</span><CampaignHiringProgress :campaign="campaign" /></td>
            <td><span class="creator-offer__status" :class="`company-campaign-list__status--${campaign.status}`">{{ t(`account.${campaign.status}`) }}</span></td>
            <td><div class="creator-offers__actions">
              <LocalizedLink class="button button--outline" :to="detailRoute(campaign)">{{ t('account.viewCampaign') }}</LocalizedLink>
              <AdminRowActions :label="`${t('account.activityActions')}: ${campaign.title}`">
                <button class="admin-row-actions__item" role="menuitem" type="button" @click="emit('edit', campaign)"><Pencil :size="16" aria-hidden="true" />{{ t('account.editCampaign') }}</button>
                <LocalizedLink class="admin-row-actions__item" role="menuitem" :to="{ name: 'campaign-detail', params: { slug: campaign.slug } }">{{ t('campaignCard.viewBrief') }}</LocalizedLink>
              </AdminRowActions>
            </div></td>
          </tr>
        </tbody>
      </table>
    </div>
    <DirectoryPagination v-if="filteredCampaigns.length" v-model:page="page" :page-size="pageSize" :show-page-size="false" :show-single-page="true" :total="filteredCampaigns.length" :status-label="t('account.activityShown', { shown: pageCampaigns.length, total: filteredCampaigns.length })" />
  </section>
</template>

<style lang="scss" src="../../scss/components/account/CreatorCampaignActivity.scss"></style>
<style scoped lang="scss" src="../../scss/components/account/CompanyCampaignList.scss"></style>
