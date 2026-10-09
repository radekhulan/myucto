<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import DimensionPicker from './DimensionPicker.vue'
import Modal from '@/components/ui/Modal.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { ICONS, btnFilled, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'
import {
  dimensionsApi,
  type DimensionCoverageRow, type DimensionRule, type DimensionRuleAudit, type DimensionRuleEnforcement, type DimensionRulePayload,
} from '@/api/dimensions'
import { useDimensions } from '@/composables/useDimensions'
import { useToast } from '@/composables/useToast'
import { useSupplierStore } from '@/stores/supplier'
import { formatDate, formatMoney } from '@/composables/useFormat'

/**
 * Firma → Dimenze → Pravidla: povinné dimenze podle účtu, výchozí hodnota pro
 * rozsah účtů (i vozidlo podle platební karty) a zpětná kontrola deníku.
 */
const props = defineProps<{ canWrite: boolean }>()

const { t } = useI18n()
const toast = useToast()
const dims = useDimensions()
// Daňová evidence: pravidla se uplatní na pohyby peněžního deníku (výdaj = 5, příjem = 6).
const isTaxEvidence = computed(() => useSupplierStore().currentSupplier?.accounting_mode === 'tax_evidence')

const ENFORCEMENTS: DimensionRuleEnforcement[] = ['error', 'warning', 'none']

const rules = ref<DimensionRule[]>([])
const loading = ref(true)
const failed = ref(false)
const busy = ref(false)

async function load() {
  loading.value = true
  failed.value = false
  try {
    await dims.load()
    rules.value = await dimensionsApi.listRules()
  } catch {
    failed.value = true
  } finally {
    loading.value = false
  }
}
onMounted(load)

function errorMessage(e: any): string {
  return e?.response?.data?.error?.message || t('common.error')
}

// ── formulář ─────────────────────────────────────────────────────────────
const formOpen = ref(false)
const form = reactive({
  id: null as number | null,
  dimension_type_id: 0,
  account_mask: '',
  enforcement: 'error' as DimensionRuleEnforcement,
  default_value_id: null as number | null,
  default_from_card: false,
  valid_from: '',
  valid_to: '',
  is_active: true,
  note: '',
})
const activeTypes = computed(() => dims.types.value.filter(ty => ty.is_active))
const formType = computed(() => dims.typeById.value.get(form.dimension_type_id) ?? null)

function openForm(rule?: DimensionRule, preset?: { type_id: number; mask: string }) {
  form.id = rule?.id ?? null
  form.dimension_type_id = rule?.dimension_type_id ?? preset?.type_id ?? activeTypes.value[0]?.id ?? 0
  form.account_mask = rule?.account_mask ?? preset?.mask ?? ''
  form.enforcement = rule?.enforcement ?? 'error'
  form.default_value_id = rule?.default_value_id ?? null
  form.default_from_card = rule?.default_from_card ?? false
  form.valid_from = rule?.valid_from ?? ''
  form.valid_to = rule?.valid_to ?? ''
  form.is_active = rule?.is_active ?? true
  form.note = rule?.note ?? ''
  formOpen.value = true
}

function onTypeChange() {
  form.default_value_id = null
  if (formType.value?.kind !== 'vehicle') form.default_from_card = false
}

async function save() {
  busy.value = true
  const payload: DimensionRulePayload = {
    dimension_type_id: form.dimension_type_id,
    account_mask: form.account_mask,
    enforcement: form.enforcement,
    default_value_id: form.default_value_id,
    default_from_card: form.default_from_card,
    valid_from: form.valid_from || null,
    valid_to: form.valid_to || null,
    is_active: form.is_active,
    note: form.note.trim() || null,
  }
  try {
    if (form.id === null) {
      await dimensionsApi.createRule(payload)
    } else {
      await dimensionsApi.updateRule(form.id, payload)
    }
    toast.success(t('dimensions.rules.saved'))
    formOpen.value = false
    await load()
  } catch (e) {
    toast.error(errorMessage(e))
  } finally {
    busy.value = false
  }
}

