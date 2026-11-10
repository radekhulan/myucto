<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useIntervalFn } from '@vueuse/core'
import { useI18n } from 'vue-i18n'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import { membershipApi, type MembershipIssueRequest, type MembershipBatchDetail, type MembershipBatchCreate, type MembershipBatchRecent } from '@/api/nonprofitMembership'
import type { AccountingPeriod } from '@/api/accounting'
import type { DimensionOverview } from '@/api/dimensions'
import { formatDate, formatDateTime, formatMoney, formatMoneyMinor } from '@/composables/useFormat'
import { dimensionValueName } from '@/utils/dimensionValueLabel'
import { errorBox, membershipError, formatDateRange, warningBox, membershipMemberLabel } from './membershipFormat'
import MembershipConfirmBar from './MembershipConfirmBar.vue'
import MembershipIssueFlow from './MembershipIssueFlow.vue'

const props = defineProps<{ supplier: number; canManage: boolean; periods: AccountingPeriod[]; selectedMemberIds: number[] }>()
const emit = defineEmits<{ changed: []; close: []; lock: [locked: boolean] }>()
const { t, te } = useI18n()
const mode = ref<'active' | 'selected'>('selected')
const draftMemberIds = ref<number[] | null>(null)
const effectiveMemberIds = computed(() => draftMemberIds.value ?? props.selectedMemberIds)
const draftTemplate = ref<MembershipBatchCreate['template']>()
const draftKey = ref(0)
const detail = ref<MembershipBatchDetail | null>(null), busy = ref(false), stopRequested = ref(false)
const cancelling = ref(false)
const error = ref(''), notice = ref(''), acknowledged = ref(false)
const recent = ref<MembershipBatchRecent[]>([]), recentBefore = ref<number | null>(null), recentError = ref(''), recentLoading = ref(false)
const overview = ref<DimensionOverview | null>(null), dimensionsError = ref(false)
const pageAfter = ref(0), previousPages = ref<number[]>([])
let alive = true, epoch = 0, recentTicket = 0, dimensionsTicket = 0
let lastCreate: { fingerprint: string; body: MembershipBatchCreate } | null = null
const axes = ['activity', 'activity_kind', 'tax_regime'] as const
const classificationRows = computed(() => axes.map(axis => {
  const id = detail.value?.batch.template.classification[`${axis}_value_id`]
  const value = overview.value?.values.find(v => v.supplier_id === props.supplier && v.id === id)
  const type = overview.value?.types.find(x => x.id === value?.type_id && x.supplier_id === props.supplier && x.system_key === axis)
  return { axis, label: t(`nonprofitMembership.${axis === 'activity' ? 'classificationActivity' : axis === 'activity_kind' ? 'classificationKind' : 'classificationTaxRegime'}`),
    value: value && type ? dimensionValueName({t, te}, value, axis) : '' }
}))
const now = ref(Date.now())
useIntervalFn(() => {now.value = Date.now()}, 1000)
const approvalExpired = computed(() => {
  const expires = detail.value?.batch.approval_expires_at
  if (!expires) return false
  const deadline = Date.parse(expires.replace(' ', 'T').replace(/Z$/, '') + 'Z')
  return !Number.isFinite(deadline) || deadline <= now.value
})
watch(approvalExpired, expired => {if (expired) acknowledged.value = false})
const classificationVisible = computed(() => classificationRows.value.every(x => !!x.value))

async function loadRecent(append = false) {
  const supplier = props.supplier, ticket = ++recentTicket
  recentLoading.value = true; recentError.value = ''
  try {
    const page = await membershipApi.batches(supplier, append ? recentBefore.value ?? undefined : undefined)
    if (!alive || ticket !== recentTicket || supplier !== props.supplier) return
    recent.value = append ? [...recent.value, ...page.items] : page.items; recentBefore.value = page.next_before_id
  } catch (e) { if (alive && ticket === recentTicket) recentError.value = membershipError({t, te}, e) }
  finally { if (alive && ticket === recentTicket) recentLoading.value = false }
}

