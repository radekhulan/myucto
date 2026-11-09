<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Modal from '@/components/ui/Modal.vue'
import RowActionsMenu, { type RowAction } from '@/components/ui/RowActionsMenu.vue'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import { numberSeriesApi, SELECTABLE_KINDS, type NumberSeries, type NumberSeriesCompleteness, type NumberSeriesKind, type NumberSeriesPeriod } from '@/api/numberSeries'
import { cashApi, type CashRegister } from '@/api/cash'
import { numberSeriesLabel } from '@/utils/numberSeries'
import { normalizeInputBrackets, numberTemplateMessage, validateNumberTemplate, type NumberTemplateOptions } from '@/utils/numberTemplate'
import { renderVarsymbolTemplate } from '@/utils/varsymbol'
import { formatDate } from '@/composables/useFormat'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { useToast } from '@/composables/useToast'

/**
 * Číselník řad firmy (Nastavení → Číselné řady): všechny řady všech druhů dokladů,
 * převedené i nové. Nová řada, výchozí řada druhu a pokladny, moje výchozí řada,
 * ukončení řady a kontrola úplnosti po řadách.
 */
const { t } = useI18n()
const auth = useAuthStore()
const supplierStore = useSupplierStore()
const toast = useToast()

const rows = ref<NumberSeries[]>([])
const registers = ref<CashRegister[]>([])
const loading = ref(false)
const canWrite = computed(() => auth.canWrite('settings.company'))
const isDoubleEntry = computed(() => supplierStore.currentSupplier?.accounting_mode === 'double_entry')
const creatableKinds = computed(() => SELECTABLE_KINDS.filter(k => k !== 'manual' || isDoubleEntry.value))

const GROUP_ORDER = ['issued', 'purchase', 'cash', 'accounting', 'stock', 'closing', 'bank']
const groups = computed(() => GROUP_ORDER
  .map(g => ({ group: g, rows: rows.value.filter(r => r.group === g) }))
  .filter(g => g.rows.length > 0))

async function load() {
  loading.value = true
  try {
    const [series, regs] = await Promise.all([numberSeriesApi.list(), cashApi.listRegisters().catch(() => [])])
    rows.value = series
    registers.value = regs
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    loading.value = false
  }
}
onMounted(load)

function label(s: NumberSeries): string {
  return numberSeriesLabel(t, s)
}

function templateShown(s: NumberSeries): string {
  return s.template.replace('{PREFIX}', s.prefix ?? '')
}

const today = new Date().toISOString().slice(0, 10)
const isEnded = (s: NumberSeries) => s.valid_to !== null && s.valid_to < today

// ── formulář řady ─────────────────────────────────────────────────────────────
const formOpen = ref(false)
const editing = ref<NumberSeries | null>(null)
const saving = ref(false)
const form = reactive({
  doc_kind: 'invoice' as NumberSeriesKind,
  scope_id: 0,
  name: '',
  prefix: '',
  template: '{PREFIX}{YY}{CCCC}',
  period: 'year' as NumberSeriesPeriod,
  next_number: '' as string | number,
  valid_to: '',
  is_default: false,
})

function openCreate() {
  editing.value = null
  Object.assign(form, {
    doc_kind: 'invoice', scope_id: 0, name: '', prefix: '', template: '{PREFIX}{YY}{CCCC}',
    period: 'year', next_number: '', valid_to: '', is_default: false,
  })
  formOpen.value = true
}

function openEdit(s: NumberSeries) {
  editing.value = s
  Object.assign(form, {
    doc_kind: s.doc_kind, scope_id: s.scope_type === 'cash_register' ? s.scope_id : 0,
    name: s.name, prefix: s.prefix ?? '', template: s.template, period: s.period ?? 'year',
    next_number: s.can_set_next && s.next_number !== null ? s.next_number : '',
    valid_to: s.valid_to ?? '', is_default: s.is_default,
  })
  formOpen.value = true
}

const isRegisterKind = computed(() => form.doc_kind === 'cash_in' || form.doc_kind === 'cash_out')

