<script setup>
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Plus, Trash2 } from '@lucide/vue'
import FormModal from '../shared/FormModal.vue'
import MultiSelect from '../shared/MultiSelect.vue'
import ProfileImageField from '../shared/ProfileImageField.vue'
import SingleSelect from '../shared/SingleSelect.vue'
import StatusMessage from '../shared/StatusMessage.vue'
import { currentUser, loadCurrentUser } from '../../composables/useCurrentUser'
import { useMarketplaceCatalog } from '../../composables/useMarketplaceCatalog'
import { apiRequest } from '../../lib/api'
import { PRESENTATION_PLATFORMS } from '../../lib/creatorPresentation'
import { dateOnly } from '../../lib/datePicker'
import { COMPANY_SOCIAL_PLATFORMS } from '../../lib/marketplace'
import { accountProfileDraft, htmlToPlainText, paragraphsHtml, profileRequestBody } from '../../lib/profileDraft'
import { ABOUT_MIN_LENGTH, BIO_MIN_LENGTH } from '../../lib/profileStrength'

// One quick editor per profile-strength step. Saves through the regular profile
// endpoint with the complete current profile, changing only this step's field.
const props = defineProps({
  open: { type: Boolean, required: true },
  step: { type: String, default: '' },
  kind: { type: String, default: 'creator' },
  points: { type: Number, default: 0 },
})
const emit = defineEmits(['update:open', 'saved'])
const { t, locale } = useI18n()
const busy = ref(false)
const error = ref('')
const imageField = ref(null)
const imageDirty = ref(false)
const text = ref('')
const socialRows = ref([])
const links = ref([])
const pkg = ref({ title: '', description: '', price: '' })
const industries = ref([])
const faqs = ref([])
const { industries: catalogIndustries } = useMarketplaceCatalog(locale, { includeIndustries: true })

const creator = computed(() => props.kind === 'creator')
const imageStep = computed(() => ['photo', 'logo', 'cover'].includes(props.step))
const textMin = computed(() => props.step === 'bio' ? BIO_MIN_LENGTH : ABOUT_MIN_LENGTH)
const textLength = computed(() => text.value.replace(/\s+/g, ' ').trim().length)
const industryOptions = computed(() => {
  const options = catalogIndustries.value || []
  return [...options, ...industries.value.filter((value) => !options.some((option) => option.value === value)).map((value) => ({ value, label: value }))]
})
const faqQuestions = computed(() => [1, 2, 3].map((n) => t(`profileStrength.faq${creator.value ? 'Creator' : 'Agency'}${n}`)))

watch(() => [props.open, props.step], ([open]) => {
  if (!open) return
  const profile = currentUser.value?.profile || {}
  error.value = ''
  imageDirty.value = false
  text.value = htmlToPlainText(props.step === 'bio' ? profile.bio : profile.about)
  socialRows.value = [{ platform: 'Instagram', handle: '', followers: '' }]
  links.value = (profile.socialLinks || []).length ? profile.socialLinks.map((row) => ({ ...row })) : [{ platform: 'Website', url: '' }]
  pkg.value = { title: '', description: '', price: '' }
  industries.value = [...(profile.industries || [])]
  faqs.value = faqQuestions.value.map((question) => ({ question, answer: '' }))
}, { immediate: true })

const valid = computed(() => {
  switch (props.step) {
    case 'photo': case 'logo': case 'cover': return imageDirty.value
    case 'bio': case 'about': return textLength.value >= textMin.value
    case 'social': return socialRows.value.some((row) => row.handle.trim() && Number(row.followers) > 0)
    case 'links': return links.value.some((row) => row.url.trim())
    case 'packages': return pkg.value.title.trim().length >= 3 && pkg.value.description.trim().length >= 10 && Number(pkg.value.price) >= 1
    case 'industries': return industries.value.length > 0
    case 'faq': return faqs.value.some((row) => row.answer.trim().length >= 10)
    default: return false
  }
})

