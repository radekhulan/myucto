<script setup lang="ts">
/**
 * Výjimky mapování účtů do výkazů pro konkrétní firmu.
 *
 * Globální mapa zařazuje účet podle čísla syntetiky. Tady účetní určí, že konkrétní účet
 * nebo analytika jde u TÉTO firmy jinam (dlouhodobá půjčka od společníka, spřízněné osoby,
 * zvláštní analytika). Editor je jedna sada změn s jedním společným Uložit v liště dole;
 * náhled dopadu i návrhy z podaného přiznání pracují nad neuloženou sadou a nic neukládají.
 */
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import {
  accountingApi,
  type AccountingPeriod,
  type StatementBalanceCondition,
  type StatementOverride,
  type StatementOverrideAccount,
  type StatementOverrideOverview,
  type StatementOverridePreview,
  type StatementOverrideRow,
  type StatementOverrideSuggestion,
  type StatementOverrideSuggestions,
  type StatementOverrideType,
} from '@/api/accounting'
import { useToast } from '@/composables/useToast'
import { formatMoney } from '@/composables/useFormat'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { btnFilled, btnOutline, btnIconSm, ICONS } from '@/components/ui/buttonStyles'
import { useAuthStore } from '@/stores/auth'

const { t } = useI18n()
const toast = useToast()
const auth = useAuthStore()
const route = useRoute()
const canWrite = computed(() => auth.canWrite('accounting'))

const TYPES: { key: StatementOverrideType; label: string }[] = [
  { key: 'balance_sheet', label: 'accounting.statements.mapping.tab_balance_sheet' },
  { key: 'income_statement', label: 'accounting.statements.mapping.tab_income_statement' },
  { key: 'income_statement_purpose', label: 'accounting.statements.mapping.tab_income_statement_purpose' },
]
const CONDITIONS: StatementBalanceCondition[] = ['any', 'debit', 'credit']

const periods = ref<AccountingPeriod[]>([])
const periodId = ref<number | ''>('')
const statementType = ref<StatementOverrideType>('balance_sheet')
const overview = ref<StatementOverrideOverview | null>(null)
const loading = ref(false)
const saving = ref(false)
const draft = ref<StatementOverride[]>([])
const savedSnapshot = ref('')
const search = ref('')
const onlyOverrides = ref(false)
const onlyBalances = ref(true)
const preview = ref<StatementOverridePreview | null>(null)
const previewing = ref(false)
const suggestions = ref<StatementOverrideSuggestions | null>(null)
const suggesting = ref(false)
const selected = ref<Set<string>>(new Set())
const fileInput = ref<HTMLInputElement | null>(null)

function normalize(o: StatementOverride): StatementOverride {
  return {
    account_prefix: o.account_prefix,
    row_code: o.row_code,
    target: o.target ?? 'gross',
    balance_condition: o.balance_condition ?? 'any',
    sign: o.sign ?? 1,
    note: o.note ? o.note : null,
    valid_from_year: o.valid_from_year ?? null,
    valid_to_year: o.valid_to_year ?? null,
    ...(o.target === 'correction' && o.follows_prefix ? { follows_prefix: o.follows_prefix } : {}),
  }
}

function keyOf(o: StatementOverride): string {
  return `${o.account_prefix}|${o.balance_condition}|${o.valid_from_year ?? ''}`
}

/** Účetní období editoru — tabulka účtů ukazuje výjimky platné v něm. */
const year = computed<number | null>(() => overview.value?.period.fiscal_year ?? null)

function validIn(o: StatementOverride): boolean {
  const y = year.value
  if (y === null) return true
  return (o.valid_from_year == null || o.valid_from_year <= y) && (o.valid_to_year == null || o.valid_to_year >= y)
}

function parseYear(value: string): number | null {
  const n = Number.parseInt(value.trim(), 10)
  return Number.isFinite(n) ? n : null
}

function validityLabel(o: StatementOverride): string {
  if (o.valid_from_year == null && o.valid_to_year == null) return t('accounting.statements.mapping.validity_always')
  return `${o.valid_from_year ?? '…'}–${o.valid_to_year ?? '…'}`
}

function snapshot(list: StatementOverride[]): string {
  return JSON.stringify(list.map(normalize).sort((a, b) => keyOf(a).localeCompare(keyOf(b))))
}

const dirty = computed(() => snapshot(draft.value) !== savedSnapshot.value)
const dirtyCount = computed(() => {
  const before = new Map((overview.value?.overrides ?? []).map(o => [keyOf(o), JSON.stringify(normalize(o))]))
  const after = new Map(draft.value.map(o => [keyOf(o), JSON.stringify(normalize(o))]))
  let count = 0
  for (const [k, v] of after) if (before.get(k) !== v) count++
  for (const k of before.keys()) if (!after.has(k)) count++
  return count
})

const rowsByCode = computed(() => new Map((overview.value?.rows ?? []).map(r => [r.row_code, r])))

function rowText(r: StatementOverrideRow | undefined, code: string): string {
  return r ? `${r.display_code} ${r.label}` : code
}

function rowOptionLabel(r: StatementOverrideRow): string {
  return `${'  '.repeat(Math.max(0, r.level - 1))}${r.display_code} ${r.label}`
}

/** Řádky, do kterých smí účet daného druhu jít — rozvaha obě strany, výsledovka jen VZZ. */
const rowOptions = computed(() => (overview.value?.rows ?? []).filter(r => r.row_type !== 'computed' || r.row_code === 'P.A.V.'))

function activeIndex(code: string): number {
  return draft.value.findIndex(o => o.account_prefix === code && validIn(o))
}

function overrideFor(code: string): StatementOverride | undefined {
  const idx = activeIndex(code)
  return idx >= 0 ? draft.value[idx] : undefined
}

/** Výjimky, které v roce editoru neplatí (jiné roky platnosti) — spravují se zvlášť. */
const otherYears = computed(() => draft.value.filter(o => !validIn(o)))

/**
 * Výjimka pro skupinu účtů (prefix `062.`), která na účet dopadá, když účet nemá vlastní.
 * Vyhrává nejdelší prefix, stejně jako v StatementMapResolver::longestMatching.
 */
