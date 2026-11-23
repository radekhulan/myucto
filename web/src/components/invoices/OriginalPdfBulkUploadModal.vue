<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Modal from '@/components/ui/Modal.vue'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import { invoicesApi } from '@/api/invoices'
import { apiErrorMessage } from '@/api/errors'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'
import {
  ORIGINAL_PDF_BULK_MAX, ORIGINAL_PDF_STATE_BADGE, buildRows, countStates, isPdfFile,
  type OriginalPdfRow,
} from './originalPdfBulk'

/**
 * Hromadné nahrání originálů PDF k vydaným fakturám: výběr souborů → náhled párování
 * podle čísla dokladu v názvu (server) → po potvrzení nahrání spárovaných po jednom
 * přes stejnou cestu jako detail faktury.
 */
const emit = defineEmits<{ (e: 'close'): void; (e: 'uploaded', ids: number[]): void }>()

const { t } = useI18n()
const toast = useToast()

const rows = ref<OriginalPdfRow[]>([])
const phase = ref<'pick' | 'matching' | 'review' | 'uploading' | 'done'>('pick')
const dragOver = ref(false)
const progress = ref(0)
const input = ref<HTMLInputElement | null>(null)

const counts = computed(() => countStates(rows.value))
const toUpload = computed(() => rows.value.filter(r => r.state === 'matched'))

async function pick(files: File[]) {
  if (files.length === 0) return
  if (files.length > ORIGINAL_PDF_BULK_MAX) {
    toast.error(t('invoice.original_pdf_bulk.too_many', { max: ORIGINAL_PDF_BULK_MAX }))
    return
  }
  phase.value = 'matching'
  try {
    const pdfNames = files.filter(isPdfFile).map(f => f.name)
    const matches = pdfNames.length > 0 ? await invoicesApi.matchImportedPdfs(pdfNames) : []
    rows.value = buildRows(files, matches)
    phase.value = 'review'
  } catch (e: unknown) {
    toast.error(apiErrorMessage(e, t('invoice.original_pdf_bulk.match_failed')))
    phase.value = 'pick'
  } finally {
    if (input.value) input.value.value = ''
  }
}

function onInputChange(ev: Event) {
  const el = ev.target as HTMLInputElement
  void pick(el.files ? Array.from(el.files) : [])
}

function onDrop(ev: DragEvent) {
  ev.preventDefault()
  dragOver.value = false
  if (phase.value !== 'pick') return
  void pick(ev.dataTransfer?.files ? Array.from(ev.dataTransfer.files) : [])
}

async function upload() {
  const queue = toUpload.value
  if (queue.length === 0) return
  phase.value = 'uploading'
  progress.value = 0
  const uploaded: number[] = []
  for (const row of queue) {
    const id = row.match?.invoice_id
    if (id == null) continue
    try {
      await invoicesApi.uploadImportedPdf(id, row.file)
      row.state = 'uploaded'
      uploaded.push(id)
    } catch (e: unknown) {
      row.state = 'failed'
      row.error = apiErrorMessage(e)
    }
    progress.value++
  }
  phase.value = 'done'
  const failed = queue.length - uploaded.length
  const msg = t('invoice.original_pdf_bulk.done', { ok: uploaded.length, failed })
  if (failed > 0) toast.warning(msg)
  else toast.success(msg)
  if (uploaded.length > 0) emit('uploaded', uploaded)
}

function reset() {
  rows.value = []
  phase.value = 'pick'
}

function reasonText(row: OriginalPdfRow): string {
  const m = row.match
  if (row.error) return row.error
  if (!m || m.status !== 'ambiguous' || !m.reason) return ''
  const numbers = m.candidates.map(c => c.varsymbol ?? `#${c.id}`).join(', ')
  return t(`invoice.original_pdf_bulk.reason.${m.reason}`, { numbers })
}
</script>

