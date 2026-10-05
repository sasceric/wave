<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps({
  loading: { type: Boolean, required: true },
  hasMore: { type: Boolean, required: true },
  error: { type: String, default: '' },
  count: { type: Number, required: true },
})
const emit = defineEmits(['load'])
const { t } = useI18n()
const sentinel = ref(null)
let observer

function observeEnd() {
  // Re-observe after rendering to measure the new end rather than reusing the
  // previous intersection. This also fills a viewport when a batch is short.
  observer?.disconnect()
  if (sentinel.value && props.hasMore && !props.loading && !props.error) {
    observer?.observe(sentinel.value)
  }
}

onMounted(() => {
  if (typeof IntersectionObserver !== 'undefined') {
    observer = new IntersectionObserver((entries) => {
      if (entries.some((entry) => entry.isIntersecting)
        && props.hasMore && !props.loading && !props.error) emit('load')
    }, { rootMargin: '200px 0px' })
    observeEnd()
  }
})
watch(() => [props.loading, props.hasMore, props.error, props.count], observeEnd, { flush: 'post' })
onBeforeUnmount(() => observer?.disconnect())
</script>

<template>
  <div ref="sentinel" class="directory-load-more" :aria-busy="loading">
    <p v-if="error" class="directory-load-more__error" role="alert">{{ error }}</p>
    <p v-if="loading" role="status">{{ t('directoryLoading.loading') }}</p>
    <button v-else-if="hasMore" type="button" @click="emit('load')">
      {{ t(error ? 'directoryLoading.retry' : 'directoryLoading.loadMore') }}
    </button>
    <p v-else role="status">{{ t('directoryLoading.end') }}</p>
  </div>
</template>
