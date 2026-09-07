<script setup lang="ts">
import { ref, onMounted, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { adminApi, type SmtpLogAnalysis } from '@/api/admin'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'
import DateInput from '@/components/ui/DateInput.vue'

const { t } = useI18n()

const data = ref<SmtpLogAnalysis | null>(null)
const loading = ref(false)

const filter = ref({
  date_from: '',
  date_to: '',
  status: '',
  kind: '',
  search: '',
  limit: 50,
  offset: 0,
})

async function load() {
  loading.value = true
  try {
    const params: Record<string, string | number> = { limit: filter.value.limit, offset: filter.value.offset }
    if (filter.value.date_from) params.date_from = filter.value.date_from
    if (filter.value.date_to) params.date_to = filter.value.date_to
    if (filter.value.status) params.status = filter.value.status
    if (filter.value.kind) params.kind = filter.value.kind
    if (filter.value.search.trim()) params.search = filter.value.search.trim()
    data.value = await adminApi.smtpLogAnalysis(params)
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch([() => filter.value.status, () => filter.value.kind, () => filter.value.date_from, () => filter.value.date_to],
  () => { filter.value.offset = 0; load() })

let searchTimer: ReturnType<typeof setTimeout> | undefined
watch(() => filter.value.search, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => { filter.value.offset = 0; load() }, 350)
})

const enabled = computed(() => data.value?.enabled === true)
const summary = computed(() => data.value?.summary)

const STATUS_BADGE: Record<string, string> = {
  delivered: 'bg-success-50 text-success-700',
  queued:    'bg-primary-100 text-primary-700',
  deferred:  'bg-warning-50 text-warning-600',
  rejected:  'bg-danger-100 text-danger-700',
  error:     'bg-danger-50 text-danger-600',
  info:      'bg-neutral-100 text-neutral-600',
}
function statusBadge(s: string): string { return STATUS_BADGE[s] ?? 'bg-neutral-100 text-neutral-600' }
function statusLabel(s: string): string { return t(`smtp_logs.status.${s}`) }

const KIND_BADGE: Record<string, string> = {
  submission: 'bg-neutral-100 text-neutral-600',
  delivery:   'bg-primary-50 text-primary-700',
  notice:     'bg-warning-50 text-warning-600',
}
function kindBadge(k: string): string { return KIND_BADGE[k] ?? 'bg-neutral-100 text-neutral-600' }
function kindLabel(k: string): string { return t(`smtp_logs.kind.${k}`) }

function fmtTime(ts: string): string { return ts.replace('T', ' ').slice(0, 19) }
function fmtRecipients(r: string[]): string { return r.length ? r.join(', ') : '—' }

const totalPages = computed(() => Math.max(1, Math.ceil((data.value?.total ?? 0) / filter.value.limit)))
const currentPage = computed(() => Math.floor(filter.value.offset / filter.value.limit) + 1)
function goPage(delta: number) {
  filter.value.offset = Math.max(0, filter.value.offset + delta * filter.value.limit)
  load()
}

// Top hosty s problémy (deferred+rejected+error > 0) — rychlý přehled „kde to vázne".
const problemHosts = computed(() => {
  const hosts = summary.value?.by_host ?? {}
  return Object.entries(hosts)
    .map(([host, c]) => ({
      host,
      delivered: c.delivered ?? 0,
      deferred: c.deferred ?? 0,
      rejected: c.rejected ?? 0,
      error: c.error ?? 0,
      problems: (c.deferred ?? 0) + (c.rejected ?? 0) + (c.error ?? 0),
    }))
    .filter(h => h.problems > 0)
    .sort((a, b) => b.problems - a.problems)
    .slice(0, 8)
})

function shortId(id: string): string { return id.length > 10 ? id.slice(0, 8) : id }

// Klik na souhrnnou kartu naplní (resp. přepne) filtr stavu dole.
function toggleStatus(s: string) {
  filter.value.status = filter.value.status === s ? '' : s
}
</script>

<template>
  <div>
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('smtp_logs.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">
        {{ t('smtp_logs.subtitle') }}
        <span v-if="data?.connector" class="ml-1 text-neutral-400">· {{ t('smtp_logs.connector') }}: {{ data.connector.label }}</span>
      </p>
    </div>

    <!-- Vypnuto / nenakonfigurováno -->
    <div v-if="data && !enabled" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-8 text-center">
      <p class="text-neutral-700 font-medium">{{ t('smtp_logs.disabled_title') }}</p>
      <p class="text-sm text-neutral-500 mt-1">{{ t('smtp_logs.disabled_hint') }}</p>
      <p v-if="data.connectors?.length" class="text-xs text-neutral-400 mt-3">
        {{ t('smtp_logs.supported') }}: {{ data.connectors.map(c => c.label).join(', ') }}
      </p>
    </div>

    <template v-else-if="data && enabled && summary">
      <!-- Diagnostika: zapnuto, ale žádná data (špatná cesta / oprávnění) -->
      <div v-if="summary.total_events === 0 && data.reason"
           class="bg-warning-50 border border-warning-200 rounded-lg p-4 mb-4 text-sm">
        <p class="font-medium text-warning-700">{{ t(`smtp_logs.reason.${data.reason}`) }}</p>
        <p v-if="data.path" class="text-warning-700 mt-1">{{ t('smtp_logs.configured_path') }}: <span class="font-mono break-all">{{ data.path }}</span></p>
        <p v-if="data.reason === 'unreadable'" class="text-warning-600 mt-1 text-xs">{{ t('smtp_logs.reason_unreadable_hint', { n: data.glob_matched ?? 0 }) }}</p>
      </div>

      <!-- Souhrnné karty -->
      <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-4">
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3">
          <div class="text-xs text-neutral-500">{{ t('smtp_logs.cards.deliveries') }}</div>
          <div class="text-xl font-semibold">{{ summary.deliveries }}</div>
        </div>
        <button type="button" @click="toggleStatus('delivered')" :title="t('smtp_logs.filter_by_status')"
          class="text-left bg-surface border rounded-lg shadow-sm p-3 cursor-pointer transition hover:shadow"
          :class="filter.status === 'delivered' ? 'border-success-500 ring-2 ring-success-500/30' : 'border-success-200'">
          <div class="text-xs text-success-700">{{ t('smtp_logs.status.delivered') }}</div>
          <div class="text-xl font-semibold text-success-700">{{ summary.by_status.delivered ?? 0 }}</div>
        </button>
        <button type="button" @click="toggleStatus('deferred')" :title="t('smtp_logs.filter_by_status')"
          class="text-left bg-surface border rounded-lg shadow-sm p-3 cursor-pointer transition hover:shadow"
          :class="filter.status === 'deferred' ? 'border-warning-500 ring-2 ring-warning-500/30' : 'border-warning-200'">
          <div class="text-xs text-warning-600">{{ t('smtp_logs.status.deferred') }}</div>
          <div class="text-xl font-semibold text-warning-600">{{ summary.by_status.deferred ?? 0 }}</div>
        </button>
        <button type="button" @click="toggleStatus('rejected_error')" :title="t('smtp_logs.filter_by_status')"
          class="text-left bg-surface border rounded-lg shadow-sm p-3 cursor-pointer transition hover:shadow"
          :class="filter.status === 'rejected_error' ? 'border-danger-500 ring-2 ring-danger-500/30' : 'border-danger-200'">
          <div class="text-xs text-danger-700">{{ t('smtp_logs.status.rejected') }}</div>
          <div class="text-xl font-semibold text-danger-700">{{ (summary.by_status.rejected ?? 0) + (summary.by_status.error ?? 0) }}</div>
        </button>
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3">
          <div class="text-xs text-neutral-500">{{ t('smtp_logs.cards.submissions') }}</div>
          <div class="text-xl font-semibold">{{ summary.submissions }}</div>
        </div>
      </div>

      <!-- Problémové cílové servery -->
      <div v-if="problemHosts.length" class="bg-surface border border-neutral-200 rounded-lg shadow-sm mb-4 p-3">
        <div class="text-xs font-medium text-neutral-500 uppercase tracking-wide mb-2">{{ t('smtp_logs.problem_hosts') }}</div>
        <div class="flex flex-wrap gap-2">
          <button v-for="h in problemHosts" :key="h.host"
            @click="filter.search = h.host; filter.kind = 'delivery'"
            class="cursor-pointer text-xs px-2.5 py-1 rounded-md border border-neutral-200 hover:bg-neutral-50 flex items-center gap-1.5">
            <span class="font-mono">{{ h.host }}</span>
            <span v-if="h.rejected" class="px-1.5 rounded bg-danger-100 text-danger-700">{{ h.rejected }}</span>
            <span v-if="h.deferred" class="px-1.5 rounded bg-warning-50 text-warning-600">{{ h.deferred }}</span>
          </button>
        </div>
      </div>

      <!-- Filtry -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm mb-4 p-3 flex flex-wrap gap-2 items-center">
        <input v-model="filter.search" type="text" :placeholder="t('smtp_logs.search_ph')"
          class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm flex-1 min-w-[12rem]" />
        <select v-model="filter.kind" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option value="">{{ t('smtp_logs.all_kinds') }}</option>
          <option value="delivery">{{ t('smtp_logs.kind.delivery') }}</option>
          <option value="submission">{{ t('smtp_logs.kind.submission') }}</option>
          <option value="notice">{{ t('smtp_logs.kind.notice') }}</option>
        </select>
        <select v-model="filter.status" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option value="">{{ t('smtp_logs.all_statuses') }}</option>
          <option value="delivered">{{ t('smtp_logs.status.delivered') }}</option>
          <option value="queued">{{ t('smtp_logs.status.queued') }}</option>
          <option value="deferred">{{ t('smtp_logs.status.deferred') }}</option>
          <option value="rejected">{{ t('smtp_logs.status.rejected') }}</option>
          <option value="error">{{ t('smtp_logs.status.error') }}</option>
          <option value="rejected_error">{{ t('smtp_logs.status.rejected_error') }}</option>
        </select>
        <DateInput v-model="filter.date_from" class="h-9 px-2 border border-neutral-300 rounded-md bg-surface text-sm" />
        <span class="text-neutral-400 text-sm">–</span>
        <DateInput v-model="filter.date_to" class="h-9 px-2 border border-neutral-300 rounded-md bg-surface text-sm" />
        <button @click="load" :class="btnOutline('neutral')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>
          {{ t('smtp_logs.refresh') }}
        </button>
        <span class="ml-auto text-xs text-neutral-500">{{ t('smtp_logs.total', { n: data.total, p: currentPage, tp: totalPages }) }}</span>
      </div>

      <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

      <EmptyState v-else-if="!data.events.length" boxed variant="filtered" icon="search" accent="neutral"
        :title="t('smtp_logs.no_records')" />

      <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <!-- Desktop tabulka -->
        <div class="hidden md:block overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium w-40">{{ t('smtp_logs.col.time') }}</th>
                <th class="px-3 py-2 text-left font-medium w-28">{{ t('smtp_logs.col.status') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('smtp_logs.col.flow') }}</th>
                <th class="px-3 py-2 text-left font-medium w-48">{{ t('smtp_logs.col.target') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('smtp_logs.col.response') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="(e, i) in data.events" :key="i" class="hover:bg-neutral-50 align-top"
                  :class="e.status === 'rejected' || e.status === 'error' ? 'bg-danger-50/40' : (e.status === 'deferred' ? 'bg-warning-50/30' : '')">
                <td class="px-3 py-2 font-mono text-xs whitespace-nowrap">{{ fmtTime(e.ts) }}</td>
                <td class="px-3 py-2 space-y-1">
                  <span class="text-xs px-2 py-0.5 rounded font-medium inline-block" :class="statusBadge(e.status)">{{ statusLabel(e.status) }}</span>
                  <span class="text-[11px] px-1.5 py-0.5 rounded inline-block" :class="kindBadge(e.kind)">{{ kindLabel(e.kind) }}</span>
                  <span v-if="e.code" class="text-[11px] text-neutral-400 font-mono block">{{ e.code }}</span>
                </td>
                <td class="px-3 py-2 text-xs break-all leading-snug">
                  <span class="text-neutral-500">{{ e.mail_from || '—' }}</span>
                  <span class="text-neutral-300 mx-1">→</span>
                  <span class="text-neutral-800">{{ fmtRecipients(e.recipients) }}</span>
                  <div v-if="e.subject" class="text-neutral-400 truncate max-w-[28rem] mt-0.5">{{ e.subject }}</div>
                  <RouterLink v-if="e.invoice_id" :to="`/invoices/${e.invoice_id}`"
                    class="text-primary-700 hover:underline font-medium mt-0.5 inline-block">
                    {{ t('smtp_logs.invoice') }} {{ e.invoice_varsymbol || `#${e.invoice_id}` }}
                  </RouterLink>
                </td>
                <td class="px-3 py-2 text-xs break-all">
                  <span class="font-mono">{{ e.remote_host || (e.kind === 'submission' ? t('smtp_logs.inbound') : '—') }}</span>
                  <div v-if="e.remote_ip" class="text-neutral-400 font-mono">{{ e.remote_ip }}</div>
                  <span v-if="e.message_id" class="text-neutral-300 font-mono text-[11px]" :title="e.message_id">#{{ shortId(e.message_id) }}</span>
                </td>
                <td class="px-3 py-2 text-xs break-words leading-snug"
                    :class="e.status === 'rejected' || e.status === 'error' ? 'text-danger-700' : (e.status === 'deferred' ? 'text-warning-700' : 'text-neutral-500')">
                  {{ e.response || '—' }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Mobile karty -->
        <div class="md:hidden divide-y divide-neutral-100">
          <div v-for="(e, i) in data.events" :key="`m-${i}`" class="p-3 space-y-1"
               :class="e.status === 'rejected' || e.status === 'error' ? 'bg-danger-50/40' : (e.status === 'deferred' ? 'bg-warning-50/30' : '')">
            <div class="flex items-center gap-1.5 flex-wrap">
              <span class="text-xs px-2 py-0.5 rounded font-medium" :class="statusBadge(e.status)">{{ statusLabel(e.status) }}</span>
              <span class="text-[11px] px-1.5 py-0.5 rounded" :class="kindBadge(e.kind)">{{ kindLabel(e.kind) }}</span>
              <span v-if="e.code" class="text-[11px] text-neutral-400 font-mono">{{ e.code }}</span>
              <span class="ml-auto font-mono text-xs text-neutral-500">{{ fmtTime(e.ts) }}</span>
            </div>
            <div class="text-xs break-all leading-snug">
              <span class="text-neutral-500">{{ e.mail_from || '—' }}</span> →
              <span class="text-neutral-800">{{ fmtRecipients(e.recipients) }}</span>
            </div>
            <div v-if="e.subject" class="text-xs text-neutral-400 truncate">{{ e.subject }}</div>
            <RouterLink v-if="e.invoice_id" :to="`/invoices/${e.invoice_id}`"
              class="text-xs text-primary-700 hover:underline font-medium inline-block">
              {{ t('smtp_logs.invoice') }} {{ e.invoice_varsymbol || `#${e.invoice_id}` }}
            </RouterLink>
            <div v-if="e.remote_host" class="text-xs font-mono text-neutral-500">{{ e.remote_host }}</div>
            <div v-if="e.response" class="text-xs break-words"
                 :class="e.status === 'rejected' || e.status === 'error' ? 'text-danger-700' : (e.status === 'deferred' ? 'text-warning-700' : 'text-neutral-500')">
              {{ e.response }}
            </div>
          </div>
        </div>

        <div class="border-t border-neutral-200 p-3 flex items-center justify-between">
          <button @click="goPage(-1)" :disabled="filter.offset === 0"
            class="cursor-pointer h-8 px-3 border border-neutral-300 rounded text-sm disabled:opacity-30 hover:bg-neutral-50">
            {{ t('common.previous') }}
          </button>
          <span class="text-xs text-neutral-500">{{ t('common.page') }} {{ currentPage }} / {{ totalPages }}</span>
          <button @click="goPage(1)" :disabled="currentPage >= totalPages"
            class="cursor-pointer h-8 px-3 border border-neutral-300 rounded text-sm disabled:opacity-30 hover:bg-neutral-50">
            {{ t('common.next') }} →
          </button>
        </div>
      </div>

      <!-- Naskenované soubory + okno -->
      <div v-if="data.scanned?.length" class="mt-3 text-xs text-neutral-400">
        {{ t('smtp_logs.scanned', { n: data.scanned.length }) }}
        <span v-if="data.window?.limited" class="text-warning-600">· {{ t('smtp_logs.window_limited', { n: data.window.files_parsed, total: data.window.files_total }) }}</span>
        <span v-if="data.scanned.some(s => s.truncated)" class="text-warning-600">· {{ t('smtp_logs.truncated_note') }}</span>
      </div>
    </template>

    <div v-else class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
  </div>
</template>
