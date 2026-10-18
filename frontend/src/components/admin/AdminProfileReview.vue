<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiGet, formatDate } from '../../lib/api'
import { currentUser } from '../../composables/useCurrentUser'
import FormModal from '../shared/FormModal.vue'
import StatusMessage from '../shared/StatusMessage.vue'
import RichTextContent from '../shared/RichTextContent.vue'
import ProfilePackages from '../shared/ProfilePackages.vue'
import ProfileFaqs from '../shared/ProfileFaqs.vue'
import SocialPlatformIcon from '../shared/SocialPlatformIcon.vue'
import CreatorAudience from '../creators/CreatorAudience.vue'
import CreatorWorks from '../creators/CreatorWorks.vue'
import AgencyServiceCard from '../companies/AgencyServiceCard.vue'
import AdminProfileIdentity from './AdminProfileIdentity.vue'

const props = defineProps({ open: Boolean, kind: { type: String, required: true }, id: { type: Number, default: null } })
const emit = defineEmits(['update:open'])
const { t, locale } = useI18n()
const data = ref(null), loading = ref(false), error = ref('')
let generation = 0
const profile = computed(() => data.value?.profile || {})
const name = computed(() => profile.value.displayName || profile.value.name || data.value?.email || '')
function safeLink(value) {
  try { const url = new URL(value); return ['http:', 'https:'].includes(url.protocol) ? url.href : null } catch { return null }
}
const links = computed(() => (profile.value.socialLinks || []).filter(link => safeLink(link.url)))
const videoUrl = computed(() => safeLink(profile.value.presentation?.introVideoUrl))
const facts = computed(() => [
  [t('auth.email'), data.value?.email], [t('auth.phone'), data.value?.phone],
  [t('adminDashboard.emailVerification'), t(data.value?.emailVerified ? 'adminDashboard.verified' : 'adminDashboard.unverified')],
  [t('adminDashboard.approvalStatus'), t(data.value?.approved ? 'adminDashboard.approved' : 'adminDashboard.pending')],
  [t('adminProfiles.visibility'), t(data.value?.publicVisible ? 'adminProfiles.visible' : data.value?.accountId && !data.value.approved ? 'adminDashboard.pending' : 'adminProfiles.hidden')],
  [t('auth.city'), data.value?.city || profile.value.city], [t('auth.country'), data.value?.countryCode],
  [t('account.birthday'), profile.value.birthday ? formatDate(profile.value.birthday) : ''],
  [t('account.tagline'), profile.value.tagline],
  [t('adminDashboard.accountType'), data.value?.accountType === 'company' ? t(`companyTypes.options.${profile.value.companyType || 'brand'}`) : t('adminDashboard.creator')],
  [t('creatorTypes.label'), profile.value.creatorTypes?.map(key => t(`creatorTypes.options.${key}`)).join(', ')],
  [t('adminDashboard.category'), (profile.value.categoryLabels || profile.value.industryLabels || profile.value.categories || profile.value.industries || []).join(', ')],
  [t('studio.languages'), profile.value.presentation?.languages?.map(key => t(`studio.contentLanguages.${key}`)).join(', ')],
  [t('studio.availability'), profile.value.presentation?.availability ? t(`studio.${profile.value.presentation.availability}`) : ''],
  [t('studio.availableFrom'), profile.value.presentation?.availableFrom ? formatDate(profile.value.presentation.availableFrom) : ''],
].filter(([, value]) => value !== undefined && value !== null && value !== ''))

watch([() => props.open, () => props.kind, () => props.id, locale], async () => {
  const version = ++generation
  data.value = null; error.value = ''; loading.value = false
  if (!props.open || !props.id) return
  loading.value = true
  try {
    const response = await apiGet(`/admin/catalog/${props.kind}/${props.id}/profile`)
    if (version === generation) data.value = response.data
  } catch (cause) { if (version === generation) error.value = cause.message }
  finally { if (version === generation) loading.value = false }
}, { immediate: true })
watch(() => currentUser.value?.id, () => { ++generation; data.value = null; emit('update:open', false) })
onBeforeUnmount(() => { ++generation })
</script>

