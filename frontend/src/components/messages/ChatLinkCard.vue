<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { chatLinks } from '../../lib/chatLinks'
import { loadChatPreview } from '../../lib/chatPreviews'

const props = defineProps({ body: { type: String, required: true }, path: { type: String, required: true } })
const emit = defineEmits(['loaded'])
const { locale } = useI18n()
const host = ref(null)
const preview = ref(null)
const imageFailed = ref(false)
const url = computed(() => chatLinks(props.body).find(part => part.href)?.href)
const imagePath = computed(() => `/api${props.path}-image?locale=${encodeURIComponent(locale.value)}`)
let observer
let visible = false
let version = 0
let disposed = false
async function load() {
  const current = ++version
  if (!url.value || !visible) return
  const data = await loadChatPreview(props.path, url.value)
  if (!disposed && version === current) { preview.value = data; if (data) emit('loaded') }
}
onMounted(() => {
  if (typeof IntersectionObserver === 'undefined') { visible = true; void load(); return }
  observer = new IntersectionObserver(entries => {
    if (!entries.some(entry => entry.isIntersecting)) return
    visible = true
    observer.disconnect()
    void load()
  }, { rootMargin: '120px' })
  if (host.value) observer.observe(host.value)
})
watch(() => [props.body, props.path], async () => {
  version += 1
  preview.value = null
  imageFailed.value = false
  await nextTick()
  if (disposed) return
  if (visible) void load()
  else if (host.value) observer?.observe(host.value)
})
onBeforeUnmount(() => { disposed = true; version += 1; observer?.disconnect() })
</script>

<template>
  <div v-if="url" ref="host" class="chat-link-card" :class="{ 'is-ready': preview }">
    <a v-if="preview" :href="url" target="_blank" rel="noopener noreferrer ugc" @pointerdown.stop @contextmenu.stop>
      <img v-if="preview.hasImage && !imageFailed" :src="imagePath" alt="" loading="lazy" @error="imageFailed = true">
      <div class="chat-link-card__copy"><strong>{{ preview.title || preview.domain }}</strong><span v-if="preview.description">{{ preview.description }}</span><small>{{ preview.domain }}</small></div>
    </a>
  </div>
</template>

<style scoped lang="scss" src="../../scss/components/messages/ChatLinkCard.scss"></style>
