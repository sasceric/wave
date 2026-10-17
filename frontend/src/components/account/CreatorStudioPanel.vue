<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ArrowDown, ArrowUp, Images, Pencil, Pin, Plus, Trash2, UsersRound, Handshake } from '@lucide/vue'
import ProfileCollectionCard from './ProfileCollectionCard.vue'
import CreatorDeliverables from './CreatorDeliverables.vue'
import SingleSelect from '../shared/SingleSelect.vue'
import SearchableSelect from '../shared/SearchableSelect.vue'
import SwitchField from '../shared/SwitchField.vue'
import DatePicker from '../shared/DatePicker.vue'
import MediaUploadField from '../shared/MediaUploadField.vue'
import StatusMessage from '../shared/StatusMessage.vue'
import CreatorAudience from '../creators/CreatorAudience.vue'
import CreatorWorks from '../creators/CreatorWorks.vue'
import CreatorPackageFacts from '../creators/CreatorPackageFacts.vue'
import CreatorMediaKit from '../creators/CreatorMediaKit.vue'
import { emptyWork, PRESENTATION_PLATFORMS } from '../../lib/creatorPresentation'
import { apiGet, formatMoney } from '../../lib/api'
import { dateOnly } from '../../lib/datePicker'
import { CURRENCIES } from '../../lib/marketplace'
const profile = defineModel('profile', { type: Object, required: true })
const props = defineProps({ tab: { type: String, required: true }, editing: Boolean })
const emit = defineEmits(['edit'])
const collection = computed(() => ({
    social: { title: t('studio.audience'), icon: UsersRound, hint: t('studio.audienceIntro'), emptyMessage: t('account.noSocialProfiles'), count: profile.value.socialProfiles.length, limit: 5, addLabel: t('account.addSocial'), add: addSocial },
    portfolio: { title: t('studio.selectedWorks'), icon: Images, hint: t('studio.worksIntro'), emptyMessage: t('account.noPortfolioItems'), count: profile.value.portfolio.length, limit: 4, addLabel: t('account.addPortfolioItem'), add: addWork },
    packages: { title: t('account.packages'), icon: Handshake, hint: t('account.packagesHint'), emptyMessage: t('account.noPackages'), count: profile.value.packages.length, limit: 12, addLabel: t('account.addPackage'), add: addPackage },
})[props.tab])
const { t, te } = useI18n()
const step = ref(1)
const panel = ref(null)
async function revealInvalidField(field) {
    if (!panel.value?.contains(field)) return
    const section = field.closest('[data-studio-step]')
    const network = field.closest('[data-studio-network]')
    const packageSection = field.closest('[data-studio-package]')
    if (section) step.value = Number(section.dataset.studioStep)
    if (network) selectedNetwork.value = Number(network.dataset.studioNetwork)
    if (packageSection) selectedPackage.value = Number(packageSection.dataset.studioPackage)
    await nextTick()
    const control = field.matches('input, textarea') ? field : field.querySelector('[data-validation-control], button, input')
    control?.focus()
    control?.scrollIntoView({ block: 'center', behavior: 'smooth' })
}
defineExpose({ revealInvalidField })
const selectedNetwork = ref(0)
const selectedPackage = ref(null)
const selectedWork = ref(null)
const campaigns = ref([])
const nextCursor = ref(null)
const loading = ref(false)
const loadError = ref('')
let generation = 0
const platforms = computed(() => PRESENTATION_PLATFORMS.map(value => ({ value, label: value })))
const allPlatforms = computed(() => [{ value: 'All', label: t('creatorProfile.allPlatforms') }, ...platforms.value])
const choices = values => values.map(value => ({ value, label: t(profile.value.companyType === 'agency' && value === 'posting' ? 'agency.posting' : `studio.${value}`) }))
const work = computed(() => profile.value.portfolio[selectedWork.value])
const campaignOptions = computed(() => {
    const options = new Map(campaigns.value.map(row => [row.id, { value: row.id, label: `${row.company} · ${row.title}` }]))
    for (const item of profile.value.portfolio) {
        if (item.source === 'wave' && item.campaignId && !options.has(item.campaignId)) {
            options.set(item.campaignId, { value: item.campaignId, label: `${item.agency || item.client} · ${item.title}` })
        }
    }
    return [...options.values()]
})
const agencyCampaign = computed(() => {
    const campaign = campaigns.value.find(row => row.id === work.value?.campaignId)
    return campaign ? campaign.companyType === 'agency' : Boolean(work.value?.agency)
})
async function loadCampaigns(append = false) {
    if (loading.value)
        return
    const token = ++generation
    loading.value = true
    loadError.value = ''
    try {
        const result = await apiGet(`/me/portfolio-campaigns${append && nextCursor.value ? `?before=${nextCursor.value}` : ''}`)
        if (token !== generation)
            return
        campaigns.value = append ? [...campaigns.value, ...result.data] : result.data
        nextCursor.value = result.meta.nextCursor
    }
    catch (cause) {
        if (token === generation)
            loadError.value = cause.message
    }
    finally {
        if (token === generation)
            loading.value = false
    }
}
watch(() => [profile.value.id, props.tab, props.editing], () => {
    selectedWork.value = null
    selectedNetwork.value = 0
    selectedPackage.value = null
    step.value = 1
    if (props.tab === 'portfolio' && props.editing && (!profile.value.companyType || profile.value.providerId)) loadCampaigns()
}, { immediate: true })
onBeforeUnmount(() => { ++generation; })
function addWork() { if (profile.value.portfolio.length < 4) {
    profile.value.portfolio.push(emptyWork())
    selectedWork.value = profile.value.portfolio.length - 1
    step.value = 1
} }
function pin(item, enabled) { if (enabled)
    profile.value.portfolio.forEach(row => { row.featured = row === item; })
else
    item.featured = false; }
