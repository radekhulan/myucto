<script setup lang="ts">
import { requestSupplierId } from '@/workspace/supplierContext'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useYearOptions } from '@/composables/useYearOptions'
import { btnOutline, ICONS } from '@/components/ui/buttonStyles'
import PohodaSchedulePanel from './PohodaSchedulePanel.vue'

const { t, tm, rt } = useI18n()

type Format = 'pdf-zip' | 'isdoc' | 'pohoda' | 'stereo' | 'money_s3' | 'csv'
type PeriodType = 'monthly' | 'quarterly'

// Default = předchozí měsíc: export se dělá po uzávěrce právě skončeného měsíce,
// ne rozpracovaného aktuálního (getMonth()-1 = -1 normalizuje na prosinec loni).
const now = new Date()
const prev = new Date(now.getFullYear(), now.getMonth() - 1, 1)
const defYear = prev.getFullYear()
const defMonth = prev.getMonth() + 1

const format = ref<Format>('pdf-zip')
const periodType = ref<PeriodType>('monthly')
const month = ref(`${defYear}-${String(defMonth).padStart(2, '0')}`) // YYYY-MM
const year = ref(defYear)
const quarter = ref(Math.ceil(defMonth / 3))
const type = ref<'' | 'invoice' | 'proforma' | 'credit_note'>('')
const dateBy = ref<'issue' | 'tax'>('issue')
const mergePdf = ref(false)
const signPdf = ref(false)
const downloading = ref(false)
const error = ref('')
const yearOptions = useYearOptions('invoices', year)
const quarterOptions = [1, 2, 3, 4]

const monthParts = computed(() => {
  const [y, m] = month.value.split('-').map(Number)
  return {
    year: Number.isFinite(y) ? y : defYear,
    month: Number.isFinite(m) ? m : defMonth,
  }
})

const periodFileLabel = computed(() => {
  if (periodType.value === 'quarterly') return `${year.value}-Q${quarter.value}`
  return `${monthParts.value.year}-${String(monthParts.value.month).padStart(2, '0')}`
})

const selectedPeriodLabel = computed(() => {
  if (periodType.value === 'quarterly') return `Q${quarter.value} ${year.value}`
  return monthLabel(periodFileLabel.value)
})

watch(format, value => {
  if (value !== 'pdf-zip') {
    mergePdf.value = false
    signPdf.value = false
  }
})
watch(mergePdf, value => {
  if (!value) signPdf.value = false
})

async function downloadExport() {
  downloading.value = true
  error.value = ''
  try {
    const params = new URLSearchParams({
      format: format.value,
      date_by: dateBy.value,
      period: periodType.value,
    })
    if (periodType.value === 'quarterly') {
      params.set('year', String(year.value))
      params.set('quarter', String(quarter.value))
    } else {
      params.set('year', String(monthParts.value.year))
      params.set('month', String(monthParts.value.month))
    }
    if (type.value) params.set('type', type.value)
    if (format.value === 'pdf-zip' && mergePdf.value) params.set('merge_pdf', '1')
    if (format.value === 'pdf-zip' && mergePdf.value && signPdf.value) params.set('sign_pdf', '1')
    const url = `/api/admin/export?${params.toString()}`

    // fetch + Blob → trigger download. Posíláme X-Supplier-Id explicitně (není to axios).
    const sid = requestSupplierId() || ''
    const headers: Record<string, string> = {}
    if (/^\d+$/.test(sid)) headers['X-Supplier-Id'] = sid
    const resp = await fetch(url, { credentials: 'include', headers })
    if (!resp.ok) {
      const j = await resp.json().catch(() => null)
      error.value = j?.error?.message || `HTTP ${resp.status}`
      return
    }
    const blob = await resp.blob()
    const dispo = resp.headers.get('Content-Disposition') || ''
    const m = dispo.match(/filename="?([^"]+)"?/)
    const ext = ['pohoda', 'stereo', 'money_s3'].includes(format.value) ? 'xml' : (format.value === 'isdoc' ? 'isdoc' : (format.value === 'csv' ? 'csv' : (mergePdf.value ? 'pdf' : 'zip')))
    const filename = m ? m[1] : `myucto-${periodFileLabel.value}.${ext}`

    const a = document.createElement('a')
    a.href = URL.createObjectURL(blob)
    a.download = filename
    document.body.appendChild(a)
    a.click()
    a.remove()
    URL.revokeObjectURL(a.href)
  } catch (e: any) {
    error.value = e?.message || t('export.download_failed')
  } finally {
    downloading.value = false
  }
}

