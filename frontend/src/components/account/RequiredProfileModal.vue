<script setup>
import { plainProfileName } from '../../lib/plainProfileName'
import { companyTypeOptions } from '../../lib/companyTypes'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import LocalizedLink from '../shared/LocalizedLink.vue'
import SingleSelect from '../shared/SingleSelect.vue'
import MultiSelect from '../shared/MultiSelect.vue'
import PhoneNumberField from '../shared/PhoneNumberField.vue'
import SearchableSelect from '../shared/SearchableSelect.vue'
import StatusMessage from '../shared/StatusMessage.vue'

const props = defineProps({
  user: { type: Object, required: true },
  profile: { type: Object, required: true },
  industryOptions: { type: Array, default: () => [] },
  categories: { type: Array, default: () => [] },
  countryOptions: { type: Array, default: () => [] },
  phoneCountry: { type: String, required: true },
  busy: { type: Boolean, default: false },
  error: { type: String, default: '' },
})

const emit = defineEmits(['save', 'update:phoneCountry', 'update:profile'])
const { t } = useI18n()
const companyTypes = computed(() => companyTypeOptions(t))
const dialog = ref(null)
const profile = computed(() => new Proxy(props.profile, {
  set(target, key, value) {
    emit('update:profile', { ...target, [key]: value })
    return true
  },
}))

function updateCountry(countryCode) {
  profile.value.countryCode = countryCode
  emit('update:phoneCountry', countryCode)
}

onMounted(() => dialog.value?.showModal())
onBeforeUnmount(() => {
  if (dialog.value?.open) {
    dialog.value.close()
  }
})
</script>

<template>
  <dialog
    ref="dialog"
    class="required-profile-modal wave-modal"
    aria-modal="true"
    aria-labelledby="required-profile-title"
    aria-describedby="required-profile-description"
    @cancel.prevent
    @click.self.prevent
  >
    <section class="required-profile-modal__content wave-modal__content" :aria-busy="busy">
      <p class="eyebrow">{{ t('activation.start') }}</p>
      <h2 id="required-profile-title" class="wave-modal__title">{{ t('activation.title') }}</h2>
      <p id="required-profile-description">{{ t('activation.lockedHint') }}</p>
      <form v-form-validation class="required-profile-modal__form" @submit.prevent="emit('save')">
        <template v-if="user.accountType === 'creator'">
          <label class="form-field">
            <span>{{ t('auth.name') }}</span>
            <input v-model.trim="profile.displayName" @blur="profile.displayName = plainProfileName(profile.displayName)" required minlength="2" maxlength="120" autocomplete="name" autofocus />
          </label>
          <MultiSelect required
            v-model="profile.categories"
            :options="categories"
            :label="t('auth.category')"
            :placeholder="t('account.selectCategories')"
            :search-placeholder="t('account.searchCategories')"
            :no-results-label="t('account.noCategoriesFound')"
            :remove-label="t('account.remove')"
            :max-selections="5"
          />

        </template>
        <template v-else>
          <label class="form-field">
            <span>{{ t('account.companyName') }}</span>
            <input v-model.trim="profile.name" @blur="profile.name = plainProfileName(profile.name)" required minlength="2" maxlength="120" autocomplete="organization" autofocus />
          </label>
          <SingleSelect v-model="profile.companyType" :label="t('companyTypes.label')" :options="companyTypes" required />
          <MultiSelect required
              v-model="profile.industries"
              :options="industryOptions"
              :label="t('auth.industry')"
              :placeholder="t('companyDirectory.selectIndustries')"
              :search-placeholder="t('companyDirectory.searchIndustries')"
              :no-results-label="t('companyDirectory.noIndustries')"
              :remove-label="t('account.remove')"
              :max-selections="20"
            />
        </template>
        <SearchableSelect required
          :model-value="profile.countryCode || ''"
          :options="countryOptions"
          :label="t('auth.country')"
          :placeholder="t('auth.selectCountry')"
          :search-placeholder="t('auth.searchCountry')"
          :no-results-label="t('auth.noCountriesFound')"
          @update:model-value="updateCountry"
        />
        <label class="form-field">
          <span>{{ t('auth.city') }}</span>
          <input v-model.trim="profile.city" required maxlength="70" autocomplete="address-level2" />
        </label>
        <PhoneNumberField
          v-model="profile.phone"
          :country-code="phoneCountry"
          :label="t('auth.phone')"
          :placeholder="t('auth.phonePlaceholder')"
          :country-label="t('auth.phoneCountry')"
          :country-placeholder="t('auth.selectCountry')"
          :country-search-placeholder="t('auth.searchPhoneCountry')"
          :no-countries-found-label="t('auth.noPhoneCountriesFound')"
          @update:country-code="emit('update:phoneCountry', $event)"
        />
        <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
        <button class="button button--dark required-profile-modal__save" type="submit" :disabled="busy">
          {{ busy ? `${t('account.saveProfile')}…` : t('account.saveProfile') }}
        </button>
      </form>
      <LocalizedLink class="required-profile-modal__return text-link" :to="{ name: 'account-dashboard' }">{{ t('activation.backToOverview') }}</LocalizedLink>
    </section>
  </dialog>
</template>

<style lang="scss" src="../../scss/components/account/RequiredProfileModal.scss"></style>
