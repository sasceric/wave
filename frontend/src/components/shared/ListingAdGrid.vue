<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { apiGet } from '../../lib/api'
import { listingEntries, fitListingEntries } from '../../lib/listingAds'
import CardGrid from './CardGrid.vue'
import ListingAdCard from './ListingAdCard.vue'
const props = defineProps({ kind: { type: String, required: true }, items: { type: Array, required: true }, list: { type: Boolean, default: false } })
const { locale } = useI18n()
const ads = ref([])
let request
const narrow = ref(false)
const medium = ref(false)
let removeMediaListeners = () => {}
onMounted(() => {
  const phone = window.matchMedia('(max-width: 760px)')
  const tablet = window.matchMedia('(max-width: 1000px)')
  const sync = () => { narrow.value = phone.matches; medium.value = tablet.matches }
  sync()
  phone.addEventListener('change', sync)
  tablet.addEventListener('change', sync)
  removeMediaListeners = () => { phone.removeEventListener('change', sync); tablet.removeEventListener('change', sync) }
})
watch(() => [props.kind, locale.value], async () => {
  request?.abort()
  const controller = new AbortController()
  request = controller
  ads.value = []
  try {
    const response = await apiGet(`/listing-ads/${props.kind}`, { signal: controller.signal })
    if (!controller.signal.aborted) ads.value = response.data
  } catch { /* Directory browsing remains available if ads cannot be loaded. */ }
}, { immediate: true })
onBeforeUnmount(() => { request?.abort(); removeMediaListeners() })
const columns = computed(() => props.list || (props.kind === 'companies' && narrow.value) ? 1 : (props.kind === 'creators' ? narrow.value : medium.value) ? 2 : 4)
const entries = computed(() => fitListingEntries(listingEntries(props.items, ads.value), columns.value))
</script>

<template>
  <CardGrid :kind="kind === 'companies' ? 'company' : kind === 'campaigns' ? 'campaign' : 'creator'" layout="directory" :class="{ 'listing-ad-grid--list': list }">
    <template v-for="entry in entries" :key="entry.key">
      <ListingAdCard v-if="entry.ad" :ad="entry.ad" />
      <slot v-else :item="entry.item" />
    </template>
    <slot name="loading" />
  </CardGrid>
</template>