function move(index, delta) { const target = index + delta; if (target >= 0 && target < profile.value.portfolio.length) {
    const list = profile.value.portfolio
        const previous = list[index]
        list[index] = list[target]
        list[target] = previous
} }
function pickCampaign(id) { const campaign = campaigns.value.find(row => row.id === id); if (campaign && work.value) {
    if (campaign.companyType === 'agency') {
        work.value.agency = campaign.company
        work.value.client = ''
    }
    else {
        work.value.client = campaign.company
        work.value.agency = ''
    }
    if (!work.value.title)
        work.value.title = campaign.title
    if (!work.value.projectDate)
        work.value.projectDate = campaign.finishedAt || ''
} }
function changeType(item) { item.mediaId = null; item.url = ''; delete item.embedUrl; }
function changeMediaType(item) { item.mediaId = null; item.url = ''; }
function addSocial() {
    if (profile.value.socialProfiles.length >= 5) return
    profile.value.socialProfiles.push({ platform: 'Instagram', handle: '', followers: null, averageViews: null, sampleSize: 10, engagementRate: null, engagementBasis: 'followers', audienceCountries: [], lastUpdated: dateOnly() })
    selectedNetwork.value = profile.value.socialProfiles.length - 1
}
function removeSocial(index) {
    profile.value.socialProfiles.splice(index, 1)
    selectedNetwork.value = Math.max(0, Math.min(index, profile.value.socialProfiles.length - 1))
}
function addPackage() {
    if (profile.value.packages.length >= 12) return
    profile.value.packages.push({ id: '', platform: 'All', title: '', description: '', price: null, currency: 'BAM', mode: 'content', priceMode: 'agreement', deliveryDays: null, revisions: null, usage: 'agreement', usageDays: null, deliverables: [] })
    selectedPackage.value = profile.value.packages.length - 1
}
function removePackage(index) {
    profile.value.packages.splice(index, 1)
    selectedPackage.value = null
}
function removeWork(index) { profile.value.portfolio.splice(index, 1); selectedWork.value = null; }
function countryError(social) {
    const codes = social.audienceCountries.map(row => row.countryCode).filter(Boolean)
    return new Set(codes).size !== codes.length || social.audienceCountries.reduce((sum, row) => sum + Number(row.percent || 0), 0) > 100
}
const countryOptions = ref([])
import countries from '../../data/countries.json'
const { locale } = useI18n()
watch(locale, language => { const names = new Intl.DisplayNames([language === 'cnr' ? 'bs' : language === 'sr' ? 'sr-Latn' : language], { type: 'region' }); countryOptions.value = countries.map(value => ({ value, label: te(`companyDirectory.countryNames.${value}`) ? t(`companyDirectory.countryNames.${value}`) : names.of(value) || value })).sort((a, b) => a.label.localeCompare(b.label)); }, { immediate: true })
</script>
<template>
  <section
    ref="panel"
    :id="`account-panel-${tab}`"
    class="creator-studio profile-tab-panel"
    role="tabpanel"
    :aria-labelledby="`account-tab-${tab}`"
  >
    <template v-if="tab === 'kit'">
      <CreatorMediaKit :profile="profile" />
    </template>
    <ProfileCollectionCard
      v-else
      :title="collection.title"
      :icon="collection.icon"
      :hint="collection.hint"
      :empty="collection.count === 0"
      :empty-message="collection.emptyMessage"
      :editing="editing"
      :add-label="collection.addLabel"
      :add-disabled="collection.count >= collection.limit"
      @add="collection.add()"
      @edit="emit('edit', tab)"
    >
      <template v-if="!editing">
        <CreatorAudience v-if="tab === 'social' && profile.socialProfiles.length" :demo="profile.isPresentationDemo" :profiles="profile.socialProfiles" :languages="profile.presentation?.languages || []" :description="profile.tagline || ''" :show-heading="false" />
        <CreatorWorks
          v-else-if="tab === 'portfolio' && profile.portfolio.length"
          :items="profile.portfolio"
          :show-heading="false"
          :name="profile.displayName || profile.name"
        />
        <template v-else-if="tab === 'packages' && profile.packages.length">
          <article v-for="item in profile.packages" :key="item.id" class="creator-studio__package">
            <header>
              <h3>{{ item.title }}</h3>
              <strong>
                {{ item.priceMode === 'from' ? t('studio.priceFrom') : '' }}
                {{ item.price ? formatMoney(item.price,item.currency) : t('creatorProfile.priceByAgreement') }}
              </strong>
            </header>
            <p>{{ item.description }}</p>
            <CreatorPackageFacts :item="item" :agency="profile.companyType === 'agency'" />
          </article>
        </template>
    </template>
    <template v-else-if="tab === 'social'">
      <div v-if="profile.socialProfiles.length" class="creator-studio__networks" role="group" :aria-label="t('studio.audience')">
        <button
          v-for="(social, index) in profile.socialProfiles"
          :key="index"
          type="button"
          :aria-pressed="selectedNetwork === index"
          @click="selectedNetwork = index"
        >
          {{ social.platform }}
          <small v-if="social.handle">{{ social.handle }}</small>
        </button>
      </div>
      <article
        v-for="(social,index) in profile.socialProfiles"
        :key="index"
        v-show="selectedNetwork === index"
        :data-studio-network="index"
        class="creator-studio__card"
      >
        <header>
          <h3>{{ social.platform }}</h3>
          <button class="profile-editor__remove" type="button" @click="removeSocial(index)">
            <Trash2 :size="15" />
            {{ t('account.remove') }}
          </button>
        </header>
        <div class="form-grid">
          <SingleSelect v-model="social.platform" :label="t('account.platform')" :options="platforms" required />
          <label class="form-field">
            <span>{{ t('account.handle') }}</span>
            <input v-model.trim="social.handle" required maxlength="120" />
          </label>
          <label class="form-field">
            <span>{{ t('account.followers') }}</span>
            <input v-model.number="social.followers" type="number" min="0" max="200000000" />
          </label>
          <label class="form-field">
            <span>
              {{ t(social.platform === 'Spotify' ? 'studio.averagePlays' : social.platform === 'Twitch' ? 'studio.averageLive' : 'studio.averageViews') }}
            </span>
            <input v-model.number="social.averageViews" type="number" min="0" max="2000000000" />
          </label>
          <label class="form-field">
            <span>{{ t('studio.sampleSize') }}</span>
            <input v-model.number="social.sampleSize" type="number" min="1" max="1000" />
            <small>{{ t('studio.sampleHint') }}</small>
          </label>
          <DatePicker
            :model-value="social.lastUpdated || ''"
            :label="t('studio.measuredAt')"
            :max="dateOnly()"
            :placeholder="t('datePicker.placeholder')"
            @update:model-value="social.lastUpdated = $event"
          />
          <label class="form-field">
            <span>{{ t('studio.engagement') }} (%)</span>
            <input v-model.number="social.engagementRate" type="number" min="0" max="100" step="0.01" />
          </label>
          <SingleSelect
            v-model="social.engagementBasis"
            :label="t('studio.engagementBasis')"
            :options="['followers','reach','views','platform'].map(value=>({value,label:t(`studio.basis_${value}`)}))"
          />
        </div>
        <div class="creator-studio__repeat">
          <header>
            <h3>{{ t('studio.countries') }}</h3>
            <button
              type="button"
              class="profile-editor__add"
              :disabled="social.audienceCountries.length >= 5"
              @click="social.audienceCountries.push({countryCode:'',percent:null})"
            >
              <Plus :size="16" />
              {{ t('studio.addCountry') }}
            </button>
          </header>
          <div v-for="(country,countryIndex) in social.audienceCountries" :key="countryIndex" class="creator-studio__country">
            <SearchableSelect
              v-model="country.countryCode"
              :options="countryOptions"
              :label="t('auth.country')"
              :placeholder="t('auth.selectCountry')"
              :search-placeholder="t('studio.search')"
              :no-results-label="t('studio.noResults')"
              required
            />
            <label class="form-field">
              <span>{{ t('studio.percent') }}</span>
              <input v-model.number="country.percent" type="number" min="0" max="100" step="0.1" required />
            </label>
            <button
              class="creator-studio__icon"
              type="button"
              :aria-label="t('account.remove')"
              @click="social.audienceCountries.splice(countryIndex,1)"
            >
              <Trash2 :size="16" />
            </button>
          </div>
          <StatusMessage v-if="countryError(social)" variant="error">{{ t('studio.countryValidation') }}</StatusMessage>
          <p class="creator-studio__hint">
            {{ t('studio.remainingCountries',{percent:Math.max(0,100-social.audienceCountries.reduce((sum,row)=>sum+Number(row.percent||0),0))}) }}
          </p>
        </div>
        <p class="creator-studio__hint">{{ t('studio.selfReportedHint') }}</p>
      </article>
    </template>
    <template v-else-if="tab === 'portfolio'">
      <div v-if="!work" class="creator-studio__card">
        <article v-for="(item,index) in profile.portfolio" :key="item.id || index" class="creator-studio__work-row">
          <img v-if="item.coverUrl || item.url && item.type === 'image'" :src="item.coverUrl || item.url" :alt="item.title" />
          <div>
            <h3>{{ item.title || t('studio.workTitle') }}</h3>
            <p>{{ item.client || t(`studio.${item.source}`) }}</p>
            <small v-if="item.featured">{{ t('studio.featuredWork') }}</small>
          </div>
          <div class="creator-studio__row-actions">
            <button class="creator-studio__icon" type="button" :aria-label="t('studio.moveUp')" :disabled="index === 0" @click="move(index,-1)">
              <ArrowUp :size="16" />
            </button>
            <button
              class="creator-studio__icon"
              type="button"
              :aria-label="t('studio.moveDown')"
              :disabled="index === profile.portfolio.length-1"
              @click="move(index,1)"
            >
              <ArrowDown :size="16" />
            </button>
            <button class="creator-studio__icon" type="button" :aria-label="t('studio.featuredWork')" @click="pin(item,!item.featured)">
              <Pin :size="16" />
            </button>
            <button class="button button--outline" type="button" @click="selectedWork=index;step=1">
              <Pencil :size="15" />
              {{ t('account.editProfile') }}
            </button>
            <button class="creator-studio__icon" type="button" :aria-label="t('account.remove')" @click="removeWork(index)">
              <Trash2 :size="16" />
            </button>
          </div>
        </article>
      </div>
      <article v-else class="creator-studio__card">
        <header>
          <h3>{{ work.title || t('studio.workTitle') }}</h3>
          <button type="button" class="button button--outline creator-studio__back" @click="selectedWork=null">{{ t('account.back') }}</button>
        </header>
        <nav class="creator-studio__steps" :aria-label="t('studio.workDetails')">
          <button v-for="value in [1,2,3]" :key="value" type="button" :aria-pressed="step===value" @click="step=value">
            <b>{{ value }}</b>
            {{ t(`studio.step${value}`) }}
          </button>
        </nav>
        <div data-studio-step="1" v-show="step===1" class="form-grid">
          <label class="form-field form-field--wide">
            <span>{{ t('studio.workTitle') }}</span>
            <input v-model.trim="work.title" maxlength="120" required />
          </label>
          <label class="form-field form-field--wide">
            <span>{{ t('studio.summary') }}</span>
            <textarea v-model.trim="work.summary" maxlength="300"></textarea>
          </label>
          <SingleSelect v-model="work.source" :label="t('studio.source')" :options="choices(['wave','external','sample'])" />
          <DatePicker
            :model-value="work.projectDate || ''"
            :label="t('studio.projectDate')"
            :placeholder="t('datePicker.placeholder')"
            @update:model-value="work.projectDate=$event"
          />
          <template v-if="work.source==='wave'">
            <SingleSelect
              v-model="work.campaignId"
              class="form-field--wide"
              :label="t('studio.campaign')"
              :options="campaignOptions"
              required
              @update:model-value="pickCampaign"
            />
            <button v-if="nextCursor" class="profile-editor__add" type="button" :disabled="loading" @click="loadCampaigns(true)">
              {{ t('studio.loadMore') }}
            </button>
            <StatusMessage v-if="loadError" variant="error">{{ loadError }}</StatusMessage>
          </template>
          <label v-if="work.source!=='sample'" class="form-field">
            <span>{{ t('studio.client') }}</span>
            <input v-model.trim="work.client" maxlength="120" :readonly="work.source==='wave' && !agencyCampaign" />
          </label>
          <label v-if="work.source!=='sample'" class="form-field">
            <span>{{ t('studio.agency') }}</span>
            <input v-model.trim="work.agency" maxlength="120" />
          </label>
          <SwitchField
            class="form-field--wide"
            :model-value="work.featured?1:0"
            :label="t('studio.featuredWork')"
            :description="t('studio.featuredHint')"
            @update:model-value="pin(work,$event===1)"
          />
        </div>
        <div data-studio-step="2" v-show="step===2">
          <div class="form-grid">
            <label class="form-field form-field--wide">
              <span>{{ t('studio.brief') }}</span>
              <textarea v-model.trim="work.brief" maxlength="1500"></textarea>
            </label>
            <label class="form-field form-field--wide">
              <span>{{ t('studio.contribution') }}</span>
              <textarea v-model.trim="work.contribution" maxlength="1500"></textarea>
            </label>
            <SingleSelect
              v-model="work.type"
              :label="t('account.mediaType')"
              :options="[{value:'image',label:t('account.image')},{value:'video',label:t('account.video')}]"
              @update:model-value="changeType(work)"
            />
            <SingleSelect v-model="work.platform" :label="t('account.platform')" :options="allPlatforms" />
          </div>
          <CreatorDeliverables v-model:items="work.deliverables" />
          <MediaUploadField
            v-if="work.type==='image'"
            v-model="work.mediaId"
            folder="creator-portfolio"
            :preview-url="work.url"
            @uploaded="work.url=$event.url"
          />
          <label v-else class="form-field">
            <span>{{ t('account.mediaUrl') }}</span>
            <input v-model.trim="work.url" type="url" maxlength="1000" placeholder="https://" required />
            <small>{{ t('studio.videoHint') }}</small>
          </label>
          <h3 v-if="work.type === 'video'" class="creator-studio__subtitle">{{ t('studio.cover') }}</h3>
          <MediaUploadField
            v-if="work.type==='video'"
            v-model="work.coverMediaId"
            folder="creator-portfolio"
            :preview-url="work.coverUrl || ''"
            optional
            @uploaded="work.coverUrl=$event.url"
          />
          <div class="creator-studio__repeat">
            <header>
              <h3>{{ t('studio.extraMedia') }}</h3>
              <button
                type="button"
                class="profile-editor__add"
                :disabled="work.media.length>=4"
                @click="work.media.push({type:'video',url:'',mediaId:null})"
              >
                <Plus :size="16" />
                {{ t('studio.addItem') }}
              </button>
            </header>
            <article v-for="(media,index) in work.media" :key="index" class="creator-studio__card">
              <header>
                <SingleSelect
                  v-model="media.type"
                  :label="t('account.mediaType')"
                  :options="[{value:'image',label:t('account.image')},{value:'video',label:t('account.video')}]"
                  @update:model-value="changeMediaType(media)"
                />
                <button type="button" class="creator-studio__icon" :aria-label="t('account.remove')" @click="work.media.splice(index,1)">
                  <Trash2 :size="16" />
                </button>
              </header>
              <MediaUploadField
                v-if="media.type==='image'"
                v-model="media.mediaId"
                folder="creator-portfolio"
                :preview-url="media.url || ''"
                @uploaded="media.url=$event.url"
              />
              <label v-else class="form-field">
                <span>{{ t('account.mediaUrl') }}</span>
                <input v-model.trim="media.url" type="url" required maxlength="1000" />
              </label>
            </article>
          </div>
          <label class="form-field">
            <span>{{ t('studio.postUrl') }}</span>
            <input v-model.trim="work.postUrl" type="url" maxlength="1000" />
          </label>
        </div>
        <div data-studio-step="3" v-show="step===3">
          <div class="creator-studio__repeat">
            <header>
              <h3>{{ t('studio.results') }}</h3>
              <button
                class="profile-editor__add"
                type="button"
                :disabled="work.results.length>=8"
                @click="work.results.push({metric:'',value:null,period:''})"
              >
                <Plus :size="16" />
                {{ t('studio.addItem') }}
              </button>
            </header>
            <div v-for="(result,index) in work.results" :key="index" class="creator-studio__result">
              <label class="form-field">
                <span>{{ t('studio.metric') }}</span>
                <input v-model.trim="result.metric" required maxlength="80" />
              </label>
              <label class="form-field">
                <span>{{ t('studio.value') }}</span>
                <input v-model.number="result.value" type="number" min="0" max="2000000000" required />
              </label>
              <label class="form-field">
                <span>{{ t('studio.period') }}</span>
                <input v-model.trim="result.period" maxlength="120" />
              </label>
              <button class="creator-studio__icon" type="button" :aria-label="t('account.remove')" @click="work.results.splice(index,1)">
                <Trash2 :size="16" />
              </button>
            </div>
          </div>
          <DatePicker
            :model-value="work.measuredAt || ''"
            :label="t('studio.measuredAt')"
            :max="dateOnly()"
            :placeholder="t('datePicker.placeholder')"
            @update:model-value="work.measuredAt=$event"
          />
          <p class="creator-studio__hint">{{ t('studio.selfReportedHint') }}</p>
          <SwitchField
            :model-value="work.publicPermission?1:0"
            :label="t('studio.publicPermission')"
            :description="t('studio.permissionHint')"
            @update:model-value="work.publicPermission=$event===1"
          />
        </div>
        <div class="creator-studio__step-actions">
          <button v-if="step>1" type="button" class="button button--outline" @click="step--">{{ t('account.back') }}</button>
          <button v-if="step<3" type="button" class="button button--outline" @click="step++">{{ t('studio.next') }}</button>
        </div>
      </article>
    </template>
    <template v-else-if="tab === 'packages'">
      <div v-if="selectedPackage === null" class="creator-studio__card">
        <article v-for="(item, index) in profile.packages" :key="item.id || index" class="creator-studio__work-row">
          <div>
            <h3>{{ item.title || t('account.packageTitle') }}</h3>
            <p>{{ item.platform === 'All' ? t('creatorProfile.allPlatforms') : item.platform }} · {{ t(profile.companyType === 'agency' && item.mode === 'posting' ? 'agency.posting' : `studio.${item.mode}`) }}</p>
            <strong class="creator-studio__price">
              {{ item.priceMode === 'from' ? t('studio.priceFrom') : '' }}
              {{ item.priceMode === 'agreement' || !item.price ? t('creatorProfile.priceByAgreement') : formatMoney(item.price, item.currency) }}
            </strong>
          </div>
          <div class="creator-studio__row-actions">
            <button class="button button--outline" type="button" @click="selectedPackage = index">
              <Pencil :size="15" />
              {{ t('account.editProfile') }}
            </button>
            <button class="creator-studio__icon" type="button" :aria-label="t('account.remove')" @click="removePackage(index)">
              <Trash2 :size="16" />
            </button>
          </div>
        </article>
      </div>
      <article
        v-for="(item,index) in profile.packages"
        :key="item.id || index"
        v-show="selectedPackage === index"
        :data-studio-package="index"
        class="creator-studio__card"
      >
        <header>
          <h3>{{ item.title || t('account.packageTitle') }}</h3>
          <button type="button" class="button button--outline creator-studio__back" @click="selectedPackage = null">{{ t('studio.backToPackages') }}</button>
        </header>
        <div class="form-grid">
          <label class="form-field form-field--wide">
            <span>{{ t('account.packageTitle') }}</span>
            <input v-model.trim="item.title" required minlength="3" maxlength="120" />
          </label>
          <label class="form-field form-field--wide">
            <span>{{ t('account.packageDescription') }}</span>
            <textarea v-model.trim="item.description" required minlength="10" maxlength="1200"></textarea>
          </label>
          <SingleSelect v-model="item.mode" :label="t('studio.mode')" :options="choices(['content','posting'])" />
          <SingleSelect v-model="item.platform" :label="t('account.platform')" :options="allPlatforms" />
        </div>
        <CreatorDeliverables v-model:items="item.deliverables" />
        <div class="form-grid">
          <SingleSelect
            v-model="item.priceMode"
            :label="t('studio.priceMode')"
            :options="['fixed','from','agreement'].map(value=>({value,label:t(value==='from'?'studio.priceFrom':`studio.${value}`)}))"
          />
          <label v-if="item.priceMode!=='agreement'" class="form-field">
            <span>{{ t('studio.price') }}</span>
            <input v-model.number="item.price" type="number" min="1" max="10000000" required />
          </label>
          <SingleSelect
            v-if="item.priceMode!=='agreement'"
            v-model="item.currency"
            :label="t('account.currency')"
            :options="CURRENCIES.map(value=>({value,label:value}))"
          />
          <label class="form-field">
            <span>{{ t('studio.deliveryDays') }}</span>
            <input v-model.number="item.deliveryDays" type="number" min="1" max="365" />
          </label>
          <label class="form-field">
            <span>{{ t('studio.revisions') }}</span>
            <input v-model.number="item.revisions" type="number" min="0" max="100" />
          </label>
          <SingleSelect v-model="item.usage" :label="t('studio.usage')" :options="choices(['organic','ads','agreement'])" />
          <label v-if="item.usage==='ads'" class="form-field">
            <span>{{ t('studio.usageDays') }}</span>
            <input v-model.number="item.usageDays" type="number" min="1" max="3650" required />
          </label>
        </div>
        <div class="creator-studio__package-preview">
          <h3>{{ item.title }}</h3>
          <strong>
            {{ item.priceMode==='agreement'?t('creatorProfile.priceByAgreement'):`${item.priceMode==='from'?t('studio.priceFrom'):''} ${formatMoney(item.price||0,item.currency)}` }}
          </strong>
          <CreatorPackageFacts :item="item" :agency="profile.companyType === 'agency'" />
        </div>
      </article>
    </template>
    </ProfileCollectionCard>
  </section>
</template>
<style lang="scss" src="../../scss/components/account/CreatorStudioPanel.scss"></style>