async function loadDimensions() {
  const ticket = ++dimensionsTicket, supplier = props.supplier
  dimensionsError.value = false
  try {
    const result = await membershipApi.classificationOptions(supplier)
    if (alive && ticket === dimensionsTicket && supplier === props.supplier) overview.value = result
  } catch { if (alive && ticket === dimensionsTicket) dimensionsError.value = true }
}

async function open(id: number, after = 0, direction: 'new' | 'next' | 'previous' = 'new') {
  if (busy.value) return
  const supplier = props.supplier, ticket = ++epoch
  busy.value = true; error.value = ''; notice.value = ''; acknowledged.value = false
  try {
    const result = await membershipApi.batch(supplier, id, after)
    if (!alive || ticket !== epoch || supplier !== props.supplier) return
    if (direction === 'next') previousPages.value.push(pageAfter.value)
    else if (direction === 'previous') previousPages.value.pop()
    else previousPages.value = []
    pageAfter.value = after; detail.value = result; cancelling.value = false
  } catch (e) { if (alive && ticket === epoch) error.value = membershipError({t, te}, e) }
  finally { if (alive && ticket === epoch) busy.value = false }
}

async function prepareRequest(request: MembershipIssueRequest) {
  if (busy.value || !props.canManage || (mode.value === 'selected' && (!effectiveMemberIds.value.length || effectiveMemberIds.value.length > 500))) {
    error.value = t('nonprofitMembership.batchSelectionRequired'); return
  }
  const {member_id: _member, ...template} = request
  const input = {template, selection_mode: mode.value, member_ids: mode.value === 'selected' ? [...effectiveMemberIds.value].sort((a,b) => a-b) : []}
  const fingerprint = JSON.stringify(input)
  if (!lastCreate || lastCreate.fingerprint !== fingerprint) lastCreate = {fingerprint, body: {...input, idempotency_key: crypto.randomUUID()}}
  const ticket = ++epoch, supplier = props.supplier
  busy.value = true; error.value = ''; notice.value = ''
  let created = false
  try {
    const result = await membershipApi.createBatch(supplier, lastCreate.body)
    if (!alive || ticket !== epoch || supplier !== props.supplier) return
    if (mode.value === 'selected') draftMemberIds.value = [...input.member_ids]
    detail.value = result; pageAfter.value = 0; previousPages.value = []; acknowledged.value = false; created = true
    void loadRecent()
  } catch (e) { if (alive && ticket === epoch) error.value = membershipError({t, te}, e) }
  finally { if (alive && ticket === epoch) busy.value = false }
  if (created && alive && ticket === epoch) await work('prepare')
}

async function work(kind: 'prepare' | 'confirm') {
  if (busy.value || !props.canManage || !detail.value) return
  if (kind === 'confirm' && (approvalExpired.value || !acknowledged.value || !classificationVisible.value || !detail.value.batch.approval_token)) return
  const supplier = props.supplier, id = detail.value.batch.id, token = detail.value.batch.approval_token, ticket = ++epoch
  busy.value = true; stopRequested.value = false; error.value = ''; notice.value = ''
  pageAfter.value = 0; previousPages.value = []
  try {
    do {
      const result = kind === 'prepare' ? await membershipApi.prepareBatch(supplier, id) : await membershipApi.confirmBatch(supplier, id, token!)
      if (!alive || ticket !== epoch || supplier !== props.supplier) return
      detail.value = result
      if (['finished', 'cancelled'].includes(result.batch.phase) || (kind === 'prepare' && !['seeding', 'preparing'].includes(result.batch.phase))) { acknowledged.value = false; break }
    } while (!stopRequested.value)
    if (stopRequested.value) notice.value = t('nonprofitMembership.batchPaused')
  } catch (e) {
    if (alive && ticket === epoch) {
      error.value = membershipError({t, te}, e)
      // Při ztrátě odpovědi zjistíme uložený stav; další účetní krok vyžaduje znovu výslovnou akci.
      try {
        const result = await membershipApi.batch(supplier, id)
        if (alive && ticket === epoch && supplier === props.supplier) detail.value = result
      } catch { /* Původní chyba a uložená dávka zůstávají k bezpečnému opakování. */ }
    }
  } finally {
    if (alive && ticket === epoch) {
      busy.value = false
      if (kind === 'confirm') emit('changed')
      void loadRecent()
    }
  }
}

