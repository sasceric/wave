<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ArrowRight, Building2, CirclePlus, FolderKanban, Mail, Megaphone, MessageCircle, Send, UserRound, UserRoundPen, UsersRound } from '@lucide/vue'
import AudienceCard from '../components/shared/AudienceCard.vue'
import CardRow from '../components/shared/CardRow.vue'
import PromoSlider from '../components/shared/PromoSlider.vue'
import ContactFormModal from '../components/shared/ContactFormModal.vue'
import CountryDirectoryLinks from '../components/shared/CountryDirectoryLinks.vue'
import DirectorySkeletonCard from '../components/shared/DirectorySkeletonCard.vue'
import FaqSection from '../components/shared/FaqSection.vue'
import HeaderCreatorSearch from '../components/shared/HeaderCreatorSearch.vue'
import LocalizedLink from '../components/shared/LocalizedLink.vue'
import SegmentedControl from '../components/shared/SegmentedControl.vue'
import ShortcutTile from '../components/shared/ShortcutTile.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import CampaignCard from '../components/campaigns/CampaignCard.vue'
import CreatorCard from '../components/creators/CreatorCard.vue'
import { currentUser } from '../composables/useCurrentUser'
import { unreadMessageCount } from '../composables/useUnreadMessages'
import { apiGet } from '../lib/api'
import { HOME_IMAGE_SIZES } from '../lib/listingImage'
import { rememberRegistrationIntent } from '../lib/registrationIntent'
import { localizedPath } from '../routePaths'

const { t, locale } = useI18n()
const creators = ref([])
const campaigns = ref([])
const creatorsError = ref('')
const campaignsError = ref('')
const loading = ref(true)
const creatorMode = ref('latest')
const contactOpen = ref(false)
const stepsAudience = ref('creator')

const siteFaqItems = computed(() => Array.from({ length: 5 }, (_, index) => ({
  id: `site-${index + 1}`,
  question: t(`siteFaq.question${index + 1}`),
  answer: t(`siteFaq.answer${index + 1}`),
})))
const greetingName = computed(() => currentUser.value?.profile?.displayName || currentUser.value?.profile?.name || currentUser.value?.name || '')
const isCompany = computed(() => currentUser.value?.accountType === 'company')
const shortcuts = computed(() => {
  const messages = {
    key: 'messages',
    to: { name: 'messages' },
    icon: MessageCircle,
    title: t('app.messages'),
    description: t('home.shortcutMessagesText'),
    badge: unreadMessageCount.value,
  }
  if (isCompany.value) {
    return [
      { key: 'new', to: { name: 'account-campaign-create' }, icon: CirclePlus, title: t('home.postCampaign'), description: t('home.shortcutNewCampaignText') },
      { key: 'campaigns', to: { name: 'account-campaigns' }, icon: FolderKanban, title: t('home.shortcutMyCampaigns'), description: t('home.shortcutMyCampaignsText') },
      messages,
      { key: 'creators', to: '/creators', icon: UsersRound, title: t('home.findCreator'), description: t('home.shortcutCreatorsText') },
    ]
  }
  return [
    { key: 'campaigns', to: '/campaigns', icon: Megaphone, title: t('home.exploreCampaigns'), description: t('home.shortcutCampaignsText') },
    { key: 'applications', to: { name: 'account-applications' }, icon: Send, title: t('app.footerApplications'), description: t('home.shortcutApplicationsText') },
    messages,
    { key: 'profile', to: { name: 'account' }, icon: UserRoundPen, title: t('app.myProfile'), description: t('home.shortcutProfileText') },
  ]
})
const audienceOptions = computed(() => [
  { value: 'creator', label: t('home.forCreators') },
  { value: 'brand', label: t('home.forBrands') },
])
const steps = computed(() => [1, 2, 3].map(index => ({
  title: t(`home.${stepsAudience.value}Step${index}Title`),
  description: t(`home.${stepsAudience.value}Step${index}Text`),
})))
const campaignCreatePath = computed(() => localizedPath('account-campaign-create', locale.value))
const registerCreator = { name: 'register', query: { accountType: 'creator' } }
const registerCompany = computed(() => ({ name: 'register', query: { accountType: 'company', returnTo: campaignCreatePath.value } }))
const creatorImage = {
  src: '/images/home-faq-creator-480.webp',
  srcset: '/images/home-faq-creator-320.webp 320w, /images/home-faq-creator-480.webp 480w, /images/home-faq-creator.webp 700w',
  sizes: '(max-width: 1100px) calc(100vw - 40px), 260px',
  width: 700,
  height: 875,
}
const brandImage = {
  src: '/images/how-it-works-company.webp',
  sizes: '(max-width: 1100px) calc(100vw - 40px), 260px',
  width: 600,
  height: 900,
}

