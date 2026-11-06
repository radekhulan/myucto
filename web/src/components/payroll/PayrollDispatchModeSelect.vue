<script setup lang="ts">
/**
 * Volba kanálu, kudy mzdové podání odejde (VREP nebo datová schránka).
 *
 * Sdílí ji fronta „K odeslání" i měsíční přehled, ať se obě obrazovky
 * neliší v tom, co nabízejí. Nabídka jsou jen připravené kanály ze serveru
 * (`PayrollDispatchGate::channels()`): VREP jen se zvoleným podpisovým
 * certifikátem. Když primární kanál připravený není a podání tak odejde
 * alternativním, řekne to věta místo výběru.
 */
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { PayrollSubmissionDispatchMode } from '@/api/payroll'

const props = defineProps<{
  modes: PayrollSubmissionDispatchMode[]
  /** Primární kanál agendy z katalogu. */
  primaryMode: PayrollSubmissionDispatchMode | null
  disabled?: boolean
}>()
const model = defineModel<PayrollSubmissionDispatchMode | null>({ required: true })
const { t } = useI18n()

const fallback = computed(() =>
  props.modes.length === 1
  && props.primaryMode !== null
  && props.modes[0] !== props.primaryMode
  && isVrep(props.primaryMode),
)

function isVrep(mode: PayrollSubmissionDispatchMode): boolean {
  return mode === 'vrep_jmhz' || mode === 'vrep_registration' || mode === 'vrep_cssz_form'
}

function label(mode: PayrollSubmissionDispatchMode): string {
  return isVrep(mode) ? t('payroll.dispatch_mode.vrep') : t('payroll.dispatch_mode.isds')
}
</script>

<template>
  <label v-if="modes.length > 1" class="inline-flex flex-wrap items-center gap-2 text-xs text-neutral-600">
    <span class="whitespace-nowrap">{{ t('payroll.dispatch_mode.label') }}</span>
    <select
      v-model="model"
      class="rounded border-neutral-300 py-1 text-xs"
      :disabled="disabled"
      data-test="dispatch-mode-select"
    >
      <option v-for="mode in modes" :key="mode" :value="mode">{{ label(mode) }}</option>
    </select>
  </label>
  <p v-else-if="fallback" class="text-xs text-neutral-600" data-test="dispatch-mode-fallback">
    {{ t('payroll.dispatch_mode.fallback') }}
  </p>
</template>