const monthLabel = (m: string): string => {
  const [y, mm] = m.split('-')
  const months = tm('common.months_long') as string[]
  const label = months[Number(mm) - 1]
  return `${label ? rt(label) : mm} ${y}`
}
</script>

<template>
  <div class="max-w-5xl space-y-4">
    <p class="text-sm text-neutral-500">{{ t('export.subtitle') }}</p>

    <!-- Box: nastavení exportu -->
    <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm space-y-4">
      <!-- Formát výběr -->
      <div>
        <label class="block text-sm font-medium text-neutral-700 mb-2">{{ t('export.format') }}</label>
        <div class="grid grid-cols-1 sm:grid-cols-4 gap-2">
          <label
            v-for="opt in [
              { val: 'pdf-zip' as Format, label: t('export.format_pdf_zip'), hint: t('export.format_pdf_zip_hint') },
              { val: 'isdoc' as Format,   label: t('export.format_isdoc'),   hint: t('export.format_isdoc_hint') },
              { val: 'pohoda' as Format,  label: t('export.format_pohoda'),  hint: t('export.format_pohoda_hint') },
              { val: 'stereo' as Format,  label: t('export.format_stereo'),  hint: t('export.format_stereo_hint') },
              { val: 'money_s3' as Format, label: t('export.format_money_s3'), hint: t('export.format_money_s3_hint') },
              { val: 'csv' as Format,     label: t('export.format_csv'),     hint: t('export.format_csv_hint') },
            ]"
            :key="opt.val"
            class="cursor-pointer block p-3 border rounded-md transition"
            :class="format === opt.val
              ? 'border-primary-500 bg-primary-50 ring-2 ring-primary-500/20'
              : 'border-neutral-200 hover:border-neutral-300 hover:bg-neutral-50'"
          >
            <input type="radio" :value="opt.val" v-model="format" class="sr-only" />
            <div class="flex items-center gap-2">
              <svg v-if="opt.val === 'pdf-zip'" class="w-6 h-7" viewBox="0 0 32 36" xmlns="http://www.w3.org/2000/svg">
                <path fill="#dc2626" d="M4 2h16l8 8v22a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z"/>
                <path fill="#ffffff" opacity="0.35" d="M20 2v8h8z"/>
                <text x="16" y="26" fill="#ffffff" font-family="Arial,Helvetica,sans-serif" font-size="8" font-weight="700" text-anchor="middle" letter-spacing="0.3">PDF</text>
              </svg>
              <svg v-else-if="opt.val === 'pohoda' || opt.val === 'stereo' || opt.val === 'money_s3'" class="w-5 h-5 text-warning-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414"/></svg>
              <svg v-else-if="opt.val === 'csv'" class="w-5 h-5 text-success-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M3 14h18M9 6v12m6-12v12M5 6h14a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2z"/></svg>
              <svg v-else class="w-5 h-5 text-primary-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-6a2 2 0 0 1 2-2"/></svg>
              <span class="text-sm font-medium">{{ opt.label }}</span>
            </div>
            <p class="text-xs text-neutral-500 mt-1">{{ opt.hint }}</p>
          </label>
        </div>
      </div>

      <div v-if="format === 'pdf-zip'" class="rounded-md border border-neutral-200 bg-neutral-50 p-3 space-y-3">
        <label class="flex items-start gap-3 cursor-pointer">
          <input v-model="mergePdf" type="checkbox"
            class="mt-0.5 w-4 h-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
          <span>
            <span class="block text-sm font-medium text-neutral-800">{{ t('export.merge_pdf') }}</span>
            <span class="block text-xs text-neutral-500 mt-0.5">{{ t('export.merge_pdf_hint') }}</span>
          </span>
        </label>
        <label v-if="mergePdf" class="flex items-start gap-3 cursor-pointer border-t border-neutral-200 pt-3">
          <input v-model="signPdf" type="checkbox"
            class="mt-0.5 w-4 h-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" />
          <span>
            <span class="block text-sm font-medium text-neutral-800">{{ t('export.sign_merged_pdf') }}</span>
            <span class="block text-xs text-neutral-500 mt-0.5">{{ t('export.sign_merged_pdf_hint') }}</span>
          </span>
        </label>
      </div>

      <!-- Období -->
      <div>
        <label class="block text-sm font-medium text-neutral-700 mb-2">{{ t('export.period') }}</label>
        <div class="flex rounded-md border border-neutral-300 overflow-hidden text-sm">
          <button type="button" @click="periodType = 'monthly'"
            :class="periodType === 'monthly' ? 'bg-primary-600 text-white' : 'bg-surface text-neutral-700 hover:bg-neutral-50'"
            class="cursor-pointer flex-1 h-10 px-3">
            {{ t('export.period_month') }}
          </button>
          <button type="button" @click="periodType = 'quarterly'"
            :class="periodType === 'quarterly' ? 'bg-primary-600 text-white' : 'bg-surface text-neutral-700 hover:bg-neutral-50'"
            class="cursor-pointer flex-1 h-10 px-3 border-l border-neutral-300">
            {{ t('export.period_quarter') }}
          </button>
        </div>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div v-if="periodType === 'monthly'">
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('export.month') }}</label>
          <input v-model="month" type="month" required
                 class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm font-mono" />
          <p class="text-xs text-neutral-500 mt-1">{{ selectedPeriodLabel }}</p>
        </div>
        <div v-else class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('export.quarter') }}</label>
            <select v-model.number="quarter" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
              <option v-for="q in quarterOptions" :key="q" :value="q">Q{{ q }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('export.year') }}</label>
            <select v-model.number="year" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
              <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
            </select>
          </div>
          <p class="col-span-2 text-xs text-neutral-500">{{ selectedPeriodLabel }}</p>
        </div>
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('export.filter_by') }}</label>
          <select v-model="dateBy" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
            <option value="issue">{{ t('export.by_issue') }}</option>
            <option value="tax">{{ t('export.by_tax') }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('export.type_optional') }}</label>
          <select v-model="type" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
            <option value="">{{ t('export.type_all') }}</option>
            <option value="invoice">{{ t('export.type_invoice_only') }}</option>
            <option value="proforma">{{ t('export.type_proforma_only') }}</option>
            <option value="credit_note">{{ t('export.type_credit_only') }}</option>
          </select>
        </div>
      </div>

      <!-- Info -->
      <div v-if="format === 'csv'" class="rounded-md bg-primary-50 border border-primary-200 px-3 py-2 text-sm text-primary-700">
        <strong>{{ t('export.format_csv') }}:</strong>
        {{ t('export.csv_info_hint') }}
      </div>
      <div v-else class="rounded-md bg-primary-50 border border-primary-200 px-3 py-2 text-sm text-primary-700">
        {{ t('export.hint') }}
      </div>

      <div v-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
        {{ error }}
      </div>

      <div class="flex items-center justify-end gap-2 pt-2">
        <button
          type="button"
          @click="downloadExport"
          :disabled="downloading"
          :class="btnOutline('primary')"
        >
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download"/></svg>
          {{ downloading ? t('export.preparing') : t('export.download') }}
        </button>
      </div>
    </div>

    <PohodaSchedulePanel />
  </div>
</template>
