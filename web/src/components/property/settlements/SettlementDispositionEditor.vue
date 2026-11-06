<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { propertySettlementsApi as api, type SettlementDetail, type SettlementDisposition, type SettlementEntitlement, type SettlementApplyRequest, type SettlementApplicationPreview } from '@/api/propertySettlements'
import type { LiabilityGroup } from '@/api/propertyReceivables'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import DateInput from '@/components/ui/DateInput.vue'
import PropertyInstantInput from '@/components/property/PropertyInstantInput.vue'
import PropertyButton from '@/components/property/PropertyButton.vue'
import LiabilityGroupEditor from '@/components/property/payments/receivables/LiabilityGroupEditor.vue'
import SettlementEntitlementEditor from './SettlementEntitlementEditor.vue'
import SettlementPlanView from './SettlementPlanView.vue'
import { propertyError } from '@/components/property/propertyError'
import { formatDate } from '@/composables/useFormat'
import { settlementInput as input, settlementBox as box, settlementMoney as money, settlementCents as cents, settlementDecimal, emptyEntitlement, emptyClaimTitle } from './settlementForm'
const props = defineProps<{ supplier: number; detail: SettlementDetail }>()
const emit = defineEmits<{ applied: [] }>()
const { t } = useI18n()
const date = ref(props.detail.origin_receipt.request.accounting_date), evidence = ref(''), current = ref<SettlementDisposition | null>(null), error = ref(''), busy = ref(false), html = ref<HTMLFormElement | null>(null)
const advances = reactive<Record<string, string>>({}), reliefs = reactive<Record<number, { on: boolean; amount: string; entitlement: SettlementEntitlement }>>({}), opening = reactive<Record<string, { on: boolean; amount: string; entitlement: SettlementEntitlement }>>({})
const claim = reactive(emptyEntitlement()), title = reactive(emptyClaimTitle())
const preview = ref<SettlementApplicationPreview | null>(null)
let epoch = 0, token = crypto.randomUUID(), submitted: SettlementApplyRequest | null = null
const hasClaim = computed(() => props.detail.document.amounts.new_claim_cents !== '0')
const creditAccounts = computed(() => (current.value?.accounts ?? []).filter(a => a.account_code === '379.910'))
const claimAccounts = computed(() => (current.value?.accounts ?? []).filter(a => a.account_type === 'asset' && a.account_code.startsWith('315')))
function invalidate() { epoch++; preview.value = null; submitted = null; token = crypto.randomUUID() }
watch([evidence, advances, reliefs, opening, claim, title], invalidate, { deep: true, flush: 'sync' })
watch(date, () => { invalidate(); current.value = null })
watch(() => [props.supplier, props.detail.id], () => { invalidate(); current.value = null; busy.value = false; error.value = '' })
onBeforeUnmount(() => epoch++)
async function loadCurrent() {
 if (busy.value || !date.value) return
 invalidate(); const supplier = props.supplier, ticket = epoch; busy.value = true; error.value = ''
 try {
  const result = await api.disposition(supplier, props.detail.id, date.value)
  if (ticket !== epoch || supplier !== props.supplier) return
  for (const key of Object.keys(advances)) delete advances[key]
  for (const key of Object.keys(reliefs)) delete reliefs[Number(key)]
  for (const key of Object.keys(opening)) delete opening[key]
  for (const origin of result.origins) { advances[origin.origin_key] = result.origins.length === 1 ? settlementDecimal(props.detail.document.amounts.advance_applied_cents) : ''; if (origin.kind === 'opening_component' && origin.carried_paid_cents !== '0') opening[origin.origin_key] = { on: false, amount: '', entitlement: emptyEntitlement() } }
  for (const allocation of result.allocations.filter(a => a.active)) reliefs[allocation.id] = { on: false, amount: '', entitlement: emptyEntitlement() }
  current.value = result
 } catch (e) { if (ticket === epoch) error.value = propertyError(e, t) } finally { if (supplier === props.supplier) busy.value = false }
}
function addGroup() { title.liability_groups.push({ group_key: `group${title.liability_groups.length + 1}`, role: 'debtor', mode: 'individual', basis: 'documented_title', members: [], extent: { num: '1', den: '1' }, evidence_ref: '', legal_reason: '', valid_from: '', valid_to: null } as LiabilityGroup) }
function build(): SettlementApplyRequest {
 if (!current.value) throw new Error('current_required')
 const request: SettlementApplyRequest = { accounting_date: date.value, evidence: evidence.value, advances: current.value.origins.map(o => ({ origin_key: o.origin_key, applied_cents: cents(advances[o.origin_key] || '') })), reliefs: Object.entries(reliefs).filter(([, v]) => v.on).map(([id, v]) => ({ allocation_id: Number(id), amount_cents: cents(v.amount), entitlement: JSON.parse(JSON.stringify(v.entitlement)) as SettlementEntitlement })), opening_credits: Object.entries(opening).filter(([, v]) => v.on).map(([key, v]) => ({ origin_key: key, amount_cents: cents(v.amount), entitlement: JSON.parse(JSON.stringify(v.entitlement)) as SettlementEntitlement })), new_claim: hasClaim.value ? JSON.parse(JSON.stringify(claim)) as SettlementEntitlement : null }
 if (hasClaim.value) request.claim_title = JSON.parse(JSON.stringify(title))
 return request
}
async function showPreview() {
 if (busy.value || !props.detail.capabilities.manage || !current.value || !html.value?.reportValidity()) return
 let payload: SettlementApplyRequest
 try { payload = build() } catch { error.value = t('propertySettlements.invalidAmount'); return }
 const ticket = epoch, supplier = props.supplier; busy.value = true; error.value = ''
 try { const result = await api.preview(supplier, props.detail.id, payload, current.value.row_version); if (ticket === epoch && supplier === props.supplier) { submitted = payload; preview.value = result } }
 catch (e) { if (ticket === epoch) { invalidate(); error.value = propertyError(e, t) + ' ' + t('propertySettlements.newPreview') } } finally { if (supplier === props.supplier) busy.value = false }
}
async function apply() {
 if (busy.value || !props.detail.capabilities.post || !preview.value || !submitted || !current.value) return
 const ticket = epoch, supplier = props.supplier, payload = submitted, hash = preview.value.preview_hash, version = current.value.row_version; busy.value = true; error.value = ''
 try { await api.apply(supplier, props.detail.id, payload, version, hash, token); if (ticket === epoch && supplier === props.supplier) { invalidate(); emit('applied') } }
 catch (e) { if (ticket === epoch) { invalidate(); current.value = null; error.value = propertyError(e, t) + ' ' + t('propertySettlements.newPreview') } } finally { if (supplier === props.supplier) busy.value = false }
}
const actions = computed<ActionItem[]>(() => [{ key: preview.value ? 'apply-settlement' : 'preview-application', label: t(preview.value ? 'propertySettlements.apply' : 'propertySettlements.previewApplication'), icon: preview.value ? 'check' : 'eye', variant: preview.value ? 'success' : 'primary', tier: 'primary', disabled: busy.value || (preview.value ? !props.detail.capabilities.post : !props.detail.capabilities.manage) || !current.value, run: () => { void (preview.value ? apply() : showPreview()) } }])
</script>
<template>
 <section :class="box" data-section="disposition-editor"><h2 class="font-semibold">{{ t('propertySettlements.disposition') }}</h2><p class="text-sm text-neutral-500">{{ t('propertySettlements.dispositionHelp') }}</p><p v-if="error" role="alert" class="text-danger-700">{{ error }}</p>
 <form ref="html" class="space-y-4" @submit.prevent="showPreview"><fieldset :disabled="busy || !detail.capabilities.manage" class="space-y-4"><div class="flex flex-wrap items-end gap-3"><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertySettlements.accountingDate') }}<DateInput :class="input" v-model="date" required data-field="application-date" /></label><PropertyButton icon="cycle" data-action="load-current" @click="loadCurrent">{{ t('propertySettlements.loadCurrent') }}</PropertyButton></div>
 <template v-if="current"><p class="text-sm font-medium" data-section="late-payment-summary">{{ t('propertySettlements.currentPaid') }}: {{ money(current.current_paid_cents) }} · {{ t('propertySettlements.requiredApplied') }}: {{ money(detail.document.amounts.advance_applied_cents) }}</p>
 <dl class="grid gap-3 sm:grid-cols-3"><div v-for="key in ['cancelled_unpaid_cents', 'new_credit_cents', 'remaining_old_claim_cents'] as const" :key="key"><dt>{{ t(`propertySettlements.amounts.${key}`) }}</dt><dd>{{ money(current.amounts[key]) }}</dd></div></dl>
 <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertySettlements.dispositionEvidence') }}<input v-model="evidence" required :class="input" data-field="application-evidence" /></label>
 <article v-for="(origin, i) in current.origins" :key="origin.origin_key" class="space-y-3 rounded border p-3"><h3>{{ t('propertySettlements.originNumber', { number: i + 1 }) }} · {{ t(origin.kind === 's3_line' ? 'propertySettlements.s3' : 'propertySettlements.opening') }}</h3><dl class="flex flex-wrap gap-4 text-sm"><div><dt>{{ t('propertySettlements.prescribed_cents') }}</dt><dd>{{ money(origin.prescribed_cents) }}</dd></div><div><dt>{{ t('propertySettlements.carriedPaid') }}</dt><dd>{{ money(origin.carried_paid_cents) }}</dd></div><div><dt>{{ t('propertySettlements.physicalPaid') }}</dt><dd>{{ money(origin.paid_cents) }}</dd></div><div><dt>{{ t('propertySettlements.originalUnpaid') }}</dt><dd>{{ money(origin.unpaid_cents) }}</dd></div></dl><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertySettlements.applyAdvance') }}<input v-model="advances[origin.origin_key]" required inputmode="decimal" :class="input" data-field="advance-amount" /></label>
 <template v-if="opening[origin.origin_key]"><label class="flex flex-wrap items-center gap-2"><input v-model="opening[origin.origin_key]!.on" type="checkbox" />{{ t('propertySettlements.openingCredit') }}</label><template v-if="opening[origin.origin_key]!.on"><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertySettlements.amount') }}<input v-model="opening[origin.origin_key]!.amount" required inputmode="decimal" :class="input" /></label><SettlementEntitlementEditor :supplier="supplier" :value="opening[origin.origin_key]!.entitlement" :accounts="creditAccounts" /></template></template>
 <article v-for="allocation in current.allocations.filter(a => a.origin_key === origin.origin_key && a.active)" :key="allocation.id" class="space-y-2 border-t pt-2"><p>{{ formatDate(allocation.accounting_date) }} · {{ money(allocation.amount_cents) }} · {{ t(allocation.at_cutoff ? 'propertySettlements.paymentAtCutoff' : 'propertySettlements.latePayment') }}</p><label class="flex flex-wrap items-center gap-2"><input v-model="reliefs[allocation.id]!.on" type="checkbox" data-field="relief-enable" />{{ t('propertySettlements.releasePayment') }}</label><template v-if="reliefs[allocation.id]!.on"><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertySettlements.amount') }}<input v-model="reliefs[allocation.id]!.amount" required inputmode="decimal" :class="input" data-field="relief-amount" /></label><SettlementEntitlementEditor :supplier="supplier" :value="reliefs[allocation.id]!.entitlement" :accounts="creditAccounts" /></template></article>
 </article>
 <section v-if="hasClaim" class="space-y-3 rounded border p-3"><h3 class="font-semibold">{{ t('propertySettlements.newClaim') }} · {{ money(detail.document.amounts.new_claim_cents) }}</h3><p class="text-sm text-neutral-500">{{ t('propertySettlements.claimHelp') }}</p><SettlementEntitlementEditor :supplier="supplier" :value="claim" :accounts="claimAccounts" /><PropertyInstantInput v-model="title.effective_at" :label="t('propertySettlements.claimEffective')" required /><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertySettlements.claimTitle') }}<input v-model="title.title_ref" required :class="input" /></label><LiabilityGroupEditor v-for="(group, i) in title.liability_groups" :key="group.group_key" :supplier="supplier" :group="group" @remove="title.liability_groups.splice(i, 1)" /><PropertyButton icon="plus" @click="addGroup">{{ t('propertySettlements.addLiability') }}</PropertyButton><div class="grid gap-3 sm:grid-cols-2"><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertySettlements.legalDate') }}<DateInput :class="input" v-model="title.legal.legal_act_date" required /></label><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertySettlements.legalEvidence') }}<input v-model="title.legal.evidence" required :class="input" /></label><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertySettlements.security') }}<input v-model="title.security.evidence" required :class="input" /></label></div></section>
 </template></fieldset></form>
 <SettlementPlanView v-if="preview" :plan="preview.plan" /><ActionBar :actions="actions" />
 </section>
</template>