async function remove(rule: DimensionRule) {
  if (!confirm(t('dimensions.rules.delete_confirm', { mask: rule.account_mask, type: rule.type_name }))) return
  busy.value = true
  try {
    await dimensionsApi.deleteRule(rule.id)
    toast.success(t('dimensions.rules.deleted'))
    await load()
  } catch (e) {
    toast.error(errorMessage(e))
  } finally {
    busy.value = false
  }
}

function defaultLabel(rule: DimensionRule): string {
  const parts: string[] = []
  if (rule.default_from_card) parts.push(t('dimensions.rules.from_card_short'))
  if (rule.default_value_id) {
    parts.push(dims.valueLabel(rule.default_value_id) || rule.default_value_name || '')
  }
  return parts.length ? parts.join(', ') : '—'
}

function validity(rule: DimensionRule): string {
  if (!rule.valid_from && !rule.valid_to) return t('dimensions.rules.always')
  return `${rule.valid_from ? formatDate(rule.valid_from) : '…'} – ${rule.valid_to ? formatDate(rule.valid_to) : '…'}`
}

const badge: Record<DimensionRuleEnforcement, string> = {
  error: 'bg-danger-50 text-danger-700',
  warning: 'bg-warning-50 text-warning-800',
  none: 'bg-neutral-100 text-neutral-600',
}

// ── kontrola deníku a pokrytí ───────────────────────────────────────────────
const year = new Date().getFullYear()
const range = reactive({ date_from: `${year}-01-01`, date_to: `${year}-12-31` })
const audit = ref<DimensionRuleAudit | null>(null)
const coverage = ref<DimensionCoverageRow[] | null>(null)
const checking = ref(false)

async function runAudit() {
  checking.value = true
  try {
    audit.value = await dimensionsApi.auditRules({ ...range })
  } catch (e) {
    toast.error(errorMessage(e))
  } finally {
    checking.value = false
  }
}

async function runCoverage() {
  checking.value = true
  try {
    coverage.value = await dimensionsApi.ruleCoverage({ ...range })
  } catch (e) {
    toast.error(errorMessage(e))
  } finally {
    checking.value = false
  }
}

function percent(ratio: number): string {
  return `${Math.round(ratio * 1000) / 10} %`
}
</script>

