<script setup>
import CardImage from '../shared/CardImage.vue'
defineProps({ row: { type: Object, required: true } })
</script>

<template>
  <span class="admin-profile-identity" :class="{ 'admin-profile-identity--company': row.accountType === 'company' }">
    <span class="admin-profile-identity__image"><CardImage :image="row.profileImage" :src="row.profileImageUrl || ''" sizes="36px" alt="" /></span>
    <strong>{{ row.displayName || row.name || row.email }}</strong>
  </span>
</template>

<style scoped lang="scss">
.admin-profile-identity {
  display: flex; align-items: center; gap: 10px; min-width: 170px;
  strong { font-size: 12px; font-weight: 600; overflow-wrap: anywhere; }
  &__image { display: block; flex: 0 0 36px; width: 36px; height: 36px; overflow: hidden; border-radius: 50%; }
  &--company &__image { border-radius: 8px; }
}
</style>
