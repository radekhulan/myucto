<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { propertyPaymentOperationsApi, type AboResult, type OpsBankAccount, type Reservation } from '@/api/propertyPaymentOperations'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import DateInput from '@/components/ui/DateInput.vue'
import SnapshotView from './SnapshotView.vue'
import { downloadAbo } from './aboDownload'
import { useBusyScope } from './busy'
import { usePreviewFlow } from './flows'
import { buildAbo, emptyAboSymbol, type AboState } from './partForms'
import { failureMessage, moneyText, partyNames } from './format'
import { boxClass, inputClass, subBoxClass } from './ui'
const props = defineProps<{ supplier: number; reservations: Reservation[]; selected: number[]; canManage: boolean; canPost: boolean }>()
const emit = defineEmits<{ changed: [] }>()
const { t } = useI18n()
const scope = useBusyScope()
const fresh = (): AboState => ({ selected: [], bank_account_id: 0, due_date: '', reason: '', symbols: {} })
const state = ref<AboState>(fresh())
const accounts = ref<OpsBankAccount[]>([])
const accountsLoaded = ref(false)
const accountError = ref('')
const form = ref<HTMLFormElement | null>(null)
const confirmed = ref<AboResult | null>(null)
const downloading = ref(false)
const downloadError = ref('')
let epoch = 0
let downloadEpoch = 0
onBeforeUnmount(() => { epoch++; downloadEpoch++; if (downloading.value) scope.end() })
const flow = usePreviewFlow({
  supplier: () => props.supplier, validate: () => form.value?.reportValidity() ?? false,
  canPreview: () => props.canManage, canConfirm: () => props.canPost,
  build: () => buildAbo({ ...state.value, selected: props.selected }, props.reservations),
  preview: (supplier, request) => propertyPaymentOperationsApi.aboPreview(supplier, request),
  confirm: (supplier, request, hash, token) => propertyPaymentOperationsApi.aboConfirm(supplier, request, hash, token),
  onDone: result => { confirmed.value = result; downloadError.value = ''; if (!cancelledIds(result).length) downloadAbo(result.filename, result.content_base64, result.encoding); emit('changed') },
})
const cancelledIds = (result: AboResult) => result.reservation_ids.filter(id => result.reservation_statuses?.[id] === 'cancelled')
const allCancelled = computed(() => !!confirmed.value && cancelledIds(confirmed.value).length === confirmed.value.reservation_ids.length)
watch(() => [props.selected, state.value], () => flow.invalidate(), { deep: true, flush: 'sync' })
watch(() => props.selected, ids => {
  for (const id of ids) if (!state.value.symbols[id]) state.value.symbols[id] = emptyAboSymbol()
}, { immediate: true, deep: true })
async function loadAccounts() {
  const ticket = ++epoch; const supplier = props.supplier
  accounts.value = []; accountsLoaded.value = false; accountError.value = ''
  try { const rows = await propertyPaymentOperationsApi.bankAccounts(supplier); if (ticket === epoch && supplier === props.supplier) { accounts.value = rows; accountsLoaded.value = true } }
  catch (e) { if (ticket === epoch) accountError.value = failureMessage(e, t('propertyPayments.failed')) }
}
function resetAll() {
  downloadEpoch++
  if (downloading.value) { downloading.value = false; scope.end() }
  state.value = fresh(); confirmed.value = null; downloadError.value = ''
  for (const id of props.selected) state.value.symbols[id] = emptyAboSymbol()
}
watch(() => props.supplier, () => { resetAll(); void loadAccounts() }, { immediate: true })
async function download() {
  const result = confirmed.value; const supplier = props.supplier
  if (!result || downloading.value || scope.busy.value || flow.busy.value || !props.canManage) return
  const ticket = ++downloadEpoch
  downloading.value = true; scope.begin(); downloadError.value = ''
  try {
    const again = await propertyPaymentOperationsApi.aboDownload(supplier, result.id)
    if (ticket !== downloadEpoch || supplier !== props.supplier) return
    confirmed.value = again
    if (!cancelledIds(again).length) downloadAbo(again.filename, again.content_base64, again.encoding)
  } catch (e) { if (ticket === downloadEpoch) downloadError.value = failureMessage(e, t('propertyPayments.failed')) }
  finally { if (ticket === downloadEpoch && downloading.value) { downloading.value = false; scope.end() } }
}
const accountText = (a: OpsBankAccount) => [a.label, `${a.account_number}/${a.bank_code}`].filter(Boolean).join(' · ')
const rowFor = (id: number) => props.reservations.find(r => r.id === id)
const statusText = (id: number) => t(`propertyPayments.reservation.status.${confirmed.value?.reservation_statuses?.[id] ?? 'submitted'}`)
const actions = () => {
  const list: ActionItem[] = [flow.preview.value
    ? { key: 'abo-confirm', label: t('propertyPayments.abo.confirm'), icon: 'download', variant: 'success', tier: 'primary', disabled: flow.busy.value || downloading.value || !props.canPost, run: () => { void flow.confirm() } }
    : { key: 'abo-preview', label: t('propertyPayments.preview'), icon: 'eye', variant: 'primary', tier: 'primary', disabled: flow.busy.value || downloading.value || !props.canManage || !props.selected.length, run: () => { void flow.showPreview() } }]
  if (confirmed.value && !allCancelled.value) list.push({ key: 'abo-download', label: t('propertyPayments.abo.download'), icon: 'download', variant: 'neutral', tier: 'secondary', disabled: flow.busy.value || downloading.value || scope.busy.value || !props.canManage, run: () => { void download() } })
  return list
}
</script>