function inheritedOverride(code: string): StatementOverride | undefined {
  if (overrideFor(code)) return undefined
  let best: StatementOverride | undefined
  for (const o of draft.value) {
    if (validIn(o) && o.account_prefix !== code && code.startsWith(o.account_prefix)
      && (!best || o.account_prefix.length > best.account_prefix.length)) {
      best = o
    }
  }
  return best
}

function globalOptionLabel(code: string): string {
  const inherited = inheritedOverride(code)
  return inherited
    ? t('accounting.statements.mapping.row_inherited', { prefix: inherited.account_prefix })
    : t('accounting.statements.mapping.row_global')
}

function groupSize(prefix: string): number {
  return (overview.value?.accounts ?? []).filter(a => a.account_code !== prefix && a.account_code.startsWith(prefix)).length
}

async function loadPeriods() {
  periods.value = await accountingApi.listPeriods()
  const requested = Number(route.query.period_id)
  if (periods.value.some(p => p.id === requested)) periodId.value = requested
  if (periods.value.length > 0 && !periodId.value) {
    periodId.value = periods.value[0].id
  }
}

async function load() {
  if (!periodId.value) return
  loading.value = true
  preview.value = null
  suggestions.value = null
  try {
    overview.value = await accountingApi.getStatementOverrides(Number(periodId.value), statementType.value)
    draft.value = overview.value.overrides.map(normalize)
    savedSnapshot.value = snapshot(draft.value)
  } catch (e: any) {
    overview.value = null
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    loading.value = false
  }
}

function switchType(type: StatementOverrideType) {
  if (type === statementType.value) return
  statementType.value = type
  void load()
}

function sectionOf(rowCode: string): string | undefined {
  return rowsByCode.value.get(rowCode)?.section
}

function setRow(code: string, rowCode: string) {
  const idx = activeIndex(code)
  preview.value = null
  if (rowCode === '') {
    if (idx >= 0) draft.value.splice(idx, 1)
    return
  }
  if (idx >= 0) {
    const current = draft.value[idx]
    draft.value[idx] = { ...current, row_code: rowCode, target: sectionOf(rowCode) === 'assets' ? current.target : 'gross' }
  } else {
    // Účet má výjimku jen pro jiné roky: nová platí od roku editoru, ať se s ní nepřekrývá.
    const hasOtherYears = draft.value.some(o => o.account_prefix === code)
    draft.value.push({
      account_prefix: code, row_code: rowCode, target: 'gross', balance_condition: 'any', sign: 1, note: null,
      valid_from_year: hasOtherYears ? year.value : null, valid_to_year: null,
    })
  }
}

function setField(code: string, patch: Partial<StatementOverride>) {
  const idx = activeIndex(code)
  if (idx < 0) return
  draft.value[idx] = { ...draft.value[idx], ...patch }
  preview.value = null
}

function removeOverride(code: string) {
  const idx = activeIndex(code)
  if (idx < 0) return
  draft.value.splice(idx, 1)
  preview.value = null
}

/** Úprava výjimky jiného roku platnosti — ta nemá v tabulce účtů vlastní řádek. */
function setOtherField(o: StatementOverride, patch: Partial<StatementOverride>) {
  const idx = draft.value.indexOf(o)
  if (idx < 0) return
  draft.value[idx] = { ...draft.value[idx], ...patch }
  preview.value = null
}

function removeOther(o: StatementOverride) {
  const idx = draft.value.indexOf(o)
  if (idx < 0) return
  draft.value.splice(idx, 1)
  preview.value = null
}

function discard() {
  draft.value = (overview.value?.overrides ?? []).map(normalize)
  preview.value = null
}

function sourceLabel(source: string): string {
  return t(`accounting.statements.mapping.source_${source}`)
}

function currentRows(a: StatementOverrideAccount): { code: string; text: string; source: string }[] {
  return a.mappings.map(m => ({ code: m.row_code, text: rowText(rowsByCode.value.get(m.row_code), m.row_code), source: m.source }))
}

const accountCodes = computed(() => new Set((overview.value?.accounts ?? []).map(a => a.account_code)))

const filteredAccounts = computed(() => {
  const q = search.value.trim().toLowerCase()
  return (overview.value?.accounts ?? []).filter(a => {
    const hasOverride = !!overrideFor(a.account_code) || a.mappings.some(m => m.source === 'override')
    if (onlyOverrides.value && !hasOverride) return false
    if (onlyBalances.value && Math.round(a.balance * 100) === 0 && !hasOverride) return false
    if (q && !a.account_code.toLowerCase().includes(q) && !a.name.toLowerCase().includes(q)) return false
    return true
  })
})

/** Výjimky na prefix, který v osnově není účtem (zadané přes API nebo převzaté z návrhu). */
const orphans = computed(() => draft.value.filter(o => validIn(o) && !accountCodes.value.has(o.account_prefix)))

async function runPreview() {
  if (!periodId.value) return
  previewing.value = true
  try {
    preview.value = await accountingApi.previewStatementOverrides(Number(periodId.value), statementType.value, draft.value)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    previewing.value = false
  }
}

async function save() {
  if (!overview.value) return
  saving.value = true
  try {
    await accountingApi.saveStatementOverrides(overview.value.version.id, draft.value)
    toast.success(t('accounting.statements.mapping.saved'))
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    saving.value = false
  }
}

function suggestionKey(s: StatementOverrideSuggestion): string {
  return `${s.account_code}|${s.to_row_code}`
}

const visibleSuggestions = computed(() => (suggestions.value?.suggestions ?? []).filter(s => s.statement_type === statementType.value))
const otherTabSuggestions = computed(() => (suggestions.value?.suggestions ?? []).length - visibleSuggestions.value.length)

/** Návrhy pro sloupec minulého období — výjimky platné do minulého roku. */
const priorSuggestions = computed(() => (suggestions.value?.prior_period?.suggestions ?? []).filter(s => s.statement_type === statementType.value))

function priorKey(s: StatementOverrideSuggestion): string {
  return `prior|${suggestionKey(s)}`
}

