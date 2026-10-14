<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import SingleSelect from '../shared/SingleSelect.vue'
import StatusMessage from '../shared/StatusMessage.vue'
import { currentUser } from '../../composables/useCurrentUser'
import { apiGet, formatDate } from '../../lib/api'
const { t } = useI18n()
const days = ref(30)
const result = ref(null)
const loading = ref(false)
const error = ref('')
let generation = 0
async function load() {
    const token = ++generation, id = currentUser.value?.id
    result.value = null
    error.value = ''
    if (!id)
        return
    loading.value = true
    try {
        const response = await apiGet(`/me/profile-statistics?days=${days.value}`)
        if (token !== generation || id !== currentUser.value?.id)
            return
        if (response.userId !== id)
            throw new Error(t('api.invalidResponse'))
        result.value = response.data
    }
    catch (cause) {
        if (token === generation)
            error.value = cause.message
    }
    finally {
        if (token === generation)
            loading.value = false
    }
}
watch(() => [currentUser.value?.id, days.value], load, { immediate: true })
onBeforeUnmount(() => { ++generation; })
const bars = computed(() => {
    if (!result.value)
        return []
    const rows = new Map(result.value.daily.map(row => [row.day, Number(row.views)])), start = new Date(`${result.value.since}T00:00:00Z`), count = result.value.days, chunk = count === 7 ? 1 : 7, output = []
    for (let index = 0; index < count; index += chunk) {
        let value = 0
        const date = new Date(start)
        date.setUTCDate(date.getUTCDate() + index)
        const from = date.toISOString().slice(0, 10)
        for (let offset = 0; offset < chunk && index + offset < count; offset++) {
            const day = new Date(date)
            day.setUTCDate(day.getUTCDate() + offset)
            value += rows.get(day.toISOString().slice(0, 10)) || 0
        }
        output.push({ date: from, value })
    }
    return output
})
const maximum = computed(() => Math.max(1, ...bars.value.map(row => row.value)))
</script>
<template>
  <section class="creator-statistics" :aria-busy="loading">
    <header>
      <div>
        <h2>{{ t('studio.profileStatistics') }}</h2>
        <p>{{ t('studio.statsHint') }}</p>
      </div>
      <SingleSelect
        v-model="days"
        :label="t('studio.period')"
        :options="[7,30,90].map(value=>({value,label:t('studio.lastDays',{count:value})}))"
      />
    </header>
    <StatusMessage v-if="error" variant="error">
      {{ error }}
      <button type="button" @click="load">{{ t('reviews.retry') }}</button>
    </StatusMessage>
    <p v-else-if="loading">{{ t('reviews.loading') }}</p>
    <template v-else-if="result">
      <div class="creator-statistics__metrics">
        <div v-for="key in ['views','inquiries','invitations','hired']" :key="key">
          <strong>{{ result.counts[key] }}</strong>
          <span>{{ t(`studio.stats_${key}`) }}</span>
        </div>
      </div>
      <div class="creator-statistics__bars" role="img" :aria-label="t('studio.stats_views')">
        <div v-for="bar in bars" :key="bar.date" :title="`${formatDate(bar.date)}: ${bar.value}`">
          <span>{{ bar.value }}</span>
          <span class="creator-statistics__track">
            <span :style="{height:`${bar.value/maximum*100}%`}"></span>
          </span>
          <small>{{ formatDate(bar.date) }}</small>
        </div>
      </div>
    </template>
  </section>
</template>
<style scoped lang="scss">
.creator-statistics { padding:22px; border:1px solid #dce2d9; background:#fffefa; border-radius:16px; margin-top:24px;
 header { display:flex; flex-wrap:wrap; justify-content:space-between; gap:16px; align-items:center; >div:first-child { flex:1; min-width:200px; } >:last-child { min-width:170px; } h2 { margin:0; color:var(--forest); font-size:24px; } p { margin:8px 0 0; color:var(--muted); font-size:12px; line-height:1.6; } }
 &__metrics { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; margin:20px 0; >div { border-radius:12px; padding:15px; background:#f6f2ea; } strong { display:block; font-size:25px; color:var(--forest); } span { display:block; color:var(--muted); font-size:12px; margin-top:5px; } }
 &__bars { display:flex; align-items:end; gap:10px; min-height:170px; >div { flex:1; min-width:0; text-align:center; font-size:12px; color:var(--forest); } small { display:block; font-size:10px; margin-top:8px; overflow-wrap:anywhere; } }
 &__track { height:105px; display:flex; flex-direction:column; justify-content:flex-end; margin-top:10px; background:#eef1eb; border-radius:6px; overflow:hidden; >span { display:block; background:var(--forest); border-radius:6px; } }
 @media(max-width:760px){padding:16px; &__metrics { grid-template-columns:1fr 1fr; } &__bars { gap:5px; } }
}
</style>