async function newFromDetail() {
  if (busy.value || !detail.value || !props.canManage) return
  const source = detail.value, supplier = props.supplier, ticket = ++epoch
  busy.value = true; error.value = ''
  try {
    const ids: number[] = []
    if (source.batch.selection_mode === 'selected') {
      // Zaškrtnutá dávka má nejvýše 500 členů; načítání zůstává omezené i po reloadu.
      let after = 0
      for (let page = 0; page < 11; page++) {
        const result = await membershipApi.batch(supplier, source.batch.id, after)
        if (!alive || ticket !== epoch || supplier !== props.supplier) return
        ids.push(...result.items.map(row => row.member_id))
        if (result.next_after_member_id === null) break
        if (result.next_after_member_id <= after || ids.length > 500) throw new Error(t('nonprofitMembership.batchSelectionRequired'))
        after = result.next_after_member_id
      }
      if (ids.length !== source.batch.member_count || ids.length > 500) throw new Error(t('nonprofitMembership.batchSelectionRequired'))
    }
    if (!alive || ticket !== epoch || supplier !== props.supplier) return
    draftTemplate.value = {...source.batch.template, classification: {...source.batch.template.classification}}
    draftMemberIds.value = source.batch.selection_mode === 'selected' ? ids : null
    mode.value = source.batch.selection_mode; draftKey.value++
    detail.value = null; acknowledged.value = false; notice.value = ''; lastCreate = null
  } catch (e) { if (alive && ticket === epoch) error.value = membershipError({t, te}, e) }
  finally { if (alive && ticket === epoch) busy.value = false }
}


async function renewPreview() {
  if (busy.value || !props.canManage || !detail.value) return
  const supplier = props.supplier, id = detail.value.batch.id, ticket = ++epoch
  busy.value = true; error.value = ''; acknowledged.value = false
  let renewed = false
  try {
    const result = await membershipApi.renewBatch(supplier, id)
    if (alive && ticket === epoch && supplier === props.supplier) {detail.value = result; renewed = true}
  } catch (e) {if (alive && ticket === epoch) error.value = membershipError({t, te}, e)}
  finally {if (alive && ticket === epoch) busy.value = false}
  if (renewed) await work('prepare')
}

async function cancelBatch() {
  if (busy.value || !props.canManage || !detail.value) return
  const supplier = props.supplier, id = detail.value.batch.id, ticket = ++epoch
  busy.value = true; error.value = ''; acknowledged.value = false
  try {
    const result = await membershipApi.cancelBatch(supplier, id)
    if (alive && ticket === epoch && supplier === props.supplier) {detail.value = result; cancelling.value = false; emit('changed')}
  } catch (e) {
    if (alive && ticket === epoch) error.value = membershipError({t, te}, e)
  } finally {if (alive && ticket === epoch) {busy.value = false; void loadRecent()}}
}

