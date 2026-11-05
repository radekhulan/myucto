<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import WorkReportDisplaySettings from '@/components/invoices/WorkReportDisplaySettings.vue'
import type { Supplier } from '@/api/settings'

type PdfSettings = Pick<Supplier, 'invoice_pdf_monochrome' | 'invoice_pdf_show_issued_by' | 'invoice_pdf_show_line_discount' | 'invoice_pdf_compact_items' | 'invoice_pdf_bank_in_footer' | 'invoice_pdf_show_base_qty' | 'work_report_display_options'>
const props = defineProps<{ modelValue: PdfSettings }>()
const emit = defineEmits<{ change: [patch: Partial<PdfSettings>] }>()
const { t } = useI18n()
const options = [
  { key: 'invoice_pdf_monochrome', label: 'settings.branding_pdf_monochrome', hint: 'settings.branding_pdf_monochrome_hint', test: 'branding-invoice-pdf-monochrome', fallback: false },
  { key: 'invoice_pdf_show_issued_by', label: 'settings.invoice_pdf_show_issued_by', hint: 'settings.invoice_pdf_show_issued_by_hint', test: 'invoice-pdf-show-issued-by', fallback: false },
  { key: 'invoice_pdf_show_line_discount', label: 'settings.invoice_pdf_line_discount', hint: 'settings.invoice_pdf_line_discount_hint', test: 'invoice-pdf-line-discount', fallback: false },
  { key: 'invoice_pdf_compact_items', label: 'settings.invoice_pdf_compact_items', hint: 'settings.invoice_pdf_compact_items_hint', test: 'invoice-pdf-compact-items', fallback: false },
  { key: 'invoice_pdf_show_base_qty', label: 'stock.settings.pdf_base_qty_label', hint: 'stock.settings.pdf_base_qty_hint', test: 'invoice-pdf-base-qty', fallback: true },
] as const
// Nestandardní umístění bankovního spojení patří až na konec, za běžné volby vzhledu.
const trailingOptions = [
  { key: 'invoice_pdf_bank_in_footer', label: 'settings.invoice_pdf_bank_in_footer', hint: 'settings.invoice_pdf_bank_in_footer_hint', test: 'invoice-pdf-bank-in-footer', fallback: false },
] as const
</script>

<template>
  <div class="space-y-4">
    <h3 class="text-sm font-semibold text-neutral-700">{{ t('settings.pdf_appearance_title') }}</h3>
    <div v-for="option in options" :key="option.key">
      <label class="flex items-center gap-2 text-sm">
        <input type="checkbox" :checked="props.modelValue[option.key] ?? option.fallback"
          :data-test="option.test" :data-testid="option.test" class="rounded border-neutral-300 text-primary-600"
          @change="emit('change', { [option.key]: ($event.target as HTMLInputElement).checked })" />
        <span class="font-medium">{{ t(option.label) }}</span>
      </label>
      <p class="text-xs text-neutral-500 mt-1 ml-6">{{ t(option.hint) }}</p>
    </div>
    <WorkReportDisplaySettings :model-value="modelValue.work_report_display_options"
      @update:model-value="emit('change', { work_report_display_options: $event })" />
    <div v-for="option in trailingOptions" :key="option.key">
      <label class="flex items-center gap-2 text-sm">
        <input type="checkbox" :checked="props.modelValue[option.key] ?? option.fallback"
          :data-test="option.test" :data-testid="option.test" class="rounded border-neutral-300 text-primary-600"
          @change="emit('change', { [option.key]: ($event.target as HTMLInputElement).checked })" />
        <span class="font-medium">{{ t(option.label) }}</span>
      </label>
      <p class="text-xs text-neutral-500 mt-1 ml-6">{{ t(option.hint) }}</p>
    </div>
  </div>
</template>
