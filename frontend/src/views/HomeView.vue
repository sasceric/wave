<script setup>
import SingleSelect from '../components/shared/SingleSelect.vue'
import CardGrid from '../components/shared/CardGrid.vue'
import CountryDirectoryLinks from '../components/shared/CountryDirectoryLinks.vue'
import { computed, nextTick, onMounted, reactive, ref, useId, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ArrowRight, ArrowUpRight, Building2, Camera, CircleCheck, ClipboardCheck, CreditCard, FileCheck2, FileText, HelpCircle, Mail, Map, Search, ShieldCheck, UserRound, UsersRound } from '@lucide/vue'
import CampaignCard from '../components/campaigns/CampaignCard.vue'
import CreatorCard from '../components/creators/CreatorCard.vue'
import DirectorySkeletonCard from '../components/shared/DirectorySkeletonCard.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import FaqSection from '../components/shared/FaqSection.vue'
import LocalizedLink from '../components/shared/LocalizedLink.vue'
import { apiGet, apiRequest } from '../lib/api'
import { HOME_IMAGE_SIZES } from '../lib/listingImage'

const creators = ref([])
const campaigns = ref([])
const creatorsError = ref('')
const campaignsError = ref('')
const loading = ref(true)
const creatorMode = ref('latest')
const { t, locale } = useI18n()
const siteFaqIcons = [HelpCircle, UsersRound, FileText, CreditCard, ShieldCheck]
const siteFaqItems = computed(() => Array.from({ length: 5 }, (_, index) => ({
  id: `site-${index + 1}`,
  question: t(`siteFaq.question${index + 1}`),
  answer: t(`siteFaq.answer${index + 1}`),
})))
const contactForm = reactive({
  role: 'creator',
  name: '',
  email: '',
  interest: 'collaboration',
  message: '',
  trap: '',
})
const contactSending = ref(false)
const contactFeedbackDialog = ref(null)
const contactFeedbackId = useId()
const contactFeedbackType = ref('success')
const contactFeedbackMessage = ref('')

async function showContactFeedback(type, message) {
  contactFeedbackType.value = type
  contactFeedbackMessage.value = message
  await nextTick()

  if (contactFeedbackDialog.value && !contactFeedbackDialog.value.open) {
    contactFeedbackDialog.value.showModal()
  }
}

function closeContactFeedback() {
  if (contactFeedbackDialog.value?.open) {
    contactFeedbackDialog.value.close()
  }
}

async function loadHome() {
  loading.value = true
  creatorsError.value = ''
  campaignsError.value = ''
  const [homepageResult] = await Promise.allSettled([apiGet('/homepage')])

  if (homepageResult.status === 'fulfilled') {
    creatorMode.value = homepageResult.value.data.creatorMode
    creators.value = homepageResult.value.data.creators
    campaigns.value = homepageResult.value.data.campaigns
  } else {
    creatorsError.value = homepageResult.reason.message
    campaignsError.value = homepageResult.reason.message
  }

  loading.value = false
}

async function sendContactMessage() {
  contactSending.value = true

  try {
    const response = await apiRequest('/contact', {
      method: 'POST',
      body: { ...contactForm },
    })
    await showContactFeedback('success', response.message || t('home.contactSuccess'))
    Object.assign(contactForm, {
      role: 'creator',
      name: '',
      email: '',
      interest: 'collaboration',
      message: '',
      trap: '',
    })
  } catch (cause) {
    await showContactFeedback(
      'error',
      cause.status ? cause.message : t('home.contactNetworkError'),
    )
  } finally {
    contactSending.value = false
  }
}

watch(locale, loadHome)
onMounted(loadHome)
</script>

