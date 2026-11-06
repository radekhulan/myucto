<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { Fx504CaseDecision, Fx504CaseOrigin, Fx504SourceCell } from '@/api/fx504'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'
const props = defineProps<{ cells: Fx504SourceCell[]; decisions: Fx504CaseDecision[]; unresolved: Fx504SourceCell[]; locked: string[]; disabled: boolean }>()
const emit = defineEmits<{ change: [decisions: Fx504CaseDecision[], valid: boolean] }>()
const { t } = useI18n()
type Row = { cell_key: string; choice: string; reference: string; reason: string; czk_signed: string; foreign_signed: string }
const rows = ref<Row[]>([])
const rowsByCell = computed(() => {
  const result: Record<string, Row[]> = {}
  for (const row of rows.value) (result[row.cell_key] ??= []).push(row)
  return result
})
const identity = (origin: Fx504CaseOrigin) => origin.kind === 'declared_title' ? `declared:${origin.origin_line_id}` : JSON.stringify(origin)
function options(cell: Fx504SourceCell) {
  const origins = cell.candidates.length ? cell.candidates : props.cells.filter(c => c.account_id === cell.account_id && c.currency_code === cell.currency_code).flatMap(c => c.candidates)
  const unique = new Map(origins.map(origin => [identity(origin), { value: identity(origin), label: origin.kind === 'document' ? t(`fx504.case_${origin.doc_type}`, { id: origin.doc_id }) : t('fx504.case_cash'), origin }]))
  if (!cell.candidates.length) for (const source of props.cells.filter(c => c.account_id === cell.account_id && c.currency_code === cell.currency_code)) {
    const origin: Fx504CaseOrigin = { kind: 'declared_title', origin_line_id: source.line_id, reference: '' }
    unique.set(identity(origin), { value: identity(origin), label: t('fx504.case_declared', { entry: source.entry_id, line: source.line_no }), origin })
  }
  return [...unique.values()]
}
function add(cell: Fx504SourceCell) {
  if (props.disabled || props.locked.includes(cell.cell_key)) return
  const existing = rows.value.some(row => row.cell_key === cell.cell_key)
  rows.value.push({ cell_key: cell.cell_key, choice: '', reference: '', reason: '', czk_signed: existing ? '' : cell.czk_signed, foreign_signed: existing ? '' : cell.foreign_signed })
}
function cents(value: string): bigint | null {
  if (!/^-?\d{1,12}(?:\.\d{1,2})?$/.test(value)) return null
  const [whole, fraction = ''] = value.replace('-', '').split('.')
  return (BigInt(whole!) * 100n + BigInt(fraction.padEnd(2, '0'))) * (value.startsWith('-') ? -1n : 1n)
}
const output = computed(() => {
  const decisions: Fx504CaseDecision[] = []
  let valid = true
  for (const cell of props.cells) {
    const parts = rowsByCell.value[cell.cell_key] ?? []
    if (!parts.length) { if (props.unresolved.some(row => row.cell_key === cell.cell_key)) valid = false; continue }
    let czk = 0n; let foreign = 0n
    const expectedCzk = cents(cell.czk_signed)!; const expectedForeign = cents(cell.foreign_signed)!
    for (const row of parts) {
      const selected = options(cell).find(option => option.value === row.choice)
      const amount = cents(row.czk_signed); const fx = cents(row.foreign_signed)
      const fits = (part: bigint, whole: bigint) => whole >= 0n ? part >= 0n && part <= whole : part <= 0n && part >= whole
      if (!selected || !row.reason.trim() || amount === null || fx === null || (amount === 0n && fx === 0n) || !fits(amount, expectedCzk) || !fits(fx, expectedForeign)) { valid = false; continue }
      const origin = selected.origin.kind === 'declared_title' ? { ...selected.origin, reference: row.reference.trim() } : selected.origin
      if (origin.kind === 'declared_title' && !origin.reference) valid = false
      czk += amount; foreign += fx
      let decision = decisions.find(d => JSON.stringify(d.origin) === JSON.stringify(origin) && d.reason === row.reason)
      if (!decision) { decision = { origin, reason: row.reason, contributions: [] }; decisions.push(decision) }
      decision.contributions.push({ cell_key: row.cell_key, czk_signed: row.czk_signed, foreign_signed: row.foreign_signed })
    }
    if (czk !== expectedCzk || foreign !== expectedForeign) valid = false
  }
  return { decisions, valid }
})
watch(() => [props.cells, props.decisions], () => {
  rows.value = props.decisions.flatMap(d => d.contributions.map(part => ({ ...part, choice: identity(d.origin), reference: d.origin.kind === 'declared_title' ? d.origin.reference : '', reason: d.reason })))
  for (const cell of props.unresolved) if (!rows.value.some(row => row.cell_key === cell.cell_key)) rows.value.push({ cell_key: cell.cell_key, choice: '', reference: '', reason: '', czk_signed: cell.czk_signed, foreign_signed: cell.foreign_signed })
}, { immediate: true })
watch(output, value => emit('change', value.decisions, value.valid), { immediate: true })
</script>