function acceptSuggestions(result: StatementOverrideSuggestions) {
  suggestions.value = result
  selected.value = new Set([
    ...result.suggestions.filter(s => !s.ambiguous).map(suggestionKey),
    ...(result.prior_period?.suggestions ?? []).filter(s => !s.ambiguous).map(priorKey),
  ])
}

function togglePrior(s: StatementOverrideSuggestion) {
  const next = new Set(selected.value)
  const key = priorKey(s)
  if (next.has(key)) next.delete(key)
  else next.add(key)
  selected.value = next
}

function validInYear(o: StatementOverride, y: number): boolean {
  return (o.valid_from_year == null || o.valid_from_year <= y) && (o.valid_to_year == null || o.valid_to_year >= y)
}

/**
 * Výjimka pro minulé období platí do minulého roku. Když účet už má výjimku platnou i v něm
 * (typicky bez omezení), rozdělí se: stávající platí od běžného roku, návrh do minulého.
 */
function applyPriorSuggestion(next: StatementOverride, prevYear: number) {
  const idx = draft.value.findIndex(d => d.account_prefix === next.account_prefix
    && d.balance_condition === next.balance_condition && validInYear(d, prevYear))
  if (idx < 0) {
    draft.value.push({ ...next, valid_from_year: null, valid_to_year: prevYear })
    return
  }
  const existing = draft.value[idx]
  if (existing.valid_to_year != null && existing.valid_to_year <= prevYear) {
    draft.value[idx] = { ...next, valid_from_year: existing.valid_from_year ?? null, valid_to_year: existing.valid_to_year }
    return
  }
  draft.value[idx] = { ...existing, valid_from_year: prevYear + 1 }
  draft.value.push({ ...next, valid_from_year: existing.valid_from_year ?? null, valid_to_year: prevYear })
}

async function suggestFromFiled() {
  if (!periodId.value) return
  suggesting.value = true
  try {
    acceptSuggestions(await accountingApi.suggestStatementOverrides(Number(periodId.value)))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    suggesting.value = false
  }
}

function pickFile() {
  fileInput.value?.click()
}

async function onFile(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file || !periodId.value) return
  suggesting.value = true
  try {
    acceptSuggestions(await accountingApi.suggestStatementOverridesFromXml(Number(periodId.value), file))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    suggesting.value = false
    input.value = ''
  }
}

function toggleSuggestion(s: StatementOverrideSuggestion) {
  const next = new Set(selected.value)
  const key = suggestionKey(s)
  if (next.has(key)) next.delete(key)
  else next.add(key)
  selected.value = next
}

function applySuggestions() {
  const suggestionYear = suggestions.value?.year ?? ''
  for (const s of visibleSuggestions.value) {
    if (!selected.value.has(suggestionKey(s))) continue
    for (const o of s.overrides) {
      const next: StatementOverride = {
        account_prefix: o.account_prefix,
        row_code: o.row_code,
        target: o.target,
        balance_condition: o.balance_condition,
        sign: 1,
        note: t('accounting.statements.mapping.suggestion_note', { year: suggestionYear }),
        ...(o.follows_prefix ? { follows_prefix: o.follows_prefix } : {}),
      }
      // Návrh je za rok editoru: nahradí výjimku, která v něm platí, a převezme její platnost.
      const idx = draft.value.findIndex(d => d.account_prefix === next.account_prefix
        && d.balance_condition === next.balance_condition && validIn(d))
      if (idx >= 0) {
        draft.value[idx] = { ...next, valid_from_year: draft.value[idx].valid_from_year ?? null, valid_to_year: draft.value[idx].valid_to_year ?? null }
      } else {
        draft.value.push({ ...next, valid_from_year: draft.value.some(d => d.account_prefix === next.account_prefix) ? year.value : null, valid_to_year: null })
      }
    }
  }
  const prior = suggestions.value?.prior_period
  for (const s of priorSuggestions.value) {
    if (!prior || !selected.value.has(priorKey(s))) continue
    for (const o of s.overrides) {
      applyPriorSuggestion({
        account_prefix: o.account_prefix,
        row_code: o.row_code,
        target: o.target,
        balance_condition: o.balance_condition,
        sign: 1,
        note: t('accounting.statements.mapping.suggestion_note', { year: prior.year }),
        ...(o.follows_prefix ? { follows_prefix: o.follows_prefix } : {}),
      }, o.valid_to_year ?? prior.year)
    }
  }
  preview.value = null
  toast.success(t('accounting.statements.mapping.suggestions_applied'))
}

const hasFiledReturn = computed(() => !!overview.value?.filed_return)

const actions = computed<ActionItem[]>(() => [
  {
    key: 'preview', label: t('accounting.statements.mapping.action_preview'), icon: 'eye',
    tier: dirty.value ? 'primary' : 'secondary', variant: 'primary',
    show: canWrite.value, disabled: !dirty.value || previewing.value || loading.value,
    disabledReason: t('accounting.statements.mapping.preview_disabled'),
    loading: previewing.value, run: () => { void runPreview() },
  },
  {
    key: 'suggest', label: t('accounting.statements.mapping.action_suggest'), icon: 'doc',
    tier: !dirty.value && hasFiledReturn.value ? 'primary' : 'secondary', variant: 'primary',
    show: canWrite.value && statementType.value !== 'income_statement_purpose',
    disabled: !hasFiledReturn.value || suggesting.value || loading.value,
    disabledReason: t('accounting.statements.mapping.suggest_disabled'),
    loading: suggesting.value, run: () => { void suggestFromFiled() },
  },
  {
    key: 'suggest-upload', label: t('accounting.statements.mapping.action_suggest_upload'), icon: 'upload',
    tier: 'overflow', variant: 'neutral',
    show: canWrite.value && statementType.value !== 'income_statement_purpose',
    disabled: suggesting.value || loading.value || !periodId.value,
    run: pickFile,
  },
  {
    key: 'reload', label: t('accounting.statements.mapping.action_reload'), icon: 'cycle',
    tier: 'overflow', variant: 'neutral',
    show: true, disabled: loading.value, run: () => { void load() },
  },
])

