<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import DateInput from '@/components/ui/DateInput.vue'
import { formDateInput as dateInput, formInput as input, formLabel, formTextarea as textarea } from '@/components/ui/formStyles'
import { formatMoney, formatMoneyMinor } from '@/composables/useFormat'
import { frameworkConversionApi as api, type DecisionChoices, type DecisionCommand, type ConversionReview } from '@/api/frameworkConversion'
const props = defineProps<{ supplier: number; conversion: number; enabled: boolean }>()
const emit = defineEmits<{ done: [] }>()
const { t } = useI18n()
const label = (key: string) => t(`frameworkConversion.${key}`)
const date = ref(new Date().toISOString().slice(0,10)), authority = ref(''), reference = ref(''), choices = ref<DecisionChoices | null>(null), roles = ref<Record<string,string>>({})
const preview = ref<ConversionReview | null>(null), reviewed = ref<DecisionCommand | null>(null), token = ref(''), ack = ref(false), busy = ref(false), error = ref(''), done = ref(false)
let alive = true, generation = 0
onBeforeUnmount(() => { alive = false; generation++ })
function invalidate() { preview.value = null; reviewed.value = null; token.value = ''; ack.value = false }
const command = computed<DecisionCommand | null>(() => choices.value ? { decision_date: date.value, decision_authority: authority.value, decision_reference: reference.value, allocations: choices.value.cells.map(cell => ({ cell_key: cell.key, role: roles.value[cell.key] || '', amount: (Math.abs(cell.available_cents) / 100).toFixed(2) })) } : null)
watch(command, () => { generation++; busy.value = false; invalidate() }, { deep: true, flush: 'sync' })
watch(date,() => { generation++; choices.value = null; roles.value = {}; invalidate(); busy.value = false })
function message(e: unknown) { const response = (e as { response?: { status?: number; data?: { error?: { code?: string } } } }).response; if (response?.data?.error?.code === 'conversion_source_approval_required') return label('sourceApproval'); return response?.status === 409 ? label('stale') : label('error') }
async function load() {
 if (!date.value || busy.value) return
 const ticket = ++generation; busy.value = true; error.value = ''; invalidate()
 try { const result = await api.decisionChoices(props.supplier,props.conversion,date.value); if (alive && ticket === generation) { choices.value = result; roles.value = Object.fromEntries(result.cells.map(cell => [cell.key,''])) } }
 catch (e) { if (alive && ticket === generation) error.value = message(e) }
 finally { if (alive && ticket === generation) busy.value = false }
}
async function review() {
 if (!props.enabled || !command.value || busy.value) return
 const candidate = JSON.parse(JSON.stringify(command.value)) as DecisionCommand, ticket = ++generation; busy.value = true; error.value = ''; invalidate()
 try { const result = await api.decisionPreview(props.supplier,props.conversion,candidate); if (!alive || ticket !== generation) return; reviewed.value = candidate; preview.value = result; token.value = crypto.randomUUID() }
 catch (e) { if (alive && ticket === generation) error.value = message(e) }
 finally { if (alive && ticket === generation) busy.value = false }
}
async function confirm() {
 if (!props.enabled || !reviewed.value || !preview.value || !ack.value || busy.value) return
 const ticket = ++generation; busy.value = true; error.value = ''
 try { await api.decide(props.supplier,props.conversion,reviewed.value,preview.value,token.value); if (!alive || ticket !== generation) return; invalidate(); choices.value = null; done.value = true; emit('done') }
 catch (e) { if (!alive || ticket !== generation) return; if ((e as { response?: { status?: number } }).response?.status) { invalidate(); error.value = message(e) } else error.value = label('uncertain') }
 finally { if (alive && ticket === generation) busy.value = false }
}
const actions = computed<ActionItem[]>(() => preview.value ? [{ key: 'confirm-decision', label: label('confirmDecision'), icon: 'check', tier: 'primary', variant: 'success', disabled: busy.value || !props.enabled || !ack.value, run: confirm }, { key: 'cancel-decision-review', label: label('cancelReview'), icon: 'x', variant: 'neutral', run: invalidate }] : [{ key: 'preview-decision', label: label('previewDecision'), icon: 'search', tier: 'primary', variant: 'primary', disabled: busy.value || !props.enabled || !command.value, run: review }])
</script>
<template>
 <section class="space-y-4 rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm" data-section="decision">
  <h2 class="text-lg font-semibold">{{ label('decision') }}</h2><p v-if="error" role="alert" class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-600">{{ error }}</p><p v-if="done" role="status">{{ label('done') }}</p>
  <div class="flex flex-wrap items-end gap-3"><label :class="formLabel">{{ label('decisionDate') }}<DateInput :class="dateInput" v-model="date" data-field="decision-date" /></label><ActionBar :actions="[{ key: 'load-decision', label: label('loadDecision'), icon: 'search', variant: 'neutral', disabled: busy || !date, run: load }]" /></div>
  <template v-if="choices"><label :class="formLabel">{{ label('authority') }}<input v-model="authority" data-field="authority" :class="input" maxlength="1000" /></label><label :class="formLabel">{{ label('decisionReference') }}<textarea v-model="reference" data-field="decision-reference" :class="textarea" maxlength="1000" /></label>
   <div v-for="cell in choices.cells" :key="cell.key" class="flex flex-wrap items-end gap-3"><span>{{ cell.account_code }} · {{ formatMoneyMinor(cell.available_cents) }}</span><label :class="formLabel">{{ label('role') }}<select v-model="roles[cell.key]" data-field="decision-role" :class="input"><option value="">{{ label('choose') }}</option><option v-for="role in choices.target_roles" :key="role.role" :value="role.role">{{ label('retained') }}</option></select></label></div>
   <div v-if="preview" data-section="decision-preview"><p v-for="(line,index) in preview.plan.lines" :key="index">{{ line.account_code }} · {{ label(line.side) }} · {{ formatMoney(Number(line.amount)) }}</p><label class="flex gap-2"><input v-model="ack" data-field="decision-ack" type="checkbox" />{{ label('acknowledgement') }}</label></div><ActionBar :actions="actions" />
  </template>
 </section>
</template>