const promoSlides = computed(() => [
  {
    key: 'creators',
    to: '/creators',
    title: t('home.promoCreatorsTitle'),
    text: t('home.promoCreatorsText'),
    image: {
      src: '/images/sunlit-profile-768.webp',
      srcset: '/images/sunlit-profile-480.webp 480w, /images/sunlit-profile-768.webp 768w, /images/sunlit-profile.webp 1122w',
      sizes: '(max-width: 760px) calc(100vw - 40px), 50vw',
      width: 1122,
      height: 1402,
      position: '50% 28%',
    },
  },
  {
    key: 'campaigns',
    to: '/campaigns',
    title: t('home.promoCampaignsTitle'),
    text: t('home.promoCampaignsText'),
    image: { src: '/images/companies-hero.webp', sizes: '(max-width: 760px) calc(100vw - 40px), 50vw', width: 900, height: 600, position: '60% 50%' },
  },
])

async function loadHome() {
  loading.value = true
  creatorsError.value = ''
  campaignsError.value = ''
  try {
    const response = await apiGet('/homepage')
    creatorMode.value = response.data.creatorMode
    creators.value = response.data.creators
    campaigns.value = response.data.campaigns
  } catch (cause) {
    creatorsError.value = cause.message
    campaignsError.value = cause.message
  } finally {
    loading.value = false
  }
}

watch(locale, loadHome)
onMounted(loadHome)
</script>