/** Pravidla tvaru čísla stejná jako na backendu: {PREFIX} jen v řadě, {PP} u přijatých dokladů, období řady. */
const templateOptions = computed<NumberTemplateOptions>(() => ({
  allowPrefix: true,
  allowPp: form.doc_kind === 'purchase',
  period: form.period,
}))

/** Stav šablony rozeditované řady (chyba, náhled čísla pro dnešek a zadaný čítač). */
const templateState = computed(() => {
  const raw = form.template.trim()
  if (raw === '') return { error: t('number_template.errors.length'), warning: '', preview: '', invalid: true }
  const { period, ...strict } = templateOptions.value
  const errors = validateNumberTemplate(raw, templateOptions.value)
  if (errors.length > 0) {
    return { error: numberTemplateMessage(t, errors[0], strict), warning: '', preview: '', invalid: true }
  }
  const n = Math.max(1, Number(form.next_number) || 1)
  const preview = renderVarsymbolTemplate(raw.replaceAll('{PREFIX}', form.prefix.trim().toUpperCase()), new Date(), n)
  return { error: '', warning: '', preview, invalid: false }
})
const templateEditable = computed(() => !editing.value || editing.value.can_edit_template)
const canSaveForm = computed(() => !templateEditable.value || !templateState.value.invalid)

function onTemplateInput(e: Event) {
  form.template = normalizeInputBrackets(e)
}

async function saveForm() {
  saving.value = true
  try {
    const nextNumber = String(form.next_number).trim() === '' ? null : Number(form.next_number)
    let saved: NumberSeries
    if (editing.value === null) {
      saved = await numberSeriesApi.create({
        doc_kind: form.doc_kind,
        scope_id: isRegisterKind.value ? form.scope_id : 0,
        name: form.name,
        prefix: form.prefix.trim().toUpperCase() || null,
        template: form.template.trim(),
        period: form.period,
        next_number: nextNumber,
        valid_to: form.valid_to || null,
        is_default: form.is_default,
      })
    } else {
      const s = editing.value
      const patch: Record<string, unknown> = { name: form.name, valid_to: form.valid_to || null }
      if (s.can_edit_template) {
        patch.prefix = form.prefix.trim().toUpperCase() || null
        patch.template = form.template.trim()
        patch.period = form.period
      }
      // Čítač je živá hodnota: posílá se jen změněný, jinak by přepsal čísla vydaná mezitím.
      if (s.can_set_next && nextNumber !== null && nextNumber !== s.next_number) patch.next_number = nextNumber
      saved = await numberSeriesApi.update(s.id, patch)
    }
    formOpen.value = false
    toast.success(t('common.saved'))
    // Řady vydaných dokladů se stejnými číslicemi: platné, ale bankovní párování je nerozliší.
    if (saved.warnings?.length) toast.warning(t('number_series.digit_overlap_warning'))
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    saving.value = false
  }
}

// ── ukončení řady ─────────────────────────────────────────────────────────────
const endDialog = reactive({ open: false, series: null as NumberSeries | null, date: '', saving: false })

function openEnd(s: NumberSeries) {
  Object.assign(endDialog, { open: true, series: s, date: today, saving: false })
}

async function submitEnd() {
  const s = endDialog.series
  if (!s || !endDialog.date) return
  endDialog.saving = true
  try {
    await numberSeriesApi.update(s.id, { valid_to: endDialog.date })
    endDialog.open = false
    toast.success(t('number_series.ended'))
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    endDialog.saving = false
  }
}

