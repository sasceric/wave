<script setup>
import { MessageCircleQuestion, Plus, Trash2 } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import AccountProfileCard from './AccountProfileCard.vue'
const items = defineModel({ type: Array, required: true })
defineProps({ editing: Boolean })
const emit = defineEmits(['edit'])
const { t } = useI18n()
</script>
<template>
  <AccountProfileCard :title="t('studio.tabFaq')" :icon="MessageCircleQuestion" :editable="!editing" @edit="emit('edit', 'faqs')">
    <div v-if="editing" class="profile-editor__heading"><small>{{ t('account.creatorFaqsHint') }}</small><button class="profile-editor__add" type="button" :disabled="items.length >= 10" @click="items.push({ question: '', answer: '' })"><Plus :size="16" aria-hidden="true" />{{ t('account.addFaq') }}</button></div>
    <p v-if="!items.length" class="profile-editor__empty">{{ t('account.noCreatorFaqs') }}</p>
    <article v-for="(faq, index) in items" :key="index" :class="editing ? 'creator-faq-editor' : 'profile-details__item'">
      <template v-if="editing">
        <div class="portfolio-editor__top"><strong>{{ faq.question || t('account.faqQuestion') }}</strong><button class="profile-editor__remove" type="button" :aria-label="`${t('account.remove')}: ${faq.question || t('account.faqQuestion')}`" @click="items.splice(index, 1)"><Trash2 :size="15" aria-hidden="true" /><span>{{ t('account.remove') }}</span></button></div>
        <label class="form-field"><span>{{ t('account.faqQuestion') }}</span><input v-model.trim="faq.question" required minlength="3" maxlength="180" /></label>
        <label class="form-field"><span>{{ t('account.faqAnswer') }}</span><textarea v-model.trim="faq.answer" required minlength="10" maxlength="1500"></textarea></label>
      </template>
      <template v-else><h4>{{ faq.question }}</h4><p>{{ faq.answer }}</p></template>
    </article>
  </AccountProfileCard>
</template>
