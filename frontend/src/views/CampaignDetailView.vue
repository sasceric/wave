<script setup>
import { onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import RouterLink from '../components/shared/LocalizedLink.vue'
import { useI18n } from 'vue-i18n'
import LoadingSkeleton from '../components/shared/LoadingSkeleton.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import WaveLogo from '../components/shared/WaveLogo.vue'
import { apiGet, apiRequest, formatDate, formatMoney } from '../lib/api'
import { getSeoOrigin, updateSeo } from '../lib/seo'
import { localizedPath } from '../routePaths'

const route = useRoute()
const campaign = ref(null)
const error = ref('')
const applyError = ref('')
const applicationMessage = ref('')
const applicationSent = ref(false)
const alreadyApplied = ref(false)
const user = ref(null)
const { t, locale } = useI18n()

async function loadCampaign() {
  const slug = route.params.slug
  campaign.value = null
  error.value = ''
  applyError.value = ''
  applicationSent.value = false
  alreadyApplied.value = false
  try {
    const [response] = await Promise.all([
      apiGet(`/campaigns/${encodeURIComponent(slug)}`),
      apiGet('/auth/me').then(({ data }) => (user.value = data)).catch(() => (user.value = null)),
    ])
    campaign.value = response.data

    if (user.value?.accountType === 'creator') {
      try {
        const applicationsResponse = await apiGet('/me/applications')
        alreadyApplied.value = applicationsResponse.data.some(
          (application) => application.campaign.slug === slug,
        )
      } catch (cause) {
        applyError.value = cause.message
      }
    }
  } catch (cause) {
    error.value = cause.message
  }
}

async function applyToCampaign() {
  applyError.value = ''
  try {
    await apiRequest(`/campaigns/${encodeURIComponent(route.params.slug)}/applications`, {
      method: 'POST',
      body: { message: applicationMessage.value },
    })
    applicationSent.value = true
    applicationMessage.value = ''
  } catch (cause) {
    applyError.value = cause.message
  }
}

watch([() => route.params.slug, locale], loadCampaign)
watch(
  [campaign, locale],
  ([value]) => {
    if (!value) return

    updateSeo({
      route,
      locale: locale.value,
      title: t('seo.campaignProfileTitle', { name: value.title }),
      description: value.summary,
      mainEntity: {
        '@type': 'CreativeWork',
        name: value.title,
        description: value.summary,
        datePublished: value.publishedAt,
        expires: value.closesAt,
        provider: {
          '@type': 'Organization',
          name: value.company.name,
          url: new URL(
            localizedPath('company-profile', locale.value, { slug: value.company.slug }),
            getSeoOrigin(),
          ).href,
        },
      },
    })
  },
)
onMounted(loadCampaign)
</script>

<template>
  <section v-if="error" class="page-width profile-error">
    <StatusMessage variant="error">{{ error }}</StatusMessage>
    <RouterLink class="text-link" to="/campaigns">
      {{ t('campaignDetail.back') }} ↗
    </RouterLink>
  </section>
  <LoadingSkeleton v-else-if="!campaign" variant="campaign" :label="t('campaignDetail.loading')" />
  <template v-else>
    <section class="brief-hero">
      <div class="page-width brief-hero__inner">
        <RouterLink class="back-link" to="/campaigns">{{ t('campaignDetail.back') }}</RouterLink>
        <p class="eyebrow">{{ campaign.categoryLabel || campaign.category }} · {{ t('campaignDetail.openCampaign') }}</p>
        <h1>{{ campaign.title }}</h1>
        <p>{{ campaign.summary }}</p>
        <RouterLink
          class="brief-hero__brand brief-hero__brand-link"
          :to="{ name: 'company-profile', params: { slug: campaign.company.slug } }"
        >
          <span class="brand-avatar brand-avatar--large"><WaveLogo mark /></span>
          <span>
            {{ campaign.company.name }}
            <small>{{ campaign.company.industry }}</small>
          </span>
          <span
            v-if="campaign.company.verified"
            class="verified-mark"
            :aria-label="t('campaignCard.verified')"
          >✓</span>
        </RouterLink>
      </div>
    </section>
    <section class="brief-layout page-width">
      <article class="brief-copy">
        <p class="eyebrow">{{ t('campaignDetail.idea') }}</p>
        <h2>{{ t('campaignDetail.ideaHeadline') }}</h2>
        <p>{{ campaign.description }}</p>
        <div class="brief-copy__section">
          <p class="eyebrow">{{ t('campaignDetail.deliverables') }}</p>
          <ul><li v-for="deliverable in campaign.deliverables" :key="deliverable">{{ deliverable }}</li></ul>
        </div>
        <div class="brief-copy__section">
          <p class="eyebrow">{{ t('campaignDetail.channels') }}</p>
          <div class="profile-tags"><span v-for="channel in campaign.channels" :key="channel">{{ channel }}</span></div>
        </div>
      </article>
      <aside class="brief-aside">
        <div class="brief-aside__top"><span class="eyebrow">{{ t('campaignDetail.details') }}</span><span class="brief-status"><i></i> {{ t('campaignDetail.open') }}</span></div>
        <div class="brief-fact"><span>{{ t('campaignDetail.budget') }}</span><strong>{{ formatMoney(campaign.budgetMin, campaign.currency) }}–{{ formatMoney(campaign.budgetMax, campaign.currency) }}</strong></div>
        <div class="brief-fact"><span>{{ t('campaignDetail.spots') }}</span><strong>{{ campaign.creatorCount }}</strong></div>
        <div class="brief-fact"><span>{{ t('campaignDetail.location') }}</span><strong>{{ campaign.location }}</strong></div>
        <div class="brief-fact"><span>{{ t('campaignDetail.closes') }}</span><strong>{{ formatDate(campaign.closesAt) }}</strong></div>
        <div v-if="user?.accountType === 'creator' && user.emailVerified === false" class="brief-notice">
          <p>{{ t('account.emailUnverified') }}</p>
          <RouterLink class="button button--dark button--full" to="/account">{{ t('campaignDetail.verifyEmailInAccount') }} <span aria-hidden="true">↗</span></RouterLink>
        </div>
        <div v-else-if="user?.accountType === 'creator' && !user.approved" class="brief-notice" role="status">
          {{ t('account.approvalPendingNotice') }}
        </div>
        <div
          v-else-if="user?.accountType === 'creator' && alreadyApplied"
          class="brief-notice"
          role="status"
        >
          {{ t('campaignDetail.alreadyApplied') }}
        </div>
        <div v-else-if="user?.accountType === 'creator' && !applicationSent" class="brief-application">
          <label class="field-label" for="application-message">{{ t('account.applicationMessage') }}</label>
          <textarea id="application-message" v-model="applicationMessage" required minlength="20" maxlength="1200" :placeholder="t('account.applicationPlaceholder')"></textarea>
          <StatusMessage v-if="applyError" variant="error">{{ applyError }}</StatusMessage>
          <button class="button button--dark button--full" type="button" :disabled="applicationMessage.trim().length < 20" @click="applyToCampaign">{{ t('account.apply') }} <span aria-hidden="true">↗</span></button>
        </div>
        <div v-else-if="applicationSent" class="brief-notice" role="status">{{ t('account.applicationSent') }}</div>
        <div v-else-if="!user" class="brief-notice">
          <p>{{ t('account.signInToApply') }}</p>
          <RouterLink class="button button--dark button--full" to="/account">{{ t('auth.signIn') }} <span aria-hidden="true">↗</span></RouterLink>
        </div>
        <a v-else class="button button--outline button--full" :href="`mailto:hello@wave.example?subject=${encodeURIComponent(t('campaignDetail.emailSubject'))}`">{{ t('campaignDetail.askAbout') }} <span aria-hidden="true">↗</span></a>
      </aside>
    </section>
  </template>
</template>

<style lang="scss" src="../scss/views/CampaignDetailView.scss"></style>