// ── řádkové akce ──────────────────────────────────────────────────────────────
async function run(fn: () => Promise<unknown>, success: string) {
  try {
    await fn()
    toast.success(success)
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

function userScope(s: NumberSeries) {
  switch (s.doc_kind) {
    case 'invoice': case 'proforma': case 'credit_note':
      return { module: 'invoices' as const, type: s.doc_kind }
    case 'purchase': return { module: 'purchase-invoices' as const }
    case 'cash_in': case 'cash_out':
      return { module: 'cash-documents' as const, docType: s.doc_kind === 'cash_in' ? 'in' as const : 'out' as const,
        registerId: s.scope_type === 'cash_register' ? s.scope_id : 0 }
    default: return { module: 'internal-documents' as const }
  }
}

function rowActions(s: NumberSeries): RowAction[] {
  return [
    { key: 'edit', label: t('common.edit'), icon: 'edit', variant: 'primary', show: canWrite.value, run: () => openEdit(s) },
    {
      key: 'default', label: t('number_series.action_default'), icon: 'check', variant: 'success',
      show: canWrite.value && s.selectable && !s.is_default && !isEnded(s),
      run: () => run(() => numberSeriesApi.setDefault(s.id), t('number_series.default_saved')),
    },
    {
      key: 'mine', label: s.is_user_default ? t('number_series.action_user_default_clear') : t('number_series.action_user_default'),
      icon: 'user', variant: 'neutral', menuOnly: true, show: s.selectable && !isEnded(s),
      run: () => run(() => numberSeriesApi.setUserDefault(userScope(s), s.is_user_default ? null : s.id), t('number_series.default_saved')),
    },
    {
      key: 'end', label: t('number_series.action_end'), icon: 'lock', variant: 'warning', menuOnly: true,
      show: canWrite.value && s.can_end && s.valid_to === null,
      run: () => openEnd(s),
    },
    {
      key: 'reopen', label: t('number_series.action_reopen'), icon: 'uturn', variant: 'neutral', menuOnly: true,
      show: canWrite.value && s.valid_to !== null,
      run: () => run(() => numberSeriesApi.update(s.id, { valid_to: null }), t('common.saved')),
    },
    {
      key: 'delete', label: t('common.delete'), icon: 'trash', variant: 'danger', menuOnly: true,
      show: canWrite.value && s.can_delete,
      run: () => {
        if (confirm(t('number_series.delete_confirm', { name: label(s) }))) {
          void run(() => numberSeriesApi.remove(s.id), t('number_series.deleted'))
        }
      },
    },
  ]
}

// ── kontrola úplnosti po řadách ─────────────────────────────────────────────
const completenessYear = ref(new Date().getFullYear())
const completeness = ref<NumberSeriesCompleteness | null>(null)
const checking = ref(false)

async function checkCompleteness() {
  checking.value = true
  try {
    completeness.value = await numberSeriesApi.completeness(completenessYear.value)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    checking.value = false
  }
}

const completenessRows = computed(() => (completeness.value?.series ?? []).map(c => {
  const s = rows.value.find(r => r.id === c.number_series_id)
  const missing = c.buckets.reduce((sum, b) => sum + b.missing_total, 0)
  const preview = c.buckets.flatMap(b => b.missing_preview).slice(0, 20)
  return { id: c.number_series_id, label: s ? label(s) : `#${c.number_series_id}`, template: c.template,
    used: c.buckets.reduce((sum, b) => sum + b.used_count, 0), missing, preview }
}))
</script>

<template>
  <section class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
    <div class="flex flex-wrap items-start justify-between gap-3 mb-3">
      <div>
        <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('number_series.title') }}</h2>
        <p class="text-xs text-neutral-500 mt-1 max-w-prose">{{ t('number_series.hint') }}</p>
      </div>
      <button v-if="canWrite" type="button" :class="btnFilled('primary')" class="whitespace-nowrap" @click="openCreate">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
        {{ t('number_series.new') }}
      </button>
    </div>

    <div v-if="loading" class="text-sm text-neutral-500 py-4">{{ t('common.loading') }}</div>
    <div v-else class="space-y-5">
      <div v-for="g in groups" :key="g.group">
        <h3 class="text-sm font-semibold text-neutral-700 mb-2">{{ t(`number_series.groups.${g.group}`) }}</h3>
        <div class="hidden md:block border border-neutral-200 rounded-lg overflow-x-auto" data-test="series-table">
          <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium">{{ t('number_series.col_series') }}</th>
                <th class="px-3 py-2 text-left font-medium whitespace-nowrap">{{ t('number_series.col_template') }}</th>
                <th class="px-3 py-2 text-left font-medium whitespace-nowrap">{{ t('number_series.col_period') }}</th>
                <th class="px-3 py-2 text-left font-medium whitespace-nowrap">{{ t('number_series.col_next') }}</th>
                <th class="px-3 py-2 text-left font-medium whitespace-nowrap">{{ t('number_series.col_state') }}</th>
                <th class="px-3 py-2"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="s in g.rows" :key="s.id" :class="isEnded(s) ? 'text-neutral-400' : ''">
                <td class="px-3 py-2">
                  <div class="font-medium">{{ label(s) }}</div>
                  <div class="text-xs text-neutral-500">
                    {{ t(`number_series.kinds.${s.doc_kind}`) }}
                    <template v-if="s.scope_type === 'cash_register'"> · {{ t('number_series.scopes.cash_register') }} {{ s.scope_name }}</template>
                    · {{ s.storage === 'legacy' ? t('number_series.storage_legacy') : t('number_series.storage_native') }}
                  </div>
                </td>
                <td class="px-3 py-2 font-mono text-xs whitespace-nowrap">{{ templateShown(s) || '-' }}</td>
                <td class="px-3 py-2 whitespace-nowrap">{{ s.period ? t(`number_series.periods.${s.period}`) : '-' }}</td>
                <td class="px-3 py-2 font-mono text-xs whitespace-nowrap">{{ s.next_preview ?? '-' }}</td>
                <td class="px-3 py-2">
                  <div class="flex flex-wrap gap-1">
                    <span v-if="s.is_default" class="text-xs px-1.5 py-0.5 rounded bg-success-50 text-success-700 whitespace-nowrap">{{ t('number_series.badge_default') }}</span>
                    <span v-if="s.is_user_default" class="text-xs px-1.5 py-0.5 rounded bg-primary-50 text-primary-700 whitespace-nowrap">{{ t('number_series.badge_user_default') }}</span>
                    <span v-if="s.valid_to" class="text-xs px-1.5 py-0.5 rounded bg-warning-50 text-warning-700 whitespace-nowrap">{{ t('number_series.badge_ended', { date: formatDate(s.valid_to) }) }}</span>
                  </div>
                </td>
                <td class="px-3 py-2 text-right">
                  <RowActionsMenu :actions="rowActions(s)" :inline-count="1" />
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <ul class="md:hidden space-y-2" data-test="series-cards">
          <li v-for="s in g.rows" :key="`m-${s.id}`" class="border border-neutral-200 rounded-lg p-3"
            :class="isEnded(s) ? 'text-neutral-400' : ''">
            <div class="flex items-start justify-between gap-2">
              <div class="min-w-0">
                <div class="font-medium break-words">{{ label(s) }}</div>
                <div class="text-xs text-neutral-500">
                  {{ t(`number_series.kinds.${s.doc_kind}`) }}
                  <template v-if="s.scope_type === 'cash_register'"> · {{ t('number_series.scopes.cash_register') }} {{ s.scope_name }}</template>
                  · {{ s.storage === 'legacy' ? t('number_series.storage_legacy') : t('number_series.storage_native') }}
                </div>
              </div>
              <RowActionsMenu :actions="rowActions(s)" :inline-count="1" icon-only />
            </div>
            <dl class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
              <dt class="text-neutral-500">{{ t('number_series.col_template') }}</dt>
              <dd class="font-mono text-right break-all">{{ templateShown(s) || '-' }}</dd>
              <dt class="text-neutral-500">{{ t('number_series.col_period') }}</dt>
              <dd class="text-right">{{ s.period ? t(`number_series.periods.${s.period}`) : '-' }}</dd>
              <dt class="text-neutral-500">{{ t('number_series.col_next') }}</dt>
              <dd class="font-mono text-right">{{ s.next_preview ?? '-' }}</dd>
            </dl>
            <div v-if="s.is_default || s.is_user_default || s.valid_to" class="mt-2 flex flex-wrap gap-1">
              <span v-if="s.is_default" class="text-xs px-1.5 py-0.5 rounded bg-success-50 text-success-700 whitespace-nowrap">{{ t('number_series.badge_default') }}</span>
              <span v-if="s.is_user_default" class="text-xs px-1.5 py-0.5 rounded bg-primary-50 text-primary-700 whitespace-nowrap">{{ t('number_series.badge_user_default') }}</span>
              <span v-if="s.valid_to" class="text-xs px-1.5 py-0.5 rounded bg-warning-50 text-warning-700 whitespace-nowrap">{{ t('number_series.badge_ended', { date: formatDate(s.valid_to) }) }}</span>
            </div>
          </li>
        </ul>
      </div>
    </div>

    <div class="mt-6 border-t border-neutral-200 pt-4">
      <h3 class="text-sm font-semibold text-neutral-700 mb-1">{{ t('number_series.completeness_title') }}</h3>
      <p class="text-xs text-neutral-500 mb-2 max-w-prose">{{ t('number_series.completeness_hint') }}</p>
      <div class="flex flex-wrap items-center gap-2 mb-3">
        <label class="text-sm text-neutral-600">{{ t('common.year') }}</label>
        <input v-model.number="completenessYear" type="number" min="2000" max="2200" step="1"
          class="h-9 w-24 px-2 border border-neutral-300 rounded-md text-sm text-right" />
        <button type="button" :class="btnOutline('primary')" class="whitespace-nowrap" :disabled="checking" @click="checkCompleteness">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.clipboardCheck" /></svg>
          {{ checking ? t('common.loading') : t('number_series.completeness_run') }}
        </button>
      </div>
      <div v-if="completeness" class="border border-neutral-200 rounded-lg overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ t('number_series.col_series') }}</th>
              <th class="px-3 py-2 text-left font-medium whitespace-nowrap">{{ t('number_series.col_template') }}</th>
              <th class="px-3 py-2 text-right font-medium whitespace-nowrap">{{ t('number_series.col_used') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('number_series.col_missing') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-if="!completenessRows.length"><td colspan="4" class="px-3 py-3 text-neutral-500">{{ t('number_series.completeness_empty') }}</td></tr>
            <tr v-for="c in completenessRows" :key="c.id">
              <td class="px-3 py-2">{{ c.label }}</td>
              <td class="px-3 py-2 font-mono text-xs whitespace-nowrap">{{ c.template }}</td>
              <td class="px-3 py-2 text-right">{{ c.used }}</td>
              <td class="px-3 py-2">
                <span v-if="c.missing === 0" class="text-xs px-1.5 py-0.5 rounded bg-success-50 text-success-700">{{ t('number_series.completeness_ok') }}</span>
                <template v-else>
                  <span class="text-xs px-1.5 py-0.5 rounded bg-danger-50 text-danger-700">{{ t('number_series.completeness_missing', { count: c.missing }) }}</span>
                  <span class="ml-2 font-mono text-xs text-neutral-600">{{ c.preview.join(', ') }}<template v-if="c.missing > c.preview.length"> …</template></span>
                </template>
              </td>
            </tr>
          </tbody>
        </table>
        <p v-if="completeness.unassigned.length" class="px-3 py-2 text-xs text-warning-700 border-t border-neutral-100">
          {{ t('number_series.completeness_unassigned', { count: completeness.unassigned.length }) }}
        </p>
      </div>
    </div>

    <Modal v-if="endDialog.open && endDialog.series" :title="t('number_series.end_title')" widthClass="max-w-md" @close="endDialog.open = false">
      <div class="space-y-3">
        <p class="text-sm text-neutral-700">{{ t('number_series.end_hint', { name: label(endDialog.series) }) }}</p>
        <div>
          <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('number_series.field_valid_to') }}</label>
          <input v-model="endDialog.date" type="date" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm" data-test="end-date" />
        </div>
      </div>
      <template #footer>
        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="endDialog.open = false">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <button type="button" :class="btnFilled('warning')" class="whitespace-nowrap" :disabled="endDialog.saving || !endDialog.date" data-test="end-save" @click="submitEnd">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.lock" /></svg>
            {{ endDialog.saving ? t('common.saving') : t('number_series.action_end') }}
          </button>
        </div>
      </template>
    </Modal>

    <Modal v-if="formOpen" :title="editing ? t('number_series.edit') : t('number_series.new')" widthClass="max-w-2xl" @close="formOpen = false">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <div>
          <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('number_series.field_kind') }}</label>
          <select v-model="form.doc_kind" :disabled="editing !== null" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface disabled:bg-neutral-50">
            <option v-for="k in (editing ? [form.doc_kind] : creatableKinds)" :key="k" :value="k">{{ t(`number_series.kinds.${k}`) }}</option>
          </select>
        </div>
        <div v-if="isRegisterKind">
          <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('number_series.field_register') }}</label>
          <select v-model.number="form.scope_id" :disabled="editing !== null" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface disabled:bg-neutral-50">
            <option :value="0">{{ t('number_series.all_registers') }}</option>
            <option v-for="r in registers" :key="r.id" :value="r.id">{{ r.name }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('number_series.field_name') }}</label>
          <input v-model="form.name" type="text" maxlength="100"
            :placeholder="editing?.storage === 'legacy' ? label(editing) : ''"
            class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm" />
        </div>
        <template v-if="!editing || editing.can_edit_template">
          <div>
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('number_series.field_prefix') }}</label>
            <input v-model="form.prefix" type="text" maxlength="10" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono uppercase" />
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('number_series.field_template') }}</label>
            <input :value="form.template" type="text" maxlength="60" autocomplete="off" spellcheck="false"
              :aria-invalid="templateState.invalid" data-test="series-template"
              class="w-full h-9 px-3 border rounded-md text-sm font-mono"
              :class="templateState.invalid ? 'border-danger-500 bg-danger-50' : 'border-neutral-300'"
              @input="onTemplateInput" />
            <p v-if="templateState.error" class="text-xs text-danger-500 mt-1" role="alert">{{ templateState.error }}</p>
            <p v-else class="text-xs text-neutral-500 mt-1">{{ t('number_series.template_hint') }}</p>
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('number_series.field_period') }}</label>
            <select v-model="form.period" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
              <option value="year">{{ t('number_series.periods.year') }}</option>
              <option value="month">{{ t('number_series.periods.month') }}</option>
              <option value="none">{{ t('number_series.periods.none') }}</option>
            </select>
          </div>
        </template>
        <p v-else-if="editing" class="md:col-span-2 text-xs text-neutral-500">
          {{ editing.storage === 'legacy' ? t(`number_series.edit_target.${editing.edit_target ?? 'none'}`) : t('number_series.template_locked') }}
        </p>
        <div v-if="!editing || editing.can_set_next">
          <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('number_series.field_next') }}</label>
          <input v-model="form.next_number" type="number" min="1" step="1" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono text-right" />
        </div>
        <div v-if="!editing || editing.can_end || editing.valid_to">
          <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('number_series.field_valid_to') }}</label>
          <input v-model="form.valid_to" type="date" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm" />
        </div>
        <label v-if="!editing" class="md:col-span-2 inline-flex items-center gap-2 text-sm text-neutral-700">
          <input v-model="form.is_default" type="checkbox" class="rounded border-neutral-300" />
          {{ t('number_series.field_is_default') }}
        </label>
        <p v-if="templateEditable && templateState.preview" class="md:col-span-2 text-xs text-success-600">
          {{ t('number_series.preview') }}: <code class="font-mono font-semibold">{{ templateState.preview }}</code>
        </p>
      </div>
      <template #footer>
        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="formOpen = false">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <button type="button" :class="btnFilled('primary')" class="whitespace-nowrap" data-test="series-save" :disabled="saving || !canSaveForm" @click="saveForm">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ saving ? t('common.saving') : t('common.save') }}
          </button>
        </div>
      </template>
    </Modal>
  </section>
</template>