onMounted(async () => {
  const requestedType = TYPES.find(type => type.key === route.query.statement_type)
  if (requestedType) statementType.value = requestedType.key
  try {
    await loadPeriods()
    await load()
  } catch {
    /* seznam období není kritický — stránka jde použít po ručním výběru */
  }
})
</script>

<template>
  <div class="max-w-full" :class="dirty && canWrite ? 'pb-4' : ''">
    <div class="flex items-start justify-between mb-4 gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('accounting.statements.mapping.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('accounting.statements.mapping.subtitle') }}</p>
      </div>
      <div class="flex items-center gap-2 flex-wrap">
        <select v-model.number="periodId" data-test="period" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm" @change="load">
          <option v-for="p in periods" :key="p.id" :value="p.id">{{ p.fiscal_year }}</option>
        </select>
        <ActionBar :actions="actions" />
      </div>
    </div>

    <div class="bg-primary-50 border border-primary-200 rounded-lg p-4 mb-4 text-sm text-neutral-700">
      <p class="font-medium text-primary-800 mb-1">{{ t('accounting.statements.mapping.explainer_title') }}</p>
      <p>{{ t('accounting.statements.mapping.explainer_body') }}</p>
      <RouterLink :to="{ path: '/admin/settings', query: { tab: 'accounting' }, hash: '#net-turnover' }"
        class="inline-block mt-2 text-xs text-primary-700 hover:underline">
        {{ t('settings.net_turnover.mapping_link') }}
      </RouterLink>
    </div>

    <input ref="fileInput" type="file" accept=".xml,application/xml,text/xml" class="hidden" data-test="xml-input" @change="onFile" />

    <!-- Přepínač výkazu -->
    <div class="flex flex-wrap items-center gap-2 mb-3">
      <button v-for="tp in TYPES" :key="tp.key" type="button" data-test="type-tab"
              :class="tp.key === statementType ? btnFilled('primary') : btnOutline('neutral')"
              @click="switchType(tp.key)">
        {{ t(tp.label) }}
      </button>
      <span v-if="overview" class="text-xs text-neutral-500">{{ overview.version.version_code }} · {{ overview.as_of }}</span>
    </div>

    <!-- Návrhy z podaného přiznání -->
    <div v-if="suggestions" class="bg-surface border border-neutral-200 rounded-lg shadow-sm mb-4" data-test="suggestions">
      <div class="px-4 py-2 bg-neutral-50 border-b border-neutral-200 flex flex-wrap items-center justify-between gap-2">
        <div>
          <h2 class="text-sm font-semibold">{{ t('accounting.statements.mapping.suggestions_title') }}</h2>
          <p class="text-xs text-neutral-500 mt-0.5">
            {{ t(suggestions.source.type === 'filed_return' ? 'accounting.statements.mapping.suggestions_source_filed' : 'accounting.statements.mapping.suggestions_source_upload', { year: suggestions.year }) }}
          </p>
        </div>
        <button v-if="canWrite && (visibleSuggestions.length > 0 || priorSuggestions.length > 0)" type="button" data-test="apply-suggestions"
                :class="btnFilled('primary')" :disabled="selected.size === 0" @click="applySuggestions">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
          {{ t('accounting.statements.mapping.suggestions_apply') }}
        </button>
      </div>
      <p v-if="suggestions.differences.length === 0" class="px-4 py-3 text-sm text-success-700">
        {{ t('accounting.statements.mapping.suggestions_no_diff') }}
      </p>
      <p v-else-if="visibleSuggestions.length === 0" class="px-4 py-3 text-sm text-neutral-600">
        {{ t('accounting.statements.mapping.suggestions_empty') }}
      </p>
      <ul v-else class="divide-y divide-neutral-100">
        <li v-for="s in visibleSuggestions" :key="suggestionKey(s)" class="px-4 py-2 text-sm flex gap-3 items-start" data-test="suggestion">
          <input type="checkbox" class="mt-1" :checked="selected.has(suggestionKey(s))" :disabled="!canWrite" @change="toggleSuggestion(s)" />
          <div class="min-w-0">
            <p class="font-medium flex flex-wrap items-baseline gap-x-2">
              <span class="font-mono whitespace-nowrap">{{ s.account_code }}</span>
              <span>{{ s.account_name }}</span>
              <span class="font-mono text-neutral-500 whitespace-nowrap">{{ s.from_row_code }} → {{ s.to_row_code }}</span>
            </p>
            <p class="text-xs text-neutral-600">{{ s.reason }}</p>
            <p v-if="s.ambiguous" class="text-xs text-warning-700">{{ t('accounting.statements.mapping.suggestion_ambiguous') }}</p>
            <p v-if="s.to_is_subtotal" class="text-xs text-warning-700">{{ t('accounting.statements.mapping.suggestion_subtotal') }}</p>
          </div>
        </li>
      </ul>
      <div v-if="priorSuggestions.length > 0 && suggestions.prior_period" class="border-t border-neutral-200" data-test="prior-suggestions">
        <div class="px-4 py-2 bg-neutral-50">
          <h3 class="text-xs font-semibold">{{ t('accounting.statements.mapping.prior_title', { year: suggestions.prior_period.year }) }}</h3>
          <p class="text-[11px] text-neutral-600 mt-0.5">{{ t('accounting.statements.mapping.prior_hint', { year: suggestions.prior_period.year }) }}</p>
        </div>
        <ul class="divide-y divide-neutral-100">
          <li v-for="s in priorSuggestions" :key="priorKey(s)" class="px-4 py-2 text-sm flex gap-3 items-start" data-test="prior-suggestion">
            <input type="checkbox" class="mt-1" :checked="selected.has(priorKey(s))" :disabled="!canWrite" @change="togglePrior(s)" />
            <div class="min-w-0">
              <p class="font-medium flex flex-wrap items-baseline gap-x-2">
                <span class="font-mono whitespace-nowrap">{{ s.account_code }}</span>
                <span>{{ s.account_name }}</span>
                <span class="font-mono text-neutral-500 whitespace-nowrap">{{ s.from_row_code }} → {{ s.to_row_code }}</span>
              </p>
              <p class="text-xs text-neutral-600">{{ s.reason }}</p>
              <p v-if="s.ambiguous" class="text-xs text-warning-700">{{ t('accounting.statements.mapping.suggestion_ambiguous') }}</p>
            </div>
          </li>
        </ul>
      </div>
      <p v-if="otherTabSuggestions > 0" class="px-4 py-2 text-xs text-neutral-500 border-t border-neutral-100">
        {{ t('accounting.statements.mapping.suggestions_other_tab', { count: otherTabSuggestions }) }}
      </p>
      <details v-if="suggestions.differences.length > 0" class="border-t border-neutral-100">
        <summary class="px-4 py-2 text-xs font-medium text-neutral-600 cursor-pointer">{{ t('accounting.statements.mapping.differences_title') }}</summary>
        <div class="overflow-x-auto">
          <table class="w-full text-xs">
            <thead class="bg-neutral-50 text-neutral-500">
              <tr>
                <th class="px-4 py-1.5 text-left font-medium">{{ t('accounting.statements.mapping.col_c_radku') }}</th>
                <th class="px-2 py-1.5 text-left font-medium">{{ t('accounting.statements.mapping.col_row') }}</th>
                <th class="px-2 py-1.5 text-right font-medium">{{ t('accounting.statements.mapping.col_app') }}</th>
                <th class="px-2 py-1.5 text-right font-medium">{{ t('accounting.statements.mapping.col_filed') }}</th>
                <th class="px-4 py-1.5 text-right font-medium">{{ t('accounting.statements.mapping.col_diff') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="d in suggestions.differences" :key="`${d.sentence}-${d.c_radku}`">
                <td class="px-4 py-1 font-mono whitespace-nowrap">{{ d.sentence }} {{ d.c_radku }}</td>
                <td class="px-2 py-1 font-mono">{{ d.row_code ?? '' }}</td>
                <td class="px-2 py-1 text-right font-mono">{{ d.app }}</td>
                <td class="px-2 py-1 text-right font-mono">{{ d.filed }}</td>
                <td class="px-4 py-1 text-right font-mono">{{ d.diff }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </details>
    </div>

    <!-- Náhled dopadu -->
    <div v-if="preview" class="bg-surface border border-neutral-200 rounded-lg shadow-sm mb-4" data-test="preview">
      <div class="px-4 py-2 bg-neutral-50 border-b border-neutral-200">
        <h2 class="text-sm font-semibold">{{ t('accounting.statements.mapping.preview_title') }}</h2>
      </div>
      <p v-if="preview.balanced_after === false" class="px-4 py-2 text-sm text-danger-700 bg-danger-50">
        {{ t('accounting.statements.mapping.preview_unbalanced') }}
      </p>
      <p v-if="preview.rows.length === 0" class="px-4 py-3 text-sm text-neutral-600">{{ t('accounting.statements.mapping.preview_empty') }}</p>
      <div v-else class="overflow-x-auto">
        <table class="w-full text-xs">
          <thead class="bg-neutral-50 text-neutral-500">
            <tr>
              <th class="px-4 py-1.5 text-left font-medium">{{ t('accounting.statements.mapping.col_row') }}</th>
              <th class="px-2 py-1.5 text-right font-medium whitespace-nowrap">{{ t('accounting.statements.mapping.preview_before') }}</th>
              <th class="px-2 py-1.5 text-right font-medium whitespace-nowrap">{{ t('accounting.statements.mapping.preview_after') }}</th>
              <th class="px-4 py-1.5 text-right font-medium whitespace-nowrap">{{ t('accounting.statements.mapping.preview_delta') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="r in preview.rows" :key="r.row_code" data-test="preview-row">
              <td class="px-4 py-1" :style="{ paddingLeft: `${1 + (r.level - 1) * 0.75}rem` }">
                <span class="font-mono text-neutral-500">{{ r.display_code }}</span> {{ r.label }}
              </td>
              <td class="px-2 py-1 text-right font-mono whitespace-nowrap">{{ formatMoney(r.before) }}</td>
              <td class="px-2 py-1 text-right font-mono whitespace-nowrap">{{ formatMoney(r.after) }}</td>
              <td class="px-4 py-1 text-right font-mono whitespace-nowrap" :class="r.delta > 0 ? 'text-success-700' : 'text-danger-700'">{{ formatMoney(r.delta) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Filtr -->
    <div class="flex flex-wrap items-center gap-3 mb-3 text-sm">
      <input v-model="search" type="search" data-test="search" :placeholder="t('accounting.statements.mapping.search_placeholder')"
             class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm w-full sm:w-64" />
      <label class="inline-flex items-center gap-1.5 whitespace-nowrap">
        <input v-model="onlyBalances" type="checkbox" data-test="only-balances" /> {{ t('accounting.statements.mapping.only_balances') }}
      </label>
      <label class="inline-flex items-center gap-1.5 whitespace-nowrap">
        <input v-model="onlyOverrides" type="checkbox" /> {{ t('accounting.statements.mapping.only_overrides') }}
      </label>
    </div>

    <div v-if="loading" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-8 text-center text-neutral-400">
      {{ t('common.loading') }}…
    </div>
    <template v-else-if="overview">
      <EmptyState v-if="overview.accounts.length === 0" dense accent="neutral" icon="tag" :title="t('accounting.statements.mapping.empty')" />
      <EmptyState v-else-if="filteredAccounts.length === 0" dense accent="neutral" icon="tag" :title="t('accounting.statements.mapping.no_results')" />
      <template v-else>
        <!-- Desktop: tabulka -->
        <div class="hidden md:block bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <div class="overflow-x-auto">
            <table class="w-full text-xs">
              <thead class="bg-neutral-50 text-neutral-500">
                <tr>
                  <th class="px-3 py-2 text-left font-medium">{{ t('accounting.statements.mapping.col_account') }}</th>
                  <th class="px-2 py-2 text-right font-medium">{{ t('accounting.statements.mapping.col_balance') }}</th>
                  <th class="px-2 py-2 text-left font-medium">{{ t('accounting.statements.mapping.col_current_row') }}</th>
                  <th class="px-2 py-2 text-left font-medium">{{ t('accounting.statements.mapping.col_new_row') }}</th>
                  <th class="px-2 py-2 text-left font-medium">{{ t('accounting.statements.mapping.col_condition') }}</th>
                  <th class="px-2 py-2 text-left font-medium">{{ t('accounting.statements.mapping.col_note') }}</th>
                  <th class="px-2 py-2 text-left font-medium whitespace-nowrap">{{ t('accounting.statements.mapping.col_validity') }}</th>
                  <th class="px-3 py-2 w-8"></th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="a in filteredAccounts" :key="a.account_code" :data-test="`account-${a.account_code}`"
                    :class="overrideFor(a.account_code) ? 'bg-primary-50/40' : inheritedOverride(a.account_code) ? 'bg-primary-50/20' : ''">
                  <td class="px-3 py-1.5">
                    <div class="flex items-baseline gap-2">
                      <span class="font-mono whitespace-nowrap" :class="a.is_synthetic ? 'font-semibold' : ''">{{ a.account_code }}</span>
                      <span class="text-neutral-600">{{ a.name }}</span>
                    </div>
                  </td>
                  <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap">{{ formatMoney(a.balance) }}</td>
                  <td class="px-2 py-1.5">
                    <span v-if="a.mappings.length === 0" class="text-warning-700">{{ t('accounting.statements.mapping.unmapped') }}</span>
                    <div v-for="m in currentRows(a)" :key="m.code + m.source" class="flex flex-wrap items-center gap-1">
                      <span>{{ m.text }}</span>
                      <span class="rounded px-1.5 py-0.5 text-[10px] font-medium whitespace-nowrap"
                            :class="m.source === 'override' ? 'bg-primary-100 text-primary-800' : 'bg-neutral-100 text-neutral-600'">{{ sourceLabel(m.source) }}</span>
                    </div>
                  </td>
                  <td class="px-2 py-1.5">
                    <select :value="overrideFor(a.account_code)?.row_code ?? ''" :disabled="!canWrite" data-test="row-select"
                            class="h-8 px-2 border border-neutral-300 rounded-md bg-surface text-xs max-w-[18rem]"
                            :title="inheritedOverride(a.account_code) ? t('accounting.statements.mapping.inherited_hint', { prefix: inheritedOverride(a.account_code)!.account_prefix }) : undefined"
                            @change="setRow(a.account_code, ($event.target as HTMLSelectElement).value)">
                      <option value="">{{ globalOptionLabel(a.account_code) }}</option>
                      <option v-for="r in rowOptions" :key="r.row_code" :value="r.row_code">{{ rowOptionLabel(r) }}</option>
                    </select>
                    <label v-if="overrideFor(a.account_code) && sectionOf(overrideFor(a.account_code)!.row_code) === 'assets'"
                           class="mt-1 flex items-center gap-1 text-[11px] text-neutral-600">
                      <input type="checkbox" :checked="overrideFor(a.account_code)!.target === 'correction'" :disabled="!canWrite"
                             @change="setField(a.account_code, ($event.target as HTMLInputElement).checked ? { target: 'correction' } : { target: 'gross', follows_prefix: null })" />
                      {{ t('accounting.statements.mapping.target_correction') }}
                    </label>
                    <label v-if="overrideFor(a.account_code)?.target === 'correction'" class="mt-1 flex items-center gap-1 text-[11px] text-neutral-600"
                           :title="t('accounting.statements.mapping.follows_hint')">
                      {{ t('accounting.statements.mapping.follows_label') }}
                      <input type="text" maxlength="10" :value="overrideFor(a.account_code)!.follows_prefix ?? ''" :disabled="!canWrite"
                             :placeholder="t('accounting.statements.mapping.follows_placeholder')" data-test="follows"
                             class="h-7 w-24 px-1.5 border border-neutral-300 rounded-md bg-surface text-[11px] font-mono"
                             @change="setField(a.account_code, { follows_prefix: ($event.target as HTMLInputElement).value.trim() || null })" />
                    </label>
                  </td>
                  <td class="px-2 py-1.5">
                    <select v-if="overrideFor(a.account_code)" :value="overrideFor(a.account_code)!.balance_condition" :disabled="!canWrite"
                            class="h-8 px-2 border border-neutral-300 rounded-md bg-surface text-xs"
                            @change="setField(a.account_code, { balance_condition: ($event.target as HTMLSelectElement).value as StatementBalanceCondition })">
                      <option v-for="c in CONDITIONS" :key="c" :value="c">{{ t(`accounting.statements.mapping.condition_${c}`) }}</option>
                    </select>
                  </td>
                  <td class="px-2 py-1.5">
                    <input v-if="overrideFor(a.account_code)" type="text" maxlength="255" :value="overrideFor(a.account_code)!.note ?? ''" :disabled="!canWrite"
                           :placeholder="t('accounting.statements.mapping.note_placeholder')"
                           class="h-8 px-2 border border-neutral-300 rounded-md bg-surface text-xs w-full min-w-[10rem]"
                           @change="setField(a.account_code, { note: ($event.target as HTMLInputElement).value || null })" />
                    <span v-else-if="inheritedOverride(a.account_code)" data-test="inherited-note" class="text-neutral-500 italic">
                      <span class="font-mono not-italic">{{ inheritedOverride(a.account_code)!.account_prefix }}</span>
                      {{ inheritedOverride(a.account_code)!.note ?? '' }}
                    </span>
                  </td>
                  <td class="px-2 py-1.5">
                    <div v-if="overrideFor(a.account_code)" class="flex items-center gap-1" data-test="validity">
                      <input type="number" min="1900" max="2999" step="1" :value="overrideFor(a.account_code)!.valid_from_year ?? ''" :disabled="!canWrite"
                             :placeholder="t('accounting.statements.mapping.validity_from')" :aria-label="t('accounting.statements.mapping.validity_from')"
                             class="h-8 w-20 px-1.5 border border-neutral-300 rounded-md bg-surface text-xs" data-test="valid-from"
                             @change="setField(a.account_code, { valid_from_year: parseYear(($event.target as HTMLInputElement).value) })" />
                      <span class="text-neutral-400">–</span>
                      <input type="number" min="1900" max="2999" step="1" :value="overrideFor(a.account_code)!.valid_to_year ?? ''" :disabled="!canWrite"
                             :placeholder="t('accounting.statements.mapping.validity_to')" :aria-label="t('accounting.statements.mapping.validity_to')"
                             class="h-8 w-20 px-1.5 border border-neutral-300 rounded-md bg-surface text-xs" data-test="valid-to"
                             @change="setField(a.account_code, { valid_to_year: parseYear(($event.target as HTMLInputElement).value) })" />
                    </div>
                    <span v-else-if="inheritedOverride(a.account_code)" class="text-neutral-500 whitespace-nowrap">{{ validityLabel(inheritedOverride(a.account_code)!) }}</span>
                  </td>
                  <td class="px-3 py-1.5 text-right">
                    <button v-if="canWrite && overrideFor(a.account_code)" type="button" data-test="remove"
                            :class="btnIconSm('danger')" :title="t('accounting.statements.mapping.remove')" :aria-label="t('accounting.statements.mapping.remove')"
                            @click="removeOverride(a.account_code)">
                      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Mobil: karty -->
        <div class="md:hidden space-y-2">
          <div v-for="a in filteredAccounts" :key="a.account_code" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3 text-sm"
               :class="overrideFor(a.account_code) ? 'border-primary-300' : ''">
            <div class="flex items-start justify-between gap-2">
              <div class="min-w-0">
                <p class="font-mono font-medium">{{ a.account_code }}</p>
                <p class="text-xs text-neutral-600 truncate">{{ a.name }}</p>
              </div>
              <p class="font-mono whitespace-nowrap">{{ formatMoney(a.balance) }}</p>
            </div>
            <div class="mt-2 text-xs">
              <span v-if="a.mappings.length === 0" class="text-warning-700">{{ t('accounting.statements.mapping.unmapped') }}</span>
              <p v-for="m in currentRows(a)" :key="m.code + m.source">{{ m.text }} · <span class="text-neutral-500">{{ sourceLabel(m.source) }}</span></p>
            </div>
            <div class="mt-2 flex flex-wrap items-center gap-2">
              <select :value="overrideFor(a.account_code)?.row_code ?? ''" :disabled="!canWrite"
                      class="h-9 px-2 border border-neutral-300 rounded-md bg-surface text-xs w-full"
                      @change="setRow(a.account_code, ($event.target as HTMLSelectElement).value)">
                <option value="">{{ globalOptionLabel(a.account_code) }}</option>
                <option v-for="r in rowOptions" :key="r.row_code" :value="r.row_code">{{ rowOptionLabel(r) }}</option>
              </select>
              <template v-if="overrideFor(a.account_code)">
                <select :value="overrideFor(a.account_code)!.balance_condition" :disabled="!canWrite"
                        class="h-9 px-2 border border-neutral-300 rounded-md bg-surface text-xs flex-1"
                        @change="setField(a.account_code, { balance_condition: ($event.target as HTMLSelectElement).value as StatementBalanceCondition })">
                  <option v-for="c in CONDITIONS" :key="c" :value="c">{{ t(`accounting.statements.mapping.condition_${c}`) }}</option>
                </select>
                <button v-if="canWrite" type="button" :class="btnIconSm('danger')" :title="t('accounting.statements.mapping.remove')"
                        :aria-label="t('accounting.statements.mapping.remove')" @click="removeOverride(a.account_code)">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                </button>
                <input type="text" maxlength="255" :value="overrideFor(a.account_code)!.note ?? ''" :disabled="!canWrite"
                       :placeholder="t('accounting.statements.mapping.note_placeholder')"
                       class="h-9 px-2 border border-neutral-300 rounded-md bg-surface text-xs w-full"
                       @change="setField(a.account_code, { note: ($event.target as HTMLInputElement).value || null })" />
                <div class="flex items-center gap-1 text-xs">
                  <span class="text-neutral-500">{{ t('accounting.statements.mapping.col_validity') }}</span>
                  <input type="number" min="1900" max="2999" step="1" :value="overrideFor(a.account_code)!.valid_from_year ?? ''" :disabled="!canWrite"
                         :placeholder="t('accounting.statements.mapping.validity_from')" :aria-label="t('accounting.statements.mapping.validity_from')"
                         class="h-9 w-20 px-1.5 border border-neutral-300 rounded-md bg-surface text-xs"
                         @change="setField(a.account_code, { valid_from_year: parseYear(($event.target as HTMLInputElement).value) })" />
                  <span class="text-neutral-400">–</span>
                  <input type="number" min="1900" max="2999" step="1" :value="overrideFor(a.account_code)!.valid_to_year ?? ''" :disabled="!canWrite"
                         :placeholder="t('accounting.statements.mapping.validity_to')" :aria-label="t('accounting.statements.mapping.validity_to')"
                         class="h-9 w-20 px-1.5 border border-neutral-300 rounded-md bg-surface text-xs"
                         @change="setField(a.account_code, { valid_to_year: parseYear(($event.target as HTMLInputElement).value) })" />
                </div>
              </template>
            </div>
          </div>
        </div>
      </template>

      <!-- Výjimky pro skupinu účtů: prefix, který v osnově není samostatným účtem (062., 351.) -->
      <div v-if="orphans.length > 0" class="mt-4 bg-surface border border-primary-200 rounded-lg shadow-sm" data-test="group-overrides">
        <div class="px-4 py-2 bg-primary-50 border-b border-primary-200">
          <p class="text-xs font-medium text-primary-800">{{ t('accounting.statements.mapping.orphans_title') }}</p>
          <p class="text-[11px] text-neutral-600 mt-0.5">{{ t('accounting.statements.mapping.orphans_hint') }}</p>
        </div>
        <ul class="divide-y divide-neutral-100">
          <li v-for="o in orphans" :key="keyOf(o)" class="px-4 py-2 text-xs flex flex-wrap items-center gap-2" :data-test="`group-${o.account_prefix}`">
            <span class="min-w-[16rem] flex-1">
              <span class="font-mono">{{ o.account_prefix }}</span> → {{ rowText(rowsByCode.get(o.row_code), o.row_code) }}
              <span class="text-neutral-500">({{ t('accounting.statements.mapping.orphans_accounts', { count: groupSize(o.account_prefix) }) }})</span>
            </span>
            <select :value="o.balance_condition" :disabled="!canWrite"
                    class="h-8 px-2 border border-neutral-300 rounded-md bg-surface text-xs"
                    @change="setField(o.account_prefix, { balance_condition: ($event.target as HTMLSelectElement).value as StatementBalanceCondition })">
              <option v-for="c in CONDITIONS" :key="c" :value="c">{{ t(`accounting.statements.mapping.condition_${c}`) }}</option>
            </select>
            <input type="text" maxlength="255" :value="o.note ?? ''" :disabled="!canWrite"
                   :placeholder="t('accounting.statements.mapping.note_placeholder')"
                   class="h-8 px-2 border border-neutral-300 rounded-md bg-surface text-xs flex-1 min-w-[12rem]"
                   @change="setField(o.account_prefix, { note: ($event.target as HTMLInputElement).value || null })" />
            <span class="flex items-center gap-1">
              <input type="number" min="1900" max="2999" step="1" :value="o.valid_from_year ?? ''" :disabled="!canWrite"
                     :placeholder="t('accounting.statements.mapping.validity_from')" :aria-label="t('accounting.statements.mapping.validity_from')"
                     class="h-8 w-20 px-1.5 border border-neutral-300 rounded-md bg-surface text-xs"
                     @change="setField(o.account_prefix, { valid_from_year: parseYear(($event.target as HTMLInputElement).value) })" />
              <span class="text-neutral-400">–</span>
              <input type="number" min="1900" max="2999" step="1" :value="o.valid_to_year ?? ''" :disabled="!canWrite"
                     :placeholder="t('accounting.statements.mapping.validity_to')" :aria-label="t('accounting.statements.mapping.validity_to')"
                     class="h-8 w-20 px-1.5 border border-neutral-300 rounded-md bg-surface text-xs"
                     @change="setField(o.account_prefix, { valid_to_year: parseYear(($event.target as HTMLInputElement).value) })" />
            </span>
            <button v-if="canWrite" type="button" :class="btnIconSm('danger')" :title="t('accounting.statements.mapping.remove')"
                    :aria-label="t('accounting.statements.mapping.remove')" @click="removeOverride(o.account_prefix)">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
            </button>
          </li>
        </ul>
      </div>

      <!-- Výjimky platné v jiných letech než rok editoru -->
      <div v-if="otherYears.length > 0" class="mt-4 bg-surface border border-neutral-200 rounded-lg shadow-sm" data-test="other-years">
        <div class="px-4 py-2 bg-neutral-50 border-b border-neutral-200">
          <p class="text-xs font-medium text-neutral-800">{{ t('accounting.statements.mapping.other_years_title', { year: year ?? '' }) }}</p>
          <p class="text-[11px] text-neutral-600 mt-0.5">{{ t('accounting.statements.mapping.other_years_hint') }}</p>
        </div>
        <ul class="divide-y divide-neutral-100">
          <li v-for="o in otherYears" :key="keyOf(o)" class="px-4 py-2 text-xs flex flex-wrap items-center gap-2">
            <span class="min-w-[16rem] flex-1">
              <span class="font-mono">{{ o.account_prefix }}</span> → {{ rowText(rowsByCode.get(o.row_code), o.row_code) }}
              <span v-if="o.target === 'correction'" class="text-neutral-500">({{ t('accounting.statements.mapping.target_correction') }})</span>
              <span v-if="o.note" class="text-neutral-500 italic"> · {{ o.note }}</span>
            </span>
            <span class="flex items-center gap-1">
              <input type="number" min="1900" max="2999" step="1" :value="o.valid_from_year ?? ''" :disabled="!canWrite"
                     :placeholder="t('accounting.statements.mapping.validity_from')" :aria-label="t('accounting.statements.mapping.validity_from')"
                     class="h-8 w-20 px-1.5 border border-neutral-300 rounded-md bg-surface text-xs"
                     @change="setOtherField(o, { valid_from_year: parseYear(($event.target as HTMLInputElement).value) })" />
              <span class="text-neutral-400">–</span>
              <input type="number" min="1900" max="2999" step="1" :value="o.valid_to_year ?? ''" :disabled="!canWrite"
                     :placeholder="t('accounting.statements.mapping.validity_to')" :aria-label="t('accounting.statements.mapping.validity_to')"
                     class="h-8 w-20 px-1.5 border border-neutral-300 rounded-md bg-surface text-xs"
                     @change="setOtherField(o, { valid_to_year: parseYear(($event.target as HTMLInputElement).value) })" />
            </span>
            <button v-if="canWrite" type="button" :class="btnIconSm('danger')" :title="t('accounting.statements.mapping.remove')"
                    :aria-label="t('accounting.statements.mapping.remove')" @click="removeOther(o)">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
            </button>
          </li>
        </ul>
      </div>
    </template>

    <!-- Jedno společné Uložit -->
    <div v-if="dirty && canWrite" data-test="save-bar"
         class="sticky bottom-[var(--app-footer-height,0px)] z-10 mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-neutral-200 bg-surface/95 py-3">
      <span class="text-sm text-neutral-600">{{ t('accounting.statements.mapping.dirty_hint', { count: dirtyCount }) }}</span>
      <div class="flex flex-wrap gap-2">
        <button type="button" :class="btnOutline('neutral')" data-test="discard" :disabled="saving" @click="discard">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.uturn" /></svg>
          {{ t('accounting.statements.mapping.discard') }}
        </button>
        <button type="button" :class="btnFilled('success')" data-test="save" :disabled="saving" @click="save">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
          {{ saving ? '…' : t('accounting.statements.mapping.save') }}
        </button>
      </div>
    </div>
  </div>
</template>
