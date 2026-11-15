<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import { membershipApi, type MembershipImportReport, type MembershipImportRow } from '@/api/nonprofitMembership'
import { formatDate } from '@/composables/useFormat'
import { badgeBase, errorBox, errorCode, membershipError, warningBox } from './membershipFormat'

/** Import seznamu členů: soubor → náhled po řádcích → potvrzení stejného souboru (krok 8 #321). */
const props = defineProps<{ supplier: number; canManage: boolean }>()
const emit = defineEmits<{ changed: []; close: [] }>()
const { t, te } = useI18n()

const MAX_SIZE = 2 * 1024 * 1024
const file = ref<File | null>(null)
const report = ref<MembershipImportReport | null>(null)
const step = ref<'upload' | 'preview' | 'result'>('upload')
const busy = ref(false), error = ref(''), notice = ref(''), onlyProblems = ref(false)
const input = ref<HTMLInputElement | null>(null)
let alive = true
onBeforeUnmount(() => { alive = false })

function pick(event: Event) {
  const chosen = (event.target as HTMLInputElement).files?.[0] ?? null
  error.value = ''; notice.value = ''
  report.value = null; step.value = 'upload'
  if (!chosen) { file.value = null; return }
  const ext = chosen.name.toLowerCase().split('.').pop() ?? ''
  if (!['xlsx', 'csv'].includes(ext) || chosen.size > MAX_SIZE) {
    file.value = null
    error.value = t('nonprofitMembership.import.fileInvalid')
    return
  }
  file.value = chosen
}

async function send(confirming: boolean) {
  if (!file.value || busy.value) return
  const supplier = props.supplier
  busy.value = true; error.value = ''; notice.value = ''
  try {
    const result = await membershipApi.importMembers(supplier, file.value, confirming ? report.value?.preview_hash ?? null : null)
    if (!alive || supplier !== props.supplier) return
    report.value = result
    step.value = confirming ? 'result' : 'preview'
    if (confirming) {
      notice.value = t('nonprofitMembership.import.done', { written: result.written ?? 0, failed: result.failed_writes ?? 0 })
      emit('changed')
    }
  } catch (e) {
    if (!alive || supplier !== props.supplier) return
    // Data se od náhledu změnila: server vrací čerstvý náhled, potvrzení jde až nad ním.
    const fresh = (e as { response?: { data?: { error?: { details?: { preview?: MembershipImportReport } } } } })?.response?.data?.error?.details?.preview
    if (fresh) { report.value = fresh; step.value = 'preview' }
    error.value = membershipError({ t, te }, e)
    if (errorCode(e) === 'nonprofit_membership_import_file_invalid') { report.value = null; step.value = 'upload' }
  } finally {
    if (alive) busy.value = false
  }
}

function reset() {
  file.value = null; report.value = null; step.value = 'upload'
  error.value = ''; notice.value = ''; onlyProblems.value = false
  if (input.value) input.value.value = ''
}

const hasErrors = computed(() => (report.value?.counts.error ?? 0) > 0)
const writable = computed(() => (report.value?.counts.new_revision ?? 0) + (report.value?.counts.new_member ?? 0))
const rows = computed<MembershipImportRow[]>(() => {
  const all = report.value?.rows ?? []
  return onlyProblems.value ? all.filter(row => row.status === 'error' || row.outcome === 'failed') : all
})

const actions = computed<ActionItem[]>(() => [
  { key: 'import-preview', label: t('nonprofitMembership.import.preview'), icon: 'eye', tier: 'primary', variant: 'primary', show: step.value === 'upload',
    disabled: !file.value || busy.value, disabledReason: t('nonprofitMembership.import.chooseFile'), run: () => { void send(false) } },
  { key: 'import-confirm', label: t('nonprofitMembership.import.confirm'), icon: 'check', tier: 'primary', variant: 'success', show: step.value === 'preview' && props.canManage,
    disabled: busy.value || hasErrors.value || writable.value === 0,
    disabledReason: hasErrors.value ? t('nonprofitMembership.import.fixErrors') : t('nonprofitMembership.import.nothingToWrite'), run: () => { void send(true) } },
  { key: 'import-other', label: t('nonprofitMembership.import.otherFile'), icon: 'upload', show: step.value !== 'upload', disabled: busy.value, run: reset },
  { key: 'import-close', label: t('nonprofitMembership.close'), icon: 'x', disabled: busy.value, run: () => emit('close') },
])