<template>
  <section class="hero">
    <div class="hero__inner page-width">
      <div class="hero__copy">
        <p class="eyebrow">{{ t('home.kicker') }}</p>
        <p class="hero-brand" aria-hidden="true">Wave<span>.</span></p>
        <h1>{{ t('home.headlineLead') }} <em>{{ t('home.headlineEmphasis') }}</em> {{ t('home.headlineTail') }}</h1>
        <p class="hero__description">{{ t('home.description') }}</p>
        <div class="hero__actions">
          <LocalizedLink class="button button--dark" to="/campaigns">
            {{ t('home.exploreCampaigns') }}
            <span aria-hidden="true">→</span>
          </LocalizedLink>
          <LocalizedLink class="button button--outline" to="/creators">{{ t('home.findCreator') }}</LocalizedLink>
        </div>
      </div>
    </div>
    <div class="hero-art" aria-hidden="true">
      <div class="hero-art__orbit"></div>
      <div class="hero-art__sun"></div>
      <div class="hero-art__shape hero-art__shape--back"></div>
      <div class="hero-art__shape hero-art__shape--front"></div>
      <img
        class="hero-art__portrait"
        src="/images/banner-girl.webp"
        srcset="/images/banner-girl-360.webp 360w, /images/banner-girl-600.webp 600w, /images/banner-girl-900.webp 900w, /images/banner-girl.webp 1086w"
        sizes="(max-width: 420px) 289px, (max-width: 760px) 355px, max(587px, calc((100svh - 164px) * .938))"
        width="1086"
        height="1448"
        alt=""
        fetchpriority="high"
      />
      <div class="hero-art__stamp">
        <span>{{ t('home.heroStampLead') }}</span>
        <strong>{{ t('home.heroStampEmphasis') }}</strong>
        <strong>{{ t('home.heroStampFinal') }}</strong>
      </div>
    </div>
  </section>

  <section class="intro-strip">
    <div class="page-width intro-strip__inner">
      <p>{{ t('home.intro') }}</p>
      <span>{{ t('home.lessNoise') }} <span aria-hidden="true">↓</span></span>
    </div>
  </section>

  <section class="section page-width">
    <div class="section-heading">
      <div>
        <p class="eyebrow">
          {{ creatorMode === 'featured' ? t('home.featuredCreatorsEyebrow') : t('home.creatorsEyebrow') }}
        </p>
        <h2>{{ t('home.creatorsTitleLead') }} <em>{{ t('home.creatorsTitleEmphasis') }}</em></h2>
      </div>
      <LocalizedLink class="text-link" to="/creators">{{ t('home.findPeople') }} <span aria-hidden="true">→</span></LocalizedLink>
    </div>
    <StatusMessage v-if="creatorsError" variant="error">{{ creatorsError }}</StatusMessage>
    <CardGrid v-else-if="creators.length || loading" kind="creator" layout="home" :aria-busy="loading">
      <CreatorCard v-for="creator in creators" :key="creator.id" :creator="creator" :image-sizes="HOME_IMAGE_SIZES" />
      <DirectorySkeletonCard v-for="index in loading && !creators.length ? 4 : 0" :key="`loading-${index}`" kind="creator" />
      <span v-if="loading" class="sr-only" role="status">{{ t('home.loadingCreators') }}</span>
    </CardGrid>
    <StatusMessage v-else variant="empty">{{ t('home.emptyCreators') }}</StatusMessage>
    <CountryDirectoryLinks />
  </section>

  <section class="campaign-section">
    <div class="page-width section">
      <div class="section-heading">
        <div><p class="eyebrow">{{ t('home.featuredCampaignsEyebrow') }}</p><h2>{{ t('home.campaignsTitleLead') }} <em>{{ t('home.campaignsTitleEmphasis') }}</em></h2></div>
        <LocalizedLink class="text-link" to="/campaigns">{{ t('home.allCampaigns') }} <span aria-hidden="true">→</span></LocalizedLink>
      </div>
      <StatusMessage v-if="campaignsError" variant="error">{{ campaignsError }}</StatusMessage>
      <CardGrid v-else-if="campaigns.length || loading" kind="campaign" layout="home" :aria-busy="loading">
        <CampaignCard v-for="campaign in campaigns" :key="campaign.id" :campaign="campaign" :image-sizes="HOME_IMAGE_SIZES" />
        <DirectorySkeletonCard v-for="index in loading && !campaigns.length ? 4 : 0" :key="`loading-${index}`" kind="campaign" />
        <span v-if="loading" class="sr-only" role="status">{{ t('home.loadingCampaigns') }}</span>
      </CardGrid>
      <StatusMessage v-else variant="empty">{{ t('home.emptyCampaigns') }}</StatusMessage>
    </div>
  </section>

  <section class="audience-promo">
    <article class="audience-promo__card">
      <div class="audience-promo__visual" aria-hidden="true">
        <img src="/images/sunlit-profile-480.webp" srcset="/images/sunlit-profile-480.webp 480w, /images/sunlit-profile-768.webp 768w, /images/sunlit-profile.webp 1122w" sizes="(max-width: 900px) max(53vw, 432px), max(26.5vw, 420px)" width="1122" height="1402" alt="" loading="lazy" decoding="async" />
      </div>
      <div class="audience-promo__copy">
        <p class="audience-promo__eyebrow">{{ t('home.creatorsPromoEyebrow') }}</p>
        <h2 class="audience-promo__title">{{ t('home.creatorsPromoTitle').replace(/\.$/, '') }}<span>.</span></h2>
        <p class="audience-promo__description">{{ t('home.creatorsPromoDescription') }}</p>
        <ul class="audience-promo__benefits">
          <li><Map :size="17" aria-hidden="true" />{{ t('home.creatorsBenefitCampaigns') }}</li>
          <li><ClipboardCheck :size="17" aria-hidden="true" />{{ t('home.creatorsBenefitTerms') }}</li>
          <li><CircleCheck :size="17" aria-hidden="true" />{{ t('home.creatorsBenefitPlace') }}</li>
        </ul>
        <LocalizedLink class="audience-promo__button" to="/campaigns">{{ t('home.creatorsPromoAction') }}<ArrowRight :size="21" aria-hidden="true" /></LocalizedLink>
      </div>
      <svg class="audience-promo__sketch" viewBox="0 0 70 70" aria-hidden="true"><path d="M48 4 15 48 43 21M15 16 12 51 50 35M28 60 61 39" /></svg>
      <div v-if="campaigns.length" class="audience-promo__campaign"><CampaignCard :campaign="campaigns[0]" image-sizes="220px" /></div>
    </article>

    <article class="audience-promo__card audience-promo__card--brands">
      <div class="audience-promo__visual" aria-hidden="true">
        <img src="/images/minimalist-wave-480.webp" srcset="/images/minimalist-wave-480.webp 480w, /images/minimalist-wave-768.webp 768w, /images/minimalist-wave.webp 1122w" sizes="(max-width: 900px) max(53vw, 432px), max(26.5vw, 420px)" width="1122" height="1402" alt="" loading="lazy" decoding="async" />
      </div>
      <div class="audience-promo__copy">
        <p class="audience-promo__eyebrow">{{ t('home.brandsPromoEyebrow') }}</p>
        <h2 class="audience-promo__title">{{ t('home.brandsPromoTitle').replace(/\.$/, '') }}<span>.</span></h2>
        <p class="audience-promo__description">{{ t('home.brandsPromoDescription') }}</p>
        <ul class="audience-promo__benefits">
          <li><Search :size="17" aria-hidden="true" />{{ t('home.brandsBenefitDiscover') }}</li>
          <li><FileCheck2 :size="17" aria-hidden="true" />{{ t('home.brandsBenefitCommunication') }}</li>
          <li><Camera :size="17" aria-hidden="true" />{{ t('home.brandsBenefitResults') }}</li>
        </ul>
        <LocalizedLink class="audience-promo__button" to="/account/campaigns/new">{{ t('home.postCampaign') }}<ArrowRight :size="21" aria-hidden="true" /></LocalizedLink>
      </div>
      <div class="audience-promo__results">
        <span>{{ t('home.resultsEyebrow') }}<ArrowUpRight :size="20" aria-hidden="true" /></span>
        <div class="audience-promo__chart" aria-hidden="true"><i v-for="bar in 6" :key="bar" :style="{ height: `${bar * 8 + 5}px` }"></i></div>
        <p>{{ t('home.resultsDescription') }}</p>
      </div>
      <p class="audience-promo__note">{{ t('home.brandsPromoNote') }}</p>
    </article>
  </section>

  <section id="how-it-works" class="how-it-works-section" aria-labelledby="steps-title">
    <div class="how-it-works page-width">
      <div class="how-it-works__intro">
        <p class="eyebrow">{{ t('home.stepsEyebrow') }}</p>
        <h2 id="steps-title">{{ t('home.stepsTitleLead') }}<br /><em>{{ t('home.stepsTitleEmphasis') }}</em></h2>
        <p>{{ t('home.stepsIntro') }}</p>
      </div>
      <div class="steps">
        <svg class="steps__wave" viewBox="0 0 1000 130" preserveAspectRatio="none" aria-hidden="true">
          <path d="M0 75C70 75 115 0 205 30S340 125 440 78 575 20 660 55 795 135 880 90 950 90 1000 130" />
        </svg>
        <article v-for="(icon, index) in [Search, FileCheck2, Camera]" :key="index" class="step">
          <div class="step__visual">
            <span class="step__icon" aria-hidden="true"><component :is="icon" :size="30" stroke-width="1.7" /></span>
            <span class="step__number" aria-hidden="true">0{{ index + 1 }}</span>
            <img :src="`/images/home-step-${['match', 'brief', 'create'][index]}-160.webp`" :srcset="`/images/home-step-${['match', 'brief', 'create'][index]}-160.webp 160w, /images/home-step-${['match', 'brief', 'create'][index]}-320.webp 320w`" sizes="156px" alt="" loading="lazy" decoding="async" width="320" height="240" />
            <div v-if="index === 1" class="step__brief" aria-hidden="true">
              <strong>{{ t('home.briefTitle') }}</strong>
              <span v-for="key in ['Brief', 'Budget', 'Deadline', 'Deliverables']" :key="key"><CircleCheck :size="11" />{{ t(`home.brief${key}`) }}</span>
            </div>
          </div>
          <div class="step__content">
            <h3>{{ t(`home.step${index + 1}Title`) }}</h3>
            <p>{{ t(`home.step${index + 1}Description`) }}</p>
          </div>
        </article>
      </div>
    </div>
  </section>

  <section class="contact-block" aria-labelledby="contact-title">
    <div class="contact-block__inner">
      <div class="contact-block__intro">
        <p class="eyebrow">{{ t('home.contactEyebrow') }}</p>
        <h2 id="contact-title">
          <span>{{ t('home.contactTitle') }}</span>
          <em>{{ t('home.contactTitleAccent') }}</em>
        </h2>
        <p>{{ t('home.contactDescription') }}</p>
        <p class="contact-block__audience">
          <span>{{ t('home.contactForCreators') }}</span>
          <i aria-hidden="true"></i>
          <span>{{ t('home.contactForBrands') }}</span>
          <i aria-hidden="true"></i>
          <span>{{ t('home.contactForPartnerships') }}</span>
        </p>
      </div>
      <div class="contact-visual" aria-hidden="true">
        <span class="contact-visual__sun"></span>
        <img src="/images/banner-girl-600.webp" srcset="/images/banner-girl-360.webp 360w, /images/banner-girl-600.webp 600w, /images/banner-girl-900.webp 900w, /images/banner-girl.webp 1086w" sizes="(max-width: 760px) 255px, (max-width: 1100px) 547px, clamp(547px, 72svh, 691px)" width="1086" height="1448" alt="" loading="lazy" decoding="async" />
        <p class="contact-visual__stamp">
          <span>{{ t('home.heroStampLead') }}</span>
          <span>{{ t('home.heroStampEmphasis') }}</span>
          <span>{{ t('home.heroStampFinal') }}</span>
        </p>
        <span class="contact-visual__underline"></span>
      </div>
      <span class="contact-block__watermark" aria-hidden="true">Wave</span>
      <form v-form-validation
        class="contact-form"
        aria-labelledby="contact-form-title"
        :aria-busy="contactSending"
        @submit.prevent="sendContactMessage"
      >
        <div class="contact-honeypot" aria-hidden="true">
          <label for="contact-extra">Extra note</label>
          <input
            id="contact-extra"
            v-model="contactForm.trap"
            type="text"
            tabindex="-1"
            autocomplete="off"
          />
        </div>
        <header class="contact-form__heading">
          <span class="contact-form__heading-icon" aria-hidden="true">
            <Mail :size="17" stroke-width="1.8" />
          </span>
          <div>
            <p>{{ t('home.contactFormEyebrow') }}</p>
            <h3 id="contact-form-title">{{ t('home.contactFormTitle') }}</h3>
          </div>
        </header>
        <p class="contact-form__intro">{{ t('home.contactFormIntro') }}</p>
        <fieldset class="contact-role-fieldset" :disabled="contactSending">
          <legend>{{ t('home.contactRoleLabel') }}</legend>
          <div class="contact-role-options">
            <label class="contact-role-option" :class="{ 'is-selected': contactForm.role === 'creator' }">
              <input v-model="contactForm.role" type="radio" name="contact-role" value="creator" required />
              <UserRound :size="19" stroke-width="1.8" aria-hidden="true" />
              <span>{{ t('home.contactRoleCreator') }}</span>
            </label>
            <label class="contact-role-option" :class="{ 'is-selected': contactForm.role === 'brand' }">
              <input v-model="contactForm.role" type="radio" name="contact-role" value="brand" />
              <Building2 :size="19" stroke-width="1.8" aria-hidden="true" />
              <span>{{ t('home.contactRoleBrand') }}</span>
            </label>
            <label class="contact-role-option" :class="{ 'is-selected': contactForm.role === 'agency' }">
              <input v-model="contactForm.role" type="radio" name="contact-role" value="agency" />
              <UsersRound :size="19" stroke-width="1.8" aria-hidden="true" />
              <span>{{ t('home.contactRoleAgency') }}</span>
            </label>
          </div>
        </fieldset>
        <label class="form-field contact-form__field--name">
          <span>{{ t('home.contactName') }}</span>
          <input
            v-model.trim="contactForm.name"
            type="text"
            required
            minlength="2"
            maxlength="120"
            autocomplete="name"
            :placeholder="t('home.contactNamePlaceholder')"
            :disabled="contactSending"
          />
        </label>
        <label class="form-field contact-form__field--email">
          <span>{{ t('home.contactEmail') }}</span>
          <input
            v-model.trim="contactForm.email"
            type="email"
            required
            maxlength="180"
            autocomplete="email"
            :placeholder="t('home.contactEmailPlaceholder')"
            :disabled="contactSending"
          />
        </label>
        <SingleSelect class="contact-form__field--interest" v-model="contactForm.interest" :label="t('home.contactInterest')" :options="['collaboration', 'campaign', 'partnership', 'other'].map(value => ({ value, label: t(`home.contactInterest${value.charAt(0).toUpperCase() + value.slice(1)}`) }))" required :disabled="contactSending" />
        <label class="form-field contact-form__field--message">
          <span>{{ t('home.contactMessage') }}</span>
          <textarea
            v-model.trim="contactForm.message"
            required
            minlength="20"
            maxlength="4000"
            rows="4"
            :placeholder="t('home.contactMessagePlaceholder')"
            :disabled="contactSending"
          ></textarea>
          <small>{{ t('home.contactMessageMinimum', { count: 20 }) }}</small>
          <span class="contact-form__message-meta">{{ contactForm.message.length }} / 4000</span>
        </label>
        <div class="contact-form__submit">
          <button class="button button--dark" type="submit" :disabled="contactSending">
            <span>{{ contactSending ? t('home.contactSending') : t('home.contactSubmit') }}</span>
            <span aria-hidden="true">→</span>
          </button>
          <p class="contact-form__response">{{ t('home.contactResponseTime') }}</p>
        </div>
      </form>
      <dialog
        ref="contactFeedbackDialog"
        class="contact-feedback"
        aria-modal="true"
        :aria-labelledby="`${contactFeedbackId}-title`"
        :aria-describedby="`${contactFeedbackId}-message`"
        @cancel.prevent="closeContactFeedback"
        @click.self="closeContactFeedback"
      >
        <section class="contact-feedback__content">
          <button
            class="contact-feedback__close"
            type="button"
            :aria-label="t('home.contactFeedbackClose')"
            @click="closeContactFeedback"
          >
            <span aria-hidden="true">×</span>
          </button>
          <span
            class="contact-feedback__icon"
            :class="`contact-feedback__icon--${contactFeedbackType}`"
            aria-hidden="true"
          >
            {{ contactFeedbackType === 'success' ? '✓' : '!' }}
          </span>
          <p class="eyebrow">{{ t('home.contactEyebrow') }}</p>
          <h2 :id="`${contactFeedbackId}-title`">
            {{
              contactFeedbackType === 'success'
                ? t('home.contactFeedbackSuccessTitle')
                : t('home.contactFeedbackErrorTitle')
            }}
          </h2>
          <p :id="`${contactFeedbackId}-message`">{{ contactFeedbackMessage }}</p>
          <button class="button button--dark" type="button" @click="closeContactFeedback">
            {{ t('home.contactFeedbackDismiss') }}
          </button>
        </section>
      </dialog>
    </div>
  </section>
  <section class="home-faq" aria-labelledby="faq-title">
    <div class="home-faq__inner page-width">
      <div class="home-faq__visual home-faq__visual--creator" aria-hidden="true">
        <img src="/images/home-faq-creator-320.webp" srcset="/images/home-faq-creator-320.webp 320w, /images/home-faq-creator-480.webp 480w, /images/home-faq-creator.webp 700w" sizes="(max-width: 1100px) calc((100vw - 120px) * .233), (max-width: 1464px) calc((100vw - 160px) / 3.6), 363px" width="700" height="875" alt="" loading="lazy" decoding="async" />
        <p class="home-faq__note">{{ t('home.faqCreatorNote') }}</p>
      </div>
      <FaqSection
        :eyebrow="t('siteFaq.eyebrow')"
        :title="t('siteFaq.title')"
        :description="t('siteFaq.description')"
        :items="siteFaqItems"
      >
        <template #title>{{ t('home.faqTitleLead') }}<br /><em>{{ t('home.faqTitleEmphasis') }}</em></template>
        <template #icon="{ index }"><span class="home-faq__icon" aria-hidden="true"><component :is="siteFaqIcons[index]" :size="20" stroke-width="1.7" /></span></template>
      </FaqSection>
      <div class="home-faq__visual home-faq__visual--desk" aria-hidden="true">
        <p class="home-faq__note">{{ t('home.faqDeskNote') }}</p>
        <img src="/images/home-step-match-320.webp" srcset="/images/home-step-match-320.webp 320w, /images/home-step-match.webp 500w" sizes="(max-width: 1100px) calc((100vw - 120px) * .233), (max-width: 1464px) calc((100vw - 160px) / 3.6), 363px" width="500" height="375" alt="" loading="lazy" decoding="async" />
      </div>
    </div>
  </section>
  <section class="brand-separator" aria-labelledby="brand-separator-title">
    <div class="brand-separator__content page-width">
      <div class="brand-separator__copy">
        <p class="eyebrow">{{ t('home.brandSeparatorEyebrow') }}</p>
        <h2 id="brand-separator-title" class="brand-separator__headline">
          <span>{{ t('home.brandSeparatorCreators') }}</span>
          <span>{{ t('home.brandSeparatorBrands') }}</span>
          <em>{{ t('home.brandSeparatorBigWaves') }}</em>
        </h2>
        <p class="brand-separator__description">{{ t('home.brandSeparatorDescription') }}</p>
        <LocalizedLink class="audience-promo__button" to="/campaigns">{{ t('home.exploreCampaigns') }}<ArrowRight :size="21" aria-hidden="true" /></LocalizedLink>
      </div>
    </div>
  </section>

</template>

<style lang="scss" src="../scss/views/HomeView.scss"></style>
