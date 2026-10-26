<script setup>
import { workImage } from '../../lib/creatorPresentation'
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ArrowRight, BarChart3, Briefcase, Camera, Check, ChevronDown, CircleHelp, ExternalLink, FileText, Globe, Image as ImageIcon, PencilLine, Plus, Tag, Users } from '@lucide/vue'
import LocalizedLink from '../shared/LocalizedLink.vue'
import ProfileStepModal from './ProfileStepModal.vue'
import { currentUser } from '../../composables/useCurrentUser'
import { formatMoney } from '../../lib/api'
import { htmlToPlainText } from '../../lib/profileDraft'
import { profileStrength } from '../../lib/profileStrength'

// "Profile strength" on Pregled: the most valuable missing steps, each fixed in a
// quick modal (or the editor for bigger sections), with a live preview of the card.
const { t, locale } = useI18n()
const strength = computed(() => profileStrength(currentUser.value))
const profile = computed(() => currentUser.value?.profile || {})
const kind = computed(() => strength.value?.kind)
const ICONS = { photo: Camera, logo: ImageIcon, cover: Camera, works: ImageIcon, bio: PencilLine, about: PencilLine, social: Users, links: Globe, packages: Tag, audience: BarChart3, services: Briefcase, industries: Briefcase, faq: CircleHelp }
const EDITOR_TABS = { works: 'portfolio', audience: 'social', services: 'services' }
const topSteps = computed(() => strength.value?.missing.slice(0, 3) || [])
const doneCount = computed(() => strength.value ? strength.value.steps.length - strength.value.missing.length : 0)
const levelName = computed(() => strength.value ? t(`profileStrength.levels.${strength.value.level}`) : '')
const audienceKey = computed(() => kind.value === 'brand' ? 'creators' : 'brands')
const modalStep = ref(null)
const modalOpen = ref(false)
const allOpen = ref(false)
const displayScore = ref(0)
const celebration = ref('')
let celebrationTimer

watch(() => strength.value?.score ?? 0, async (score, previous) => {
  if (previous === undefined) { displayScore.value = score; return }
  await nextTick()
  requestAnimationFrame(() => { displayScore.value = score })
}, { immediate: true })

function openStep(step) {
  modalStep.value = step
  modalOpen.value = true
}
function stepSaved({ points }) {
  const score = strength.value?.score ?? 0
  celebration.value = t('profileStrength.saved', { points, score })
  clearTimeout(celebrationTimer)
  celebrationTimer = setTimeout(() => { celebration.value = '' }, 3200)
}
function editorRoute(step) {
  return { name: 'account', query: { tab: EDITOR_TABS[step], edit: '1' } }
}