<template>
  <section v-if="cells.length" class="space-y-3 rounded-lg border border-neutral-200 p-4" data-testid="fx-case-editor">
    <h3 class="font-semibold">{{ t('fx504.cases_title') }}</h3>
    <p class="text-sm text-neutral-600">{{ t('fx504.cases_description') }}</p>
    <p v-if="!output.valid" class="text-sm text-warning-700">{{ t('fx504.cases_incomplete') }}</p>
    <article v-for="cell in cells" :key="cell.cell_key" class="space-y-2 border-t border-neutral-200 pt-3">
      <p class="text-sm font-medium">{{ cell.account_code }} · {{ cell.currency_code }} · {{ t('fx504.case_source', { entry: cell.entry_id, line: cell.line_no }) }} · {{ cell.czk_signed }} CZK / {{ cell.foreign_signed }} {{ cell.currency_code }}</p>
      <p v-if="locked.includes(cell.cell_key)" class="text-sm">{{ t('fx504.case_locked') }}</p>
      <fieldset :disabled="disabled || locked.includes(cell.cell_key)" class="space-y-3">
        <div v-for="(row, index) in rowsByCell[cell.cell_key] ?? []" :key="index" class="flex flex-wrap items-end gap-2">
          <label class="text-sm">{{ t('fx504.case_title') }}<select v-model="row.choice" class="block h-9 max-w-full rounded border border-neutral-300 px-2"><option value="">{{ t('fx504.choose') }}</option><option v-for="option in options(cell)" :key="option.value" :value="option.value">{{ option.label }}</option></select></label>
          <label v-if="row.choice.startsWith('declared:')" class="text-sm">{{ t('fx504.case_reference') }}<input v-model="row.reference" data-testid="case-reference" maxlength="2000" class="block h-9 rounded border border-neutral-300 px-2" /></label>
          <label class="text-sm">CZK<input v-model="row.czk_signed" inputmode="decimal" class="block h-9 w-32 rounded border border-neutral-300 px-2" /></label>
          <label class="text-sm">{{ cell.currency_code }}<input v-model="row.foreign_signed" inputmode="decimal" class="block h-9 w-32 rounded border border-neutral-300 px-2" /></label>
          <label class="text-sm grow">{{ t('fx504.evidence') }}<input v-model="row.reason" data-testid="case-reason" maxlength="2000" class="block h-9 w-full rounded border border-neutral-300 px-2" /></label>
          <button type="button" :class="btnOutline('danger')" @click="rows.splice(rows.indexOf(row), 1)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.trash" /></svg>{{ t('fx504.remove_cell') }}</button>
        </div>
        <button type="button" :class="btnOutline('neutral')" @click="add(cell)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.plus" /></svg>{{ t('fx504.case_add') }}</button>
      </fieldset>
    </article>
  </section>
</template>
