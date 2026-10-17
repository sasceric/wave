<script setup>
import { Plus } from '@lucide/vue'
import AccountProfileCard from './AccountProfileCard.vue'
defineProps({
  title: { type: String, required: true },
  icon: { type: [Object, Function], default: null },
  hint: { type: String, default: '' },
  empty: { type: Boolean, required: true },
  emptyMessage: { type: String, required: true },
  editing: { type: Boolean, default: false },
  addLabel: { type: String, required: true },
  addDisabled: { type: Boolean, default: false },
})
defineEmits(['add', 'edit'])
</script>
<template>
  <AccountProfileCard class="profile-collection-card" :title="title" :icon="icon" :editable="!editing" @edit="$emit('edit')">
    <template v-if="editing" #actions>
      <button class="profile-editor__add" type="button" :disabled="addDisabled" @click="$emit('add')"><Plus :size="16" aria-hidden="true" />{{ addLabel }}</button>
    </template>
    <p v-if="hint" class="profile-collection-card__hint">{{ hint }}</p>
    <div v-if="empty" class="profile-collection-card__empty">{{ emptyMessage }}</div>
    <slot v-else />
  </AccountProfileCard>
</template>
<style lang="scss" src="../../scss/components/account/ProfileCollectionCard.scss"></style>