<template>
  <Modal :title="t('invoice.original_pdf_bulk.title')" width-class="max-w-4xl" @close="emit('close')">
    <p class="text-sm text-neutral-600 mb-4">{{ t('invoice.original_pdf_bulk.intro') }}</p>

    <div v-if="phase === 'pick' || phase === 'matching'"
         class="rounded-lg border-2 border-dashed px-5 py-8 text-center"
         :class="dragOver ? 'border-primary-400 bg-primary-50' : 'border-neutral-300 bg-neutral-50/50'"
         data-test="original-pdf-bulk-drop"
         @dragover.prevent="dragOver = true"
         @dragleave.prevent="dragOver = false"
         @drop="onDrop">
      <input ref="input" type="file" multiple accept="application/pdf,.pdf" class="hidden" @change="onInputChange" />
      <div class="flex flex-col items-center gap-2">
        <button type="button" :class="btnOutline('primary')" class="whitespace-nowrap"
                :disabled="phase === 'matching'" @click="input?.click()">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.upload" /></svg>
          {{ phase === 'matching' ? t('invoice.original_pdf_bulk.matching') : t('invoice.original_pdf_bulk.choose') }}
        </button>
        <span class="text-xs text-neutral-500">{{ t('invoice.original_pdf_bulk.drop_here') }}</span>
      </div>
    </div>

    <template v-else>
      <p class="text-sm text-neutral-700 mb-3" data-test="original-pdf-bulk-summary">
        {{ t('invoice.original_pdf_bulk.summary', { matched: counts.matched + counts.uploaded + counts.failed, unmatched: counts.unmatched + counts.not_pdf, ambiguous: counts.ambiguous }) }}
      </p>
      <div class="border border-neutral-200 rounded-lg overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500 max-md:hidden">
            <tr>
              <th class="px-3 py-2 text-left font-semibold">{{ t('invoice.original_pdf_bulk.col_file') }}</th>
              <th class="px-3 py-2 text-left font-semibold">{{ t('invoice.original_pdf_bulk.col_invoice') }}</th>
              <th class="px-3 py-2 text-left font-semibold">{{ t('invoice.original_pdf_bulk.col_state') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="(row, i) in rows" :key="i" class="max-md:flex max-md:flex-col max-md:gap-1 max-md:py-2">
              <td class="px-3 md:py-2 break-all text-neutral-800">{{ row.file.name }}</td>
              <td class="px-3 md:py-2">
                <template v-if="row.match?.invoice">
                  <div class="font-medium text-neutral-800">{{ row.match.invoice.varsymbol ?? `#${row.match.invoice.id}` }}</div>
                  <div class="text-xs text-neutral-500">
                    {{ row.match.invoice.client_name }}
                    <template v-if="row.match.invoice.issue_date"> · {{ formatDate(row.match.invoice.issue_date) }}</template>
                    <template v-if="row.match.invoice.total_with_vat !== null"> · {{ formatMoney(row.match.invoice.total_with_vat, row.match.invoice.currency ?? 'CZK') }}</template>
                  </div>
                  <div v-if="row.match.invoice.has_original && row.state === 'matched'" class="text-xs text-warning-600">
                    {{ t('invoice.original_pdf_bulk.replaces_original') }}
                  </div>
                </template>
                <span v-else class="text-xs text-neutral-500">{{ reasonText(row) || '—' }}</span>
              </td>
              <td class="px-3 md:py-2 whitespace-nowrap">
                <span class="text-xs px-2 py-0.5 rounded font-medium" :class="ORIGINAL_PDF_STATE_BADGE[row.state]">
                  {{ t(`invoice.original_pdf_bulk.status.${row.state}`) }}
                </span>
                <div v-if="row.state === 'failed' && row.error" class="text-xs text-danger-600 mt-0.5">{{ row.error }}</div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>

    <template #footer>
      <div class="flex flex-wrap items-center justify-end gap-2">
        <span v-if="phase === 'uploading'" class="text-sm text-neutral-600 mr-auto">
          {{ t('invoice.original_pdf_bulk.uploading', { done: progress, total: toUpload.length + progress }) }}
        </span>
        <button v-if="phase === 'review' || phase === 'done'" type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="reset">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.swap" /></svg>
          {{ t('invoice.original_pdf_bulk.reset') }}
        </button>
        <button v-if="phase === 'review'" type="button" :class="btnFilled('primary')" class="whitespace-nowrap"
                :disabled="toUpload.length === 0" data-test="original-pdf-bulk-confirm" @click="upload">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.upload" /></svg>
          {{ t('invoice.original_pdf_bulk.confirm', { n: toUpload.length }) }}
        </button>
      </div>
    </template>
  </Modal>
</template>