function apply(profile, image) {
  switch (props.step) {
    case 'photo':
      profile.avatarMediaId = image?.mediaId || null
      profile.avatarUrl = image?.url || null
      break
    case 'logo':
      profile.logoMediaId = image?.mediaId || null
      profile.logoUrl = image?.url || null
      break
    case 'cover':
      profile.coverMediaId = image?.mediaId || null
      break
    case 'bio': profile.bio = paragraphsHtml(text.value); break
    case 'about': profile.about = paragraphsHtml(text.value); break
    case 'social':
      profile.socialProfiles = [
        ...profile.socialProfiles,
        ...socialRows.value.filter((row) => row.handle.trim() && Number(row.followers) > 0).map((row) => ({
          platform: row.platform, handle: row.handle.trim(), followers: Math.round(Number(row.followers)), averageViews: null, sampleSize: 10,
          engagementRate: null, engagementBasis: 'followers', audienceCountries: [], lastUpdated: dateOnly(),
        })),
      ].slice(0, 5)
      break
    case 'links':
      profile.socialLinks = links.value.filter((row) => row.url.trim()).map((row) => ({ platform: row.platform, url: row.url.trim() }))
      break
    case 'packages':
      profile.packages = [...profile.packages, {
        id: '', platform: 'All', title: pkg.value.title.trim(), description: pkg.value.description.trim(), price: Math.round(Number(pkg.value.price)),
        currency: 'BAM', mode: 'content', priceMode: 'fixed', deliveryDays: null, revisions: null, usage: 'agreement', usageDays: null, deliverables: [],
      }].slice(0, 12)
      break
    case 'industries': profile.industries = [...industries.value]; break
    case 'faq':
      profile.faqs = [...profile.faqs, ...faqs.value.filter((row) => row.answer.trim().length >= 10).map((row) => ({ question: row.question, answer: row.answer.trim() }))].slice(0, 10)
      break
  }
}

async function save() {
  if (busy.value || !valid.value) return
  const user = currentUser.value
  const draft = accountProfileDraft(user)
  if (!draft) return
  busy.value = true
  error.value = ''
  let image = null
  let saved = false
  try {
    if (imageStep.value) {
      image = await imageField.value?.prepareSave()
      if (!image) return
    }
    apply(draft, image)
    await apiRequest('/me/profile', { method: 'PUT', body: profileRequestBody(user, draft) })
    saved = true
    imageField.value?.commit()
    await loadCurrentUser()
    emit('saved', { step: props.step, points: props.points })
    emit('update:open', false)
  } catch (cause) {
    if (!saved && image?.uploadedMediaId) {
      try { await imageField.value?.rollback(image) } catch { /* The upload is removed by the scheduled media cleanup. */ }
    }
    error.value = cause.code === 'phone' ? t('profileStrength.phoneMissing') : cause.message
  } finally {
    busy.value = false
  }
}

const imageProps = computed(() => {
  const profile = currentUser.value?.profile || {}
  if (props.step === 'cover') {
    return { folder: 'company-cover', companyType: profile.companyType || 'brand', modelValue: profile.coverMediaId || null, previewUrl: profile.coverUrl || '', addLabel: t('account.addCompanyCover'), changeLabel: t('account.changeCompanyCover'), removeLabel: t('account.removeCompanyCover'), helperText: t('account.coverUploadHint'), removeTitle: t('account.removeCompanyCoverTitle'), removeMessage: t('account.removeCompanyCoverMessage') }
  }
  const avatar = props.step === 'photo'
  return {
    folder: avatar ? 'creator-avatar' : 'company-logo',
    modelValue: (avatar ? profile.avatarMediaId : profile.logoMediaId) || null,
    previewUrl: (avatar ? profile.avatarUrl : profile.logoUrl) || '',
    addLabel: t('account.addProfileImage'), changeLabel: t('account.changeProfileImage'), removeLabel: t('account.removeProfileImage'),
    helperText: t(avatar ? 'profileStrength.photoTip' : 'profileStrength.logoTip'),
    removeTitle: t('account.profileImageRemoveTitle'), removeMessage: t('account.profileImageRemoveMessage'),
  }
})
</script>

