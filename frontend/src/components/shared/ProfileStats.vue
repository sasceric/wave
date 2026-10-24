<script setup>
defineProps({
  items: { type: Array, required: true },
  label: { type: String, required: true },
})
</script>
<template>
  <dl v-if="items.length" class="profile-stats" :style="{ '--stat-count': items.length }" :aria-label="label">
    <div v-for="item in items" :key="item.key" class="profile-stats__item">
      <dt>{{ item.label }}</dt>
      <dd>
        <strong>{{ item.value }}</strong>
        <span v-if="item.hint">{{ item.hint }}</span>
      </dd>
    </div>
  </dl>
</template>
<style lang="scss" src="../../scss/components/shared/ProfileStats.scss"></style>
