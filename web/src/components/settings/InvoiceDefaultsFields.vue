<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { InvoiceDefaultsSettings } from '@/api/settings'
import { DEFAULT_NOTE_MAX_LENGTH } from '@/pages/invoices/invoiceDefaultNote'
const props = defineProps<{ modelValue: InvoiceDefaultsSettings }>()
const { t } = useI18n()
// Výchozí splatnost — UI preset ('7' / '14' / 'month' / 'custom') je odvozen z dvojice
// (default_payment_due_days, default_payment_due_unit). 'month' znamená přesně 1 kalendářní
// měsíc (days=1, unit='month'); 'custom' nechá volný číselný input v dnech.
type DuePreset = '7' | '14' | 'month' | 'custom'
// 'custom' musí být „sticky" i když hodnota náhodou odpovídá presetu (7/14) — jinak
// by getter spadl zpět na preset a číselný input by se nikdy neukázal.
const dueCustom = ref(false)
const dueSelectValue = computed<DuePreset>({
  get() {
    if (!props.modelValue) return '7'
    if (dueCustom.value) return 'custom'
    const d = props.modelValue.default_payment_due_days
    const u = props.modelValue.default_payment_due_unit
    if (u === 'month' && d === 1) return 'month'
    if (u === 'days' && d === 7)  return '7'
    if (u === 'days' && d === 14) return '14'
    return 'custom'
  },
  set(v: DuePreset) {
    if (!props.modelValue) return
    dueCustom.value = (v === 'custom')
    if (v === '7') {
      props.modelValue.default_payment_due_days = 7
      props.modelValue.default_payment_due_unit = 'days'
    } else if (v === '14') {
      props.modelValue.default_payment_due_days = 14
      props.modelValue.default_payment_due_unit = 'days'
    } else if (v === 'month') {
      props.modelValue.default_payment_due_days = 1
      props.modelValue.default_payment_due_unit = 'month'
    } else {
      props.modelValue.default_payment_due_unit = 'days'
      // days zachovat — pokud byl 7/14 user dostane editovatelnou hodnotu k úpravě
    }
  },
})

</script>

<template>
  <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.default_due_label') }}</label>
            <div class="flex flex-wrap gap-2">
              <select v-model="dueSelectValue" data-test="invoice-default-due-preset" class="h-10 px-2 border border-neutral-300 rounded-md text-sm bg-surface" :class="dueSelectValue === 'custom' ? 'w-40' : 'w-full'">
                <option value="7">{{ t('settings.default_due_preset_7') }}</option>
                <option value="14">{{ t('settings.default_due_preset_14') }}</option>
                <option value="month">{{ t('settings.default_due_preset_month') }}</option>
                <option value="custom">{{ t('settings.default_due_preset_custom') }}</option>
              </select>
              <div v-if="dueSelectValue === 'custom'" class="flex items-center gap-2 flex-1">
                <input v-model.number="modelValue.default_payment_due_days" type="number" min="0" max="4294967295" data-test="invoice-default-due-days" class="w-24 h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
                <span class="text-sm text-neutral-500">{{ t(modelValue.default_payment_due_unit === 'month' ? 'settings.default_due_custom_months_suffix' : 'settings.default_due_custom_days_suffix') }}</span>
              </div>
            </div>
            <p v-if="dueSelectValue === 'month'" class="text-xs text-neutral-500 mt-1">{{ t('settings.default_due_month_hint') }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.default_hourly_rate') }} ({{ modelValue.default_currency }})</label>
            <input v-model.number="modelValue.default_hourly_rate" type="number" step="0.000001" min="0" max="99999999.999999" data-test="invoice-default-hourly-rate" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
          </div>
          <div class="md:col-span-2 border-t border-neutral-200 pt-4 space-y-3">
            <div>
              <h3 class="text-sm font-semibold text-neutral-700">{{ t('invoice_default_note.title') }}</h3>
              <p class="text-xs text-neutral-500 mt-1">{{ t('invoice_default_note.hint') }}</p>
            </div>
            <label class="flex items-start gap-2 text-sm">
              <input v-model="modelValue.default_note_below_items_enabled" data-test="invoice-default-note-enabled" type="checkbox" class="mt-0.5 rounded border-neutral-300 text-primary-600" />
              <span>
                <span class="font-medium text-neutral-700">{{ t('invoice_default_note.enabled') }}</span>
                <span class="block text-xs text-neutral-500 mt-0.5">{{ t('invoice_default_note.enabled_hint') }}</span>
              </span>
            </label>
            <div v-if="modelValue.default_note_below_items_enabled" class="ml-6 grid grid-cols-1 md:grid-cols-2 gap-3">
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('invoice_default_note.text_cs') }}</label>
                <textarea v-model="modelValue.default_note_below_items_cs" data-test="invoice-default-note-cs" rows="3" :maxlength="DEFAULT_NOTE_MAX_LENGTH"
                          :placeholder="t('invoice_default_note.placeholder')"
                          class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm resize-y"></textarea>
              </div>
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('invoice_default_note.text_en') }}</label>
                <textarea v-model="modelValue.default_note_below_items_en" data-test="invoice-default-note-en" rows="3" :maxlength="DEFAULT_NOTE_MAX_LENGTH"
                          class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm resize-y"></textarea>
              </div>
              <p class="md:col-span-2 text-xs text-neutral-500">{{ t('invoice_default_note.language_hint') }}</p>
            </div>
          </div>
  </div>
</template>
