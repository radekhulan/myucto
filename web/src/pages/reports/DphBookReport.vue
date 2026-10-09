<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  reportsApi, KH_EVIDENCE_SECTIONS,
  type DphBookPreview, type DphBookRow, type KhEvidenceReport, type KhEvidenceSection,
} from '@/api/reports'
import { apiErrorMessage } from '@/api/errors'
import { useYearOptions } from '@/composables/useYearOptions'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'
import { useAuthStore } from '@/stores/auth'
import EmptyState from '@/components/ui/EmptyState.vue'
import KhEvidenceSections from '@/components/reports/KhEvidenceSections.vue'

const { t, locale } = useI18n()
const auth = useAuthStore()

const now = new Date()
const year = ref(now.getFullYear())
const month = ref(now.getMonth() + 1)
const periodType = ref<'monthly' | 'quarterly'>('monthly')

const preview = ref<DphBookPreview | null>(null)
const loading = ref(false)
const error = ref('')

// Filtr „Oddíl KH" (issue #142): prázdný = celá Kniha DPH, jinak soupis dokladů
// oddílu sestavený stejnou logikou jako kontrolní hlášení.
const khSection = ref<KhEvidenceSection | ''>('')
const khReport = ref<KhEvidenceReport | null>(null)
const khSectionOptions: KhEvidenceSection[] = ['all', ...KH_EVIDENCE_SECTIONS]

