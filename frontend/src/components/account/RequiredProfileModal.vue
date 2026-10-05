<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import MultiSelect from '../shared/MultiSelect.vue'
import PhoneNumberField from '../shared/PhoneNumberField.vue'
import SearchableSelect from '../shared/SearchableSelect.vue'
import StatusMessage from '../shared/StatusMessage.vue'

const props = defineProps({
  user: { type: Object, required: true },
  profile: { type: Object, required: true },
  categories: { type: Array, default: () => [] },
  countryOptions: { type: Array, default: () => [] },
  phoneCountry: { type: String, required: true },
  busy: { type: Boolean, default: false },
  error: { type: String, default: '' },
})

const emit = defineEmits(['save', 'update:phoneCountry'])
const { t } = useI18n()
const dialog = ref(null)

function updateCountry(countryCode) {
  props.profile.countryCode = countryCode
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
    class="required-profile-modal"
    aria-modal="true"
    aria-labelledby="required-profile-title"
    aria-describedby="required-profile-description"
    @cancel.prevent
    @click.self.prevent
  >
    <section class="required-profile-modal__content" :aria-busy="busy">
      <p class="eyebrow">{{ t('auth.registrationEyebrow') }}</p>
      <h2 id="required-profile-title">{{ t('auth.registrationHeroTitle') }}</h2>
      <p id="required-profile-description">{{ t('account.profileIncomplete') }}</p>
      <form class="required-profile-modal__form" @submit.prevent="emit('save')">
        <template v-if="user.accountType === 'creator'">
          <label class="form-field">
            <span>{{ t('auth.name') }}</span>
            <input v-model.trim="profile.displayName" required minlength="2" maxlength="120" autocomplete="name" autofocus />
          </label>
          <MultiSelect
            v-model="profile.categories"
            :options="categories"
            :label="t('auth.category')"
            :placeholder="t('account.selectCategories')"
            :search-placeholder="t('account.searchCategories')"
            :no-results-label="t('account.noCategoriesFound')"
            :remove-label="t('account.remove')"
            :max-selections="5"
          />
          <label class="form-field">
            <span>{{ t('auth.location') }}</span>
            <input v-model.trim="profile.location" required minlength="2" maxlength="120" autocomplete="address-level2" />
          </label>
        </template>
        <template v-else>
          <label class="form-field">
            <span>{{ t('account.companyName') }}</span>
            <input v-model.trim="profile.name" required minlength="2" maxlength="120" autocomplete="organization" autofocus />
          </label>
          <label class="form-field">
            <span>{{ t('auth.industry') }}</span>
            <input v-model.trim="profile.industry" required minlength="2" maxlength="100" />
          </label>
        </template>
        <SearchableSelect
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
    </section>
  </dialog>
</template>
