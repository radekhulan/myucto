<script setup lang="ts">
/**
 * Přepnutí měsíce z importu docházky na směny jedním krokem.
 *
 * „Počítat ze směn" zruší neschválené importované vstupy vztahu za měsíc
 * a zdroj přepne; schválené nebo zamčené vstupy server nezmění a vrátí důvod.
 * „Zapisovat směny, částky ponechat" (`keepImportInputs`) zdroj přepne a vstupy
 * nechá: smí jen vztah, u kterého se mzda z hodin nepočítá.
 *
 * Má-li měsíc publikovaný rozpis směn, dialog nabídne jeho převzetí jako
 * odpracovaného času. Zapnuté je předem, když měsíc nemá žádný časový záznam.
 */
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { payrollApi } from '@/api/payroll'
import { apiErrorMessage } from '@/api/errors'
import Modal from '@/components/ui/Modal.vue'
import { btnFilled, btnOutline, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import { useToast } from '@/composables/useToast'

const props = defineProps<{
  employmentId: number
  period: string
  monthRowVersion: number
  name?: string | null
  keepImportInputs?: boolean
  publishedShiftCount?: number
  entryCount?: number
}>()
const emit = defineEmits<{ switched: [] }>()
const { t } = useI18n()
const toast = useToast()

const open = ref(false)
const busy = ref(false)
const error = ref('')
const takeOver = ref(false)
const shiftCount = computed(() => props.publishedShiftCount ?? 0)
const texts = computed(() => props.keepImportInputs
  ? {
      action: t('payroll.worked_hours.switch_to_entries.keep.action'),
      title: t('payroll.worked_hours.switch_to_entries.keep.title'),
      text: t('payroll.worked_hours.switch_to_entries.keep.text', { name: props.name ?? '' }),
      confirm: t('payroll.worked_hours.switch_to_entries.keep.confirm'),
    }
  : {
      action: t('payroll.worked_hours.switch_to_entries.action'),
      title: t('payroll.worked_hours.switch_to_entries.title'),
      text: t('payroll.worked_hours.switch_to_entries.text', { name: props.name ?? '' }),
      confirm: t('payroll.worked_hours.switch_to_entries.confirm'),
    })

function start(): void {
  error.value = ''
  takeOver.value = shiftCount.value > 0 && (props.entryCount ?? 0) === 0
  open.value = true
}

async function confirm(): Promise<void> {
  if (busy.value) return
  busy.value = true
  error.value = ''
  try {
    const result = await payrollApi.switchTimeToEntries(props.period, {
      employment_id: props.employmentId,
      row_version: props.monthRowVersion,
      ...(props.keepImportInputs ? { keep_import_inputs: true } : {}),
      ...(takeOver.value && shiftCount.value > 0 ? { take_over_shifts: true } : {}),
    })
    open.value = false
    const done = props.keepImportInputs
      ? t('payroll.worked_hours.switch_to_entries.keep.done')
      : t('payroll.worked_hours.switch_to_entries.done', { count: result.cancelled_input_ids.length })
    const taken = (result.created_entry_ids ?? []).length
    toast.success(taken > 0 ? `${done} ${t('payroll.worked_hours.switch_to_entries.taken_over', { count: taken })}` : done)
    emit('switched')
  } catch (failure) {
    error.value = apiErrorMessage(failure, t('payroll.worked_hours.switch_to_entries.failed'))
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <button type="button" :class="[btnOutlineSm('warning'), 'whitespace-nowrap']" :disabled="busy" :data-test="keepImportInputs ? 'switch-to-entries-keep' : 'switch-to-entries'" @click="start">
    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="keepImportInputs ? ICONS.calendar : ICONS.uturn" /></svg>
    {{ texts.action }}
  </button>
  <Modal
    v-if="open"
    :title="texts.title"
    width-class="max-w-md"
    @close="open = false"
  >
    <p class="text-sm text-neutral-700" data-test="switch-to-entries-text">
      {{ texts.text }}
    </p>
    <label v-if="shiftCount > 0" class="mt-3 flex items-start gap-2 text-sm text-neutral-700">
      <input v-model="takeOver" type="checkbox" class="mt-0.5 rounded border-neutral-300 text-primary-600" :disabled="busy" data-test="switch-to-entries-take-over">
      <span>
        <span class="block font-medium">{{ t('payroll.worked_hours.switch_to_entries.take_over') }}</span>
        <span class="block text-xs text-neutral-500">{{ t('payroll.worked_hours.switch_to_entries.take_over_hint', { count: shiftCount }) }}</span>
      </span>
    </label>
    <p
      v-if="error"
      class="mt-3 rounded-md border border-danger-200 bg-danger-50 px-3 py-2 text-xs text-danger-700"
      role="alert"
      data-test="switch-to-entries-error"
    >{{ error }}</p>
    <div class="mt-4 flex flex-wrap justify-end gap-2">
      <button type="button" :class="[btnOutline('neutral'), 'whitespace-nowrap']" :disabled="busy" @click="open = false">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
        {{ t('common.cancel') }}
      </button>
      <button type="button" :class="[btnFilled('warning'), 'whitespace-nowrap']" :disabled="busy" data-test="switch-to-entries-confirm" @click="confirm">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
        {{ busy ? t('common.saving') : texts.confirm }}
      </button>
    </div>
  </Modal>
</template>
