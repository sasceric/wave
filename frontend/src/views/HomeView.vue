<script setup>
import { computed, nextTick, onMounted, reactive, ref, useId, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Building2, Camera, Mail, Megaphone, Search, UserRound, UsersRound } from '@lucide/vue'
import CampaignCard from '../components/campaigns/CampaignCard.vue'
import CreatorCard from '../components/creators/CreatorCard.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import FaqSection from '../components/shared/FaqSection.vue'
import LocalizedLink from '../components/shared/LocalizedLink.vue'
import { apiGet, apiRequest } from '../lib/api'

const creators = ref([])
const campaigns = ref([])
const creatorsError = ref('')
const campaignsError = ref('')
const loading = ref(false)
const creatorMode = ref('latest')
const { t, locale } = useI18n()
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
          <a class="button button--outline" href="#how-it-works">{{ t('home.howItWorks') }}</a>
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
      <LocalizedLink class="text-link" to="/creators">{{ t('home.findPeople') }} <span aria-hidden="true">↗</span></LocalizedLink>
    </div>
    <StatusMessage v-if="creatorsError" variant="error">{{ creatorsError }}</StatusMessage>
    <div v-else-if="creators.length" class="creator-grid">
      <CreatorCard v-for="creator in creators" :key="creator.id" :creator="creator" />
    </div>
    <StatusMessage v-else-if="loading">{{ t('home.loadingCreators') }}</StatusMessage>
    <StatusMessage v-else variant="empty">{{ t('home.emptyCreators') }}</StatusMessage>
  </section>

  <section class="campaign-section">
    <div class="page-width section">
      <div class="section-heading">
        <div><p class="eyebrow">{{ t('home.featuredCampaignsEyebrow') }}</p><h2>{{ t('home.campaignsTitleLead') }} <em>{{ t('home.campaignsTitleEmphasis') }}</em></h2></div>
        <LocalizedLink class="text-link" to="/campaigns">{{ t('home.allCampaigns') }} <span aria-hidden="true">↗</span></LocalizedLink>
      </div>
      <StatusMessage v-if="campaignsError" variant="error">{{ campaignsError }}</StatusMessage>
      <div v-else-if="campaigns.length" class="campaign-grid campaign-grid--home">
        <CampaignCard v-for="campaign in campaigns" :key="campaign.id" :campaign="campaign" />
      </div>
      <StatusMessage v-else-if="loading">{{ t('home.loadingCampaigns') }}</StatusMessage>
      <StatusMessage v-else variant="empty">{{ t('home.emptyCampaigns') }}</StatusMessage>
    </div>
  </section>

  <section class="audience-promo">
    <LocalizedLink class="audience-promo__card" to="/campaigns">
      <img src="/images/sunlit-profile.webp" alt="" loading="lazy" decoding="async" />
      <div class="audience-promo__copy">
        <p class="audience-promo__eyebrow">{{ t('home.creatorsPromoEyebrow') }}</p>
        <h2 class="audience-promo__title">{{ t('home.creatorsPromoTitle') }}</h2>
        <p class="audience-promo__description">{{ t('home.creatorsPromoDescription') }}</p>
        <span class="audience-promo__arrow" aria-hidden="true">→</span>
      </div>
    </LocalizedLink>

    <LocalizedLink class="audience-promo__card audience-promo__card--brands" to="/creators">
      <img src="/images/minimalist-wave.webp" alt="" loading="lazy" decoding="async" />
      <div class="audience-promo__copy">
        <p class="audience-promo__eyebrow">{{ t('home.brandsPromoEyebrow') }}</p>
        <h2 class="audience-promo__title">{{ t('home.brandsPromoTitle') }}</h2>
        <p class="audience-promo__description">{{ t('home.brandsPromoDescription') }}</p>
        <span class="audience-promo__arrow audience-promo__arrow--brands" aria-hidden="true">→</span>
      </div>
    </LocalizedLink>
  </section>

  <section id="how-it-works" class="how-it-works-section" aria-labelledby="steps-title">
    <div class="how-it-works page-width">
      <div class="how-it-works__intro">
        <p class="eyebrow">{{ t('home.stepsEyebrow') }}</p>
        <h2 id="steps-title">{{ t('home.stepsTitleLead') }}<br /><em>{{ t('home.stepsTitleEmphasis') }}</em></h2>
        <p>{{ t('home.stepsIntro') }}</p>
      </div>
      <div class="steps">
        <svg class="steps__wave" viewBox="0 0 1000 110" preserveAspectRatio="none" aria-hidden="true">
          <path d="M0 55C70 55 100 25 167 25S270 85 334 85 435 42 500 42 600 8 667 8 770 75 834 75 930 48 1000 48" />
        </svg>
        <article class="step">
          <span class="step__icon" aria-hidden="true"><Search :size="19" stroke-width="1.7" /></span>
          <div class="step__content">
            <h3>{{ t('home.step1Title') }}</h3>
            <p>{{ t('home.step1Description') }}</p>
          </div>
        </article>
        <article class="step">
          <span class="step__icon" aria-hidden="true"><Megaphone :size="19" stroke-width="1.7" /></span>
          <div class="step__content">
            <h3>{{ t('home.step2Title') }}</h3>
            <p>{{ t('home.step2Description') }}</p>
          </div>
        </article>
        <article class="step">
          <span class="step__icon" aria-hidden="true"><Camera :size="19" stroke-width="1.7" /></span>
          <div class="step__content">
            <h3>{{ t('home.step3Title') }}</h3>
            <p>{{ t('home.step3Description') }}</p>
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
        <img src="/images/banner-girl.webp" alt="" loading="lazy" decoding="async" />
        <p class="contact-visual__stamp">
          <span>{{ t('home.heroStampLead') }}</span>
          <span>{{ t('home.heroStampEmphasis') }}</span>
          <span>{{ t('home.heroStampFinal') }}</span>
        </p>
        <span class="contact-visual__underline"></span>
      </div>
      <span class="contact-block__watermark" aria-hidden="true">Wave</span>
      <form
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
        <label class="form-field contact-form__field--interest">
          <span>{{ t('home.contactInterest') }}</span>
          <select v-model="contactForm.interest" required :disabled="contactSending">
            <option value="collaboration">{{ t('home.contactInterestCollaboration') }}</option>
            <option value="campaign">{{ t('home.contactInterestCampaign') }}</option>
            <option value="partnership">{{ t('home.contactInterestPartnership') }}</option>
            <option value="other">{{ t('home.contactInterestOther') }}</option>
          </select>
        </label>
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
  <FaqSection
    class="home-faq"
    :eyebrow="t('siteFaq.eyebrow')"
    :title="t('siteFaq.title')"
    :description="t('siteFaq.description')"
    :items="siteFaqItems"
  />
  <section class="brand-separator" aria-labelledby="brand-separator-title">
    <div class="brand-separator__content">
      <h2 id="brand-separator-title" class="brand-separator__headline">
        <span>{{ t('home.brandSeparatorCreators') }}</span>
        <span>{{ t('home.brandSeparatorBrands') }}</span>
        <em>{{ t('home.brandSeparatorBigWaves') }}</em>
      </h2>
      <p class="brand-separator__wordmark">{{ t('home.brandSeparatorWordmark') }}</p>
    </div>
  </section>

</template>
