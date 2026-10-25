<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { ArrowLeft, Check, Download, Lightbulb, ThumbsDown, ThumbsUp } from '@lucide/vue'
import AccountPage from '../components/account/AccountPage.vue'
import LocalizedLink from '../components/shared/LocalizedLink.vue'
import WaveLogo from '../components/shared/WaveLogo.vue'
import { currentUser } from '../composables/useCurrentUser'
import { profileKind, profileStrength, profileSteps } from '../lib/profileStrength'

// The profile guide ("brochure"): four printable pages per account type. "Download PDF"
// prints the pages, so every browser can save them as a PDF in the reader's language.
const KINDS = ['creator', 'agency', 'brand']
const IMAGES = { creator: '/images/home-faq-creator-480.webp', agency: '/images/how-it-works-company.webp', brand: '/images/companies-hero.webp' }
const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const ownKind = computed(() => profileKind(currentUser.value))
const kind = computed(() => KINDS.includes(route.query.type) ? route.query.type : ownKind.value || 'creator')
const strength = computed(() => ownKind.value === kind.value ? profileStrength(currentUser.value) : null)
const doneKeys = computed(() => new Set(strength.value?.steps.filter((step) => step.done).map((step) => step.key) || []))
const steps = computed(() => profileSteps(kind.value))
const copy = (key) => `profileGuide.${kind.value}.${key}`
// Chips are separated by semicolons (numbers may use decimal commas).
// Fixed counts keep every locale's guide the same length and layout.
const items = (key, count, fields) => Array.from({ length: count }, (_, index) => (fields
  ? Object.fromEntries(fields.map((field) => [field, t(copy(`${key}.${index + 1}.${field}`))]))
  : t(copy(`${key}.${index + 1}`))))
const notes = computed(() => items('notes', 5, ['title', 'text']))
const tips = computed(() => items('tips', 3, ['title', 'text']))
const dos = computed(() => items('do', 3))
const donts = computed(() => items('dont', 3))
const looks = computed(() => items('look', 4, ['title', 'text']))
const chips = computed(() => t(copy('sampleChips')).split(';').map((chip) => chip.trim()).filter(Boolean))
const audienceKey = computed(() => kind.value === 'brand' ? 'creators' : 'brands')

function choose(type) {
  void router.replace({ query: { ...route.query, type } })
}
function download() {
  window.print()
}
</script>

