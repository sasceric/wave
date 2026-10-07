<script setup>
import { BadgeCheck, Building2, ExternalLink, FileText, Globe, Handshake, Images, MessageCircleQuestion, Pencil, Phone, Tags, UserRound, UsersRound } from '@lucide/vue'
import AccountProfileCard from './AccountProfileCard.vue'
import RichTextContent from '../shared/RichTextContent.vue'
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
const emit = defineEmits(['edit'])
const { t } = useI18n()
const isCreator = computed(() => props.accountType === 'creator')
const countryName = computed(() => props.countryOptions.find(
  (country) => country.value === props.profile.countryCode,
)?.label || props.profile.countryCode)
const basics = computed(() => [
  [UserRound, isCreator.value ? t('auth.name') : t('account.companyName'), isCreator.value ? props.profile.displayName : props.profile.name],
  [BadgeCheck, isCreator.value ? t('auth.category') : t('auth.industry'), isCreator.value
    ? (props.profile.categories || [props.profile.category]).filter(Boolean).map((value) => (
      props.categories.find((category) => category.value === value)?.label || value
    ))
    : props.profile.industryLabels || props.profile.industries || [props.profile.industry]],
  [Globe, t('auth.country'), countryName.value],
  [Building2, t('auth.city'), props.profile.city],
  [Phone, t('auth.phone'), props.profile.phone],
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
      <div class="profile-details__layout">
        <AccountProfileCard class="profile-details__basics" :title="t('account.basicInformation')" :icon="UserRound" editable @edit="emit('edit', 'about')">
          <dl class="profile-details__grid">
            <div v-for="[icon, label, value] in basics" :key="label" class="profile-details__row">
              <component :is="icon" :size="20" aria-hidden="true" />
              <dt>{{ label }}</dt>
              <dd v-if="Array.isArray(value) && value.length" class="profile-details__chips"><span v-for="item in value" :key="item">{{ item }}</span></dd>
              <dd v-else>{{ Array.isArray(value) ? t('account.notProvided') : value || t('account.notProvided') }}</dd>
            </div>
          </dl>
        </AccountProfileCard>
        <AccountProfileCard class="profile-details__additional" :title="t('account.additionalInformation')" :icon="FileText" editable @edit="emit('edit', 'about')">
          <dl class="profile-details__grid">
            <div v-if="isCreator" class="profile-details__row"><FileText :size="20" aria-hidden="true" /><dt>{{ t('account.tagline') }}</dt><dd>{{ profile.tagline || t('account.notProvided') }}</dd></div>
            <div class="profile-details__row"><Pencil :size="20" aria-hidden="true" /><dt>{{ isCreator ? t('account.bio') : t('account.companyAbout') }}</dt><dd><RichTextContent v-if="aboutHtml" :html="aboutHtml" /><span v-else>{{ t('account.notProvided') }}</span></dd></div>
            <div v-if="isCreator" class="profile-details__row"><Tags :size="20" aria-hidden="true" /><dt>{{ t('account.tags') }}</dt><dd>{{ (profile.tags || []).join(', ') || t('account.notProvided') }}</dd></div>
            <div v-if="!isCreator && profile.socialLinks?.length" class="profile-details__row"><ExternalLink :size="20" aria-hidden="true" /><dt>{{ t('account.companySocialLinks') }}</dt><dd class="profile-details__links"><a v-for="link in profile.socialLinks" :key="link.platform" :href="link.url" target="_blank" rel="noopener noreferrer">{{ link.platform }} <ExternalLink :size="13" aria-hidden="true" /></a></dd></div>
          </dl>
        </AccountProfileCard>
      </div>
    </template>
    <template v-else-if="tab === 'social'">
      <AccountProfileCard :title="t('account.socialProfiles')" :icon="UsersRound" editable @edit="emit('edit', 'social')">
        <p v-if="!profile.socialProfiles.length" class="profile-editor__empty">{{ t('account.noSocialProfiles') }}</p>
        <article v-for="(social, index) in profile.socialProfiles" :key="index" class="profile-details__item">
          <h3>{{ social.platform }}</h3>
          <dl class="profile-details__grid">
            <div><dt>{{ t('account.handle') }}</dt><dd>{{ social.handle }}</dd></div>
            <div><dt>{{ t('account.followers') }}</dt><dd>{{ social.followers }}</dd></div>
          </dl>
        </article>
      </AccountProfileCard>
    </template>
    <template v-else-if="tab === 'portfolio'">
      <AccountProfileCard :title="t('account.portfolio')" :icon="Images" editable @edit="emit('edit', 'portfolio')">
        <p v-if="!profile.portfolio.length" class="profile-editor__empty">{{ t('account.noPortfolioItems') }}</p>
        <article v-for="(item, index) in profile.portfolio" :key="item.id || index" class="profile-details__item">
          <h3>{{ item.title || t('account.mediaTitle') }}</h3>
          <p>{{ item.platform === 'All' ? t('account.allPlatforms') : item.platform }}</p>
          <img v-if="item.type === 'image' && item.url" class="profile-details__media" :src="item.url" :alt="item.title || t('account.image')" loading="lazy" />
          <p v-else>{{ item.url || t('account.notProvided') }}</p>
        </article>
      </AccountProfileCard>
    </template>
    <template v-else-if="tab === 'packages'">
      <AccountProfileCard :title="t('account.packages')" :icon="Handshake" editable @edit="emit('edit', 'packages')">
        <p v-if="!profile.packages.length" class="profile-editor__empty">{{ t('account.noPackages') }}</p>
        <article v-for="(item, index) in profile.packages" :key="item.id || index" class="profile-details__item">
          <h3>{{ item.title }}</h3>
          <p>{{ item.platform }}</p>
          <p class="profile-details__description">{{ item.description }}</p>
          <p>{{ item.price ? formatMoney(item.price, item.currency || 'BAM') : t('account.notProvided') }}</p>
        </article>
      </AccountProfileCard>
    </template>
    <template v-else-if="tab === 'faqs'">
      <AccountProfileCard :title="t('account.creatorFaqs')" :icon="MessageCircleQuestion" editable @edit="emit('edit', 'faqs')">
        <p v-if="!profile.faqs.length" class="profile-editor__empty">{{ t('account.noCreatorFaqs') }}</p>
        <article v-for="(faq, index) in profile.faqs" :key="index" class="profile-details__item">
          <h3>{{ faq.question }}</h3>
          <p class="profile-details__description">{{ faq.answer }}</p>
        </article>
      </AccountProfileCard>
    </template>
  </section>
</template>

<style lang="scss" src="../../scss/components/account/AccountProfileDetails.scss"></style>