function statusClass(row: MembershipImportRow): string {
  if (row.outcome === 'failed' || row.status === 'error') return 'bg-danger-50 text-danger-600'
  if (row.status === 'new_member') return 'bg-success-50 text-success-700'
  if (row.status === 'new_revision') return 'bg-primary-50 text-primary-700'
  return 'bg-neutral-100 text-neutral-600'
}
function detail(row: MembershipImportRow): string {
  if (row.outcome === 'failed') return membershipError({ t, te }, row.failure ?? '')
  if (row.outcome === 'already_imported') return t('nonprofitMembership.import.reason_already_imported')
  if (row.reason) {
    const params = { ...(row.params ?? {}) }
    if (params.date) params.date = formatDate(params.date)
    if (params.status) params.status = t(`nonprofitMembership.status_${params.status}`)
    const key = `nonprofitMembership.import.reason_${row.reason}`
    return te(key) ? t(key, params) : t('nonprofitMembership.error')
  }
  const change = row.new_status && row.effective_from ? t('nonprofitMembership.import.change', { status: t(`nonprofitMembership.status_${row.new_status}`), date: formatDate(row.effective_from) }) : ''
  if (row.status === 'new_member') return [change, t(row.contact === 'create' ? 'nonprofitMembership.import.contactCreate' : 'nonprofitMembership.import.contactExisting')].filter(Boolean).join(' · ')
  return change
}
</script>

<template>
  <section class="space-y-3 rounded-lg border border-neutral-200 bg-surface p-4" data-section="member-import">
    <h2 class="font-semibold">{{ t('nonprofitMembership.import.title') }}</h2>
    <p class="text-sm text-neutral-500">{{ t('nonprofitMembership.import.help') }}</p>
    <label v-if="step === 'upload'" class="block text-sm">
      <span class="font-medium">{{ t('nonprofitMembership.import.file') }}</span>
      <input ref="input" type="file" accept=".xlsx,.csv" class="mt-1 block w-full text-sm" :disabled="busy" data-field="import-file" @change="pick" />
    </label>
    <p v-if="error" role="alert" :class="errorBox" data-section="import-error">{{ error }}</p>
    <p v-if="notice" role="status" class="text-sm text-success-600" data-section="import-notice">{{ notice }}</p>
    <p v-if="busy" role="status" class="text-sm text-neutral-500">{{ t('common.loading') }}</p>

    <template v-if="report && step !== 'upload'">
      <p class="text-sm" data-section="import-summary">{{ t('nonprofitMembership.import.summary', report.counts) }}</p>
      <p v-if="step === 'preview' && hasErrors" :class="warningBox" data-section="import-has-errors">{{ t('nonprofitMembership.import.fixErrors') }}</p>
      <label class="inline-flex items-center gap-2 text-sm">
        <input v-model="onlyProblems" type="checkbox" data-field="import-only-problems" />{{ t('nonprofitMembership.import.onlyProblems') }}
      </label>
      <ul class="space-y-2" data-section="import-rows">
        <li v-for="row in rows" :key="row.line" class="flex flex-wrap items-start gap-x-3 gap-y-1 rounded border border-neutral-200 p-2 text-sm" data-section="import-row" :data-line="row.line">
          <span class="whitespace-nowrap text-neutral-500">{{ t('nonprofitMembership.import.line', { line: row.line }) }}</span>
          <span class="min-w-0 break-words font-medium">{{ row.member_number || '—' }}<template v-if="row.name"> · {{ row.name }}</template></span>
          <span :class="[badgeBase, statusClass(row)]" data-field="import-status">{{ t(`nonprofitMembership.import.status_${row.outcome === 'failed' || row.outcome === 'already_imported' ? row.outcome : row.status}`) }}</span>
          <span class="basis-full break-words text-neutral-600" data-field="import-detail">{{ detail(row) }}</span>
        </li>
        <li v-if="!rows.length" class="text-sm text-neutral-500">{{ t('nonprofitMembership.import.noRows') }}</li>
      </ul>
    </template>
    <ActionBar :actions="actions" />
  </section>
</template>
