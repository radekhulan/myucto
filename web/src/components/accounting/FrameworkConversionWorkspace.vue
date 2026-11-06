<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import DateInput from '@/components/ui/DateInput.vue'
import { formDateInput as dateInput, formInput as input, formLabel, formTextarea as textarea } from '@/components/ui/formStyles'
import { formatDate, formatMoney, formatMoneyMinor } from '@/composables/useFormat'
import FrameworkConversionDecision from './FrameworkConversionDecision.vue'
import { frameworkConversionApi as api, type CancellationReview, type ConversionChoices, type ConversionCommand, type ConversionDefinition, type ConversionMapping, type ConversionPage, type ConversionReview } from '@/api/frameworkConversion'
const props = defineProps<{ supplier: number }>()
const { t } = useI18n()
const label = (key: string) => t(`frameworkConversion.${key}`)
const page = ref<ConversionPage>({ periods: [], rows: [], financial_enabled: false, can_write: false })
const source = ref(''), target = ref(''), evidence = ref(''), choices = ref<ConversionChoices | null>(null)
const definitions = ref<ConversionDefinition[]>([]), mapping = ref<Record<string, ConversionMapping>>({})
const preview = ref<ConversionReview | null>(null), reviewed = ref<ConversionCommand | null>(null), token = ref(''), ack = ref(false), busy = ref(false), error = ref(''), notice = ref(''), uncertain = ref(false)
const cancellation = ref<CancellationReview | null>(null), cancellationId = ref<number | null>(null), cancellationToken = ref(''), cancellationAck = ref(false), decisionId = ref<number | null>(null)
let alive = true, generation = 0
onBeforeUnmount(() => { alive = false; generation++ })
const enabled = computed(() => page.value.financial_enabled && page.value.can_write)
const command = computed<ConversionCommand | null>(() => choices.value ? { source_period_id: Number(source.value), target_period_id: Number(target.value), evidence: evidence.value, definitions: definitions.value, mapping: mapping.value } : null)
function invalidate() { preview.value = null; reviewed.value = null; token.value = ''; ack.value = false; uncertain.value = false }
watch(command, () => { generation++; busy.value = false; invalidate() }, { deep: true, flush: 'sync' })
watch([source, target], () => { generation++; choices.value = null; definitions.value = []; mapping.value = {}; busy.value = false; invalidate() })
const boundary = computed(() => page.value.periods.find(p => Number(p.id) === Number(target.value))?.starts_on || '')
const sources = computed(() => page.value.periods.filter(p => ['closed','reviewed','approved'].includes(p.status)))
const targets = computed(() => page.value.periods.filter(p => p.status === 'open'))
function periodLabel(period: { fiscal_year: number | string; starts_on: string; ends_on: string }) {
 return `${period.fiscal_year} (${formatDate(period.starts_on)} – ${formatDate(period.ends_on)})`
}
function message(e: unknown) {
 const response = (e as { response?: { status?: number; data?: { error?: { code?: string } } } }).response
 const code = response?.data?.error?.code || ''
 if (code === 'conversion_svj_adoption_required') return label('svj')
 if (code === 'conversion_native_subledger_adoption_required') return label('subledger')
 if (code === 'conversion_source_approval_required') return label('sourceApproval')
 if (code === 'conversion_native_followup_required') return label('followup')
 if (code === 'conversion_offbalance_adoption_required') return label('offbalance')
 if (code === 'conversion_integration_required') return label('integration')
 if (['conversion_already_consumed','conversion_configuration_consumed'].includes(code)) return label('consumed')
 if (response?.status === 409) return label('stale')
 return label('error')
}
async function refresh() {
 const ticket = ++generation; busy.value = true; error.value = ''
 try { const result = await api.list(props.supplier); if (alive && ticket === generation) page.value = result }
 catch (e) { if (alive && ticket === generation) error.value = message(e) }
 finally { if (alive && ticket === generation) busy.value = false }
}
async function load() {
 if (!source.value || !target.value || busy.value) return
 const ticket = ++generation; busy.value = true; error.value = ''; invalidate()
 try { const result = await api.choices(props.supplier,Number(source.value),Number(target.value)); if (!alive || ticket !== generation) return; choices.value = result; definitions.value = structuredClone(result.definitions); mapping.value = Object.fromEntries(result.cells.map(c => [c.cell_key, { account_code: '', activity: '', activity_kind: { code: '', name: '' }, tax_regime: '' }])) }
 catch (e) { if (alive && ticket === generation) error.value = message(e) }
 finally { if (alive && ticket === generation) busy.value = false }
}
async function review() {
 if (!enabled.value || !command.value || busy.value) return
 const candidate = JSON.parse(JSON.stringify(command.value)) as ConversionCommand, ticket = ++generation; busy.value = true; error.value = ''; invalidate()
 try { const result = await api.preview(props.supplier,candidate); if (!alive || ticket !== generation) return; reviewed.value = candidate; preview.value = result; token.value = crypto.randomUUID() }
 catch (e) { if (alive && ticket === generation) error.value = message(e) }
 finally { if (alive && ticket === generation) busy.value = false }
}
async function confirm() {
 if (!enabled.value || !reviewed.value || !preview.value || !ack.value || busy.value) return
 const ticket = ++generation; busy.value = true; error.value = ''
 try { await api.confirm(props.supplier,reviewed.value,preview.value,token.value); if (!alive || ticket !== generation) return; invalidate(); notice.value = label('done'); choices.value = null; await refresh() }
 catch (e) { if (!alive || ticket !== generation) return; const status = (e as { response?: { status?: number } }).response?.status; if ([400,401,403,404,409,422].includes(status || 0)) { invalidate(); error.value = message(e) } else { uncertain.value = true; error.value = label('uncertain') } }
 finally { if (alive && ticket === generation) busy.value = false }
}
async function reviewCancellation(id: number) {
 if (!enabled.value || busy.value) return
 const ticket = ++generation; busy.value = true; error.value = ''; cancellation.value = null; cancellationAck.value = false
 try { const result = await api.cancellationPreview(props.supplier,id); if (!alive || ticket !== generation) return; cancellation.value = result; cancellationId.value = id; cancellationToken.value = crypto.randomUUID() }
 catch (e) { if (alive && ticket === generation) error.value = message(e) }
 finally { if (alive && ticket === generation) busy.value = false }
}
async function cancel() {
 if (!enabled.value || !cancellation.value || !cancellationId.value || !cancellationAck.value || busy.value) return
 const ticket = ++generation; busy.value = true; error.value = ''
 try { await api.cancel(props.supplier,cancellationId.value,cancellation.value,cancellationToken.value); if (!alive || ticket !== generation) return; cancellation.value = null; notice.value = label('done'); await refresh() }
 catch (e) { if (!alive || ticket !== generation) return; if ((e as { response?: { status?: number } }).response?.status) { cancellation.value = null; error.value = message(e) } else error.value = label('uncertain') }
 finally { if (alive && ticket === generation) busy.value = false }
}
function accountChoices(type: string, role: string) { return definitions.value.filter(d => d.definition.is_active === 1 && d.account_type === type && (role === 'pending_approval' ? d.account_code === '931' : role === 'retained' ? d.account_code === '932' : d.account_code !== '931')) }
const actions = computed<ActionItem[]>(() => preview.value ? [ { key: 'confirm', label: label('confirm'), icon: 'check', tier: 'primary', variant: 'success', disabled: busy.value || !enabled.value || !ack.value, run: confirm }, { key: 'cancel-review', label: label('cancelReview'), icon: 'x', variant: 'neutral', run: invalidate } ] : [ { key: 'preview', label: label('preview'), icon: 'search', tier: 'primary', variant: 'primary', disabled: busy.value || !enabled.value || !command.value, run: review } ])
onMounted(refresh)
</script>

