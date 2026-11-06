<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { payrollImportsApi } from '@/api/payrollImports'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { btnOutline, ICONS } from '@/components/ui/buttonStyles'
import type { AttendanceSampleKey } from '@/api/payrollImports'
import { ATTENDANCE_PAMICA_TEMPLATE_FILENAME, ATTENDANCE_TEMPLATE_FILENAME } from './importHelpers'

const props = withDefaults(defineProps<{ disabled?: boolean; sampleKey?: AttendanceSampleKey; period?: string }>(), {
  sampleKey: 'myucto',
  period: undefined,
})

const { t } = useI18n()
const toast = useToast()
const busy = ref(false)

const pamica = computed(() => props.sampleKey === 'pamica')

// Šablona XLSX pro Vzor MyÚčto (z týchž sloupců jako pravidla vzoru), u Vzoru PAMICA ukázkový XML export.
async function download() {
  if (busy.value) return
  busy.value = true
  try {
    await payrollImportsApi.downloadAttendanceTemplate(
      pamica.value ? ATTENDANCE_PAMICA_TEMPLATE_FILENAME : ATTENDANCE_TEMPLATE_FILENAME,
      props.sampleKey,
      props.period || undefined,
    )
  } catch (err) {
    toast.error(apiErrorMessage(err, t('payroll_imports.template.failed')))
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <button type="button" data-testid="attendance-template-download" class="whitespace-nowrap" :class="btnOutline('neutral')"
    :disabled="disabled || busy" :title="t(pamica ? 'payroll_imports.template.pamica_hint' : 'payroll_imports.template.hint')" @click="download">
    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.download" /></svg>
    {{ busy ? t('payroll_imports.common.working') : t(pamica ? 'payroll_imports.template.pamica_download' : 'payroll_imports.template.download') }}
  </button>
</template>
