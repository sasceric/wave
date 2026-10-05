<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatMoney } from '../../lib/api'
import { sanitizeRichText } from '../../lib/richText'

const props = defineProps({
  profile: { type: Object, required: true },
  accountType: { type: String, required: true },
  tab: { type: String, default: 'about' },
  countryOptions: { type: Array, default: () => [] },
  categories: { type: Array, default: () => [] },
})
const { t } = useI18n()
const isCreator = computed(() => props.accountType === 'creator')
const countryName = computed(() => props.countryOptions.find(
  (country) => country.value === props.profile.countryCode,
)?.label || props.profile.countryCode)
const basics = computed(() => [
  [isCreator.value ? t('auth.name') : t('account.companyName'), isCreator.value ? props.profile.displayName : props.profile.name],
  [isCreator.value ? t('auth.category') : t('auth.industry'), isCreator.value
    ? (props.profile.categories || [props.profile.category]).filter(Boolean).map((value) => (
      props.categories.find((category) => category.value === value)?.label || value
    )).join(', ')
    : props.profile.industry],
  ...(isCreator.value ? [[t('auth.location'), props.profile.location]] : []),
  [t('auth.country'), countryName.value],
  [t('auth.city'), props.profile.city],
  [t('auth.phone'), props.profile.phone],
  ...(isCreator.value ? [
    [t('account.tagline'), props.profile.tagline],
    [t('account.tags'), (props.profile.tags || []).join(', ')],
  ] : []),
])
const aboutHtml = computed(() => sanitizeRichText(
  isCreator.value ? props.profile.bio : props.profile.about,
))
</script>

<template>
  <section
    class="profile-tab-panel profile-details"
    :id="isCreator ? `account-panel-${tab}` : undefined"
    :role="isCreator ? 'tabpanel' : undefined"
    :aria-labelledby="isCreator ? `account-tab-${tab}` : undefined"
  >
    <template v-if="!isCreator || tab === 'about'">
      <dl class="profile-details__grid">
        <div v-for="[label, value] in basics" :key="label">
          <dt>{{ label }}</dt>
          <dd>{{ value || t('account.notProvided') }}</dd>
        </div>
      </dl>
      <div class="profile-details__about">
        <h3>{{ isCreator ? t('account.bio') : t('account.companyAbout') }}</h3>
        <div v-if="aboutHtml" class="profile-bio" v-html="aboutHtml"></div>
        <p v-else class="profile-details__empty">{{ t('account.notProvided') }}</p>
      </div>
    </template>
    <template v-else-if="tab === 'social'">
      <p v-if="!profile.socialProfiles.length" class="profile-editor__empty">{{ t('account.noSocialProfiles') }}</p>
      <article v-for="(social, index) in profile.socialProfiles" :key="index" class="profile-details__item">
        <h3>{{ social.platform }}</h3>
        <dl class="profile-details__grid">
          <div><dt>{{ t('account.handle') }}</dt><dd>{{ social.handle }}</dd></div>
          <div><dt>{{ t('account.followers') }}</dt><dd>{{ social.followers }}</dd></div>
        </dl>
      </article>
    </template>
    <template v-else-if="tab === 'portfolio'">
      <p v-if="!profile.portfolio.length" class="profile-editor__empty">{{ t('account.noPortfolioItems') }}</p>
      <article v-for="(item, index) in profile.portfolio" :key="item.id || index" class="profile-details__item">
        <h3>{{ item.title || t('account.mediaTitle') }}</h3>
        <p>{{ item.platform === 'All' ? t('account.allPlatforms') : item.platform }}</p>
        <img v-if="item.type === 'image' && item.url" class="profile-details__media" :src="item.url" :alt="item.title || t('account.image')" loading="lazy" />
        <p v-else>{{ item.url || t('account.notProvided') }}</p>
      </article>
    </template>
    <template v-else-if="tab === 'packages'">
      <p v-if="!profile.packages.length" class="profile-editor__empty">{{ t('account.noPackages') }}</p>
      <article v-for="(item, index) in profile.packages" :key="item.id || index" class="profile-details__item">
        <h3>{{ item.title }}</h3>
        <p>{{ item.platform }}</p>
        <p class="profile-details__description">{{ item.description }}</p>
        <p>{{ item.price ? formatMoney(item.price, item.currency || 'BAM') : t('account.notProvided') }}</p>
      </article>
    </template>
    <template v-else-if="tab === 'faqs'">
      <p v-if="!profile.faqs.length" class="profile-editor__empty">{{ t('account.noCreatorFaqs') }}</p>
      <article v-for="(faq, index) in profile.faqs" :key="index" class="profile-details__item">
        <h3>{{ faq.question }}</h3>
        <p class="profile-details__description">{{ faq.answer }}</p>
      </article>
    </template>
  </section>
</template>
