<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { propertyChargeIssuanceApi, type IssuedCharge, type IssueEvidence } from '@/api/propertyChargeIssuance'
import PropertyButton from '@/components/property/PropertyButton.vue'
import PropertyInstantInput from '@/components/property/PropertyInstantInput.vue'
import PropertyPartyPicker from '@/components/property/PropertyPartyPicker.vue'
import DateInput from '@/components/ui/DateInput.vue'
import { propertyError } from '@/components/property/propertyError'
import { propertyControl as control, propertyDateInput as dateInput, propertyInput as input, propertyTextarea as textarea } from '@/components/property/propertyFormStyles'
const props = defineProps<{ supplierId: number; charge: IssuedCharge; events: IssueEvidence[]; canEdit: boolean }>()
const emit = defineEmits<{ saved: [] }>()
const { t } = useI18n()
const form = reactive({ kind: '', effective_at: '', reason: '', evidence_ref: '', selected: [] as number[], recipient_id: null as number | null,
  authority_ref: '', delivered_at: '', delivery_ref: '', assessment_scale: '', rank: '', legal_reason: '',
  parties: {} as Record<number, { regime: string; statute_as_of: string; legal_reason: string; evidence_ref: string }> })
const busy = ref(false); const error = ref(''); let token = ''; let epoch = 0
const debtors = computed(() => [...new Set(props.charge.snapshot.liability_groups.filter(g => g.role === 'debtor').flatMap(g => g.members))])
function name(id: number) { const p = props.charge.snapshot.parties.find(v => v.id === id); return p?.company_name || [p?.first_name, p?.last_name].filter(Boolean).join(' ') || t('propertyIssuance.unknownPerson') }
watch(form, () => { token = '' }, { deep: true })
watch(() => [props.supplierId, props.charge.id], () => { epoch++; token = ''; busy.value = false; form.kind = ''; form.selected = []; form.parties = {}; error.value = '' })
onBeforeUnmount(() => epoch++)
function toggle(id: number, yes: boolean) { if (yes) form.parties[id] = { regime: '', statute_as_of: '', legal_reason: '', evidence_ref: '' }; else delete form.parties[id] }
async function save() {
  if (!props.canEdit || busy.value) return
  const expected = props.events.filter(e => e.request.kind === form.kind).reduce((max, e) => Math.max(max, e.id), 0)
  let facts: Record<string, unknown>
  if (form.kind === 'reminder') facts = { recipient_id: form.recipient_id, represented_ids: form.selected, authority_ref: form.authority_ref, delivered_at: form.delivered_at, delivery_ref: form.delivery_ref }
  else if (form.kind === 'allocation_regime') facts = { parties: Object.entries(form.parties).map(([id, value]) => ({ client_id: Number(id), ...value })) }
  else if (form.kind === 'security_basis') facts = { assessment_scale: form.assessment_scale, rank: form.rank === '' ? null : Number(form.rank), legal_reason: form.legal_reason }
  else facts = { client_ids: form.selected, legal_reason: form.legal_reason }
  const request = { kind: form.kind, effective_at: form.effective_at, reason: form.reason, evidence_ref: form.evidence_ref, facts, expected_event_id: expected }
  const supplier = props.supplierId; const charge = props.charge.id; const current = epoch; token ||= crypto.randomUUID(); busy.value = true; error.value = ''
  try { await propertyChargeIssuanceApi.evidence(supplier, charge, request, token); if (current === epoch && supplier === props.supplierId && charge === props.charge.id) { token = ''; emit('saved') } }
  catch (e) { if (current === epoch) error.value = propertyError(e, t) } finally { if (current === epoch) busy.value = false }
}
</script>
<template>
  <section class="space-y-3">
    <h3 class="font-medium">{{ t('propertyIssuance.evidenceHistory') }}</h3><p>{{ t('propertyIssuance.evidenceHelp') }}</p>
    <article v-for="event in events" :key="event.id" class="rounded border p-2"><strong>{{ t(`propertyIssuance.eventKinds.${event.request.kind}`) }}</strong> · {{ event.request.effective_at }}<p>{{ event.request.reason }} · {{ event.request.evidence_ref }}</p></article>
    <p v-if="error" role="alert" class="text-danger-700">{{ error }}</p>
    <fieldset v-if="canEdit" :disabled="busy" class="space-y-3">
      <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyIssuance.eventKind') }}<select v-model="form.kind" :class="input"><option value="">{{ t('propertyIssuance.choose') }}</option><option v-for="kind in ['reminder', 'liability_assessment', 'allocation_regime', 'security_basis']" :key="kind" :value="kind">{{ t(`propertyIssuance.eventKinds.${kind}`) }}</option></select></label>
      <PropertyInstantInput v-model="form.effective_at" :label="t('propertyIssuance.effectiveAt')" />
      <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyIssuance.reason') }}<textarea v-model="form.reason" :class="textarea" /></label><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyIssuance.evidence') }}<input v-model="form.evidence_ref" :class="input"></label>
      <template v-if="form.kind === 'reminder'"><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyIssuance.deliveryRecipient') }}<PropertyPartyPicker v-model="form.recipient_id" :supplier-id="supplierId" /></label><PropertyInstantInput v-model="form.delivered_at" :label="t('propertyIssuance.deliveredAt')" /><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyIssuance.deliveryEvidence') }}<input v-model="form.delivery_ref" :class="input"></label><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyIssuance.authority') }}<input v-model="form.authority_ref" :class="input"></label></template>
      <fieldset v-if="['reminder', 'liability_assessment'].includes(form.kind)"><legend>{{ t('propertyIssuance.represented') }}</legend><div class="flex flex-wrap gap-3"><label v-for="id in debtors" :key="id" class="inline-flex items-center gap-2"><input v-model="form.selected" type="checkbox" :value="id">{{ name(id) }}</label></div></fieldset>
      <template v-if="form.kind === 'allocation_regime'"><div v-for="id in debtors" :key="id" class="space-y-2"><label class="flex gap-2"><input type="checkbox" :checked="!!form.parties[id]" @change="toggle(id, ($event.target as HTMLInputElement).checked)">{{ name(id) }}</label><template v-if="form.parties[id]"><select v-model="form.parties[id].regime" :class="input" :aria-label="t('propertyIssuance.regime')"><option value="">{{ t('propertyIssuance.choose') }}</option><option v-for="regime in ['consumer', 'general', 'other_documented']" :key="regime" :value="regime">{{ t(`propertyIssuance.regimes.${regime}`) }}</option></select><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyIssuance.statuteAsOf') }}<DateInput v-model="form.parties[id].statute_as_of" :class="[dateInput, 'sm:w-40']" /></label><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyIssuance.legalReason') }}<textarea v-model="form.parties[id].legal_reason" :class="textarea" /></label><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyIssuance.evidence') }}<input v-model="form.parties[id].evidence_ref" :class="input"></label></template></div></template>
      <template v-if="form.kind === 'security_basis'"><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyIssuance.assessmentScale') }}<input v-model="form.assessment_scale" :class="input"></label><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyIssuance.securityRank') }}<input v-model="form.rank" inputmode="numeric" :class="[control, 'w-full sm:w-36']"></label></template>
      <label v-if="['liability_assessment', 'security_basis'].includes(form.kind)" class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyIssuance.legalReason') }}<textarea v-model="form.legal_reason" :class="textarea" /></label>
      <div class="flex flex-wrap gap-2"><PropertyButton icon="check" variant="success" :disabled="!form.kind || busy" @click="save">{{ t('propertyIssuance.recordEvidence') }}</PropertyButton></div>
    </fieldset>
  </section>
</template>
