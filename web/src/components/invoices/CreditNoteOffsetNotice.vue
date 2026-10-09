<script setup lang="ts">
/**
 * Zápočet dobropisu proti opravované faktuře (issue #140) — sdílený pro vydané
 * i přijaté doklady. Na dobropisu ukazuje, s čím je započtený (nebo nabídne
 * zápočet), na faktuře, kterými dobropisy se jí snížilo „Zbývá uhradit".
 * Tlačítka drží koncept ActionBar: ikona + sémantická barva.
 */
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { creditNoteOffsetsApi, type CreditNoteOffsetDocType, type CreditNoteOffsetState } from '@/api/creditNoteOffsets'
import { apiErrorMessage } from '@/api/errors'
import { formatMoney } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'
import { btnFilledSm, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'

const props = defineProps<{
  docType: CreditNoteOffsetDocType
  docId: number
  isCreditNote: boolean
  currency: string
  canWrite: boolean
}>()
const emit = defineEmits<{ changed: [] }>()

const { t } = useI18n()
const toast = useToast()
const state = ref<CreditNoteOffsetState | null>(null)
const busy = ref(false)

async function load() {
  try {
    state.value = await creditNoteOffsetsApi.get(props.docType, props.docId)
  } catch {
    state.value = null
  }
}
watch(() => [props.docType, props.docId], load, { immediate: true })

const detailPath = (id: number) => props.docType === 'invoice' ? `/invoices/${id}` : `/purchase-invoices/${id}`
const ownOffset = computed(() => state.value?.offsets.find(o => o.credit_note_id === props.docId) ?? null)
const invoiceOffsets = computed(() => state.value?.offsets.filter(o => o.invoice_id === props.docId) ?? [])
const canApply = computed(() => props.isCreditNote && props.canWrite && !!state.value?.can_offset)

async function apply() {
  busy.value = true
  try {
    state.value = await creditNoteOffsetsApi.apply(props.docType, props.docId)
    toast.success(t('credit_note_offset.applied'))
    emit('changed')
  } catch (e) {
    toast.error(apiErrorMessage(e, t('credit_note_offset.failed')))
  } finally {
    busy.value = false
  }
}

async function revert() {
  if (!window.confirm(t('credit_note_offset.revert_confirm'))) return
  busy.value = true
  try {
    state.value = await creditNoteOffsetsApi.revert(props.docType, props.docId)
    toast.success(t('credit_note_offset.reverted'))
    emit('changed')
  } catch (e) {
    toast.error(apiErrorMessage(e, t('credit_note_offset.failed')))
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div v-if="ownOffset || invoiceOffsets.length > 0 || canApply"
    data-test="credit-note-offset"
    class="flex flex-wrap items-center justify-between gap-3 bg-success-50 border border-success-500/30 rounded-lg px-4 py-2.5 text-sm mb-4">
    <div class="min-w-0 text-success-700 dark:text-success-300">
      <template v-if="ownOffset">
        {{ t('credit_note_offset.credit_note_offset', { amount: formatMoney(ownOffset.amount, currency), date: ownOffset.offset_on }) }}
        <RouterLink :to="detailPath(ownOffset.invoice_id)" class="font-mono font-medium hover:underline">
          {{ ownOffset.invoice_number || `#${ownOffset.invoice_id}` }}
        </RouterLink>
      </template>
      <template v-else-if="invoiceOffsets.length > 0">
        <div v-for="o in invoiceOffsets" :key="o.id">
          {{ t('credit_note_offset.invoice_offset', { amount: formatMoney(o.amount, currency), date: o.offset_on }) }}
          <RouterLink :to="detailPath(o.credit_note_id)" class="font-mono font-medium hover:underline">
            {{ o.credit_note_number || `#${o.credit_note_id}` }}
          </RouterLink>
        </div>
      </template>
      <template v-else>{{ t('credit_note_offset.can_apply_hint') }}</template>
    </div>
    <div class="flex flex-wrap gap-2">
      <button v-if="canApply" type="button" :class="btnFilledSm('success')" :disabled="busy" data-test="credit-note-offset-apply" @click="apply">
        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.swap" /></svg>
        {{ t('credit_note_offset.apply') }}
      </button>
      <button v-if="ownOffset && canWrite" type="button" :class="btnOutlineSm('warning')" :disabled="busy" data-test="credit-note-offset-revert" @click="revert">
        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
        {{ t('credit_note_offset.revert') }}
      </button>
    </div>
  </div>
</template>