<template>
  <div class="home">
    <section class="home-hero home-hero--search page-width" :aria-labelledby="currentUser ? 'home-title' : undefined" :aria-label="currentUser ? undefined : t('globalSearch.search')">
      <h1 v-if="currentUser" id="home-title">
        <template v-if="greetingName">{{ t('home.greetingLead') }} <em>{{ greetingName }}.</em></template>
        <template v-else>{{ t('home.heroTitleLead') }} <em>{{ t('home.heroTitleEmphasis') }}</em></template>
      </h1>
      <div class="home-hero__search"><HeaderCreatorSearch variant="hero" /></div>
    </section>

    <section v-if="currentUser" class="home-shortcuts page-width" :aria-label="t('home.shortcutsLabel')">
      <ShortcutTile
        v-for="item in shortcuts"
        :key="item.key"
        :to="item.to"
        :icon="item.icon"
        :title="item.title"
        :description="item.description"
        :badge="item.badge || 0"
        :badge-label="item.badge ? `${item.title} (${item.badge})` : ''"
      />
    </section>
    <PromoSlider class="home-promos page-width" :slides="promoSlides" :label="t('home.promoLabel')" />

    <CardRow
      class="home-row page-width"
      kind="creator"
      :title="creatorMode === 'featured' ? t('home.featuredCreatorsTitle') : t('home.creatorsRowTitle')"
      :description="t('home.creatorsRowText')"
      link-to="/creators"
      :link-label="t('home.allCreators')"
      :link-short-label="t('home.allCreatorsShort')"
      :busy="loading"
      :show-grid="!creatorsError && Boolean(creators.length || loading)"
    >
      <template #status>
        <StatusMessage v-if="creatorsError" variant="error">{{ creatorsError }}</StatusMessage>
        <StatusMessage v-else-if="!loading && !creators.length" variant="empty">{{ t('home.emptyCreators') }}</StatusMessage>
      </template>
      <CreatorCard v-for="creator in creators" :key="creator.id" :creator="creator" :image-sizes="HOME_IMAGE_SIZES" />
      <DirectorySkeletonCard v-for="index in loading && !creators.length ? 4 : 0" :key="`loading-${index}`" kind="creator" />
      <span v-if="loading" class="sr-only" role="status">{{ t('home.loadingCreators') }}</span>
      <template #after><CountryDirectoryLinks class="home-row__countries" compact /></template>
    </CardRow>

    <CardRow
      class="home-row page-width"
      kind="campaign"
      :title="t('home.campaignsRowTitle')"
      :description="t('home.campaignsRowText')"
      link-to="/campaigns"
      :link-label="t('home.allCampaigns')"
      :link-short-label="t('home.allCampaignsShort')"
      :busy="loading"
      :show-grid="!campaignsError && Boolean(campaigns.length || loading)"
    >
      <template #status>
        <StatusMessage v-if="campaignsError" variant="error">{{ campaignsError }}</StatusMessage>
        <StatusMessage v-else-if="!loading && !campaigns.length" variant="empty">{{ t('home.emptyCampaigns') }}</StatusMessage>
      </template>
      <CampaignCard v-for="campaign in campaigns" :key="campaign.id" :campaign="campaign" :image-sizes="HOME_IMAGE_SIZES" />
      <DirectorySkeletonCard v-for="index in loading && !campaigns.length ? 4 : 0" :key="`loading-${index}`" kind="campaign" />
      <span v-if="loading" class="sr-only" role="status">{{ t('home.loadingCampaigns') }}</span>
    </CardRow>

    <section v-if="!currentUser" class="home-hero home-hero--statement page-width" aria-labelledby="home-title">
      <p class="eyebrow home-hero__eyebrow">{{ t('home.kicker') }}</p>
      <h1 id="home-title">{{ t('home.heroTitleLead') }} <em>{{ t('home.heroTitleEmphasis') }}</em></h1>
      <p class="home-hero__lede">{{ t('siteFaq.answer1') }}</p>
    </section>
    <div v-if="!currentUser" class="home-audiences page-width">
      <AudienceCard
        :eyebrow="t('home.forCreators')"
        :title="t('home.creatorsDoorTitle')"
        :items="[t('home.creatorsDoorPoint1'), t('home.creatorsDoorPoint2'), t('home.creatorsDoorPoint3')]"
        :icon="UserRound"
        :image="creatorImage"
      >
        <template #actions>
          <LocalizedLink class="button button--dark" :to="registerCreator" @click="rememberRegistrationIntent('', 'creator')">{{ t('home.createProfile') }}</LocalizedLink>
          <LocalizedLink class="button button--tonal" to="/campaigns">{{ t('home.exploreCampaigns') }}</LocalizedLink>
        </template>
      </AudienceCard>
      <AudienceCard
        dark
        :eyebrow="t('home.forBrandsAndAgencies')"
        :title="t('home.brandsDoorTitle')"
        :items="[t('home.brandsDoorPoint1'), t('home.brandsDoorPoint2'), t('home.brandsDoorPoint3')]"
        :icon="Building2"
        :image="brandImage"
      >
        <template #actions>
          <LocalizedLink class="button button--light" :to="registerCompany" @click="rememberRegistrationIntent(campaignCreatePath, 'company')">{{ t('home.postCampaign') }}</LocalizedLink>
          <LocalizedLink class="button button--glass-dark" to="/creators">{{ t('home.findCreator') }}</LocalizedLink>
        </template>
      </AudienceCard>
    </div>

    <section id="how-it-works" class="home-steps page-width" aria-labelledby="home-steps-title">
      <div class="home-steps__panel">
        <div class="home-steps__head">
          <p class="eyebrow">{{ t('home.howItWorks') }}</p>
          <h2 id="home-steps-title">{{ t('home.stepsHeading') }}</h2>
          <SegmentedControl v-model="stepsAudience" :options="audienceOptions" :label="t('home.stepsAudience')" />
        </div>
        <ol class="home-steps__list">
          <li v-for="(step, index) in steps" :key="`${stepsAudience}-${index}`">
            <span class="home-steps__number" aria-hidden="true">{{ index + 1 }}</span>
            <h3>{{ step.title }}</h3>
            <p>{{ step.description }}</p>
          </li>
        </ol>
        <div class="home-steps__footer">
          <p>{{ t('registrationPrompts.noPayments') }} {{ t('home.stepsPaymentNote') }}</p>
          <nav class="home-steps__guides" :aria-label="t('home.guidesLabel')">
            <LocalizedLink :to="{ name: 'creator-guides' }">{{ t('regionalSeo.creatorGuides.title') }} <ArrowRight :size="15" aria-hidden="true" /></LocalizedLink>
            <LocalizedLink :to="{ name: 'seo-guides' }">{{ t('regionalSeo.guideTitle') }} <ArrowRight :size="15" aria-hidden="true" /></LocalizedLink>
            <LocalizedLink :to="{ name: 'how-it-works' }">{{ t('home.howItWorksMore') }} <ArrowRight :size="15" aria-hidden="true" /></LocalizedLink>
          </nav>
          <LocalizedLink
            v-if="!currentUser"
            class="button button--dark button--small"
            :to="stepsAudience === 'creator' ? registerCreator : registerCompany"
            @click="stepsAudience === 'creator' ? rememberRegistrationIntent('', 'creator') : rememberRegistrationIntent(campaignCreatePath, 'company')"
          >
            {{ stepsAudience === 'creator' ? t('home.createProfile') : t('home.postCampaign') }}
            <ArrowRight :size="16" aria-hidden="true" />
          </LocalizedLink>
        </div>
      </div>
    </section>

    <div class="home-faq page-width">
      <FaqSection split :eyebrow="t('siteFaq.eyebrow')" :title="t('home.faqHeading')" :description="t('siteFaq.description')" :items="siteFaqItems">
        <template #actions>
          <button class="button button--tonal" type="button" @click="contactOpen = true">
            <Mail :size="16" aria-hidden="true" />
            {{ t('home.contactOpen') }}
          </button>
        </template>
      </FaqSection>
    </div>

    <ContactFormModal v-model:open="contactOpen" />
  </div>
</template>

<style lang="scss" src="../scss/views/HomeView.scss"></style>
