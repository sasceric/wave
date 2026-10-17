<script setup>
import { MessageCircleQuestion, Trash2 } from '@lucide/vue'
import { useI18n } from 'vue-i18n'
import ProfileCollectionCard from './ProfileCollectionCard.vue'
const items = defineModel({ type: Array, required: true })
defineProps({ editing: Boolean })
const emit = defineEmits(['edit'])
const { t } = useI18n()
</script>
<template>
  <ProfileCollectionCard
    :title="t('studio.tabFaq')"
    :icon="MessageCircleQuestion"
    :hint="t('account.creatorFaqsHint')"
    :empty="!items.length"
    :empty-message="t('account.noCreatorFaqs')"
    :editing="editing"
    :add-label="t('account.addFaq')"
    :add-disabled="items.length >= 10"
    @add="items.push({ question: '', answer: '' })"
    @edit="emit('edit', 'faqs')"
  >
    <article v-for="(faq, index) in items" :key="index" :class="editing ? 'creator-faq-editor' : 'profile-details__item'">
      <template v-if="editing">
        <div class="portfolio-editor__top"><strong>{{ faq.question || t('account.faqQuestion') }}</strong><button class="profile-editor__remove" type="button" :aria-label="`${t('account.remove')}: ${faq.question || t('account.faqQuestion')}`" @click="items.splice(index, 1)"><Trash2 :size="15" aria-hidden="true" /><span>{{ t('account.remove') }}</span></button></div>
        <label class="form-field"><span>{{ t('account.faqQuestion') }}</span><input v-model.trim="faq.question" required minlength="3" maxlength="180" /></label>
        <label class="form-field"><span>{{ t('account.faqAnswer') }}</span><textarea v-model.trim="faq.answer" required minlength="10" maxlength="1500"></textarea></label>
      </template>
      <template v-else><h4>{{ faq.question }}</h4><p>{{ faq.answer }}</p></template>
    </article>
  </ProfileCollectionCard>
</template>