<template>
  <section class="space-y-5">
    <header>
      <h1 class="text-2xl font-semibold text-neutral-900">{{ label('title') }}</h1>
      <p class="text-sm text-neutral-500">{{ label('intro') }}</p>
    </header>

    <p v-if="!page.financial_enabled" role="status" class="rounded-lg border border-warning-500/30 bg-warning-50 p-4 text-sm text-warning-600">
      {{ label('integration') }}
    </p>
    <p v-if="error" role="alert" class="rounded-lg border border-danger-500/30 bg-danger-50 p-4 text-sm text-danger-600">{{ error }}</p>
    <p v-if="notice" role="status" class="rounded-lg border border-success-500/30 bg-success-50 p-4 text-sm text-success-700">{{ notice }}</p>

    <div class="rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm">
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:flex lg:flex-wrap lg:items-end">
        <label :class="formLabel">
          {{ label('source') }}
          <select v-model="source" data-field="source" :class="input">
            <option value="">{{ label('choose') }}</option>
            <option v-for="p in sources" :key="p.id" :value="String(p.id)">{{ periodLabel(p) }}</option>
          </select>
        </label>
        <label :class="formLabel">
          {{ label('target') }}
          <select v-model="target" data-field="target" :class="input">
            <option value="">{{ label('choose') }}</option>
            <option v-for="p in targets" :key="p.id" :value="String(p.id)">{{ periodLabel(p) }}</option>
          </select>
        </label>
        <label :class="formLabel">
          {{ label('boundary') }}
          <DateInput :class="dateInput" :model-value="boundary" readonly data-field="boundary" />
        </label>
        <ActionBar :actions="[{ key: 'load', label: label('load'), icon: 'search', variant: 'neutral', disabled: busy || !source || !target, run: load }]" />
      </div>
    </div>

    <div v-if="choices" class="space-y-4">
      <label :class="formLabel">
        {{ label('evidence') }}
        <textarea v-model="evidence" data-field="evidence" required maxlength="1000" :class="textarea" />
      </label>

      <article
        v-for="cell in choices.cells"
        :key="cell.cell_key"
        :data-cell="cell.cell_key"
        class="rounded-lg border border-neutral-200 bg-surface p-4 shadow-sm sm:p-5"
      >
        <div class="flex flex-wrap items-baseline justify-between gap-2">
          <h2 class="font-semibold text-neutral-900">
            {{ cell.role === 'pending_approval' ? label('pending_approval') : cell.account_code }}
          </h2>
          <div class="text-right">
            <div class="font-mono font-semibold text-neutral-900" data-field="cell-amount">{{ formatMoneyMinor(cell.czk_cents) }}</div>
            <div v-if="cell.currency_code !== 'CZK'" class="font-mono text-xs text-neutral-500">
              {{ formatMoneyMinor(cell.foreign_cents, cell.currency_code) }}
            </div>
          </div>
        </div>
        <p class="text-sm text-neutral-600">{{ label(cell.role === 'retained' ? 'retainedRole' : cell.role) }}</p>
        <div v-if="choices.source_labels[cell.cell_key]" class="mt-2 space-y-0.5 text-sm text-neutral-600">
          <p v-if="choices.source_labels[cell.cell_key]!.cost_center">{{ label('costCenter') }}: {{ choices.source_labels[cell.cell_key]!.cost_center }}</p>
          <p v-if="choices.source_labels[cell.cell_key]!.project">{{ label('project') }}: {{ choices.source_labels[cell.cell_key]!.project }}</p>
          <p v-for="sourceLabel in choices.source_labels[cell.cell_key]!.dimensions" :key="sourceLabel">{{ sourceLabel }}</p>
        </div>
        <div v-if="mapping[cell.cell_key]" class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
          <label :class="formLabel">
            {{ label('targetAccount') }}
            <select v-model="mapping[cell.cell_key]!.account_code" data-field="account" :class="input">
              <option value="">{{ label('choose') }}</option>
              <option v-for="d in accountChoices(cell.account_type,cell.role)" :key="d.account_code" :value="d.account_code">{{ d.account_code }} {{ d.definition.name }}</option>
            </select>
          </label>
          <label :class="formLabel">
            {{ label('activity') }}
            <select v-model="mapping[cell.cell_key]!.activity" data-field="activity" :class="input">
              <option value="">{{ label('choose') }}</option>
              <option value="main">{{ label('main') }}</option>
              <option value="economic">{{ label('economic') }}</option>
            </select>
          </label>
          <label :class="formLabel">
            {{ label('tax') }}
            <select v-model="mapping[cell.cell_key]!.tax_regime" data-field="tax" :class="input">
              <option value="">{{ label('choose') }}</option>
              <option v-for="key in ['subject','out_of_scope','exempt']" :key="key" :value="key">{{ label(key) }}</option>
            </select>
          </label>
          <label :class="formLabel">
            {{ label('activityCode') }}
            <input v-model="mapping[cell.cell_key]!.activity_kind.code" data-field="kind-code" maxlength="50" :class="input" />
          </label>
          <label :class="formLabel">
            {{ label('activityName') }}
            <input v-model="mapping[cell.cell_key]!.activity_kind.name" data-field="kind-name" maxlength="100" :class="input" />
          </label>
        </div>
      </article>

      <details class="rounded-lg border border-neutral-200 bg-surface p-4 shadow-sm">
        <summary class="cursor-pointer font-semibold text-neutral-900">{{ label('definitions') }}</summary>
        <p class="my-3 text-sm text-neutral-600">{{ label('definitionHelp') }}</p>
        <article
          v-for="definition in definitions"
          :key="definition.account_code"
          class="grid grid-cols-1 gap-3 border-t border-neutral-200 py-3 sm:grid-cols-2 lg:grid-cols-4"
        >
          <div>
            <strong class="font-mono">{{ definition.account_code }}</strong>
            <p class="text-sm text-neutral-600">
              {{ label('before') }}: {{ choices.before_definitions[definition.account_code]?.name || label('newAccount') }}
              · {{ label(choices.before_definitions[definition.account_code]?.normal_side || 'both') }}
            </p>
          </div>
          <label :class="formLabel">
            {{ label('name') }}
            <input v-model="definition.definition.name" :class="input" maxlength="190" />
          </label>
          <label :class="formLabel">
            {{ label('normalSide') }}
            <select v-model="definition.definition.normal_side" :class="input">
              <option :value="null">{{ label('both') }}</option>
              <option value="debit">{{ label('debit') }}</option>
              <option value="credit">{{ label('credit') }}</option>
            </select>
          </label>
          <div class="space-y-2">
            <label class="flex items-center gap-2 text-sm text-neutral-700">
              <input v-model="definition.definition.is_active" type="checkbox" :true-value="1" :false-value="0" />
              {{ label('active') }}
            </label>
            <label :class="formLabel">
              {{ label('taxDeductibility') }}
              <select v-model="definition.definition.tax_deductibility" :class="input">
                <option value="deductible">{{ label('deductible') }}</option>
                <option value="non_deductible">{{ label('non_deductible') }}</option>
              </select>
            </label>
          </div>
        </article>
      </details>

      <div v-if="preview" data-section="preview" class="rounded-lg border border-success-500/30 bg-surface p-4 shadow-sm">
        <table class="hidden w-full text-sm md:table">
          <thead class="border-b border-neutral-200 bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ label('targetAccount') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ label('normalSide') }}</th>
              <th class="px-3 py-2 text-right font-medium">CZK</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="(line,index) in preview.plan.lines" :key="index">
              <td class="px-3 py-2 font-mono">{{ line.account_code }}</td>
              <td class="px-3 py-2">{{ label(line.side) }}</td>
              <td class="px-3 py-2 text-right font-mono">
                {{ formatMoney(Number(line.amount)) }}
                <div v-if="line.currency_code" class="text-xs text-neutral-500">{{ formatMoney(Number(line.amount_foreign), line.currency_code) }}</div>
              </td>
            </tr>
          </tbody>
        </table>
        <div class="divide-y divide-neutral-100 md:hidden">
          <div v-for="(line,index) in preview.plan.lines" :key="`m-${index}`" class="flex items-baseline justify-between gap-2 py-2">
            <div class="min-w-0">
              <span class="font-mono text-sm font-medium">{{ line.account_code }}</span>
              <span class="block text-xs text-neutral-500">{{ label(line.side) }}</span>
            </div>
            <div class="shrink-0 text-right font-mono text-sm">
              {{ formatMoney(Number(line.amount)) }}
              <div v-if="line.currency_code" class="text-xs text-neutral-500">{{ formatMoney(Number(line.amount_foreign), line.currency_code) }}</div>
            </div>
          </div>
        </div>
        <label class="mt-4 flex items-start gap-2 text-sm text-neutral-700">
          <input v-model="ack" data-field="ack" type="checkbox" class="mt-0.5" />
          {{ label('acknowledgement') }}
        </label>
      </div>

      <ActionBar :actions="actions" />
      <p v-if="uncertain" role="status" class="text-sm text-warning-600">{{ label('uncertain') }}</p>
    </div>

    <section v-if="page.rows.length" class="space-y-3">
      <h2 class="text-lg font-semibold text-neutral-900">{{ label('records') }}</h2>
      <article
        v-for="row in page.rows"
        :key="row.id"
        class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-neutral-200 bg-surface p-4 shadow-sm"
      >
        <span class="text-sm text-neutral-700">{{ formatDate(row.effective_from) }} · {{ label(row.status) }}</span>
        <ActionBar
          v-if="row.status === 'applied'"
          :actions="[
            { key: 'decision', label: label('decision'), icon: 'check', variant: 'primary', disabled: busy || !enabled, run: () => { decisionId = row.id } },
            { key: 'cancel', label: label('cancellation'), icon: 'trash', variant: 'danger', disabled: busy || !enabled, run: () => reviewCancellation(row.id) },
          ]"
        />
      </article>
    </section>

    <div v-if="cancellation" data-section="cancellation" class="space-y-3 rounded-lg border border-danger-500/30 bg-danger-50 p-4">
      <label class="flex items-start gap-2 text-sm text-neutral-700">
        <input v-model="cancellationAck" data-field="cancellation-ack" type="checkbox" class="mt-0.5" />
        {{ label('cancelAck') }}
      </label>
      <ActionBar :actions="[{ key: 'confirm-cancellation', label: label('confirmCancellation'), icon: 'trash', variant: 'danger', disabled: busy || !enabled || !cancellationAck, run: cancel }]" />
    </div>

    <FrameworkConversionDecision v-if="decisionId" :key="decisionId" :supplier="supplier" :conversion="decisionId" :enabled="enabled" @done="refresh" />
  </section>
</template>
