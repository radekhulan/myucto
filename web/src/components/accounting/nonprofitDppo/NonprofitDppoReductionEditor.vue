<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import { formInput as input, formLabel, formTextarea as textarea } from '@/components/ui/formStyles'
import { nonprofitDppoApi as api } from '@/api/nonprofitDppo'
import type { NonprofitDppoState } from '@/api/nonprofitDppoTypes'
const props = defineProps<{ supplier: number; state: NonprofitDppoState; canEdit: boolean }>()
const emit = defineEmits<{ updated: [state: NonprofitDppoState] }>()
const { t } = useI18n()
const reduction = ref(''), evidence = ref(''), busy = ref(false), uncertain = ref(false), error = ref('')
let pending: { supplier: number; year: number; rowVersion: number; nonprofit: Record<string, unknown> } | null = null, alive = true, generation = 0
const boundSupplier = ref(props.supplier)
const editable = computed(() => boundSupplier.value === props.supplier && props.canEdit && props.state.return.status === 'draft' && props.state.return.type === 'po' && props.state.podklady.accounting_decree === '504' && Boolean(props.state.nonprofit_filing_proof_hash))
function nonprofit(state: NonprofitDppoState): Record<string, unknown> { const data = state.return.inputs.nonprofit; return data && typeof data === 'object' && !Array.isArray(data) ? data as Record<string, unknown> : {} }
function stable(value: unknown): string { return JSON.stringify(value, (_key, item: unknown) => item && typeof item === 'object' && !Array.isArray(item) ? Object.fromEntries(Object.entries(item).sort(([a], [b]) => a.localeCompare(b))) : item) }
watch(() => props.supplier, () => { generation++; pending = null; uncertain.value = false; error.value = ''; busy.value = false; boundSupplier.value = 0; reduction.value = ''; evidence.value = '' })
watch(() => props.state, state => { if (uncertain.value) return; boundSupplier.value = props.supplier; const current = nonprofit(state); reduction.value = String(current.reduction_kc ?? 0); evidence.value = String(current.reduction_evidence ?? ''); pending = null }, { immediate: true })
onBeforeUnmount(() => { alive = false; generation++ })
async function save() {
 if (!editable.value || busy.value) return
 if (!uncertain.value) { const amount = Number(reduction.value); if (!/^(?:0|[1-9][0-9]*)$/.test(reduction.value) || !Number.isSafeInteger(amount) || !evidence.value.trim()) return; pending = { supplier: props.supplier, year: props.state.return.year, rowVersion: props.state.return.row_version, nonprofit: { ...JSON.parse(JSON.stringify(nonprofit(props.state))), reduction_kc: amount, reduction_evidence: evidence.value.trim() } } }
 const request = pending; if (!request || request.supplier !== props.supplier) return
 const ticket = ++generation; busy.value = true; error.value = ''
 const accept = (result: NonprofitDppoState) => { if (!alive || ticket !== generation || request.supplier !== props.supplier) return; uncertain.value = false; pending = null; emit('updated', result) }
 try { if (uncertain.value) { const current = await api.native(request.supplier, request.year); if (!alive || ticket !== generation) return; if (stable(nonprofit(current)) === stable(request.nonprofit)) { accept(current); return } if (current.return.row_version !== request.rowVersion || current.return.status !== 'draft') { uncertain.value = false; pending = null; error.value = t('nonprofitDppo.stale'); emit('updated', current); return } }
  const result = await api.saveReduction(request.supplier, request.year, request.rowVersion, request.nonprofit); accept(result)
 } catch (e) { if (!alive || ticket !== generation) return; const r = (e as { response?: { status?: number; data?: { error?: { message?: string } } } }).response; uncertain.value = !r?.status || r.status >= 500; if (!uncertain.value) pending = null; error.value = uncertain.value ? t('nonprofitDppo.reductionUncertain') : r?.data?.error?.message || (r?.status === 409 ? t('nonprofitDppo.stale') : t('nonprofitDppo.error')) }
 finally { if (alive && ticket === generation) busy.value = false }
}
const actions = computed<ActionItem[]>(() => [{ key: 'save-reduction', label: t(uncertain.value ? 'nonprofitDppo.retryReduction' : 'nonprofitDppo.saveReduction'), icon: 'check', variant: 'success', tier: 'primary', disabled: busy.value || !editable.value, run: save }])
</script>
<template>
 <section class="space-y-3 rounded border p-4">
  <h3 class="font-semibold">{{ t('nonprofitDppo.reduction') }}</h3><p class="text-sm text-neutral-500">{{ t('nonprofitDppo.reductionHelp') }}</p><p v-if="error" role="alert" class="text-danger">{{ error }}</p>
  <fieldset :disabled="!editable || busy || uncertain" class="grid gap-3 sm:grid-cols-2"><label :class="formLabel">{{ t('nonprofitDppo.reductionAmount') }}<input v-model="reduction" data-field="reduction" inputmode="numeric" :class="input" /></label><label :class="formLabel">{{ t('nonprofitDppo.reductionEvidence') }}<textarea v-model="evidence" data-field="reduction-evidence" :class="textarea" /></label></fieldset>
  <ActionBar :actions="actions" />
 </section>
</template>
