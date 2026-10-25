<script setup>
import { computed } from 'vue'
import { chatLinks } from '../../lib/chatLinks'

const props = defineProps({ text: { type: String, default: '' } })
const parts = computed(() => chatLinks(props.text))
</script>

<template>
  <p class="chat-message-text"><template v-for="(part, index) in parts" :key="index"><a v-if="part.href" :href="part.href" target="_blank" rel="noopener noreferrer ugc" @pointerdown.stop @contextmenu.stop>{{ part.text }}</a><template v-else>{{ part.text }}</template></template></p>
</template>

<style scoped lang="scss" src="../../scss/components/messages/ChatMessageText.scss"></style>