const actions = computed<ActionItem[]>(() => [
  {key: 'batch-prepare', label: t('nonprofitMembership.batchContinuePreview'), icon: 'eye', tier: 'primary', variant: 'primary', show: !!detail.value && ['seeding', 'preparing'].includes(detail.value.batch.phase), disabled: busy.value || !props.canManage, run: () => {void work('prepare')}},
  {key: 'batch-renew', label: t('nonprofitMembership.batchRenewPreview'), icon: 'eye', variant: 'primary', show: !!detail.value && ['ready', 'posting'].includes(detail.value.batch.phase) && approvalExpired.value && props.canManage, disabled: busy.value, run: () => {void renewPreview()}},
  {key: 'batch-cancel', label: t('nonprofitMembership.batchCancel'), icon: 'x', variant: 'warning', show: !!detail.value && ['seeding','preparing','ready','posting'].includes(detail.value.batch.phase) && props.canManage, disabled: busy.value, run: () => {cancelling.value = true}},
  {key: 'batch-pause', label: t('nonprofitMembership.batchPause'), icon: 'x', variant: 'warning', show: busy.value && !!detail.value, disabled: stopRequested.value, run: () => {stopRequested.value = true}},
  {key: 'batch-new', label: t('nonprofitMembership.batchNew'), icon: 'plus', show: !!detail.value && props.canManage, disabled: busy.value, run: () => {void newFromDetail()}},
  {key: 'batch-close', label: t('nonprofitMembership.close'), icon: 'x', disabled: busy.value, run: () => emit('close')},
])
const pageActions = computed<ActionItem[]>(() => [
  {key: 'batch-previous', label: t('nonprofitMembership.batchPrevious'), icon: 'chevron', disabled: busy.value || !previousPages.value.length, run: () => {if (detail.value) void open(detail.value.batch.id, previousPages.value.at(-1) ?? 0, 'previous')}},
  {key: 'batch-next', label: t('nonprofitMembership.batchNext'), icon: 'chevron', disabled: busy.value || detail.value?.next_after_member_id == null, run: () => {if (detail.value?.next_after_member_id) void open(detail.value.batch.id, detail.value.next_after_member_id, 'next')}},
])
const recentActions = computed<ActionItem[]>(() => [
  {key: 'batches-reload', label: t('nonprofitMembership.reload'), icon: 'cycle', disabled: recentLoading.value, run: () => {void loadRecent()}},
  {key: 'batches-more', label: t('nonprofitMembership.batchMore'), icon: 'chevron', show: recentBefore.value !== null, disabled: recentLoading.value, run: () => {void loadRecent(true)}},
])
watch(() => props.supplier, () => {
  epoch++; recentTicket++; dimensionsTicket++; busy.value = false; detail.value = null; recent.value = []; recentBefore.value = null
  error.value = ''; notice.value = ''; acknowledged.value = false; cancelling.value = false; lastCreate = null; overview.value = null; previousPages.value = []
  draftMemberIds.value = null
  draftTemplate.value = undefined; draftKey.value++
  void loadRecent(); void loadDimensions()
})
watch(busy, value => emit('lock', value), {flush: 'sync'})
onMounted(() => {void loadRecent(); void loadDimensions()})
onBeforeUnmount(() => {alive = false; epoch++; recentTicket++; dimensionsTicket++})
</script>

