<script setup lang="ts">
/**
 * „Počítat ze směn" — přepnutí měsíce z importu docházky na směny jedním krokem.
 *
 * Zápis směny do měsíce s importovaným souhrnem hodin server odmítne, a změna
 * zdroje v Podkladech hodin spadla na nezrušených mzdových vstupech z importu,
 * které musela účetní hledat v Mzdových vstupech. Tlačítko po potvrzení
 * v dialogu zruší neschválené importované vstupy vztahu za měsíc a zdroj
 * přepne. Schválené nebo zamčené vstupy server nezmění a vrátí důvod.
 */
import { ref } from 'vue'
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
}>()
const emit = defineEmits<{ switched: [] }>()
const { t } = useI18n()
const toast = useToast()

const open = ref(false)
const busy = ref(false)
const error = ref('')

function start(): void {
  error.value = ''
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
    })
    open.value = false
    toast.success(t('payroll.worked_hours.switch_to_entries.done', { count: result.cancelled_input_ids.length }))
    emit('switched')
  } catch (failure) {
    error.value = apiErrorMessage(failure, t('payroll.worked_hours.switch_to_entries.failed'))
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <button type="button" :class="[btnOutlineSm('warning'), 'whitespace-nowrap']" :disabled="busy" data-test="switch-to-entries" @click="start">
    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.uturn" /></svg>
    {{ t('payroll.worked_hours.switch_to_entries.action') }}
  </button>
  <Modal
    v-if="open"
    :title="t('payroll.worked_hours.switch_to_entries.title')"
    width-class="max-w-md"
    @close="open = false"
  >
    <p class="text-sm text-neutral-700" data-test="switch-to-entries-text">
      {{ t('payroll.worked_hours.switch_to_entries.text', { name: name ?? '' }) }}
    </p>
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
        {{ busy ? t('common.saving') : t('payroll.worked_hours.switch_to_entries.confirm') }}
      </button>
    </div>
  </Modal>
</template>