<template>
  <FormModal :open="open" :title="t('adminProfiles.review')" :description="t('adminProfiles.reviewHint')" wide @update:open="emit('update:open', $event)">
    <StatusMessage v-if="loading">{{ t('adminDashboard.loading') }}</StatusMessage>
    <StatusMessage v-else-if="error" variant="error">{{ error }}</StatusMessage>
    <div v-else-if="data" class="admin-profile-review">
      <AdminProfileIdentity :row="{ ...data, name }" />
      <dl class="admin-profile-review__facts"><div v-for="[label, value] in facts" :key="label"><dt>{{ label }}</dt><dd>{{ value }}</dd></div></dl>
      <section><h3>{{ t('adminProfiles.filledData') }}</h3><dl class="admin-profile-review__checks"><div v-for="(count, key) in data.completion" :key="key"><dt>{{ t(`adminProfiles.sections.${key}`) }}</dt><dd>{{ count ? t('adminProfiles.filled') : t('adminProfiles.missing') }}<template v-if="['networks', 'services', 'works', 'packages', 'faqs'].includes(key)"> · {{ count }}</template></dd></div></dl></section>
      <RichTextContent v-if="profile.bio || profile.about" :html="profile.bio || profile.about" />
      <img v-if="profile.coverUrl" class="admin-profile-review__cover" :src="profile.coverUrl" :alt="t('account.companyCover')" loading="lazy" />
      <p v-if="profile.tags?.length">{{ profile.tags.join(', ') }}</p>
      <section v-if="profile.services?.length"><h3>{{ t('agency.services') }}</h3><AgencyServiceCard v-for="service in profile.services" :key="service.id" :service="service" /></section>
      <section v-if="links.length"><h3>{{ t('adminProfiles.sections.networks') }}</h3><a v-for="link in links" :key="link.platform" :href="safeLink(link.url)" target="_blank" rel="noopener noreferrer"><SocialPlatformIcon :platform="link.platform" :size="16" />{{ link.platform }} · {{ link.url }}</a></section>
      <CreatorAudience v-if="profile.socialProfiles?.length" :profiles="profile.socialProfiles" :languages="profile.presentation?.languages || []" />
      <section v-if="profile.portfolio?.length">
        <p class="admin-profile-review__note">{{ t('adminProfiles.privateWorksHint') }}</p>
        <ul><li v-for="(work, index) in profile.portfolio" :key="work.id || index">{{ work.title }} · {{ t(work.publicPermission === false ? 'adminProfiles.hidden' : 'adminProfiles.visible') }}</li></ul>
        <CreatorWorks :items="profile.portfolio" :name="name" layout="grid" />
      </section>
      <ProfilePackages v-if="profile.packages?.length" :items="profile.packages" :agency="data.accountType === 'company'" />
      <ProfileFaqs v-if="profile.faqs?.length" :items="profile.faqs" />
      <a v-if="videoUrl" :href="videoUrl" target="_blank" rel="noopener noreferrer">{{ t('studio.introVideo') }}</a>
    </div>
    <template #actions="{ close }"><button class="button button--outline" type="button" @click="close">{{ t('reviews.close') }}</button></template>
  </FormModal>
</template>

<style scoped lang="scss">
.admin-profile-review {
  display: grid; gap: 22px; min-width: 0; overflow-wrap: anywhere;
  h3 { margin: 0 0 12px; color: var(--forest); font-size: 16px; }
  section { display: grid; gap: 10px; min-width: 0; }
  a { display: flex; align-items: center; gap: 7px; font-size: 12px; color: var(--forest); }
  svg { flex-shrink: 0; }
  &__facts, &__checks { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 13px; margin: 0; font-size: 12px; dt { color: var(--muted); margin-bottom: 4px; } dd { margin: 0; } }
  &__cover { width: 100%; max-height: 180px; object-fit: cover; border-radius: 10px; }
  &__note, li { font-size: 12px; line-height: 1.6; color: var(--muted); margin: 0; }
  ul { padding-left: 18px; margin: 0; }
  @media (max-width: 500px) { &__facts, &__checks { grid-template-columns: minmax(0, 1fr); } }
}
</style>