<template>
  <section class="space-y-4 rounded-lg border border-neutral-200 bg-surface p-4" data-section="membership-batch">
    <h2 class="font-semibold">{{ t('nonprofitMembership.batchTitle') }}</h2>
    <p class="text-sm text-neutral-500">{{ t('nonprofitMembership.batchHelp') }}</p>
    <p v-if="error" role="alert" :class="errorBox" data-section="batch-error">{{ error }}</p>
    <p v-if="notice" role="status" class="text-sm text-warning-700">{{ notice }}</p>
    <p v-if="busy" role="status" class="text-sm text-neutral-500">{{ t('nonprofitMembership.batchWorking') }}</p>
    <div v-if="!detail">
      <fieldset :disabled="busy || !!detail" class="flex flex-wrap gap-4 text-sm">
        <label class="flex items-center gap-2"><input v-model="mode" type="radio" value="selected" data-field="batch-selected" />{{ t('nonprofitMembership.batchSelected', {count: effectiveMemberIds.length}) }}</label>
        <label class="flex items-center gap-2"><input v-model="mode" type="radio" value="active" data-field="batch-active" />{{ t('nonprofitMembership.batchActive') }}</label>
      </fieldset>
      <p v-if="mode === 'selected' && !effectiveMemberIds.length" :class="warningBox">{{ t('nonprofitMembership.batchSelectionRequired') }}</p>
      <p v-if="mode === 'selected' && draftMemberIds" class="text-sm text-neutral-500">{{ t('nonprofitMembership.batchReusedSelection') }}</p>
      <MembershipIssueFlow :key="`${supplier}-${draftKey}`" :supplier="supplier" :can-manage="canManage" :periods="periods" :history="[]" :initial-request="draftTemplate"
        :member="{id: 0, member_number: '', label: ''}" batch-mode :batch-busy="busy || !!detail" @batch-request="prepareRequest" @close="emit('close')" />
    </div>
    <template v-if="detail">
      <p class="font-medium">{{ detail.batch.template.title_key }} · {{ formatDateRange(detail.batch.template.period_from, detail.batch.template.period_to) }}</p>
      <p class="text-sm">{{ t(`nonprofitMembership.batchPhase_${detail.batch.phase}`) }}</p>
      <dl class="grid gap-3 sm:grid-cols-3" data-section="batch-summary">
        <div v-for="status in ['total', 'pending', 'ready', 'created', 'existing', 'rejected'] as const" :key="status"><dt class="text-sm text-neutral-500">{{ t(`nonprofitMembership.batchStatus_${status}`) }}</dt><dd class="tabular-nums">{{ detail.summary[status] }}</dd></div>
        <div><dt class="text-sm text-neutral-500">{{ t('nonprofitMembership.batchPlannedAmount') }}</dt><dd class="tabular-nums">{{ formatMoneyMinor(Number(detail.summary.planned_cents)) }}</dd></div>
      </dl>
      <p v-if="detail.summary.deferral_date && Number(detail.summary.deferred_cents) > 0" class="text-sm text-warning-700" data-section="batch-deferral">{{ t('nonprofitMembership.deferralProposal', {amount: formatMoneyMinor(Number(detail.summary.deferred_cents)), date: formatDate(detail.summary.deferral_date)}) }}</p>
      <dl class="grid gap-3 text-sm sm:grid-cols-3">
        <div><dt class="text-neutral-500">{{ t('nonprofitMembership.amount') }}</dt><dd>{{ formatMoney(detail.batch.template.amount) }}</dd></div>
        <div><dt class="text-neutral-500">{{ t('nonprofitMembership.accountingDate') }}</dt><dd>{{ formatDate(detail.batch.template.accounting_date) }}</dd></div>
        <div><dt class="text-neutral-500">{{ t('nonprofitMembership.dueDate') }}</dt><dd>{{ formatDate(detail.batch.template.due_date) }}</dd></div>
        <div v-for="row in classificationRows" :key="row.axis"><dt class="text-neutral-500">{{ row.label }}</dt><dd>{{ row.value || t('nonprofitMembership.classificationProofMissing') }}</dd></div>
        <div class="sm:col-span-3"><dt class="text-neutral-500">{{ t('nonprofitMembership.titleReference') }}</dt><dd class="break-words">{{ detail.batch.template.title_reference }}</dd></div>
        <div class="sm:col-span-3"><dt class="text-neutral-500">{{ t('nonprofitMembership.evidence') }}</dt><dd class="break-words">{{ detail.batch.template.evidence }}</dd></div>
        <div class="sm:col-span-3"><dt class="text-neutral-500">{{ t('nonprofitMembership.reason') }}</dt><dd class="break-words">{{ detail.batch.template.reason }}</dd></div>
      </dl>
      <ActionBar v-if="dimensionsError || !classificationVisible" :actions="[{key: 'batch-dimensions', label: t('nonprofitMembership.classificationRetry'), icon: 'cycle', run: () => {void loadDimensions()}}]" />
      <div class="overflow-x-auto"><table class="w-full text-sm" data-section="batch-accounts"><caption class="pb-2 text-left font-medium">{{ t('nonprofitMembership.batchAccountsCaption') }}</caption><thead><tr class="text-left text-neutral-500"><th class="px-3 py-2">{{ t('nonprofitMembership.side') }}</th><th class="px-3 py-2">{{ t('nonprofitMembership.account') }}</th><th class="px-3 py-2 text-right">{{ t('nonprofitMembership.amount') }}</th></tr></thead><tbody><tr v-for="row in detail.accounts" :key="row.side"><td class="px-3 py-2">{{ t(row.side === 'debit' ? 'nonprofitMembership.debit' : 'nonprofitMembership.credit') }}</td><td class="px-3 py-2">{{ row.account }}</td><td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ formatMoneyMinor(Number(row.amount_cents)) }}</td></tr></tbody></table></div>
      <p class="text-sm text-neutral-500">{{ t('nonprofitMembership.batchExistingHelp') }}</p>
      <div v-for="row in detail.items" :key="row.member_id" class="flex flex-wrap items-start justify-between gap-3 rounded border border-neutral-200 p-3 text-sm" data-section="batch-member">
        <div class="min-w-0"><p class="break-words font-medium">{{ row.member_number }} · {{ membershipMemberLabel(row) }}</p><p :class="row.status === 'rejected' ? 'text-danger-600' : row.status === 'created' ? 'text-success-700' : 'text-neutral-500'">{{ t(`nonprofitMembership.batchStatus_${row.status}`) }}</p><p v-if="row.error_code || row.error_message" class="break-words text-neutral-500">{{ membershipError({t, te}, row.error_code ?? '', row.error_message ?? '') }}</p></div>
        <span class="whitespace-nowrap tabular-nums">{{ formatMoneyMinor(row.amount_cents) }}</span>
      </div>
      <ActionBar :actions="pageActions" />
      <MembershipConfirmBar v-if="['ready', 'posting'].includes(detail.batch.phase) && canManage && !cancelling && !approvalExpired"
        v-model:acknowledged="acknowledged" :busy="busy" :uncertain="false" :can-manage="canManage" :show-cancel="false" ack-field="batch-ack"
        :ack-label="t('nonprofitMembership.batchAck')" :confirm-label="t(detail.batch.phase === 'posting' ? 'nonprofitMembership.batchContinuePosting' : 'nonprofitMembership.batchConfirm')"
        :blocked-reason="!classificationVisible ? t('nonprofitMembership.classificationProofMissing') : !detail.batch.approval_token ? t('nonprofitMembership.batchNeedNewPreview') : undefined"
        @confirm="work('confirm')" />
      <p v-if="approvalExpired" :class="warningBox">{{ t('nonprofitMembership.batchNeedNewPreview') }}</p>
      <div v-if="cancelling" :class="warningBox" role="alert">
        <p>{{ t('nonprofitMembership.batchCancelHelp') }}</p>
        <ActionBar :actions="[
          {key: 'batch-keep', label: t('common.cancel'), icon: 'x', disabled: busy, run: () => {cancelling = false}},
          {key: 'batch-cancel-confirm', label: t('nonprofitMembership.batchCancel'), icon: 'check', variant: 'warning', disabled: busy, run: () => {void cancelBatch()}},
        ]" />
      </div>
    </template>
    <ActionBar :actions="actions" />
    <details class="space-y-3 rounded border border-neutral-200 p-3" data-section="batch-recent">
      <summary class="cursor-pointer font-medium">{{ t('nonprofitMembership.batchRecent') }}</summary>
      <p v-if="recentError" role="alert" :class="errorBox">{{ recentError }}</p>
      <ActionBar :actions="recentActions" />
      <div v-for="row in recent" :key="row.id" class="space-y-1 border-t border-neutral-200 pt-3 text-sm">
        <p>{{ row.title_key }} · {{ formatDateRange(row.period_from, row.period_to) }} · {{ formatDateTime(row.created_at) }}</p>
        <p class="text-neutral-500">{{ t(`nonprofitMembership.batchPhase_${row.phase}`) }} · {{ t('nonprofitMembership.batchMemberCount', {count: row.member_count}) }}</p>
        <ActionBar :actions="[{key: 'batch-open', label: t('nonprofitMembership.batchOpen'), icon: 'eye', variant: 'primary', disabled: busy, run: () => {void open(row.id)}}]" />
      </div>
    </details>
  </section>
</template>