<template>
  <AccountPage class="profile-guide">
    <header class="profile-guide__heading">
      <div>
        <LocalizedLink v-if="currentUser" class="profile-guide__back" :to="{ name: 'account-dashboard' }"><ArrowLeft :size="15" aria-hidden="true" />{{ t('personalDashboard.title') }}</LocalizedLink>
        <h1>{{ t('profileGuide.title') }}</h1>
        <p>{{ t('profileGuide.intro') }}</p>
      </div>
      <div class="profile-guide__controls">
        <div class="profile-guide__types" role="group" :aria-label="t('profileGuide.typeLabel')">
          <button v-for="type in KINDS" :key="type" type="button" :aria-pressed="kind === type" @click="choose(type)">{{ t(`profileGuide.types.${type}`) }}</button>
        </div>
        <button class="button button--dark" type="button" @click="download"><Download :size="16" aria-hidden="true" />{{ t('profileGuide.download') }}</button>
      </div>
    </header>

    <article class="profile-guide__doc" :aria-label="t(copy('title'))">
      <section class="profile-guide__page profile-guide__page--cover">
        <span class="profile-guide__logo"><WaveLogo /></span>
        <p class="profile-guide__eyebrow">{{ t(copy('eyebrow')) }}</p>
        <h2>{{ t(copy('title')) }}</h2>
        <p class="profile-guide__lead">{{ t(copy('lead')) }}</p>
        <img class="profile-guide__hero" :src="IMAGES[kind]" alt="" loading="lazy" />
        <div class="profile-guide__toc">
          <b>{{ t('profileGuide.inside') }}</b>
          <ol>
            <li><span>{{ t('profileGuide.anatomyTitle') }}</span><small>2</small></li>
            <li><span>{{ t('profileGuide.checklistTitle') }}</span><small>3</small></li>
            <li><span>{{ t(`profileGuide.lookTitle.${audienceKey}`) }}</span><small>4</small></li>
          </ol>
        </div>
        <span class="profile-guide__folio">1 / 4</span>
      </section>

      <section class="profile-guide__page">
        <p class="profile-guide__eyebrow">{{ t('profileGuide.anatomyEyebrow') }}</p>
        <h2>{{ t('profileGuide.anatomyTitle') }}</h2>
        <div class="profile-guide__anatomy">
          <div class="profile-guide__sample" :class="`profile-guide__sample--${kind}`" aria-hidden="true">
            <div class="profile-guide__sample-media"><img :src="IMAGES[kind]" alt="" loading="lazy" /><i>1</i></div>
            <div class="profile-guide__sample-body">
              <strong>{{ t(copy('sampleName')) }}<i>2</i></strong>
              <span>{{ t(copy('sampleMeta')) }}</span>
              <p>{{ t(copy('sampleAbout')) }}<i>3</i></p>
              <span class="profile-guide__sample-chips"><b v-for="chip in chips" :key="chip">{{ chip }}</b><i>4</i></span>
              <span class="profile-guide__sample-works"><span v-for="n in 4" :key="n" /><i>5</i></span>
            </div>
          </div>
          <ol class="profile-guide__notes">
            <li v-for="(note, index) in notes" :key="index"><span>{{ index + 1 }}</span><div><b>{{ note.title }}</b>{{ note.text }}</div></li>
          </ol>
        </div>
        <span class="profile-guide__folio">2 / 4</span>
      </section>

      <section class="profile-guide__page">
        <p class="profile-guide__eyebrow">{{ t('profileGuide.checklistEyebrow') }}</p>
        <h2>{{ t('profileGuide.checklistTitle') }}</h2>
        <ul class="profile-guide__checklist">
          <li v-for="step in steps" :key="step.key" :class="{ 'is-done': doneKeys.has(step.key) }">
            <span class="profile-guide__box"><Check v-if="doneKeys.has(step.key)" :size="12" stroke-width="3" aria-hidden="true" /></span>
            <div><b>{{ t(`profileStrength.steps.${step.key}.title`) }}</b>{{ t(`profileStrength.steps.${step.key}.why`) }}</div>
            <small>+{{ step.points }}%</small>
          </li>
        </ul>
        <div class="profile-guide__tips">
          <div v-for="(tip, index) in tips" :key="index"><Lightbulb :size="18" aria-hidden="true" /><b>{{ tip.title }}</b><p>{{ tip.text }}</p></div>
        </div>
        <div class="profile-guide__dodont">
          <div class="is-do"><b><ThumbsUp :size="15" aria-hidden="true" />{{ t('profileGuide.do') }}</b><ul><li v-for="(item, index) in dos" :key="index">{{ item }}</li></ul></div>
          <div class="is-dont"><b><ThumbsDown :size="15" aria-hidden="true" />{{ t('profileGuide.dont') }}</b><ul><li v-for="(item, index) in donts" :key="index">{{ item }}</li></ul></div>
        </div>
        <span class="profile-guide__folio">3 / 4</span>
      </section>

      <section class="profile-guide__page">
        <p class="profile-guide__eyebrow">{{ t('profileGuide.lookEyebrow') }}</p>
        <h2>{{ t(`profileGuide.lookTitle.${audienceKey}`) }}</h2>
        <ol class="profile-guide__look">
          <li v-for="(item, index) in looks" :key="index"><span>{{ index + 1 }}</span><div><b>{{ item.title }}</b><p>{{ item.text }}</p></div></li>
        </ol>
        <div class="profile-guide__cta">
          <div>
            <b>{{ strength ? t('profileGuide.ctaScore', { score: strength.score }) : t('profileGuide.ctaTitle') }}</b>
            <span>{{ t('profileGuide.ctaText') }}</span>
          </div>
          <em>wave.ba</em>
        </div>
        <span class="profile-guide__folio">4 / 4</span>
      </section>
    </article>
  </AccountPage>
</template>

<style lang="scss" src="../scss/views/AccountProfileGuideView.scss"></style>
