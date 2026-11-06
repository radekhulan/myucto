<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { propertyReceivablesApi as api, type LegalContext, type LiabilityGroup, type TransferRequest } from '@/api/propertyReceivables'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import PropertyButton from '@/components/property/PropertyButton.vue'
import LegalGroups from './LegalGroups.vue'
import LiabilityGroupEditor from './LiabilityGroupEditor.vue'
import { usePreviewFlow } from '../operations/flows'
import { inputClass } from '../operations/ui'
const props = defineProps<{ supplier: number; context: LegalContext }>()
const emit = defineEmits<{ saved: []; stale: [] }>()
const { t } = useI18n(); const form = ref<HTMLFormElement | null>(null)
const decision = ref<TransferRequest['decision']>('review_required'); const reason = ref('')
const acquisition = reactive({ kind: '', applicable_law: '', legal_basis: '', evidence: '', had_and_could_discover: false, knowledge_evidence: '', guarantee_evidence: '' })
const fields = ['kind', 'applicable_law', 'legal_basis', 'evidence', 'knowledge_evidence', 'guarantee_evidence'] as const
const selected = ref<number[]>([]); const groups = reactive<Record<number, LiabilityGroup[]>>({})
const claims = computed(() => props.context.projection.claims.filter(c => c.debt_id !== null))
function copyGroup(g: LiabilityGroup): LiabilityGroup { const { remaining_cap_cents: ignored, ...copy } = g; void ignored; return JSON.parse(JSON.stringify(copy)) }
function select(id: number, checked: boolean) { if (checked) { selected.value.push(id); groups[id] = JSON.parse(JSON.stringify(claims.value.find(c => c.debt_id === id)!.groups.map(copyGroup))) } else selected.value = selected.value.filter(v => v !== id) }
function add(id: number) { (groups[id] ??= []).push({ group_key: `group.${crypto.randomUUID()}`, role: 'debtor', mode: 'individual', basis: 'documented_title', members: [0], extent: { num: '1', den: '1' }, evidence_ref: '', legal_reason: '', valid_from: props.context.as_of, valid_to: null }) }
function build(): TransferRequest { return JSON.parse(JSON.stringify({ unit_id: props.context.unit.id, registry_revision_id: props.context.unit.revision?.id, effective_at: props.context.as_of, decision: decision.value, reason: reason.value, acquisition, debts: selected.value.map(id => ({ debt_id: id, expected_event_id: claims.value.find(c => c.debt_id === id)!.liability.head_event_id, groups: groups[id] })) })) }
const flow = usePreviewFlow({ supplier: () => props.supplier, validate: () => !!form.value?.reportValidity() && selected.value.length > 0 && selected.value.every(id => groups[id]?.every(g => g.members.length > 0 && g.members.every(id => id > 0))), canPreview: () => props.context.capabilities.manage && !!props.context.unit.revision, canConfirm: () => props.context.capabilities.confirm, build,
  preview: api.transferPreview, confirm: async (supplier, request, hash, token) => { try { return await api.transferConfirm(supplier, request, hash, token) } catch (e) { if ((e as { response?: { status: number } }).response?.status === 409) { flow.invalidate(); emit('stale') } throw e } }, onDone: () => emit('saved') })
watch([decision, reason, acquisition, selected, groups], flow.invalidate, { deep: true, flush: 'sync' })
watch(() => props.context, () => { flow.reset(); selected.value = []; reason.value = '' })
const actions = computed<ActionItem[]>(() => [{ key: 'transfer-preview', label: t('propertySaldo.preview'), icon: 'search', variant: 'primary', tier: 'primary', disabled: !props.context.capabilities.manage || flow.busy.value || selected.value.length === 0, run: flow.showPreview }, { key: 'transfer-confirm', label: t('propertySaldo.confirmTransfer'), icon: 'check', variant: 'success', tier: 'secondary', disabled: !props.context.capabilities.confirm || !flow.preview.value || flow.busy.value, run: flow.confirm }])
</script>
<template>
  <form ref="form" class="space-y-3" data-section="transfer-form" @submit.prevent="flow.showPreview">
    <h3 class="font-semibold">{{ t('propertySaldo.transfer') }}</h3><p class="text-sm">{{ t('propertySaldo.transferHelp') }}</p>
    <p>{{ t('propertySaldo.effectiveAt') }}: {{ context.as_of }}</p>
    <fieldset :disabled="flow.busy.value || !context.capabilities.manage" class="space-y-3">
      <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertySaldo.decision') }}<select v-model="decision" :class="inputClass"><option v-for="v in ['transfer_confirmed', 'transfer_not_applicable', 'review_required', 'superseding_correction']" :key="v" :value="v">{{ t(`propertySaldo.decisions.${v}`) }}</option></select></label>
      <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.reason') }}<input v-model="reason" required maxlength="4000" :class="inputClass" data-field="transfer-reason" /></label>
      <div class="grid gap-3 sm:grid-cols-2"><label class="flex min-w-0 flex-col gap-1 text-sm" v-for="key in fields" :key="key">{{ t(`propertySaldo.acquisition.${key}`) }}<input v-model="acquisition[key]" required maxlength="4000" :class="inputClass" :data-field="key" /></label></div>
      <label class="flex items-center gap-2"><input v-model="acquisition.had_and_could_discover" type="checkbox" :required="decision === 'transfer_confirmed'" />{{ t('propertySaldo.discoverable') }}</label>
      <article v-for="c in claims" :key="c.debt_id!" class="space-y-2 rounded border border-neutral-200 p-3"><label class="flex items-center gap-2"><input type="checkbox" :checked="selected.includes(c.debt_id!)" :data-select-debt="c.debt_id" @change="select(c.debt_id!, ($event.target as HTMLInputElement).checked)" />{{ c.title_ref }}</label><LegalGroups :groups="c.groups" :parties="context.parties" /><div v-if="selected.includes(c.debt_id!)" class="space-y-3"><h4>{{ t('propertySaldo.proposedGroups') }}</h4><LiabilityGroupEditor v-for="(g, index) in groups[c.debt_id!]" :key="index" :supplier="supplier" :group="g" @remove="groups[c.debt_id!]!.splice(index, 1)" /><PropertyButton icon="plus" @click="add(c.debt_id!)">{{ t('propertySaldo.addGroup') }}</PropertyButton></div></article>
    </fieldset>
    <p v-if="flow.error.value" role="alert" class="text-danger-600">{{ flow.error.value }}</p>
    <div v-if="flow.preview.value" data-section="transfer-preview" class="space-y-3 rounded border border-warning-300 p-3"><h4>{{ t('propertySaldo.reviewPreview') }}</h4><article v-for="d in flow.preview.value.snapshot.debts" :key="d.debt_id"><p>{{ t('propertySaldo.previousGroups') }}</p><LegalGroups :groups="d.previous_liability.groups" :parties="context.parties" /><p>{{ t('propertySaldo.proposedGroups') }}</p><LegalGroups :groups="d.groups" :parties="flow.preview.value.snapshot.parties" /></article></div>
    <ActionBar :actions="actions" />
  </form>
</template>