<template>
  <FormModal :open="open" :title="t(`profileStrength.steps.${step}.title`)" :description="t(`profileStrength.steps.${step}.why`)" :busy="busy" @update:open="emit('update:open', $event)" @submit="save">
    <div class="profile-step" :class="`profile-step--${step}`">
      <p class="profile-step__points">+{{ points }}%</p>

      <div v-if="imageStep" class="profile-step__image" @change="imageDirty = true">
        <ProfileImageField
          ref="imageField"
          :class="{ 'company-cover-field': step === 'cover' }"
          v-bind="imageProps"
          :alt="currentUser?.profile?.displayName || currentUser?.profile?.name || ''"
          :confirm-label="t('account.remove')"
          :cancel-label="t('account.richTextCancel')"
          :disabled="busy"
        />
        <p v-if="step === 'cover'" class="profile-step__tip">{{ t('profileStrength.coverTip') }}</p>
      </div>

      <label v-else-if="step === 'bio' || step === 'about'" class="form-field">
        <span>{{ t(step === 'bio' ? 'account.bio' : 'account.companyAbout') }}</span>
        <textarea v-model="text" rows="7" maxlength="1500" :placeholder="t(`profileStrength.${step}Placeholder`)" :disabled="busy"></textarea>
        <small class="profile-step__count" :class="{ 'is-ok': textLength >= textMin }">{{ t('profileStrength.minChars', { count: textLength, min: textMin }) }}</small>
      </label>

      <template v-else-if="step === 'social'">
        <div v-for="(row, index) in socialRows" :key="index" class="profile-step__row profile-step__row--social">
          <SingleSelect v-model="row.platform" :label="t('profileStrength.network')" :options="PRESENTATION_PLATFORMS.map((value) => ({ value, label: value }))" :disabled="busy" />
          <label class="form-field"><span>{{ t('account.handle') }}</span><input v-model="row.handle" maxlength="120" :placeholder="t('profileStrength.handlePlaceholder')" :disabled="busy" /></label>
          <label class="form-field"><span>{{ t('account.followers') }}</span><input v-model="row.followers" type="number" min="1" max="200000000" inputmode="numeric" placeholder="12400" :disabled="busy" /></label>
          <button v-if="socialRows.length > 1" class="profile-step__remove" type="button" :aria-label="t('account.remove')" :disabled="busy" @click="socialRows.splice(index, 1)"><Trash2 :size="16" aria-hidden="true" /></button>
        </div>
        <button v-if="socialRows.length < 3" class="profile-step__add" type="button" :disabled="busy" @click="socialRows.push({ platform: 'TikTok', handle: '', followers: '' })"><Plus :size="16" aria-hidden="true" />{{ t('profileStrength.addNetwork') }}</button>
      </template>

      <template v-else-if="step === 'links'">
        <div v-for="(row, index) in links" :key="index" class="profile-step__row profile-step__row--link">
          <SingleSelect v-model="row.platform" :label="t('profileStrength.network')" :options="COMPANY_SOCIAL_PLATFORMS.map((value) => ({ value, label: value }))" :disabled="busy" />
          <label class="form-field"><span>{{ t('profileStrength.link') }}</span><input v-model="row.url" type="url" maxlength="500" placeholder="https://" inputmode="url" :disabled="busy" /></label>
          <button v-if="links.length > 1" class="profile-step__remove" type="button" :aria-label="t('account.remove')" :disabled="busy" @click="links.splice(index, 1)"><Trash2 :size="16" aria-hidden="true" /></button>
        </div>
        <button v-if="links.length < COMPANY_SOCIAL_PLATFORMS.length" class="profile-step__add" type="button" :disabled="busy" @click="links.push({ platform: COMPANY_SOCIAL_PLATFORMS.find((platform) => !links.some((row) => row.platform === platform)) || 'Website', url: '' })"><Plus :size="16" aria-hidden="true" />{{ t('profileStrength.addLink') }}</button>
      </template>

      <template v-else-if="step === 'packages'">
        <label class="form-field"><span>{{ t('profileStrength.packageTitle') }}</span><input v-model="pkg.title" maxlength="120" :placeholder="t(`profileStrength.packageTitlePlaceholder${creator ? '' : 'Agency'}`)" :disabled="busy" /></label>
        <label class="form-field"><span>{{ t('profileStrength.packageIncludes') }}</span><textarea v-model="pkg.description" rows="3" maxlength="1200" :placeholder="t(`profileStrength.packageIncludesPlaceholder${creator ? '' : 'Agency'}`)" :disabled="busy"></textarea></label>
        <label class="form-field"><span>{{ t('profileStrength.packagePrice') }}</span><input v-model="pkg.price" type="number" min="1" max="10000000" inputmode="numeric" placeholder="150" :disabled="busy" /></label>
        <p class="profile-step__tip">{{ t('profileStrength.packageTip') }}</p>
      </template>

      <MultiSelect
        v-else-if="step === 'industries'"
        v-model="industries"
        :options="industryOptions"
        :label="t('auth.industry')"
        :placeholder="t('companyDirectory.selectIndustries')"
        :search-placeholder="t('companyDirectory.searchIndustries')"
        :no-results-label="t('companyDirectory.noIndustries')"
        :remove-label="t('account.remove')"
        :max-selections="20"
        :disabled="busy"
      />

      <template v-else-if="step === 'faq'">
        <p class="profile-step__tip">{{ t('profileStrength.faqTip') }}</p>
        <label v-for="row in faqs" :key="row.question" class="form-field"><span>{{ row.question }}</span><input v-model="row.answer" maxlength="1500" :disabled="busy" /></label>
      </template>

      <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
    </div>
    <template #actions="{ close }">
      <button type="button" class="button button--outline" :disabled="busy" @click="close">{{ t('account.richTextCancel') }}</button>
      <button type="submit" class="button button--dark" :disabled="busy || !valid">{{ busy ? t('profileStrength.saving') : t('profileStrength.save') }}</button>
    </template>
  </FormModal>
</template>

<style lang="scss" src="../../scss/components/account/ProfileStepModal.scss"></style>