<template>
  <div class="space-y-4" data-test="dimension-rules">
    <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
      <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 border-b border-neutral-200">
        <div>
          <h2 class="text-lg font-semibold">{{ t('dimensions.rules.title') }}</h2>
          <p class="text-xs text-neutral-500 max-w-3xl">{{ t('dimensions.rules.hint') }}</p>
          <p v-if="isTaxEvidence" class="text-xs text-primary-800 max-w-3xl mt-1" data-test="rules-tax-evidence">{{ t('dimensions.rules.tax_evidence_hint') }}</p>
        </div>
        <button v-if="props.canWrite" type="button" :disabled="busy || activeTypes.length === 0" :class="btnFilled('primary')" class="whitespace-nowrap" data-test="rule-new" @click="openForm()">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
          {{ t('dimensions.rules.new') }}
        </button>
      </div>
      <div v-if="loading" class="py-6 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>
      <EmptyState v-else-if="failed" variant="failed" @action="load" />
      <p v-else-if="rules.length === 0" class="px-4 py-6 text-sm text-neutral-500">{{ t('dimensions.rules.empty') }}</p>
      <template v-else>
        <table class="w-full text-sm hidden md:table">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide border-b border-neutral-200">
            <tr>
              <th class="text-left font-medium px-4 py-2">{{ t('dimensions.rules.type') }}</th>
              <th class="text-left font-medium px-4 py-2">{{ t('dimensions.rules.mask') }}</th>
              <th class="text-left font-medium px-4 py-2">{{ t('dimensions.rules.enforcement') }}</th>
              <th class="text-left font-medium px-4 py-2">{{ t('dimensions.rules.default') }}</th>
              <th class="text-left font-medium px-4 py-2">{{ t('dimensions.rules.validity') }}</th>
              <th class="px-4 py-2" />
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="rule in rules" :key="rule.id" :class="{ 'opacity-60': !rule.is_active }" data-test="rule-row">
              <td class="px-4 py-2 font-medium">{{ rule.type_name }}</td>
              <td class="px-4 py-2 font-mono">{{ rule.account_mask }}</td>
              <td class="px-4 py-2">
                <span class="rounded px-1.5 py-0.5 text-xs whitespace-nowrap" :class="badge[rule.enforcement]">{{ t(`dimensions.rules.enforcement_${rule.enforcement}`) }}</span>
              </td>
              <td class="px-4 py-2">{{ defaultLabel(rule) }}</td>
              <td class="px-4 py-2 whitespace-nowrap">{{ validity(rule) }}</td>
              <td class="px-4 py-2">
                <div v-if="props.canWrite" class="flex flex-wrap justify-end gap-1">
                  <button type="button" :class="btnOutlineSm('neutral')" :title="t('common.edit')" :aria-label="t('common.edit')" @click="openForm(rule)">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
                  </button>
                  <button type="button" :class="btnOutlineSm('danger')" :title="t('common.delete')" :aria-label="t('common.delete')" @click="remove(rule)">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
        <div class="md:hidden divide-y divide-neutral-100">
          <div v-for="rule in rules" :key="`m-${rule.id}`" class="px-4 py-3 space-y-1" :class="{ 'opacity-60': !rule.is_active }">
            <div class="flex flex-wrap items-center gap-2">
              <span class="font-medium">{{ rule.type_name }}</span>
              <span class="font-mono text-sm">{{ rule.account_mask }}</span>
              <span class="rounded px-1.5 py-0.5 text-xs" :class="badge[rule.enforcement]">{{ t(`dimensions.rules.enforcement_${rule.enforcement}`) }}</span>
            </div>
            <div class="text-xs text-neutral-500">{{ t('dimensions.rules.default') }}: {{ defaultLabel(rule) }} · {{ validity(rule) }}</div>
            <div v-if="props.canWrite" class="flex flex-wrap gap-2 pt-1">
              <button type="button" :class="btnOutlineSm('neutral')" class="whitespace-nowrap" @click="openForm(rule)">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
                {{ t('common.edit') }}
              </button>
              <button type="button" :class="btnOutlineSm('danger')" class="whitespace-nowrap" @click="remove(rule)">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                {{ t('common.delete') }}
              </button>
            </div>
          </div>
        </div>
      </template>
    </section>

    <!-- Kontrola deníku -->
    <section class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 space-y-3" data-test="rule-audit">
      <div>
        <h2 class="text-lg font-semibold">{{ t('dimensions.rules.audit_title') }}</h2>
        <p class="text-xs text-neutral-500 max-w-3xl">{{ t('dimensions.rules.audit_hint') }}</p>
      </div>
      <div class="flex flex-wrap items-end gap-2">
        <label class="block">
          <span class="block text-xs text-neutral-500 mb-1">{{ t('dimensions.rules.date_from') }}</span>
          <input v-model="range.date_from" type="date" class="h-10 px-2 border border-neutral-300 rounded-md text-sm" />
        </label>
        <label class="block">
          <span class="block text-xs text-neutral-500 mb-1">{{ t('dimensions.rules.date_to') }}</span>
          <input v-model="range.date_to" type="date" class="h-10 px-2 border border-neutral-300 rounded-md text-sm" />
        </label>
        <button type="button" :disabled="checking" :class="btnFilled('warning')" class="whitespace-nowrap" data-test="rule-audit-run" @click="runAudit">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.clipboardCheck" /></svg>
          {{ t('dimensions.rules.audit_run') }}
        </button>
        <button type="button" :disabled="checking" :class="btnOutline('neutral')" class="whitespace-nowrap" data-test="rule-coverage-run" @click="runCoverage">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chart" /></svg>
          {{ t('dimensions.rules.coverage_run') }}
        </button>
      </div>

      <template v-if="audit">
        <p class="text-sm" :class="audit.total === 0 ? 'text-success-700' : 'text-warning-800'" data-test="rule-audit-total">
          {{ audit.total === 0 ? t('dimensions.rules.audit_ok') : t('dimensions.rules.audit_found', { count: audit.total }) }}
        </p>
        <div v-if="audit.summary.length" class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="text-xs text-neutral-500 uppercase tracking-wide border-b border-neutral-200">
              <tr>
                <th class="text-left font-medium py-1 pr-3">{{ t('dimensions.rules.type') }}</th>
                <th class="text-left font-medium py-1 pr-3">{{ t('dimensions.rules.synthetic') }}</th>
                <th class="text-left font-medium py-1 pr-3">{{ t('dimensions.rules.source') }}</th>
                <th class="text-left font-medium py-1 pr-3">{{ t('dimensions.rules.enforcement') }}</th>
                <th class="text-right font-medium py-1 pr-3">{{ t('dimensions.rules.lines') }}</th>
                <th class="text-right font-medium py-1">{{ t('dimensions.rules.amount') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="(s, i) in audit.summary" :key="i">
                <td class="py-1 pr-3">{{ s.type_name }}</td>
                <td class="py-1 pr-3 font-mono">{{ s.synthetic }}</td>
                <td class="py-1 pr-3 text-neutral-600">{{ s.source_type }}</td>
                <td class="py-1 pr-3"><span class="rounded px-1.5 py-0.5 text-xs" :class="badge[s.enforcement]">{{ t(`dimensions.rules.enforcement_${s.enforcement}`) }}</span></td>
                <td class="py-1 pr-3 text-right font-mono">{{ s.lines }}</td>
                <td class="py-1 text-right font-mono">{{ formatMoney(s.amount) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="audit.rows.length" class="overflow-x-auto">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-1">{{ t('dimensions.rules.audit_rows', { count: audit.rows.length }) }}</h3>
          <table class="w-full text-sm">
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="(r, i) in audit.rows" :key="`${r.line_id}-${r.type_id}-${i}`">
                <td class="py-1 pr-3 whitespace-nowrap">{{ formatDate(r.entry_date) }}</td>
                <td class="py-1 pr-3">
                  <RouterLink v-if="r.entry_id" :to="{ path: '/accounting/journal', query: { entry_id: r.entry_id } }" class="text-primary-600 hover:underline">
                    {{ r.document_no || `#${r.entry_id}` }}
                  </RouterLink>
                  <span v-else>{{ r.document_no || `#${r.source_id}` }}</span>
                </td>
                <td class="py-1 pr-3"><span class="font-mono">{{ r.account_code }}</span> <span class="text-neutral-500">{{ r.account_name }}</span></td>
                <td class="py-1 pr-3">{{ r.type_name }}</td>
                <td class="py-1 text-right font-mono whitespace-nowrap">{{ formatMoney(r.amount) }}</td>
              </tr>
            </tbody>
          </table>
          <p v-if="audit.truncated" class="text-xs text-neutral-500 mt-1">{{ t('dimensions.rules.audit_truncated') }}</p>
        </div>
      </template>

      <div v-if="coverage" class="overflow-x-auto" data-test="rule-coverage">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-1">{{ t('dimensions.rules.coverage_title') }}</h3>
        <p v-if="coverage.length === 0" class="text-sm text-neutral-500">{{ t('dimensions.rules.coverage_empty') }}</p>
        <table v-else class="w-full text-sm">
          <thead class="text-xs text-neutral-500 uppercase tracking-wide border-b border-neutral-200">
            <tr>
              <th class="text-left font-medium py-1 pr-3">{{ t('dimensions.rules.type') }}</th>
              <th class="text-left font-medium py-1 pr-3">{{ t('dimensions.rules.synthetic') }}</th>
              <th class="text-right font-medium py-1 pr-3">{{ t('dimensions.rules.lines') }}</th>
              <th class="text-right font-medium py-1 pr-3">{{ t('dimensions.rules.covered') }}</th>
              <th class="py-1" />
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="c in coverage" :key="`${c.type_id}-${c.synthetic}`">
              <td class="py-1 pr-3">{{ c.type_name }}</td>
              <td class="py-1 pr-3 font-mono">{{ c.synthetic }}</td>
              <td class="py-1 pr-3 text-right font-mono">{{ c.lines }}</td>
              <td class="py-1 pr-3 text-right font-mono">{{ c.covered }} ({{ percent(c.ratio) }})</td>
              <td class="py-1 text-right">
                <button v-if="props.canWrite && c.suggested" type="button" :class="btnOutlineSm('primary')" class="whitespace-nowrap" @click="openForm(undefined, { type_id: c.type_id, mask: c.synthetic })">
                  <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
                  {{ t('dimensions.rules.suggest_create') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <Modal v-if="formOpen && props.canWrite" :title="form.id === null ? t('dimensions.rules.new') : t('dimensions.rules.edit')" width-class="max-w-2xl" @close="formOpen = false">
      <div class="space-y-4" data-test="rule-form">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <label class="block">
            <span class="block text-sm font-medium text-neutral-700 mb-1">{{ t('dimensions.rules.type') }}</span>
            <select v-model.number="form.dimension_type_id" class="w-full h-10 px-2 border border-neutral-300 rounded-md text-sm bg-surface" data-test="rule-type" @change="onTypeChange">
              <option v-for="ty in activeTypes" :key="ty.id" :value="ty.id">{{ ty.name }}</option>
            </select>
          </label>
          <label class="block">
            <span class="block text-sm font-medium text-neutral-700 mb-1">{{ t('dimensions.rules.mask') }}</span>
            <input v-model="form.account_mask" type="text" maxlength="190" placeholder="5, 6, !59" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" data-test="rule-mask" />
            <span class="block text-xs text-neutral-400 mt-1">{{ t('dimensions.rules.mask_hint') }}</span>
          </label>
          <label class="block">
            <span class="block text-sm font-medium text-neutral-700 mb-1">{{ t('dimensions.rules.enforcement') }}</span>
            <select v-model="form.enforcement" class="w-full h-10 px-2 border border-neutral-300 rounded-md text-sm bg-surface" data-test="rule-enforcement">
              <option v-for="e in ENFORCEMENTS" :key="e" :value="e">{{ t(`dimensions.rules.enforcement_${e}_long`) }}</option>
            </select>
          </label>
          <div>
            <span class="block text-sm font-medium text-neutral-700 mb-1">{{ t('dimensions.rules.default') }}</span>
            <DimensionPicker v-if="form.dimension_type_id" v-model="form.default_value_id" :type-id="form.dimension_type_id" teleport />
            <span class="block text-xs text-neutral-400 mt-1">{{ t('dimensions.rules.default_hint') }}</span>
          </div>
          <label class="block">
            <span class="block text-sm font-medium text-neutral-700 mb-1">{{ t('dimensions.rules.valid_from') }}</span>
            <input v-model="form.valid_from" type="date" class="w-full h-10 px-2 border border-neutral-300 rounded-md text-sm" />
          </label>
          <label class="block">
            <span class="block text-sm font-medium text-neutral-700 mb-1">{{ t('dimensions.rules.valid_to') }}</span>
            <input v-model="form.valid_to" type="date" class="w-full h-10 px-2 border border-neutral-300 rounded-md text-sm" />
          </label>
        </div>
        <label v-if="formType?.kind === 'vehicle'" class="flex items-center gap-2 text-sm text-neutral-700">
          <input v-model="form.default_from_card" type="checkbox" class="rounded border-neutral-300" />
          {{ t('dimensions.rules.from_card') }}
        </label>
        <label class="flex items-center gap-2 text-sm text-neutral-700">
          <input v-model="form.is_active" type="checkbox" class="rounded border-neutral-300" />
          {{ t('dimensions.rules.active') }}
        </label>
        <label class="block">
          <span class="block text-sm font-medium text-neutral-700 mb-1">{{ t('dimensions.note') }}</span>
          <input v-model="form.note" type="text" maxlength="500" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
        </label>
      </div>
      <template #footer>
        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="formOpen = false">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <button type="button" :disabled="busy || !form.account_mask.trim() || !form.dimension_type_id" :class="btnFilled('primary')" class="whitespace-nowrap" data-test="rule-save" @click="save">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ t('common.save') }}
          </button>
        </div>
      </template>
    </Modal>
  </div>
</template>
