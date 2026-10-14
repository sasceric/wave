<script setup>
import { useI18n } from 'vue-i18n'
import SingleSelect from '../shared/SingleSelect.vue'
import { emptyDelivery } from '../../lib/creatorPresentation'
import { Plus, Trash2 } from '@lucide/vue'
const items = defineModel('items', { type: Array, required: true })
function add() { items.value = [...items.value, emptyDelivery()]; }
function remove(index) { items.value = items.value.filter((_, position) => position !== index); }
const { t } = useI18n()
</script>
<template>
  <div class="creator-studio__repeat">
    <header>
      <h3>{{ t('studio.deliverables') }}</h3>
      <button class="profile-editor__add" type="button" :disabled="items.length >= 12" @click="add">
        <Plus :size="16" />
        {{ t('studio.addItem') }}
      </button>
    </header>
    <div v-for="(row,index) in items" :key="index" class="creator-studio__repeat-row">
      <label class="form-field">
        <span>{{ t('studio.quantity') }}</span>
        <input v-model.number="row.quantity" type="number" min="1" max="1000" required />
      </label>
      <SingleSelect
        v-model="row.format"
        :label="t('studio.format')"
        :options="['video','photo','story','podcast','stream'].map(value => ({value,label:t(`studio.${value}`)}))"
      />
      <label class="form-field">
        <span>{{ t('studio.duration') }}</span>
        <input v-model.number="row.duration" type="number" min="1" max="86400" />
      </label>
      <button class="creator-studio__icon" type="button" :aria-label="t('account.remove')" @click="remove(index)">
        <Trash2 :size="16" />
      </button>
    </div>
  </div>
</template>