const publicRoute = computed(() => profile.value.slug ? { name: kind.value === 'creator' ? 'creator-profile' : 'company-profile', params: { slug: profile.value.slug } } : null)
const previewName = computed(() => profile.value.displayName || profile.value.name || '')
const previewImage = computed(() => kind.value === 'creator' ? profile.value.avatarUrl : profile.value.logoUrl)
const previewPlace = computed(() => [profile.value.city || currentUser.value?.city, kind.value === 'creator' ? profile.value.categoryLabels?.[0] : profile.value.industryLabels?.[0] || profile.value.industry].filter(Boolean).join(' · '))
const previewAbout = computed(() => htmlToPlainText(kind.value === 'creator' ? profile.value.bio : profile.value.about))
const previewNetworks = computed(() => (profile.value.socialProfiles || []).filter((row) => Number(row.followers) > 0).slice(0, 2).map((row) => `${row.platform} ${new Intl.NumberFormat(locale.value === 'cnr' ? 'bs' : locale.value, { notation: 'compact', maximumFractionDigits: 1 }).format(row.followers)}`))
const previewPrice = computed(() => {
  const prices = (profile.value.packages || []).map((row) => Number(row.price)).filter((price) => price > 0)
  return prices.length ? t('profileStrength.priceFrom', { price: formatMoney(Math.min(...prices), profile.value.packages.find((row) => Number(row.price) === Math.min(...prices))?.currency || 'BAM') }) : ''
})
const previewWorks = computed(() => (profile.value.portfolio || []).slice(0, 4).map((item) => workImage(item)))
const previewChips = computed(() => kind.value === 'agency' ? (profile.value.services || []).slice(0, 3).map((row) => (typeof row === 'string' ? row : row.title)).filter(Boolean) : (profile.value.socialLinks || []).slice(0, 3).map((row) => row.platform === 'Website' ? row.url.replace(/^https?:\/\//, '').replace(/\/$/, '') : row.platform))
const done = (key) => strength.value?.steps.find((step) => step.key === key)?.done
</script>

<template>
  <section v-if="strength && strength.score >= 100" class="profile-strength-done" :aria-label="t('profileStrength.title')">
    <span class="profile-strength__ring profile-strength__ring--small" style="--score: 100" aria-hidden="true"><b>100<small>%</small></b></span>
    <div><strong>{{ t('profileStrength.readyTitle') }}</strong><span>{{ t(`profileStrength.readyText.${audienceKey}`) }}</span></div>
    <LocalizedLink v-if="publicRoute" class="button button--tonal" :to="publicRoute"><ExternalLink :size="15" aria-hidden="true" />{{ t('account.viewPublicProfile') }}</LocalizedLink>
  </section>

  <section v-else-if="strength" class="profile-strength" aria-labelledby="profile-strength-title">
    <div class="profile-strength__grid">
    <div class="profile-strength__score">
      <span class="profile-strength__ring" :style="{ '--score': displayScore }" role="img" :aria-label="t('profileStrength.scoreLabel', { score: strength.score })"><b>{{ strength.score }}<small>%</small></b></span>
      <span class="profile-strength__level" :class="`profile-strength__level--${strength.level}`">{{ levelName }}</span>
      <span class="profile-strength__ladder" aria-hidden="true"><i v-for="n in 4" :key="n" :class="{ 'is-on': n <= strength.level }"></i></span>
      <p>
        <template v-if="strength.next">{{ t('profileStrength.toNext', { points: strength.next - strength.score, level: t(`profileStrength.levels.${strength.level + 1}`) }) }}</template>
        {{ t(`profileStrength.why.${audienceKey}`) }}
      </p>
      <LocalizedLink class="profile-strength__guide" :to="{ name: 'account-profile-guide' }"><FileText :size="15" aria-hidden="true" />{{ t('profileGuide.cardLink') }}</LocalizedLink>
    </div>

    <div class="profile-strength__steps">
      <h2 id="profile-strength-title">{{ t('profileStrength.title') }}</h2>
      <p>{{ strength.missing.length === 1 ? t('profileStrength.oneLeft') : t('profileStrength.stepsLeft', { count: strength.missing.length }) }}</p>
      <ul>
        <li v-for="(step, index) in topSteps" :key="step.key">
          <span class="profile-strength__icon"><component :is="ICONS[step.key]" :size="20" aria-hidden="true" /></span>
          <div><strong>{{ t(`profileStrength.steps.${step.key}.title`) }}<span class="profile-strength__points">+{{ step.points }}%</span></strong><span>{{ t(`profileStrength.steps.${step.key}.why`) }}</span></div>
          <LocalizedLink v-if="EDITOR_TABS[step.key]" class="button" :class="index === 0 ? 'button--dark' : 'button--tonal'" :to="editorRoute(step.key)">{{ t('profileStrength.open') }}<ArrowRight :size="15" aria-hidden="true" /></LocalizedLink>
          <button v-else class="button" :class="index === 0 ? 'button--dark' : 'button--tonal'" type="button" @click="openStep(step.key)">{{ t(index === 0 ? 'profileStrength.addNow' : 'profileStrength.add') }}</button>
        </li>
      </ul>
      <button class="profile-strength__all-toggle" type="button" :aria-expanded="allOpen" aria-controls="profile-strength-all" @click="allOpen = !allOpen">
        {{ t('profileStrength.allSteps', { done: doneCount, total: strength.steps.length }) }}<ChevronDown :size="16" aria-hidden="true" />
      </button>
      <ul v-show="allOpen" id="profile-strength-all" class="profile-strength__all">
        <li v-for="step in strength.steps" :key="step.key" :class="{ 'is-done': step.done }">
          <span class="profile-strength__check"><Check v-if="step.done" :size="12" stroke-width="3" aria-hidden="true" /></span>
          <span>{{ t(`profileStrength.steps.${step.key}.short`) }}</span>
          <small>{{ step.points }}%</small>
        </li>
      </ul>
    </div>

    <div class="profile-strength__preview">
      <h3>{{ t(`profileStrength.previewTitle.${audienceKey}`) }}</h3>
      <article class="profile-strength-preview" :class="`profile-strength-preview--${kind}`" :aria-label="t('profileStrength.previewLabel')">
        <template v-if="kind === 'creator'">
          <div class="profile-strength-preview__media">
            <img v-if="previewImage" :src="previewImage" alt="" loading="lazy" />
            <button v-else class="profile-strength-preview__missing" type="button" @click="openStep('photo')"><Camera :size="22" aria-hidden="true" />{{ t('profileStrength.missing.photo') }}</button>
          </div>
        </template>
        <template v-else>
          <div class="profile-strength-preview__cover">
            <img v-if="profile.coverUrl" :src="profile.coverUrl" alt="" loading="lazy" />
            <button v-else class="profile-strength-preview__missing" type="button" @click="openStep('cover')"><Camera :size="16" aria-hidden="true" />{{ t('profileStrength.missing.cover') }}</button>
          </div>
          <div class="profile-strength-preview__logo">
            <img v-if="previewImage" :src="previewImage" alt="" loading="lazy" />
            <button v-else class="profile-strength-preview__missing" type="button" @click="openStep('logo')">{{ t('profileStrength.missing.logo') }}</button>
          </div>
        </template>
        <div class="profile-strength-preview__body">
          <strong>{{ previewName }}</strong>
          <span class="profile-strength-preview__meta"><span>{{ previewPlace }}</span><b v-if="kind === 'creator' && previewPrice">{{ previewPrice }}</b></span>
          <template v-if="kind === 'creator'">
            <span v-if="previewNetworks.length" class="profile-strength-preview__chips"><span v-for="network in previewNetworks" :key="network">{{ network }}</span></span>
            <button v-else class="profile-strength-preview__missing profile-strength-preview__missing--inline" type="button" @click="openStep('social')"><Users :size="15" aria-hidden="true" />{{ t('profileStrength.missing.social') }}</button>
          </template>
          <p v-if="previewAbout">{{ previewAbout }}</p>
          <button v-else class="profile-strength-preview__missing profile-strength-preview__missing--inline" type="button" @click="openStep(kind === 'creator' ? 'bio' : 'about')"><PencilLine :size="15" aria-hidden="true" />{{ t(`profileStrength.missing.${kind === 'creator' ? 'bio' : 'about'}`) }}</button>
          <span v-if="kind !== 'creator' && previewChips.length" class="profile-strength-preview__chips"><span v-for="chip in previewChips" :key="chip">{{ chip }}</span></span>
          <button v-else-if="kind === 'brand'" class="profile-strength-preview__missing profile-strength-preview__missing--inline" type="button" @click="openStep('links')"><Globe :size="15" aria-hidden="true" />{{ t('profileStrength.missing.links') }}</button>
          <span v-if="kind !== 'brand'" class="profile-strength-preview__works">
            <span v-for="n in 4" :key="n" :class="{ 'is-empty': !previewWorks[n - 1] && !done('works') }"><img v-if="previewWorks[n - 1]" :src="previewWorks[n - 1]" alt="" loading="lazy" /></span>
          </span>
          <button v-if="kind === 'creator' && !previewPrice" class="profile-strength-preview__missing profile-strength-preview__missing--inline" type="button" @click="openStep('packages')"><Plus :size="15" aria-hidden="true" />{{ t('profileStrength.missing.packages') }}</button>
        </div>
      </article>
      <p>{{ t('profileStrength.previewHint') }}</p>
    </div>
    </div>

    <p v-if="celebration" class="profile-strength__celebration" role="status">{{ celebration }}</p>
    <ProfileStepModal v-model:open="modalOpen" :step="modalStep || ''" :kind="kind" :points="strength.steps.find((step) => step.key === modalStep)?.points || 0" @saved="stepSaved" />
  </section>
</template>

<style lang="scss" src="../../scss/components/account/ProfileStrengthCard.scss"></style>
