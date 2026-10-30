<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import LocalizedLink from '../shared/LocalizedLink.vue'
const props = defineProps({ row: { type: Object, required: true }, kind: { type: String, required: true } })
const { t } = useI18n()
const sections = computed(() => {
  if (!props.row.activity) return []
  const { row, kind } = props
  const provider = { providerType: kind === 'creators' ? 'creator' : 'agency', providerId: row.id, label: row.displayName || row.name }
  const client = { clientId: row.id, label: row.name }
  const result = []
  if (kind === 'creators' || kind === 'companies' && row.companyType === 'agency') {
    result.push({ label: 'packages', data: row.activity.packages, query: { kind: 'packages', ...provider }, keys: ['requests', 'engaged', 'completed'] })
    const campaigns = kind === 'creators' ? row.activity.campaigns : row.activity.providerCampaigns
    if (campaigns?.applications || campaigns?.engaged || campaigns?.completed) result.push({ label: 'providerCampaigns', data: campaigns, query: { kind: 'campaigns', ...provider }, keys: ['applications', 'engaged', 'completed'] })
  }
  if (kind === 'campaigns' || kind === 'companies') {
    const query = kind === 'campaigns' ? { campaignId: row.id, label: row.title } : client
    result.push({ label: kind === 'companies' ? 'clientCampaigns' : null, data: row.activity.campaigns, query: { kind: 'campaigns', ...query }, keys: ['applications', 'engaged', 'completed'] })
  }
  return result
})
</script>
<template>
  <div class="admin-collaboration-counts">
    <LocalizedLink v-if="kind === 'companies' && row.activity?.campaigns" class="admin-collaboration-counts__campaigns" :to="{ name: 'admin-campaigns', query: { companyId: row.id, companyLabel: row.name } }">{{ t('adminCollaborations.campaignCount', { count: row.activity.campaigns.campaigns }) }}</LocalizedLink>
    <div v-for="section in sections" :key="section.label || 'campaign'">
      <small v-if="section.label">{{ t(`adminCollaborations.${section.label}`) }}</small>
      <div class="admin-collaboration-counts__links">
        <LocalizedLink v-for="key in section.keys" :key="key" :to="{ name: 'admin-collaborations', query: { ...section.query, origin: key === 'applications' ? 'application' : undefined, status: key === 'applications' ? 'all' : key } }" :aria-label="`${row.displayName || row.name || row.title}: ${t(`adminCollaborations.${key}`)} ${section.data[key]}`"><span>{{ t(`adminCollaborations.${key}`) }}</span><strong>{{ section.data[key] }}</strong></LocalizedLink>
      </div>
    </div>
  </div>
</template>
<style scoped lang="scss">
.admin-collaboration-counts { display: grid; gap: 10px; min-width: 210px; small { display: block; margin-bottom: 5px; color: var(--muted-text); font-size: 10px; } &__campaigns { font-size: 11px; color: var(--forest); } &__links { display: flex; flex-wrap: wrap; gap: 7px 12px; a { display: inline-flex; align-items: center; gap: 5px; color: var(--forest); font-size: 11px; text-decoration: none; &:hover { text-decoration: underline; } &:focus-visible { outline: 2px solid var(--forest); outline-offset: 3px; } } strong { padding: 2px 5px; border-radius: 5px; background: var(--surface); font-weight: 600; } } }
</style>
