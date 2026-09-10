<script setup lang="ts">
/**
 * Připojení skenů k dokladům, které už v systému jsou.
 *
 * Nahrání dávky (ZIP nebo víc souborů) → zpracování na pozadí → přehled:
 * co se připojilo, co čeká na potvrzení, které doklady sken nemají a které
 * skeny firmy k žádnému dokladu nesedí. Sekce „Rozpory" je připravená pro
 * porovnání skenu s údaji dokladu.
 */
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import {
  scanAttachApi,
  type ScanBatch,
  type ScanBatchDetail,
  type ScanTargetInfo,
  type ScanTargetType,
} from '@/api/scanAttach'
import { apiErrorMessage } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import { btnFilled, btnFilledSm, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'

const { t, locale } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const CHUNK_BYTES = 8 * 1024 * 1024
const GROUP_BYTES = 16 * 1024 * 1024
const GROUP_FILES = 25

type Tab = 'attached' | 'candidates' | 'missing' | 'orphans' | 'unrecognized' | 'discrepancies'
const TABS: Tab[] = ['attached', 'candidates', 'missing', 'orphans', 'unrecognized', 'discrepancies']

const targets = ref<ScanTargetInfo[]>([])
const batches = ref<ScanBatch[]>([])
const detail = ref<ScanBatchDetail | null>(null)
const loading = ref(true)
const error = ref('')
const showForm = ref(false)
const tab = ref<Tab>('candidates')
const deciding = ref<number | null>(null)

const picked = ref<File[]>([])
const selectedTargets = ref<ScanTargetType[]>(['purchase_invoice'])
const dateFrom = ref('')
const dateTo = ref('')
const extract = ref(true)
const acceptLikely = ref(false)
const trustDocNo = ref(false)
const uploading = ref(false)
const uploadDone = ref(0)
const uploadTotal = ref(0)

let timer: ReturnType<typeof setTimeout> | null = null

const canUpload = computed(() => auth.canWrite('documents.upload'))
const selectableTargets = computed(() => targets.value.filter(x => x.available && x.allowed))
const isRunning = computed(() => detail.value?.status === 'queued' || detail.value?.status === 'running')
const overview = computed(() => detail.value?.overview ?? null)
const percent = computed(() => {
  const j = detail.value
  if (!j || !j.total_items) return null
  return Math.min(100, Math.round((j.processed / j.total_items) * 100))
})
const pickedSize = computed(() => picked.value.reduce((s, f) => s + f.size, 0))
const canSubmit = computed(() => !uploading.value && picked.value.length > 0 && selectedTargets.value.length > 0)

function stopPolling() {
  if (timer !== null) {
    clearTimeout(timer)
    timer = null
  }
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const [tg, list] = await Promise.all([scanAttachApi.targets(), scanAttachApi.batches()])
    targets.value = tg.targets
    selectedTargets.value = tg.default.filter(tp => tg.targets.some(x => x.type === tp && x.available && x.allowed))
    batches.value = list
    if (!detail.value && list.length > 0) await open(list[0].id)
    if (list.length === 0) showForm.value = true
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

async function open(id: number) {
  stopPolling()
  try {
    detail.value = await scanAttachApi.batch(id)
    if (isRunning.value) poll(id)
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

function poll(id: number) {
  let failures = 0
  const tick = async () => {
    try {
      const d = await scanAttachApi.batch(id)
      failures = 0
      detail.value = d
      if (d.status === 'queued' || d.status === 'running') {
        timer = setTimeout(tick, 2000)
        return
      }
      timer = null
      if (d.status === 'failed') toast.error(d.last_error || t('scan_attach.status.failed'))
      else if (d.status === 'cancelled') toast.warning(t('scan_attach.status.cancelled'))
      else toast.success(t('scan_attach.done', { attached: d.overview?.counts.attached ?? 0, candidates: d.overview?.counts.candidates ?? 0 }))
      tab.value = (d.overview?.counts.candidates ?? 0) > 0 ? 'candidates' : 'attached'
      batches.value = await scanAttachApi.batches()
    } catch (e) {
      failures++
      if (failures < 5) {
        timer = setTimeout(tick, 4000)
      } else {
        timer = null
        error.value = apiErrorMessage(e)
      }
    }
  }
  timer = setTimeout(tick, 1000)
}

function onPick(e: Event) {
  const input = e.target as HTMLInputElement
  picked.value = Array.from(input.files ?? [])
}

async function submit() {
  if (!canSubmit.value) return
  uploading.value = true
  error.value = ''
  const files = picked.value
  const isZip = files.length === 1 && files[0].name.toLowerCase().endsWith('.zip')
  try {
    const { job_id } = await scanAttachApi.start({
      mode: isZip ? 'zip' : 'files',
      targets: selectedTargets.value,
      date_from: dateFrom.value || null,
      date_to: dateTo.value || null,
      accept_likely: acceptLikely.value,
      trust_doc_no: trustDocNo.value,
      extract: extract.value,
    })
    if (isZip) {
      const zip = files[0]
      uploadTotal.value = Math.max(1, Math.ceil(zip.size / CHUNK_BYTES))
      uploadDone.value = 0
      for (let offset = 0; offset < zip.size; offset += CHUNK_BYTES) {
        await scanAttachApi.chunkBytes(job_id, zip.slice(offset, offset + CHUNK_BYTES))
        uploadDone.value++
      }
    } else {
      uploadTotal.value = files.length
      uploadDone.value = 0
      let group: File[] = []
      let bytes = 0
      for (const f of files) {
        if (group.length > 0 && (group.length >= GROUP_FILES || bytes + f.size > GROUP_BYTES)) {
          await scanAttachApi.chunkFiles(job_id, group)
          uploadDone.value += group.length
          group = []
          bytes = 0
        }
        group.push(f)
        bytes += f.size
      }
      if (group.length > 0) {
        await scanAttachApi.chunkFiles(job_id, group)
        uploadDone.value += group.length
      }
    }
    await scanAttachApi.finish(job_id)
    toast.success(t('scan_attach.started'))
    picked.value = []
    showForm.value = false
    batches.value = await scanAttachApi.batches()
    await open(job_id)
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    uploading.value = false
  }
}

async function resume() {
  const id = detail.value?.id
  if (!id) return
  try {
    await scanAttachApi.resume(id)
    await open(id)
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

async function cancel() {
  const id = detail.value?.id
  if (!id) return
  try {
    await scanAttachApi.cancel(id)
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

async function removeBatch() {
  const id = detail.value?.id
  if (!id || !window.confirm(t('scan_attach.delete_confirm'))) return
  try {
    await scanAttachApi.remove(id)
    detail.value = null
    batches.value = await scanAttachApi.batches()
    if (batches.value.length > 0) await open(batches.value[0].id)
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

async function decide(matchId: number, confirm: boolean) {
  const id = detail.value?.id
  if (!id || deciding.value !== null) return
  deciding.value = matchId
  try {
    if (confirm) await scanAttachApi.confirm(matchId)
    else await scanAttachApi.reject(matchId)
    toast.success(t(confirm ? 'scan_attach.confirmed' : 'scan_attach.rejected'))
    detail.value = await scanAttachApi.batch(id)
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    deciding.value = null
  }
}

const actions = computed<ActionItem[]>(() => [
  {
    key: 'new', label: t('scan_attach.action_new'), icon: 'upload',
    tier: 'primary', variant: 'primary',
    show: canUpload.value, disabled: uploading.value,
    run: () => { showForm.value = !showForm.value },
  },
  {
    key: 'resume', label: t('scan_attach.action_resume'), icon: 'cycle',
    tier: 'secondary', variant: 'warning',
    show: canUpload.value && !!detail.value && !isRunning.value,
    title: t('scan_attach.action_resume_hint'),
    run: resume,
  },
  {
    key: 'cancel', label: t('scan_attach.action_cancel'), icon: 'x',
    tier: 'secondary', variant: 'danger',
    show: canUpload.value && isRunning.value && !detail.value?.cancel_requested,
    run: cancel,
  },
  {
    key: 'refresh', label: t('scan_attach.action_refresh'), icon: 'cycle',
    tier: 'overflow', variant: 'neutral',
    show: true, disabled: loading.value,
    run: () => { void load(); if (detail.value) void open(detail.value.id) },
  },
  {
    key: 'delete', label: t('scan_attach.action_delete'), icon: 'trash',
    tier: 'overflow', variant: 'danger',
    show: canUpload.value && !!detail.value && detail.value.status !== 'running',
    run: removeBatch,
  },
])

function fmtMoney(v: number | null | undefined, currency?: string | null): string {
  if (v === null || v === undefined) return '—'
  return new Intl.NumberFormat(locale.value === 'en' ? 'en-US' : 'cs-CZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(v)
    + (currency ? ' ' + currency : '')
}

function fmtDateTime(iso: string | null): string {
  if (!iso) return '—'
  const d = new Date(iso.replace(' ', 'T'))
  return isNaN(d.getTime()) ? iso : d.toLocaleString(locale.value === 'en' ? 'en-US' : 'cs-CZ')
}

function fmtSize(bytes: number): string {
  if (bytes < 1024 * 1024) return Math.max(1, Math.round(bytes / 1024)) + ' KB'
  return (bytes / 1024 / 1024).toFixed(1) + ' MB'
}

function targetLink(type: ScanTargetType, id: number): string | null {
  if (type === 'purchase_invoice') return `/purchase-invoices/${id}`
  if (type === 'invoice') return `/invoices/${id}`
  return null
}

function tabCount(key: Tab): number {
  const c = overview.value?.counts
  if (!c) return 0
  return {
    attached: c.attached, candidates: c.candidates, missing: c.missing,
    orphans: c.orphans, unrecognized: c.unrecognized, discrepancies: c.discrepancies,
  }[key]
}

onMounted(load)
onUnmounted(stopPolling)
</script>

<template>
  <div class="max-w-6xl">
    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('scan_attach.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">
          {{ t('scan_attach.subtitle') }}
          <a href="/manual?ch=31a_Pripojeni_skenu" target="_blank" rel="noopener" class="text-primary-600 hover:underline whitespace-nowrap">{{ t('scan_attach.manual_link') }}</a>
        </p>
      </div>
      <ActionBar :actions="actions" />
    </div>

    <div v-if="error" class="bg-danger-50 border border-danger-500/40 text-danger-500 rounded-md p-3 text-sm mb-4">{{ error }}</div>

    <!-- Nová dávka -->
    <div v-if="showForm && canUpload" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 mb-4 space-y-4">
      <h2 class="font-medium text-neutral-900">{{ t('scan_attach.form_title') }}</h2>

      <div>
        <label class="block text-sm font-medium text-neutral-700 mb-1" for="scan-files">{{ t('scan_attach.form_files') }}</label>
        <input id="scan-files" type="file" multiple accept=".pdf,.jpg,.jpeg,.jfif,.png,.webp,.heic,.tif,.tiff,.zip"
          class="block w-full text-sm" :disabled="uploading" @change="onPick" />
        <p class="text-xs text-neutral-500 mt-1">{{ t('scan_attach.form_files_hint') }}</p>
        <p v-if="picked.length" class="text-xs text-neutral-700 mt-1">{{ t('scan_attach.form_selected', { n: picked.length, size: fmtSize(pickedSize) }) }}</p>
      </div>

      <div>
        <div class="text-sm font-medium text-neutral-700 mb-1">{{ t('scan_attach.form_targets') }}</div>
        <p v-if="selectableTargets.length === 0" class="text-sm text-warning-700">{{ t('scan_attach.no_targets_allowed') }}</p>
        <div class="flex flex-wrap gap-x-5 gap-y-2">
          <label v-for="tg in targets" :key="tg.type" class="inline-flex items-center gap-2 text-sm whitespace-nowrap"
            :class="tg.available && tg.allowed ? '' : 'text-neutral-400'">
            <input v-model="selectedTargets" type="checkbox" :value="tg.type" :disabled="!tg.available || !tg.allowed || uploading" />
            {{ t('scan_attach.target.' + tg.type) }}
            <span v-if="!tg.available" class="text-xs">({{ t('scan_attach.target_unavailable') }})</span>
          </label>
        </div>
      </div>

      <div class="flex flex-wrap gap-4">
        <label class="text-sm">
          <span class="block text-neutral-700 mb-1">{{ t('scan_attach.form_date_from') }}</span>
          <input v-model="dateFrom" type="date" class="border border-neutral-300 rounded-md px-2 py-1.5 text-sm" :disabled="uploading" />
        </label>
        <label class="text-sm">
          <span class="block text-neutral-700 mb-1">{{ t('scan_attach.form_date_to') }}</span>
          <input v-model="dateTo" type="date" class="border border-neutral-300 rounded-md px-2 py-1.5 text-sm" :disabled="uploading" />
        </label>
      </div>

      <div class="space-y-2 text-sm">
        <label class="flex items-start gap-2">
          <input v-model="extract" type="checkbox" class="mt-0.5" :disabled="uploading" />
          <span>{{ t('scan_attach.form_extract') }}<span class="block text-xs text-neutral-500">{{ t('scan_attach.form_extract_hint') }}</span></span>
        </label>
        <label class="flex items-start gap-2">
          <input v-model="acceptLikely" type="checkbox" class="mt-0.5" :disabled="uploading" />
          <span>{{ t('scan_attach.form_accept_likely') }}</span>
        </label>
        <label class="flex items-start gap-2">
          <input v-model="trustDocNo" type="checkbox" class="mt-0.5" :disabled="uploading" />
          <span>{{ t('scan_attach.form_trust_doc_no') }}</span>
        </label>
      </div>

      <div class="flex flex-wrap items-center gap-3">
        <button type="button" :class="btnFilled('primary')" class="whitespace-nowrap" :disabled="!canSubmit" @click="submit">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.upload" /></svg>
          {{ uploading ? t('scan_attach.uploading', { done: uploadDone, total: uploadTotal }) : t('scan_attach.form_submit') }}
        </button>
      </div>
    </div>

    <div v-if="loading" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-8 text-center text-neutral-400">{{ t('common.loading') }}…</div>

    <template v-else>
      <!-- Vybraná dávka -->
      <div v-if="detail" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 mb-4">
        <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
          <h2 class="font-medium text-neutral-900">{{ t('scan_attach.batch_title', { id: detail.id }) }}</h2>
          <span class="text-xs rounded-full px-2 py-0.5 whitespace-nowrap"
            :class="{
              'bg-success-50 text-success-600': detail.status === 'completed',
              'bg-warning-50 text-warning-700': detail.status === 'completed_with_warnings' || detail.status === 'cancelled',
              'bg-danger-50 text-danger-600': detail.status === 'failed',
              'bg-primary-50 text-primary-700': isRunning,
            }">{{ t('scan_attach.status.' + detail.status) }}</span>
        </div>

        <div v-if="isRunning" class="rounded-lg border border-primary-200 bg-primary-50/50 px-4 py-4 mb-4 space-y-2">
          <div class="text-sm font-medium text-primary-700">{{ detail.current_step || t('scan_attach.status.' + detail.status) }}</div>
          <div class="h-2 rounded-full bg-primary-100 overflow-hidden">
            <div class="h-full bg-primary-500 transition-all duration-300"
              :class="percent === null ? 'animate-pulse w-1/3' : ''"
              :style="percent === null ? undefined : { width: percent + '%' }"></div>
          </div>
          <div v-if="detail.total_items" class="text-xs text-neutral-600">{{ detail.processed }} / {{ detail.total_items }}<span v-if="percent !== null"> ({{ percent }} %)</span></div>
          <p class="text-xs text-neutral-500">{{ t('scan_attach.background_hint') }}</p>
        </div>

        <div v-if="detail.last_error" class="bg-danger-50 border border-danger-500/40 text-danger-500 rounded-md p-3 text-sm mb-4">{{ detail.last_error }}</div>

        <template v-if="overview">
          <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-3 mb-4 text-sm">
            <div class="rounded-md border border-neutral-100 p-3">
              <div class="text-xl font-semibold">{{ overview.counts.files }}</div>
              <div class="text-neutral-500">{{ t('scan_attach.stat.files') }}</div>
            </div>
            <button v-for="k in TABS" :key="k" type="button"
              class="cursor-pointer rounded-md border p-3 text-left transition-colors"
              :class="tab === k ? 'border-primary-400 bg-primary-50/60' : 'border-neutral-100 hover:bg-neutral-50'"
              @click="tab = k">
              <div class="text-xl font-semibold"
                :class="{
                  'text-success-600': k === 'attached' && tabCount(k) > 0,
                  'text-warning-600': (k === 'candidates' || k === 'orphans' || k === 'missing') && tabCount(k) > 0,
                  'text-neutral-400': tabCount(k) === 0,
                }">{{ tabCount(k) }}</div>
              <div class="text-neutral-500">{{ t('scan_attach.tab.' + k) }}</div>
            </button>
          </div>

          <p v-if="tabCount(tab) > overview.list_limit" class="text-xs text-neutral-500 mb-2">{{ t('scan_attach.list_limited', { n: overview.list_limit }) }}</p>

          <!-- Připojeno -->
          <div v-if="tab === 'attached'" class="overflow-x-auto">
            <p v-if="overview.attached.length === 0" class="text-sm text-neutral-500">{{ t('scan_attach.empty.attached') }}</p>
            <table v-else class="w-full text-sm">
              <thead>
                <tr class="text-left text-xs uppercase tracking-wide text-neutral-500 border-b border-neutral-100">
                  <th class="py-2 pr-3">{{ t('scan_attach.col_file') }}</th>
                  <th class="py-2 pr-3">{{ t('scan_attach.col_document') }}</th>
                  <th class="py-2 pr-3">{{ t('scan_attach.col_method') }}</th>
                  <th class="py-2">{{ t('scan_attach.col_level') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="m in overview.attached" :key="m.match_id" class="border-b border-neutral-50 last:border-0">
                  <td class="py-2 pr-3">
                    <RouterLink v-if="m.document_id" :to="`/documents/${m.document_id}`" class="text-primary-600 hover:underline">{{ m.file_name }}</RouterLink>
                    <span v-else>{{ m.file_name }}</span>
                  </td>
                  <td class="py-2 pr-3">
                    <span class="text-xs text-neutral-500 mr-1">{{ t('scan_attach.target.' + m.target_type) }}</span>
                    <RouterLink v-if="targetLink(m.target_type, m.target_id)" :to="targetLink(m.target_type, m.target_id)!" class="text-primary-600 hover:underline">{{ m.target?.label ?? '#' + m.target_id }}</RouterLink>
                    <span v-else>{{ m.target?.label ?? '#' + m.target_id }}</span>
                    <span v-if="m.target?.counterparty" class="text-neutral-500"> · {{ m.target.counterparty }}</span>
                  </td>
                  <td class="py-2 pr-3 whitespace-nowrap">{{ t('scan_attach.method.' + m.method) }}</td>
                  <td class="py-2">
                    {{ t('scan_attach.level.' + m.level) }}
                    <span v-if="m.note" class="block text-xs text-warning-700">{{ t('scan_attach.note.' + m.note) }}</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Ke kontrole -->
          <div v-else-if="tab === 'candidates'" class="overflow-x-auto">
            <p v-if="overview.candidates.length === 0" class="text-sm text-neutral-500">{{ t('scan_attach.empty.candidates') }}</p>
            <table v-else class="w-full text-sm">
              <thead>
                <tr class="text-left text-xs uppercase tracking-wide text-neutral-500 border-b border-neutral-100">
                  <th class="py-2 pr-3">{{ t('scan_attach.col_file') }}</th>
                  <th class="py-2 pr-3">{{ t('scan_attach.col_scan') }}</th>
                  <th class="py-2 pr-3">{{ t('scan_attach.col_document') }}</th>
                  <th class="py-2 pr-3">{{ t('scan_attach.col_level') }}</th>
                  <th class="py-2 text-right">{{ t('scan_attach.col_actions') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="m in overview.candidates" :key="m.match_id" class="border-b border-neutral-50 last:border-0 align-top">
                  <td class="py-2 pr-3">
                    <RouterLink v-if="m.document_id" :to="`/documents/${m.document_id}`" class="text-primary-600 hover:underline">{{ m.file_name }}</RouterLink>
                    <span v-else>{{ m.file_name }}</span>
                  </td>
                  <td class="py-2 pr-3 text-xs text-neutral-700">
                    <template v-if="m.scan">
                      <div>{{ m.scan.vendor_name || m.scan.vendor_ico || '—' }}<span v-if="m.scan.document_number"> · {{ m.scan.document_number }}</span></div>
                      <div>{{ m.scan.issue_date || '—' }} · {{ fmtMoney(m.scan.total_with_vat, m.scan.currency) }}</div>
                    </template>
                    <span v-else class="text-neutral-400">{{ t('scan_attach.scan_none') }}</span>
                  </td>
                  <td class="py-2 pr-3 text-xs">
                    <div>
                      <span class="text-neutral-500 mr-1">{{ t('scan_attach.target.' + m.target_type) }}</span>
                      <RouterLink v-if="targetLink(m.target_type, m.target_id)" :to="targetLink(m.target_type, m.target_id)!" class="text-primary-600 hover:underline">{{ m.target?.label ?? '#' + m.target_id }}</RouterLink>
                      <span v-else>{{ m.target?.label ?? '#' + m.target_id }}</span>
                    </div>
                    <div class="text-neutral-700">{{ m.target?.counterparty || '—' }}</div>
                    <div class="text-neutral-700">{{ m.target?.date || '—' }} · {{ fmtMoney(m.target?.total, m.target?.currency) }}</div>
                  </td>
                  <td class="py-2 pr-3 text-xs">
                    {{ t('scan_attach.level.' + m.level) }} · {{ t('scan_attach.method.' + m.method) }}
                    <span v-if="m.note" class="block text-warning-700">{{ t('scan_attach.note.' + m.note) }}</span>
                  </td>
                  <td class="py-2">
                    <div class="flex flex-wrap justify-end gap-2">
                      <button type="button" :class="btnFilledSm('success')" class="whitespace-nowrap" :disabled="deciding !== null" @click="decide(m.match_id, true)">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
                        {{ t('scan_attach.confirm') }}
                      </button>
                      <button type="button" :class="btnOutlineSm('danger')" class="whitespace-nowrap" :disabled="deciding !== null" @click="decide(m.match_id, false)">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
                        {{ t('scan_attach.reject') }}
                      </button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Doklady bez skenu -->
          <div v-else-if="tab === 'missing'" class="overflow-x-auto">
            <p v-if="overview.missing.length === 0" class="text-sm text-neutral-500">{{ t('scan_attach.empty.missing') }}</p>
            <table v-else class="w-full text-sm">
              <thead>
                <tr class="text-left text-xs uppercase tracking-wide text-neutral-500 border-b border-neutral-100">
                  <th class="py-2 pr-3">{{ t('scan_attach.col_type') }}</th>
                  <th class="py-2 pr-3">{{ t('scan_attach.col_document') }}</th>
                  <th class="py-2 pr-3">{{ t('scan_attach.col_counterparty') }}</th>
                  <th class="py-2 pr-3">{{ t('scan_attach.col_date') }}</th>
                  <th class="py-2 text-right">{{ t('scan_attach.col_amount') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="r in overview.missing" :key="r.target_type + r.id" class="border-b border-neutral-50 last:border-0">
                  <td class="py-2 pr-3 text-xs text-neutral-500 whitespace-nowrap">{{ t('scan_attach.target.' + r.target_type) }}</td>
                  <td class="py-2 pr-3">
                    <RouterLink v-if="targetLink(r.target_type, r.id)" :to="targetLink(r.target_type, r.id)!" class="text-primary-600 hover:underline">{{ r.label }}</RouterLink>
                    <span v-else>{{ r.label }}</span>
                  </td>
                  <td class="py-2 pr-3">{{ r.counterparty || '—' }}</td>
                  <td class="py-2 pr-3 whitespace-nowrap">{{ r.date || '—' }}</td>
                  <td class="py-2 text-right whitespace-nowrap">{{ fmtMoney(r.total, r.currency) }}</td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Skeny bez dokladu / nerozpoznané -->
          <div v-else-if="tab === 'orphans' || tab === 'unrecognized'" class="overflow-x-auto">
            <p v-if="(tab === 'orphans' ? overview.orphans : overview.unrecognized).length === 0" class="text-sm text-neutral-500">{{ t('scan_attach.empty.' + tab) }}</p>
            <table v-else class="w-full text-sm">
              <thead>
                <tr class="text-left text-xs uppercase tracking-wide text-neutral-500 border-b border-neutral-100">
                  <th class="py-2 pr-3">{{ t('scan_attach.col_file') }}</th>
                  <th class="py-2 pr-3">{{ t('scan_attach.col_scan') }}</th>
                  <th class="py-2 pr-3">{{ t('scan_attach.col_date') }}</th>
                  <th class="py-2 pr-3 text-right">{{ t('scan_attach.col_amount') }}</th>
                  <th v-if="tab === 'unrecognized'" class="py-2">{{ t('scan_attach.col_result') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="r in (tab === 'orphans' ? overview.orphans : overview.unrecognized)" :key="r.item_id" class="border-b border-neutral-50 last:border-0">
                  <td class="py-2 pr-3">
                    <RouterLink v-if="r.document_id" :to="`/documents/${r.document_id}`" class="text-primary-600 hover:underline">{{ r.file_name }}</RouterLink>
                    <span v-else>{{ r.file_name }}</span>
                  </td>
                  <td class="py-2 pr-3 text-xs">
                    <template v-if="r.scan">{{ r.scan.vendor_name || r.scan.vendor_ico || '—' }}<span v-if="r.scan.document_number"> · {{ r.scan.document_number }}</span></template>
                    <span v-else class="text-neutral-400">{{ t('scan_attach.scan_none') }}</span>
                  </td>
                  <td class="py-2 pr-3 whitespace-nowrap">{{ r.scan?.issue_date || '—' }}</td>
                  <td class="py-2 pr-3 text-right whitespace-nowrap">{{ fmtMoney(r.scan?.total_with_vat, r.scan?.currency) }}</td>
                  <td v-if="tab === 'unrecognized'" class="py-2 text-xs">
                    {{ t('scan_attach.outcome.' + r.outcome) }}
                    <span v-if="r.error" class="block text-neutral-500 break-all">{{ r.error }}</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Rozpory -->
          <div v-else class="rounded-md border border-dashed border-neutral-200 p-4 text-sm text-neutral-500">
            {{ t('scan_attach.discrepancies_pending') }}
          </div>
        </template>

        <p v-else-if="!isRunning" class="text-sm text-neutral-500">{{ t('scan_attach.running_overview') }}</p>

        <details v-if="detail.log_text" class="text-xs mt-4">
          <summary class="cursor-pointer text-neutral-600 hover:text-neutral-900">{{ t('scan_attach.log') }}</summary>
          <pre class="mt-2 max-h-72 overflow-auto bg-neutral-900 text-neutral-100 rounded p-3 text-[11px] leading-relaxed">{{ detail.log_text }}</pre>
        </details>
      </div>

      <!-- Historie dávek -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5">
        <h2 class="font-medium text-neutral-900 mb-3">{{ t('scan_attach.history_title') }}</h2>
        <p v-if="batches.length === 0" class="text-sm text-neutral-500">{{ t('scan_attach.history_empty') }}</p>
        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead>
              <tr class="text-left text-xs uppercase tracking-wide text-neutral-500 border-b border-neutral-100">
                <th class="py-2 pr-3">{{ t('scan_attach.col_created') }}</th>
                <th class="py-2 pr-3">{{ t('scan_attach.col_status') }}</th>
                <th class="py-2 pr-3 text-right">{{ t('scan_attach.col_files') }}</th>
                <th class="py-2 pr-3 text-right">{{ t('scan_attach.col_attached') }}</th>
                <th class="py-2 text-right">{{ t('scan_attach.col_proposed') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="b in batches" :key="b.id" class="border-b border-neutral-50 last:border-0 cursor-pointer hover:bg-neutral-50"
                :class="detail?.id === b.id ? 'bg-primary-50/50' : ''" @click="open(b.id)">
                <td class="py-2 pr-3 whitespace-nowrap">#{{ b.id }} · {{ fmtDateTime(b.created_at) }}</td>
                <td class="py-2 pr-3">{{ t('scan_attach.status.' + b.status) }}</td>
                <td class="py-2 pr-3 text-right">{{ Object.values(b.counts ?? {}).reduce((s, n) => s + n, 0) }}</td>
                <td class="py-2 pr-3 text-right">{{ b.attached_count }}</td>
                <td class="py-2 text-right" :class="b.proposed_count > 0 ? 'text-warning-600 font-medium' : ''">{{ b.proposed_count }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
  </div>
</template>