async function loadPreview() {
  loading.value = true
  error.value = ''
  try {
    if (khSection.value === '') {
      khReport.value = null
      preview.value = await reportsApi.dphBookPreview(year.value, month.value, periodType.value)
    } else {
      khReport.value = await reportsApi.khEvidencePreview(year.value, month.value, periodType.value, khSection.value)
    }
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

function downloadPdf() {
  window.open(reportsApi.dphBookPdfUrl(year.value, month.value, periodType.value), '_blank')
}

function downloadKh(format: 'pdf' | 'xlsx') {
  if (khSection.value === '') return
  window.open(reportsApi.khEvidenceDownloadUrl(year.value, month.value, periodType.value, khSection.value, format), '_blank')
}

function khSectionLabel(s: KhEvidenceSection): string {
  if (s === 'all') return t('reports.kh_evidence.filter_all')
  if (s === 'none') return t('reports.kh_evidence.outside')
  return `${s} - ${t(`reports.kh_evidence.section_label.${s.replace('.', '')}`)}`
}

const monthOptions = computed(() =>
  Array.from({ length: 12 }, (_, i) =>
    new Date(2000, i, 1).toLocaleDateString(locale.value === 'en' ? 'en-US' : 'cs-CZ', { month: 'long' })
  )
)
// Distinct roky z dat (issue #33).
const yearOptions = useYearOptions('combined', year)

const quarterOptions = [1, 2, 3, 4]
const isQuarterly = computed(() => periodType.value === 'quarterly')
// Kvartální období: $month nese (jako u DPH přiznání) poslední měsíc kvartálu (3/6/9/12).
const currentQuarter = computed(() => Math.ceil(month.value / 3))
function setQuarter(q: number) {
  month.value = q * 3
}

function fmtMoney(v: number): string {
  return new Intl.NumberFormat(locale.value === 'en' ? 'en-US' : 'cs-CZ', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(Number(v) || 0)
}

function fmtDate(iso: string | null | undefined): string {
  if (!iso) return ''
  const d = new Date(iso)
  if (isNaN(d.getTime())) return ''
  return d.toLocaleDateString(locale.value === 'en' ? 'en-US' : 'cs-CZ')
}

// Období odpočtu (§ 73 ZDPH) — issue #9. Doklad se v Knize objevil podle claim_date,
// ne podle DUZP; bez pojmenovaného důvodu vypadalo zařazení do „cizího" měsíce jako chyba.
function claimBasisLabel(basis: DphBookRow['claim_basis']): string {
  return basis ? t(`reports.dph_book.claim_basis.${basis}`) : ''
}

function claimBasisHint(basis: DphBookRow['claim_basis']): string {
  return basis ? t(`reports.dph_book.claim_hint.${basis}`) : ''
}

// Zvýrazníme jen případ, kdy odpočet posunulo datum přijetí — to je jediná varianta,
// kterou uživatel z dokladu nevyčte a která ho překvapí.
function claimShifted(row: DphBookRow): boolean {
  return row.claim_basis === 'received_at'
}

function isNilRow(row: DphBookRow): boolean {
  return Math.abs(Number(row.base)) < 0.005 && Math.abs(Number(row.vat)) < 0.005
}

const anyClaimShifted = computed(() =>
  (preview.value?.sections ?? []).some(s => s.rows.some(claimShifted))
)

watch([year, month, periodType, khSection], loadPreview)
onMounted(loadPreview)
</script>

<template>
  <div class="max-w-full">
    <!-- Topbar -->
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('reports.dph_book.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('reports.dph_book.subtitle') }}</p>
      </div>
      <div class="flex items-center gap-2 flex-wrap">
        <!-- Period toggle (měsíční / kvartální) -->
        <div class="flex rounded-md border border-neutral-300 overflow-hidden text-sm">
          <button type="button" @click="periodType = 'monthly'"
            :class="periodType === 'monthly' ? 'bg-primary-600 text-white' : 'bg-surface text-neutral-700 hover:bg-neutral-50'"
            class="px-3 h-9 cursor-pointer">
            {{ t('reports.dph.monthly') }}
          </button>
          <button type="button" @click="periodType = 'quarterly'"
            :class="periodType === 'quarterly' ? 'bg-primary-600 text-white' : 'bg-surface text-neutral-700 hover:bg-neutral-50'"
            class="px-3 h-9 cursor-pointer border-l border-neutral-300">
            {{ t('reports.dph.quarterly') }}
          </button>
        </div>
        <!-- Quarter picker pokud quarterly, jinak month -->
        <select v-if="isQuarterly" :value="currentQuarter" @change="setQuarter(Number(($event.target as HTMLSelectElement).value))"
          class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option v-for="q in quarterOptions" :key="q" :value="q">Q{{ q }}</option>
        </select>
        <select v-else v-model.number="month" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option v-for="(label, i) in monthOptions" :key="i + 1" :value="i + 1">{{ label }}</option>
        </select>
        <select v-model.number="year" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
        </select>
        <select v-model="khSection" :aria-label="t('reports.kh_evidence.filter_label')"
          class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm max-w-full">
          <option value="">{{ t('reports.kh_evidence.filter_none') }}</option>
          <option v-for="s in khSectionOptions" :key="s" :value="s">{{ khSectionLabel(s) }}</option>
        </select>
        <template v-if="auth.canRead('reports.export')">
          <button v-if="khSection === ''" type="button" @click="downloadPdf" :disabled="loading || !preview"
            :class="btnOutline('primary')" class="whitespace-nowrap">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
            {{ t('reports.dph_book.download_pdf') }}
          </button>
          <template v-else>
            <button type="button" @click="downloadKh('pdf')" :disabled="loading || !khReport"
              :class="btnOutline('primary')" class="whitespace-nowrap">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
              {{ t('reports.kh_evidence.download_pdf') }}
            </button>
            <button type="button" @click="downloadKh('xlsx')" :disabled="loading || !khReport"
              :class="btnOutline('neutral')" class="whitespace-nowrap">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.table" /></svg>
              {{ t('reports.kh_evidence.download_xlsx') }}
            </button>
          </template>
        </template>
      </div>
    </div>

    <div v-if="loading" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-8 text-center text-neutral-400">
      {{ t('common.loading') }}…
    </div>
    <div v-else-if="error" class="bg-danger-50 border border-danger-500/40 text-danger-500 rounded-md p-3 text-sm">
      {{ error }}
    </div>

    <div v-else-if="khSection !== '' && khReport" class="space-y-4">
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
        <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium mb-1">
          {{ t('reports.kh_evidence.title') }}
        </div>
        <div class="text-lg font-semibold font-mono">{{ khReport.period.label }}</div>
        <p class="text-xs text-neutral-500 mt-2">{{ t('reports.kh_evidence.source_current') }}</p>
        <p class="text-xs text-neutral-500 mt-1">{{ t('reports.kh_evidence.rule_note') }}</p>
      </div>
      <KhEvidenceSections :report="khReport" />
    </div>

    <div v-else-if="khSection === '' && preview" class="space-y-4">
      <!-- Period info -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
        <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium mb-1">
          {{ t('reports.dph_book.period_label') }}
        </div>
        <div class="text-lg font-semibold font-mono">{{ preview.period.label }}</div>
        <!-- issue #9: pravidlo pro zařazení přijatého dokladu do období odpočtu musí být
             vidět v sestavě, ne jen v zákoně — jinak vypadá červnové DUZP v červenci jako chyba. -->
        <p class="text-xs text-neutral-500 mt-2">{{ t('reports.dph_book.claim_period_note') }}</p>
        <p v-if="anyClaimShifted" class="text-xs text-warning-700 mt-1">{{ t('reports.dph_book.claim_shift_legend') }}</p>
      </div>

      <!-- No data -->
      <EmptyState v-if="preview.sections.length === 0" boxed accent="neutral" icon="doc"
        :title="t('reports.dph_book.no_data')" />

      <!-- Sections -->
      <div v-for="section in preview.sections" :key="section.key"
        class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <header class="sticky top-0 px-5 py-3 border-b border-neutral-200 bg-neutral-50">
          <h3 class="text-sm font-semibold text-neutral-800">
            <span class="font-mono">{{ section.key }}</span>
            - {{ section.direction }}:
            <span class="text-neutral-600">{{ section.label }}</span>
          </h3>
        </header>
        <div class="overflow-x-auto">
          <table class="w-full text-xs">
            <thead class="bg-neutral-50 text-neutral-500">
              <tr>
                <th class="px-2 py-2 text-left font-medium whitespace-nowrap">{{ t('reports.dph_book.col.tax_date') }}</th>
                <th class="px-2 py-2 text-left font-medium whitespace-nowrap">{{ t('reports.dph_book.col.accounting_date') }}</th>
                <th class="px-2 py-2 text-left font-medium whitespace-nowrap" :title="t('reports.dph_book.col.claim_date_hint')">
                  {{ t('reports.dph_book.col.claim_date') }}
                </th>
                <th class="px-2 py-2 text-left font-medium whitespace-nowrap">{{ t('reports.dph_book.col.doc_number') }}</th>
                <th class="px-2 py-2 text-left font-medium">{{ t('reports.dph_book.col.description') }}</th>
                <th class="px-2 py-2 text-right font-medium whitespace-nowrap">{{ t('reports.dph_book.col.base_czk') }}</th>
                <th class="px-2 py-2 text-right font-medium whitespace-nowrap">{{ t('reports.dph_book.col.vat_czk') }}</th>
                <th class="px-2 py-2 text-right font-medium whitespace-nowrap">{{ t('reports.dph_book.col.total_czk') }}</th>
                <th class="px-2 py-2 text-left font-medium">{{ t('reports.dph_book.col.partner') }}</th>
                <th class="px-2 py-2 text-left font-medium whitespace-nowrap">{{ t('reports.dph_book.col.partner_dic') }}</th>
                <th class="px-2 py-2 text-left font-medium whitespace-nowrap">{{ t('reports.dph_book.col.original_doc_number') }}</th>
                <th class="px-2 py-2 text-left font-medium whitespace-nowrap">{{ t('reports.dph_book.col.original_tax_date') }}</th>
                <th class="px-2 py-2 text-left font-medium">{{ t('reports.dph_book.col.kh_code') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="(row, idx) in section.rows" :key="idx"
                :class="row.is_draft ? 'bg-neutral-50 text-neutral-500 italic' : (isNilRow(row) ? 'text-neutral-400' : '')">
                <td class="px-2 py-1.5 whitespace-nowrap font-mono">{{ fmtDate(row.tax_date) }}</td>
                <td class="px-2 py-1.5 whitespace-nowrap font-mono">{{ fmtDate(row.accounting_date) }}</td>
                <td class="px-2 py-1.5 whitespace-nowrap" :title="claimBasisHint(row.claim_basis)">
                  <template v-if="row.claim_date">
                    <span class="font-mono" :class="claimShifted(row) ? 'text-warning-700 font-semibold' : ''">
                      {{ fmtDate(row.claim_date) }}<template v-if="claimShifted(row)">&nbsp;*</template>
                    </span>
                    <span v-if="row.claim_basis" class="block text-[10px] text-neutral-500 leading-tight">
                      {{ claimBasisLabel(row.claim_basis) }}
                    </span>
                  </template>
                  <span v-else class="text-neutral-400">—</span>
                </td>
                <td class="px-2 py-1.5 whitespace-nowrap">
                  <span v-if="row.is_draft"
                    class="inline-block bg-warning-100 text-warning-700 text-[10px] font-bold px-1 py-px rounded mr-1">
                    {{ t('reports.dph_book.draft_badge') }}
                  </span>
                  <span class="font-mono">{{ row.direction === 'issued' ? 'VF' : 'PF' }} {{ row.doc_number }}</span>
                </td>
                <td class="px-2 py-1.5">
                  {{ row.description }}
                  <span v-if="isNilRow(row)" class="block text-[10px] text-neutral-500 leading-tight">{{ t('reports.dph_book.nil_row_note') }}</span>
                </td>
                <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap">{{ fmtMoney(row.base) }}</td>
                <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap">{{ fmtMoney(row.vat) }}</td>
                <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap">{{ fmtMoney(row.total) }}</td>
                <td class="px-2 py-1.5">{{ row.counterparty_name }}</td>
                <td class="px-2 py-1.5 font-mono whitespace-nowrap">{{ row.counterparty_dic }}</td>
                <td class="px-2 py-1.5 font-mono whitespace-nowrap">{{ row.original_doc_number || '' }}</td>
                <td class="px-2 py-1.5 font-mono whitespace-nowrap">{{ fmtDate(row.tax_date) }}</td>
                <td class="px-2 py-1.5">
                  <span v-if="!row.kh_section && isNilRow(row)" class="text-[10px] text-neutral-500 whitespace-nowrap"
                    :title="t('reports.dph_book.nil_kh_hint')">{{ t('reports.dph_book.nil_kh') }}</span>
                  <template v-else>{{ row.kh_section || '' }}</template>
                </td>
              </tr>
            </tbody>
            <tfoot class="bg-neutral-50 font-semibold">
              <tr>
                <td colspan="5" class="px-2 py-2 text-xs">
                  {{ t('reports.dph_book.subtotal') }} {{ section.key }}
                </td>
                <td class="px-2 py-2 text-right font-mono whitespace-nowrap">{{ fmtMoney(section.subtotal_base) }}</td>
                <td class="px-2 py-2 text-right font-mono whitespace-nowrap">{{ fmtMoney(section.subtotal_vat) }}</td>
                <td class="px-2 py-2 text-right font-mono whitespace-nowrap">{{ fmtMoney(section.subtotal_total) }}</td>
                <td colspan="5"></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>

      <!-- Total summary — odděleně uskutečněná (daň na výstupu) a přijatá (odpočet) -->
      <div v-if="preview.sections.length > 0" class="grid gap-4 md:grid-cols-2">
        <!-- Uskutečněná plnění -->
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
          <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium mb-3">
            {{ t('reports.dph_book.summary_issued') }}
          </div>
          <div class="grid grid-cols-3 gap-3">
            <div>
              <div class="text-[11px] uppercase text-neutral-400">{{ t('reports.dph_book.col.base_czk') }}</div>
              <div class="text-base font-bold font-mono">{{ fmtMoney(preview.totals.issued.base) }}</div>
            </div>
            <div>
              <div class="text-[11px] uppercase text-neutral-400">{{ t('reports.dph_book.col.vat_czk') }}</div>
              <div class="text-base font-bold font-mono">{{ fmtMoney(preview.totals.issued.vat) }}</div>
            </div>
            <div>
              <div class="text-[11px] uppercase text-neutral-400">{{ t('reports.dph_book.col.total_czk') }}</div>
              <div class="text-base font-bold font-mono">{{ fmtMoney(preview.totals.issued.total) }}</div>
            </div>
          </div>
        </div>
        <!-- Přijatá plnění -->
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
          <div class="text-xs uppercase tracking-wide text-neutral-500 font-medium mb-3">
            {{ t('reports.dph_book.summary_received') }}
          </div>
          <div class="grid grid-cols-3 gap-3">
            <div>
              <div class="text-[11px] uppercase text-neutral-400">{{ t('reports.dph_book.col.base_czk') }}</div>
              <div class="text-base font-bold font-mono">{{ fmtMoney(preview.totals.received.base) }}</div>
            </div>
            <div>
              <div class="text-[11px] uppercase text-neutral-400">{{ t('reports.dph_book.col.vat_czk') }}</div>
              <div class="text-base font-bold font-mono">{{ fmtMoney(preview.totals.received.vat) }}</div>
            </div>
            <div>
              <div class="text-[11px] uppercase text-neutral-400">{{ t('reports.dph_book.col.total_czk') }}</div>
              <div class="text-base font-bold font-mono">{{ fmtMoney(preview.totals.received.total) }}</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Výsledná DPH = na výstupu − odpočet (kladná = povinnost, záporná = nadměrný odpočet) -->
      <div v-if="preview.sections.length > 0"
        class="border rounded-lg p-4 flex items-center justify-between"
        :class="preview.totals.vat_balance >= 0
          ? 'bg-primary-50 border-primary-200'
          : 'bg-success-50 border-success-200'">
        <div>
          <div class="text-xs uppercase tracking-wide font-medium"
            :class="preview.totals.vat_balance >= 0 ? 'text-primary-700' : 'text-success-700'">
            {{ t('reports.dph_book.vat_balance') }}
          </div>
          <div class="text-sm text-neutral-600 mt-0.5">
            {{ preview.totals.vat_balance >= 0
              ? t('reports.dph_book.vat_balance_due')
              : t('reports.dph_book.vat_balance_refund') }}
          </div>
        </div>
        <div class="text-2xl font-bold font-mono"
          :class="preview.totals.vat_balance >= 0 ? 'text-primary-700' : 'text-success-700'">
          {{ fmtMoney(Math.abs(preview.totals.vat_balance)) }}
        </div>
      </div>

      <p class="text-xs text-neutral-400 italic text-center">{{ t('reports.dph_book.note_d_marker') }}</p>
    </div>
  </div>
</template>
