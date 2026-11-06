<script setup lang="ts">
import { computed, onActivated, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import DateInput from '@/components/ui/DateInput.vue'
import EetRecordBadge from '@/components/eet/EetRecordBadge.vue'
import { btnOutline } from '@/components/ui/buttonStyles'
import { formBox, formInput, formLabel } from '@/components/ui/formStyles'
import {
  eetApi,
  type EetDevice,
  type EetQueueSummary,
  type EetRecord,
  type EetRecordListParams,
  type EetRecordStatus,
  type EetUnit,
} from '@/api/eet'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { formatDateTime, formatMoney } from '@/composables/useFormat'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const STATUSES: EetRecordStatus[] = ['pending', 'sending', 'retry_wait', 'confirmed', 'confirmed_warning', 'action_required', 'verification_ok', 'external']
const PAGE = 50

const canExport = computed(() => auth.canRead('eet.export'))
const canReadSettings = computed(() => auth.canRead('eet.settings'))

const queue = ref<EetQueueSummary | null>(null)
const items = ref<EetRecord[]>([])
const nextCursor = ref<string | null>(null)
const loading = ref(false)
const loadingMore = ref(false)
const loaded = ref(false)
const units = ref<EetUnit[]>([])
const devices = ref<EetDevice[]>([])

const filters = reactive({
  from: '',
  to: '',
  status: '' as '' | EetRecordStatus,
  unitId: '' as '' | number,
  deviceId: '' as '' | number,
  attentionOnly: false,
})

function params(cursor?: string): EetRecordListParams {
  const p: EetRecordListParams = { limit: PAGE }
  if (cursor) p.cursor = cursor
  if (filters.from) p.from = filters.from
  if (filters.to) p.to = filters.to
  const status = filters.attentionOnly ? 'action_required' : filters.status
  if (status) p.status = status
  if (filters.unitId !== '') p.unit_id = Number(filters.unitId)
  if (filters.deviceId !== '') p.device_id = Number(filters.deviceId)
  return p
}

let generation = 0
async function load() {
  const gen = ++generation
  loading.value = true
  try {
    const [q, page] = await Promise.all([eetApi.queue(), eetApi.records(params())])
    if (gen !== generation) return
    queue.value = q
    items.value = page.items
    nextCursor.value = page.next_cursor
  } catch (e) {
    if (gen !== generation) return
    toast.error(apiErrorMessage(e, t('eet.records.load_failed')))
  } finally {
    if (gen === generation) { loading.value = false; loaded.value = true }
  }
}

async function loadMore() {
  if (!nextCursor.value || loadingMore.value) return
  const gen = generation
  loadingMore.value = true
  try {
    const page = await eetApi.records(params(nextCursor.value))
    if (gen !== generation) return
    items.value = [...items.value, ...page.items]
    nextCursor.value = page.next_cursor
  } catch (e) {
    toast.error(apiErrorMessage(e, t('eet.records.load_failed')))
  } finally {
    loadingMore.value = false
  }
}

async function loadLookups() {
  if (!canReadSettings.value) return
  try {
    const [u, d] = await Promise.all([eetApi.units(), eetApi.devices()])
    units.value = u
    devices.value = d
  } catch {
    units.value = []
    devices.value = []
  }
}

let filterTimer: ReturnType<typeof setTimeout> | null = null
function applyFilters() {
  if (filterTimer) clearTimeout(filterTimer)
  filterTimer = setTimeout(() => { void load() }, 250)
}
function resetFilters() {
  filters.from = ''
  filters.to = ''
  filters.status = ''
  filters.unitId = ''
  filters.deviceId = ''
  filters.attentionOnly = false
  applyFilters()
}
function showAttention() {
  filters.attentionOnly = true
  filters.status = ''
  applyFilters()
}
const visibleDevices = computed(() =>
  filters.unitId === '' ? devices.value : devices.value.filter(d => d.unit_id === Number(filters.unitId)))

onMounted(() => { void load(); void loadLookups() })
onActivated(() => { if (loaded.value) void load() })
onBeforeUnmount(() => { generation++; if (filterTimer) clearTimeout(filterTimer) })

const exportHref = computed(() => {
  const q = new URLSearchParams()
  if (filters.from) q.set('from', filters.from)
  if (filters.to) q.set('to', filters.to)
  const status = filters.attentionOnly ? 'action_required' : filters.status
  if (status) q.set('status', status)
  if (filters.unitId !== '') q.set('unit_id', String(filters.unitId))
  if (filters.deviceId !== '') q.set('device_id', String(filters.deviceId))
  const qs = q.toString()
  return `/api/eet/export${qs ? `?${qs}` : ''}`
})

const headerActions = computed<ActionItem[]>(() => [
  { key: 'export', label: t('eet.records.export'), icon: 'download', tier: 'secondary', variant: 'neutral',
    show: canExport.value, href: exportHref.value, download: true },
  { key: 'reload', label: t('eet.reload'), icon: 'cycle', tier: 'secondary', variant: 'neutral', loading: loading.value, run: () => { void load() } },
])

const earliestCertificate = computed(() => {
  const certs = Object.values(queue.value?.certificates ?? {})
  const dates = certs.map(c => c.valid_until).filter((d): d is string => !!d).sort()
  return { until: dates[0] ?? null, expired: certs.some(c => c.expired) }
})
const isPlayground = computed(() => queue.value?.environment === 'playground')

const BADGE = 'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset whitespace-nowrap'
const th = 'px-3 py-2 text-left font-medium whitespace-nowrap'
const card = 'rounded-lg border border-neutral-200 bg-surface px-4 py-3'

function target(r: EetRecord) {
  return { name: 'eet-record-detail', params: { id: r.record_id } }
}
</script>

<template>
  <div class="pb-24">
    <div class="mb-4 flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('eet.records.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('eet.records.subtitle') }}</p>
      </div>
      <ActionBar :actions="headerActions" />
    </div>

    <div v-if="isPlayground" class="mb-4 rounded-lg border border-warning-500/40 bg-warning-50 px-4 py-3 text-sm text-warning-700" data-test="eet-records-playground">
      <strong>{{ t('eet.environment.playground_banner_title') }}</strong> {{ t('eet.records.playground_note') }}
    </div>
    <div v-if="queue?.dispatch_blocked" class="mb-4 rounded-lg border border-danger-500/40 bg-danger-50 px-4 py-3 text-sm text-danger-700" data-test="eet-records-blocked">
      {{ t('eet.records.dispatch_blocked') }}
    </div>

    <!-- Karty fronty -->
    <div v-if="queue" class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6" data-test="eet-queue-cards">
      <div :class="card">
        <div class="text-xs text-neutral-500">{{ t('eet.records.card_open') }}</div>
        <div class="text-2xl font-semibold">{{ queue.open_total ?? 0 }}</div>
        <div class="text-xs text-neutral-500">{{ t('eet.records.card_open_hint', { retry: queue.counts?.retry_wait ?? 0, pending: (queue.counts?.pending ?? 0) + (queue.counts?.sending ?? 0) }) }}</div>
      </div>
      <button type="button" :class="[card, 'text-left cursor-pointer hover:bg-neutral-50']" @click="showAttention">
        <div class="text-xs text-neutral-500">{{ t('eet.records.card_action') }}</div>
        <div class="text-2xl font-semibold" :class="(queue.counts?.action_required ?? 0) > 0 ? 'text-danger-600' : ''">{{ queue.counts?.action_required ?? 0 }}</div>
        <div class="text-xs text-neutral-500">{{ t('eet.records.card_action_hint') }}</div>
      </button>
      <div :class="card">
        <div class="text-xs text-neutral-500">{{ t('eet.records.card_overdue') }}</div>
        <div class="text-2xl font-semibold" :class="(queue.overdue ?? 0) > 0 ? 'text-danger-600' : ''">{{ queue.overdue ?? 0 }}</div>
        <div class="text-xs text-neutral-500">{{ t('eet.records.card_overdue_hint') }}</div>
      </div>
      <div :class="card">
        <div class="text-xs text-neutral-500">{{ t('eet.records.card_oldest') }}</div>
        <div class="text-sm font-semibold">{{ queue.oldest_deadline_at ? formatDateTime(queue.oldest_deadline_at) : t('eet.records.none') }}</div>
        <div class="text-xs text-neutral-500">{{ t('eet.records.card_oldest_hint') }}</div>
      </div>
      <div :class="card">
        <div class="text-xs text-neutral-500">{{ t('eet.records.card_last_ok') }}</div>
        <div class="text-sm font-semibold">{{ queue.last_confirmed_at ? formatDateTime(queue.last_confirmed_at) : t('eet.records.none') }}</div>
      </div>
      <div :class="card">
        <div class="text-xs text-neutral-500">{{ t('eet.records.card_certificate') }}</div>
        <div class="text-sm font-semibold" :class="earliestCertificate.expired ? 'text-danger-600' : ''">
          {{ earliestCertificate.expired ? t('eet.records.cert_expired') : earliestCertificate.until ? formatDateTime(earliestCertificate.until) : t('eet.records.none') }}
        </div>
        <div class="text-xs text-neutral-500">{{ t('eet.records.card_certificate_hint') }}</div>
      </div>
    </div>

    <!-- Filtry -->
    <section :class="[formBox, 'mb-4']" data-test="eet-filters">
      <div class="flex flex-wrap items-end gap-3">
        <div :class="[formLabel, 'w-40']">
          <span>{{ t('eet.records.filter_from') }}</span>
          <DateInput v-model="filters.from" :class="formInput" @update:model-value="applyFilters" />
        </div>
        <div :class="[formLabel, 'w-40']">
          <span>{{ t('eet.records.filter_to') }}</span>
          <DateInput v-model="filters.to" :class="formInput" @update:model-value="applyFilters" />
        </div>
        <label :class="[formLabel, 'w-48']">
          <span>{{ t('eet.records.filter_status') }}</span>
          <select v-model="filters.status" :class="formInput" :disabled="filters.attentionOnly" @change="applyFilters">
            <option value="">{{ t('common.all') }}</option>
            <option v-for="s in STATUSES" :key="s" :value="s">{{ t(`eet.state.${s}`) }}</option>
          </select>
        </label>
        <label v-if="units.length > 0" :class="[formLabel, 'w-48']">
          <span>{{ t('eet.records.filter_unit') }}</span>
          <select v-model="filters.unitId" :class="formInput" @change="filters.deviceId = ''; applyFilters()">
            <option value="">{{ t('common.all') }}</option>
            <option v-for="u in units" :key="u.id" :value="u.id">{{ u.unit_number }} - {{ u.name }}</option>
          </select>
        </label>
        <label v-if="devices.length > 0" :class="[formLabel, 'w-48']">
          <span>{{ t('eet.records.filter_device') }}</span>
          <select v-model="filters.deviceId" :class="formInput" @change="applyFilters">
            <option value="">{{ t('common.all') }}</option>
            <option v-for="d in visibleDevices" :key="d.id" :value="d.id">{{ d.code }}{{ d.name ? ` - ${d.name}` : '' }}</option>
          </select>
        </label>
        <label class="flex h-9 items-center gap-2 text-sm cursor-pointer whitespace-nowrap">
          <input v-model="filters.attentionOnly" type="checkbox" @change="applyFilters" />
          {{ t('eet.records.filter_attention') }}
        </label>
        <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="resetFilters">{{ t('eet.records.filter_reset') }}</button>
      </div>
    </section>

    <div v-if="loading && !loaded" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
    <EmptyState v-else-if="items.length === 0" icon="coin" :title="t('eet.records.empty')" :message="t('eet.records.empty_hint')" />

    <div v-else class="rounded-lg border border-neutral-200 bg-surface overflow-hidden">
      <div class="hidden md:block overflow-x-auto">
        <table class="w-full text-sm" data-test="eet-records-table">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th :class="th">{{ t('eet.records.col_time') }}</th>
              <th :class="th">{{ t('eet.records.col_number') }}</th>
              <th :class="th">{{ t('eet.records.col_device') }}</th>
              <th :class="[th, 'text-right']">{{ t('eet.records.col_amount') }}</th>
              <th :class="th">{{ t('eet.records.col_state') }}</th>
              <th :class="th">POK</th>
              <th :class="th">{{ t('eet.records.col_deadline') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="r in items" :key="r.record_id" class="hover:bg-neutral-50">
              <td class="px-3 py-2 whitespace-nowrap">
                <RouterLink :to="target(r)" class="text-primary-700 hover:underline">{{ formatDateTime(r.sale_at) }}</RouterLink>
              </td>
              <td class="px-3 py-2 font-mono text-xs whitespace-nowrap">
                {{ r.sequence_number }}
                <span v-if="r.kind === 'correction'" :class="[BADGE, 'ml-1 bg-neutral-100 text-neutral-600 ring-neutral-500/20']">{{ t('eet.records.kind_correction') }}</span>
              </td>
              <td class="px-3 py-2 whitespace-nowrap text-xs">{{ r.unit_number ?? '-' }} / {{ r.device_code ?? '-' }}</td>
              <td class="px-3 py-2 text-right whitespace-nowrap" :class="Number(r.total_amount) < 0 ? 'text-danger-600' : ''">
                {{ formatMoney(Number(r.total_amount), r.currency || 'CZK') }}
              </td>
              <td class="px-3 py-2">
                <div class="flex flex-wrap items-center gap-1">
                  <EetRecordBadge :status="r.status" />
                  <span v-if="r.overdue" :class="[BADGE, 'bg-danger-50 text-danger-700 ring-danger-600/20']">{{ t('eet.records.overdue') }}</span>
                  <span v-if="r.environment === 'playground'" :class="[BADGE, 'bg-warning-50 text-warning-700 ring-warning-600/20']">{{ t('eet.records.playground') }}</span>
                </div>
              </td>
              <td class="px-3 py-2 font-mono text-xs break-all">{{ r.pok || '' }}</td>
              <td class="px-3 py-2 text-xs whitespace-nowrap text-neutral-600">{{ r.deadline_at ? formatDateTime(r.deadline_at) : '' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="md:hidden divide-y divide-neutral-100">
        <RouterLink v-for="r in items" :key="`m-${r.record_id}`" :to="target(r)" class="block px-4 py-3 space-y-1 hover:bg-neutral-50">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <span class="font-medium" :class="Number(r.total_amount) < 0 ? 'text-danger-600' : ''">{{ formatMoney(Number(r.total_amount), r.currency || 'CZK') }}</span>
            <EetRecordBadge :status="r.status" />
          </div>
          <div class="text-xs text-neutral-600">{{ formatDateTime(r.sale_at) }} · {{ r.sequence_number }}</div>
          <div class="flex flex-wrap items-center gap-1 text-xs text-neutral-600">
            <span>{{ r.unit_number ?? '-' }} / {{ r.device_code ?? '-' }}</span>
            <span v-if="r.overdue" :class="[BADGE, 'bg-danger-50 text-danger-700 ring-danger-600/20']">{{ t('eet.records.overdue') }}</span>
            <span v-if="r.environment === 'playground'" :class="[BADGE, 'bg-warning-50 text-warning-700 ring-warning-600/20']">{{ t('eet.records.playground') }}</span>
          </div>
          <div v-if="r.pok" class="font-mono text-xs break-all">POK {{ r.pok }}</div>
        </RouterLink>
      </div>
      <div v-if="nextCursor" class="flex justify-center border-t border-neutral-100 p-3">
        <button type="button" :class="btnOutline('primary')" class="whitespace-nowrap" :disabled="loadingMore" data-test="eet-records-more" @click="loadMore">
          {{ loadingMore ? t('common.loading') : t('common.load_more') }}
        </button>
      </div>
    </div>
  </div>
</template>
