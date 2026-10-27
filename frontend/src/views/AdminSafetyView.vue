<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { ArrowRight, Eye, Pencil, ShieldOff } from '@lucide/vue'
import ReviewAction from '../components/reviews/ReviewAction.vue'
import AdminPage from '../components/admin/AdminPage.vue'
import AdminDataTable from '../components/admin/AdminDataTable.vue'
import AdminRowActions from '../components/admin/AdminRowActions.vue'
import AccountSidebar from '../components/account/AccountSidebar.vue'
import FormModal from '../components/shared/FormModal.vue'
import ConfirmationModal from '../components/shared/ConfirmationModal.vue'
import ImagePreviewModal from '../components/shared/ImagePreviewModal.vue'
import SingleSelect from '../components/shared/SingleSelect.vue'
import StatusMessage from '../components/shared/StatusMessage.vue'
import LocalizedLink from '../components/shared/LocalizedLink.vue'
import { currentUser } from '../composables/useCurrentUser'
import { apiGet, apiRequest, formatDate } from '../lib/api'
const route = useRoute()
const { t, locale } = useI18n()
const personal = computed(() => route.meta.routeName !== 'admin-reports')
const section = computed(() => route.meta.safetySection || (route.meta.routeName === 'account-blocks' ? 'blocks' : 'reviews'))
const title = computed(() => t(`safety.${personal.value ? (section.value === 'reviews' ? 'myReviews' : 'myBlocks') : section.value}`))
const rows = ref([])
const page = ref(1)
const total = ref(0)
const options = ref({ limit: 25, sort: 'createdAt', direction: 'desc' })
const q = ref('')
const filter = ref('all')
const loading = ref(false)
const busy = ref(false)
const error = ref('')
const formError = ref('')
const selected = ref(null)
const editingReview = ref(null)
const unblocking = ref(null)
const preview = ref(null)
const status = ref('')
const response = ref('')
let revision = 0
let timer
const columns = computed(() => [
  { key: 'createdAt', label: t('safety.date'), sortable: true, defaultSort: true },
  ...(section.value === 'blocks' ? [{ key: 'counterpart', label: t('safety.blockedAccount'), sortable: true }] : [
    { key: 'name', label: t(section.value === 'reports' ? 'safety.reporter' : 'safety.collaborator'), sortable: true },
  ]),
  ...(section.value === 'reports' ? [
    { key: 'target_name', label: t('safety.target'), sortable: true },
    { key: 'category', label: t('safety.reason'), sortable: true },
    { key: 'status', label: t('safety.status'), sortable: true },
  ] : section.value === 'reviews' ? [
    { key: 'campaign', label: t('safety.campaign'), sortable: true },
    { key: 'received', label: t('safety.receivedReview'), sortable: true },
    { key: 'given', label: t('safety.givenReview'), sortable: true },
  ] : []),
])
const labels = computed(() => ({ caption: title.value, loading: t('adminDashboard.loading'), actions: t('adminDashboard.actions'), empty: t('safety.empty'), pageSize: t('adminDashboard.pageSize'), pagination: t('adminDashboard.pagination'), previous: t('adminDashboard.previous'), next: t('adminDashboard.next'), goToPage: value => t('adminDashboard.goToPage', { page: value }) }))
async function load(change) {
  if (change) { page.value = change.page; options.value = { limit: change.limit, sort: change.sort, direction: change.direction } }
  const current = ++revision
  const userId = currentUser.value?.id
  if (!userId || !personal.value && !currentUser.value.isAdmin) { rows.value = []; total.value = 0; return }
  loading.value = true; error.value = ''
  try {
    const result = await apiGet(`/${personal.value ? 'me' : 'admin'}/safety/${section.value}?${new URLSearchParams({ page: page.value, ...options.value, q: q.value, status: filter.value })}`)
    if (current !== revision || currentUser.value?.id !== userId) return
    rows.value = result.data; total.value = result.meta.total; page.value = result.meta.page
  } catch (cause) { if (current === revision) { error.value = cause.message; rows.value = []; total.value = 0 } }
  finally { if (current === revision) loading.value = false }
}
function view(row) { selected.value = row; status.value = row.status || ''; response.value = row.response || ''; formError.value = '' }
function editReview(row) {
  if (!personal.value || section.value !== 'reviews' || !currentUser.value?.isAdmin || !row.given) return
  selected.value = null
  editingReview.value = row.id
}
async function save() {
  if (busy.value || !selected.value || !['processing', 'resolved', 'dismissed'].includes(status.value)) return
  busy.value = true; formError.value = ''
  try { await apiRequest(`/admin/safety/reports/${selected.value.id}`, { method: 'PUT', body: { status: status.value, response: response.value, version: selected.value.version } }); selected.value = null; await load() }
  catch (cause) { formError.value = cause.message }
  finally { busy.value = false }
}
async function unblock() {
  if (busy.value || !unblocking.value) return
  busy.value = true; error.value = ''
  try { await apiRequest(`/me/safety/blocks/${unblocking.value.id}`, { method: 'DELETE' }); unblocking.value = null; window.dispatchEvent(new CustomEvent('wave:safety-changed')); await load() }
  catch (cause) { error.value = cause.message; unblocking.value = null }
  finally { busy.value = false }
}
watch(() => [section.value, personal.value, currentUser.value?.id, locale.value], () => { ++revision; page.value = 1; rows.value = []; selected.value = null; editingReview.value = null; preview.value = null; unblocking.value = null; q.value = ''; filter.value = 'all'; options.value = { limit: 25, sort: 'createdAt', direction: 'desc' }; load() }, { immediate: true })
watch([q, filter], () => { ++revision; clearTimeout(timer); timer = setTimeout(() => { page.value = 1; load() }, 250) })
onBeforeUnmount(() => { ++revision; clearTimeout(timer) })
</script>
<template>
  <AdminPage class="admin-safety">
    <div v-if="currentUser" class="admin-dashboard__layout">
      <AccountSidebar :user="currentUser" :admin-layout="!personal" />
      <main class="admin-dashboard__main">
        <header class="admin-dashboard__header"><div><h1>{{ title }}</h1><p>{{ t(`safety.${personal ? (section === 'reviews' ? 'myReviewsHint' : 'myBlocksHint') : section + 'Hint'}`) }}</p></div></header>
        <StatusMessage v-if="error" variant="error">{{ error }}</StatusMessage>
        <AdminDataTable :key="`${section}-${personal}`" :columns="columns" :rows="rows" :total="total" :current-page="page" :loading="loading" :labels="labels" :selectable="false" initial-sort-direction="desc" @change="load">
          <template #toolbar><label class="admin-dashboard__search"><span class="sr-only">{{ t('adminDashboard.search') }}</span><input v-model.trim="q" type="search" :placeholder="t('adminDashboard.search')" /></label><SingleSelect v-if="section === 'reports'" v-model="filter" :label="t('safety.status')" :show-label="false" class="admin-safety__filter" :options="[{ value: 'all', label: t('safety.all') }, ...['received','processing','resolved','dismissed'].map(value => ({value, label:t(`safety.statuses.${value}`)}))]" /></template>
          <template #cell-createdAt="{ value }">{{ formatDate(value.replace(' ', 'T') + 'Z') }}</template>
          <template #cell-name="{ row }">{{ row.name }}<small class="admin-safety__secondary">{{ row.email }}</small></template>
          <template #cell-counterpart="{ row }">{{ row.counterpart }}<small class="admin-safety__secondary">{{ row.counterpart_email }}</small></template>
          <template #cell-target_name="{ row }">{{ row.target_name }}<small class="admin-safety__secondary">{{ t(`safety.kinds.${row.target_kind}`) }}</small></template>
          <template #cell-category="{ row }">{{ t(`safety.categories.${row.category}`) }}<small class="admin-safety__secondary">{{ t(`safety.reasons.${row.reason}`) }}</small></template>
          <template #cell-received="{ value }"><template v-if="value?.rating"><strong>{{ value.rating }} / 5</strong><small class="admin-safety__excerpt">{{ value.comment }}</small></template><span v-else class="admin-safety__secondary">{{ t(value ? 'safety.statuses.pending' : 'safety.noReview') }}</span></template>
          <template #cell-given="{ value }"><template v-if="value"><strong>{{ value.rating }} / 5</strong><small class="admin-safety__excerpt">{{ value.comment }}</small></template><span v-else class="admin-safety__secondary">{{ t('safety.noReview') }}</span></template>
          <template #cell-status="{ value }"><span class="admin-safety__status">{{ t(`safety.statuses.${value}`) }}</span></template>
          <template #actions="{ row }">
            <AdminRowActions :label="`${t('adminDashboard.actions')}: ${section === 'blocks' ? row.counterpart : row.name}`">
              <button v-if="section !== 'blocks'" type="button" class="admin-row-actions__item" role="menuitem" @click="view(row)">
                <Eye :size="15" aria-hidden="true" />{{ t('safety.details') }}
              </button>
              <button v-if="personal && section === 'reviews' && currentUser.isAdmin && row.given" type="button" class="admin-row-actions__item" role="menuitem" @click="editReview(row)">
                <Pencil :size="15" aria-hidden="true" />{{ t('reviews.editOwn') }}
              </button>
              <button v-if="section === 'blocks'" type="button" class="admin-row-actions__item" role="menuitem" :disabled="busy" @click="unblocking = row">
                <ShieldOff :size="15" aria-hidden="true" />{{ t('safety.unblock') }}
              </button>
            </AdminRowActions>
          </template>
        </AdminDataTable>
      </main>
    </div>
    <main v-else class="page-width"><LocalizedLink :to="{ name: 'account' }">{{ t('auth.signIn') }}</LocalizedLink></main>
    <FormModal :open="Boolean(selected)" :title="t(section === 'reviews' ? 'reviews.title' : 'safety.reportDetails')" :busy="busy" @update:open="!$event && (selected = null)" @submit="save">
      <template v-if="selected"><p><strong>{{ selected.name }}</strong><template v-if="section === 'reports'"> <ArrowRight class="link-arrow" :size="16" aria-hidden="true" /> {{ selected.target_name }}</template></p><template v-if="section === 'reviews'"><p>{{ selected.campaign }}</p><div class="admin-safety__review-pair"><section v-for="side in ['received', 'given']" :key="side"><h3>{{ t(`safety.${side}Review`) }}</h3><template v-if="selected[side]?.rating"><strong>{{ selected[side].rating }} / 5</strong><p class="admin-safety__text">{{ selected[side].comment }}</p></template><p v-else>{{ t(selected[side] ? 'safety.statuses.pending' : 'safety.noReview') }}</p></section></div></template><template v-else><p>{{ t(`safety.categories.${selected.category}`) }} · {{ t(`safety.reasons.${selected.reason}`) }}</p><p class="admin-safety__text">{{ selected.description || '—' }}</p><div v-if="selected.attachments?.length" class="admin-safety__evidence"><button v-for="file in selected.attachments" :key="file.id" type="button" class="button button--outline" @click="preview = file">{{ file.name }}</button></div><p><small>#{{ selected.id.slice(0, 8).toUpperCase() }}</small></p><SingleSelect v-model="status" :label="t('safety.status')" required :disabled="busy || ['resolved','dismissed'].includes(selected.status)" :options="['received','processing','resolved','dismissed'].map(value => ({ value, label: t(`safety.statuses.${value}`) }))" /><label><span>{{ t('safety.response') }}</span><textarea v-model="response" maxlength="1000" :minlength="status === 'processing' ? undefined : 10" :required="['resolved','dismissed'].includes(status)" :disabled="busy || ['resolved','dismissed'].includes(selected.status)" /></label></template></template>
      <StatusMessage v-if="formError" variant="error">{{ formError }}</StatusMessage>
      <template #actions="{ close }">
        <button class="button button--outline" type="button" :disabled="busy" @click="close">{{ t('safety.close') }}</button>
        <button v-if="personal && section === 'reviews' && currentUser?.isAdmin && selected?.given" class="button button--dark" type="button" @click="editReview(selected)">
          <Pencil :size="15" aria-hidden="true" />{{ t('reviews.editOwn') }}
        </button>
        <button v-if="section === 'reports' && !['resolved','dismissed'].includes(selected?.status)" class="button button--dark" type="submit" :disabled="busy || status === 'received'">{{ t('safety.save') }}</button>
      </template>
    </FormModal>
    <ReviewAction v-if="editingReview" :key="editingReview" :application-id="editingReview" auto-open hide-trigger edit-on-open @closed="editingReview = null" @saved="load()" />
    <ImagePreviewModal :open="Boolean(preview)" :title="preview?.name || ''" :src="preview ? `/api/media/${preview.id}/file` : ''" :close-label="t('safety.close')" @update:open="!$event && (preview = null)" />
    <ConfirmationModal :open="Boolean(unblocking)" :title="t('safety.unblock')" :message="t('safety.unblockHint')" :confirm-label="t('safety.unblock')" :cancel-label="t('safety.cancel')" :loading="busy" @update:open="!$event && (unblocking = null)" @confirm="unblock" />
  </AdminPage>
</template>
<style lang="scss" src="../scss/views/AdminDashboardView.scss"></style>
<style scoped lang="scss">
.admin-safety {
  &__filter { margin-bottom: 0; width: 180px; :deep(.single-select__trigger) { height: 36px; min-height: 36px; padding: 0 11px; border-radius: 6px; } }
  &__excerpt { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; max-width: 260px; margin-top: 6px; line-height: 1.5; }
  &__review-pair { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 20px; section { padding: 16px; border: 1px solid var(--line); border-radius: 8px; } h3 { font-size: 18px; margin: 0 0 12px; } @media (max-width: 600px) { grid-template-columns: 1fr; } }
 &__secondary { display: block; color: var(--muted); font-size: 11px; margin-top: 4px; } &__status { border-radius: 20px; padding: 5px 10px; background: #eef1eb; font-size: 11px; color: var(--forest); } &__text { white-space: pre-wrap; overflow-wrap: anywhere; font-size: 13px; line-height: 1.7; } }
</style>