<template>
  <section :class="boxClass" data-section="abo-export">
    <h3 class="font-semibold">{{ t('propertyPayments.abo.title') }}</h3>
    <p class="text-sm text-neutral-500">{{ t('propertyPayments.abo.help') }}</p>
    <p v-if="!selected.length" class="text-sm">{{ t('propertyPayments.abo.selectFirst') }}</p>
    <form ref="form" class="space-y-3" @submit.prevent="flow.showPreview">
      <fieldset :disabled="flow.busy.value || downloading || !canManage" class="space-y-3">
        <div class="grid gap-3 sm:grid-cols-2">
          <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.abo.account') }}<select v-model="state.bank_account_id" required :class="inputClass" data-field="abo-account"><option :value="0">{{ t('propertyPayments.choose') }}</option><option v-for="a in accounts" :key="a.id" :value="a.id">{{ accountText(a) }}</option></select></label>
          <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.abo.dueDate') }}<DateInput v-model="state.due_date" required :class="inputClass" data-field="abo-due" /></label>
          <label class="sm:col-span-2 flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.reason') }}<input v-model="state.reason" required :class="inputClass" data-field="abo-reason"></label>
        </div>
        <p v-if="accountsLoaded && !accounts.length" class="text-sm text-warning-600" data-flag="no-accounts">{{ t('propertyPayments.abo.noAccounts') }}</p>
        <div v-for="id in selected" :key="id" :class="subBoxClass" data-row="abo-symbols">
          <p class="text-sm font-medium">{{ rowFor(id) ? `${rowFor(id)!.unit_label} · ${partyNames(rowFor(id)!.parties)} · ${moneyText(rowFor(id)!.remaining_cents, t('propertyPayments.unknown'))}` : t('propertyPayments.unknown') }}</p>
          <div v-if="state.symbols[id]" class="grid gap-3 sm:grid-cols-3">
            <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.abo.variableSymbol') }}<input v-model="state.symbols[id]!.variable" :required="!state.symbols[id]!.withoutVariable" :disabled="state.symbols[id]!.withoutVariable" inputmode="numeric" maxlength="10" :class="inputClass" data-field="abo-vs"></label>
            <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.abo.constantSymbol') }}<input v-model="state.symbols[id]!.constant" inputmode="numeric" maxlength="10" :class="inputClass" data-field="abo-ks"></label>
            <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.abo.specificSymbol') }}<input v-model="state.symbols[id]!.specific" inputmode="numeric" maxlength="10" :class="inputClass" data-field="abo-ss"></label>
            <label class="sm:col-span-3 flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.abo.message') }}<input v-model="state.symbols[id]!.message" :class="inputClass" data-field="abo-message"></label>
            <label class="sm:col-span-3 flex flex-wrap items-center gap-2"><input v-model="state.symbols[id]!.withoutVariable" type="checkbox" data-field="abo-no-vs" @change="state.symbols[id]!.variable = ''">{{ t('propertyPayments.abo.withoutVariable') }}</label>
          </div>
        </div>
      </fieldset>
      <p v-if="accountError" role="alert" class="text-danger-600">{{ accountError }}</p>
      <section v-if="flow.preview.value" class="space-y-2 rounded border border-success-300 p-3" aria-live="polite"><h4 class="font-semibold">{{ t('propertyPayments.previewTitle') }}</h4><SnapshotView :snapshot="flow.preview.value.snapshot" /></section>
      <p v-if="flow.error.value" role="alert" class="text-danger-600">{{ flow.error.value }}</p>
      <section v-if="confirmed" class="space-y-1 rounded border border-neutral-200 p-3 text-sm" data-section="abo-result">
        <p v-if="!allCancelled" role="status" class="text-success-600">{{ t('propertyPayments.abo.done', { filename: confirmed.filename }) }}</p>
        <p class="text-xs text-neutral-500">{{ t('propertyPayments.abo.notSent') }}</p>
        <p v-for="id in confirmed.reservation_ids" :key="id" data-row="abo-status" :data-status="confirmed.reservation_statuses?.[id]">{{ rowFor(id)?.unit_label ?? t('propertyPayments.abo.reservation') }} · {{ statusText(id) }}</p>
        <p v-if="cancelledIds(confirmed).length" role="alert" class="text-warning-600" data-flag="abo-cancelled">{{ t('propertyPayments.abo.cancelledWarning') }}</p>
      </section>
      <p v-if="downloadError" role="alert" class="text-danger-600">{{ downloadError }}</p>
      <ActionBar :actions="actions()" />
    </form>
  </section>
</template>
