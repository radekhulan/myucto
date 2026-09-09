<script setup lang="ts">
import { ref, computed, onMounted, reactive, watch, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, RouterLink, type RouteLocationRaw } from 'vue-router'
import { accountingApi, postingErrorI18nKey, type ChartAccount } from '@/api/accounting'
import { apiErrorMessage } from '@/api/errors'
import {
  closingApi,
  type ClosingState,
  type ClosingStep,
  type ClosingStepKey,
  type PrecheckItem,
  type FxPreview,
  type AssistedEntryRef,
  type EstimatesSuggest,
  type EstimateSuggestItem,
  type LegalProvisionSection,
  type ProvisionsPreview,
  type IncomeTaxPreview,
  type ProfitDistributionPreview,
  type ProfitDistributionAllocation,
  type SmallAssetAccrualPreview,
  type SmallAssetAccrualMode,
  type PrepaidExpenseAccrualPreview,
  type StockTotals,
  type StockTotalsGroup,
  type StockWarning,
} from '@/api/closing'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatMoney, formatDate } from '@/composables/useFormat'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import CheckFindings from '@/components/accounting/CheckFindings.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import DateInput from '@/components/ui/DateInput.vue'

const { t } = useI18n()
const route = useRoute()
const auth = useAuthStore()
const toast = useToast()
const pageId = useId()

const periodId = Number(route.params.id)

const STEP_ORDER: ClosingStepKey[] = [
  'precheck', 'depreciation', 'fx_revaluation', 'estimates', 'deferrals', 'provisions', 'income_tax', 'stock', 'close_books', 'open_next',
]

const state = ref<ClosingState | null>(null)
const loading = ref(false)
const busy = ref(false)
const selected = ref<ClosingStepKey>('precheck')
const accounts = ref<ChartAccount[]>([])

const rowVersion = computed(() => state.value?.period.row_version ?? 0)

const stepsByKey = computed<Record<string, ClosingStep | undefined>>(() => {
  const m: Record<string, ClosingStep | undefined> = {}
  for (const s of state.value?.steps ?? []) m[s.step_key] = s
  return m
})

function step(key: ClosingStepKey): ClosingStep | undefined {
  return stepsByKey.value[key]
}

const closeBooksPayload = computed(() => step('close_books')?.payload ?? null)
const openNextPayload = computed(() => step('open_next')?.payload ?? null)

// ── Krok zásob (SKLAD §3.4) — data z payloadu kroku (backend nemá GET náhled;
//    „náhled" = idempotentní přepočet přes runStep 'stock', re-run = rewrite). ──
const stockPayload = computed(() => step('stock')?.payload ?? null)
const stockTotals = computed<StockTotals | null>(() => stockPayload.value?.totals ?? null)
const stockEntryIds = computed<Record<string, number>>(() => stockPayload.value?.entry_ids ?? {})
const stockWarnings = computed<StockWarning[]>(() => stockPayload.value?.warnings ?? [])
function stockGroupSum(g?: StockTotalsGroup): number {
  return g ? (g.material ?? 0) + (g.goods ?? 0) + (g.product ?? 0) : 0
}
const stockClosingTotal = computed(() => stockGroupSum(stockTotals.value?.closing))
const stockShortageTotal = computed(() => stockGroupSum(stockTotals.value?.shortage))
const stockSurplusTotal = computed(() => stockGroupSum(stockTotals.value?.surplus))
const stockNetDiff = computed(() => stockSurplusTotal.value - stockShortageTotal.value)

/**
 * Kroky, které v tomhle období nedávají smysl (firma bez odpisovaného majetku,
 * firma bez skladu v podvojném účetnictví). Server je nevyžaduje k uzavření knih
 * (viz preCloseStepKeys → stock_step_required / depreciation_step_required); UI je
 * nesmí nabízet k potvrzení, jinak by uživatel „přeskakoval" krok, který nemá co
 * dělat — a skip se v auditní stopě čte jako rozhodnutí, ne jako „nebylo co dělat".
 *
 * Krok `stock` (SKLAD §3.4, uzávěrka způsobem B) se řeší jako plná karta mezi daní
 * z příjmů a uzavřením knih: je-li sklad vypnutý (stock_step_required=false), zobrazí
 * se jako netýkající se / auto-skipped a uzavření knih neblokuje — analogicky odpisům.
 */
const depreciationRequired = computed(() => state.value?.depreciation_step_required !== false)
const stockRequired = computed(() => state.value?.stock_step_required !== false)
// Uzávěrka zásob je v pořadí až za daní z příjmů, ale mění náklady (způsob B odúčtuje
// konečný stav z 501/504) — dokud neproběhla, je dopočtený základ daně předběžný.
const stockStepPending = computed(() => stockRequired.value && step('stock')?.status === 'pending')
function stepApplicable(key: ClosingStepKey): boolean {
  if (key === 'depreciation') return depreciationRequired.value
  if (key === 'stock') return stockRequired.value
  return true
}

async function load() {
  loading.value = true
  try {
    state.value = await closingApi.state(periodId)
    // Předvyber první nehotový krok — nerelevantní kroky přeskoč.
    const firstPending = STEP_ORDER.find(
      k => stepApplicable(k) && (stepsByKey.value[k]?.status ?? 'pending') === 'pending',
    )
    if (firstPending && (stepsByKey.value[selected.value]?.status ?? 'pending') !== 'pending') {
      selected.value = firstPending
    }
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  await load()
  try { accounts.value = await accountingApi.listAccounts() } catch { accounts.value = [] }
})

function handleApiError(e: any) {
  const status = e?.response?.status
  const code = e?.response?.data?.error?.code
  if (status === 409 || code === 'version_conflict') {
    toast.error(t('accounting.closing.errors.version_conflict'))
    load()
    return
  }
  const key = `accounting.closing.errors.${code}`
  const localized = code ? t(key) : ''
  toast.error(localized && localized !== key
    ? localized
    : (e?.response?.data?.error?.message || t('common.error')))
}

async function mutate(fn: () => Promise<unknown>, successMsg?: string) {
  busy.value = true
  try {
    await fn()
    if (successMsg) toast.success(successMsg)
    await load()
    return true
  } catch (e: any) {
    handleApiError(e)
    return false
  } finally {
    busy.value = false
  }
}

// ── Start / abort ──────────────────────────────────────────────────────────
function startClosing() {
  mutate(() => closingApi.start(periodId, rowVersion.value), t('accounting.closing.started'))
}
function abortClosing() {
  if (!confirm(t('accounting.closing.abort_confirm'))) return
  mutate(() => closingApi.abort(periodId, rowVersion.value), t('accounting.closing.aborted'))
}

// ── Precheck ───────────────────────────────────────────────────────────────
const precheckItems = computed<PrecheckItem[]>(() => {
  const p = step('precheck')?.payload
  if (!p) return []
  if (Array.isArray(p)) return p as unknown as PrecheckItem[]
  return p.checks ?? []
})

// Uložený precheck snímek zastaral (BE porovná živé error-kontroly proti snímku).
// Typicky po uzavření předchozího období: staré červené chyby už neplatí.
const precheckStale = computed(() => state.value?.precheck_stale ?? false)

// Blokují jen SKUTEČNĚ NEsplněné (ok=false) error kontroly — ne error-severity kontrola,
// která prošla (např. u prvního období „předchozí období není uzavřené" = ok, není žádné).
// Zastaralý snímek neblokuje — jeho chyby už nemusí platit; vyzveme k re-runu.
const precheckHasErrors = computed(() =>
  !precheckStale.value && precheckItems.value.some(c => !c.ok && c.severity === 'error'))

/**
 * Proč nejde uzavřít knihy. Blokující nálezy jsou vidět jen v kroku 1, takže bez tohohle
 * shrnutí zůstane u kroku 9 jen zašedlé tlačítko bez vysvětlení.
 */
const closeBlockedReason = computed<string | null>(() => {
  if (!state.value || busy.value) return null
  if (!isClosing.value) return t('accounting.closing.close.blocked_not_closing')
  const failing = precheckItems.value.filter(c => !c.ok && c.severity === 'error')
  if (!precheckStale.value && failing.length) {
    return t('accounting.closing.close.blocked_precheck', {
      checks: failing.map(c => checkLabel(c.key)).join(', '),
    })
  }
  if (!(state.value.can_close ?? true)) return t('accounting.closing.close.blocked_steps')
  return null
})

function runPrecheck() {
  mutate(() => closingApi.runStep(periodId, 'precheck', { row_version: rowVersion.value }),
    t('accounting.closing.precheck_done'))
}

function checkLabel(key: string): string {
  const k = `accounting.closing.checks.${key}`
  const v = t(k)
  return v === k ? key : v
}

function checkValue(value: unknown): string {
  if (value === null || value === undefined) return ''
  if (typeof value === 'number') return formatMoney(value)
  if (typeof value === 'string') return value
  // Objekty už sem nepatří — ty renderuje CheckFindings podle tvaru dat. Zbytek je
  // pojistka, aby se sem případná neznámá hodnota nedostala jako „[object Object]".
  return JSON.stringify(value)
}

/**
 * CSV export nálezů kontroly. Precheck běží nad CELÝM obdobím, takže se rozsah
 * nepředává — endpoint si default (celé období) dosadí sám.
 */
function checkExportUrl(key: string): string | undefined {
  if (!periodId) return undefined
  const sid = localStorage.getItem('myinvoice.current_supplier_id')
  const q = sid ? `?supplier_id=${encodeURIComponent(sid)}` : ''
  return `/api/accounting/periods/${periodId}/checks/${key}/export${q}`
}

// Proklikávací výsledky prechecku: kontroly nezaúčtovaných dokladů nesou
// { count, ids } — místo syrového JSON renderujeme odkazy na doklad i do
// seznamu předfiltrovaného na nezaúčtované (booked=0), viz A5 auditu.
const DOC_CHECK_LIST: Record<string, RouteLocationRaw> = {
  unposted_invoices:  { path: '/invoices', query: { booked: '0', year: 'all' } },
  unposted_purchases: { path: '/purchase-invoices', query: { booked: '0' } },
}

function docListLink(key: string): RouteLocationRaw | null {
  return DOC_CHECK_LIST[key] ?? null
}

function severityClass(sev: string): string {
  if (sev === 'error') return 'bg-danger-50 text-danger-600'
  if (sev === 'warning') return 'bg-warning-50 text-warning-600'
  return 'bg-neutral-100 text-neutral-500'
}

// ── Checklist kroky (depreciation / estimates / deferrals confirm & skip) ──
const stepNote = reactive<Record<string, string>>({ depreciation: '', estimates: '', deferrals: '' })

function confirmStep(key: ClosingStepKey, status: 'done' | 'skipped') {
  const note = stepNote[key]?.trim()
  mutate(() => closingApi.runStep(periodId, key, {
    row_version: rowVersion.value,
    status,
    ...(note ? { note } : {}),
  }), t('common.saved'))
}

// ── Odpisy roku (krok 2) ───────────────────────────────────────────────────
const bookingDepreciation = ref(false)

async function bookDepreciation() {
  bookingDepreciation.value = true
  try {
    const r = await closingApi.bookDepreciation(periodId)
    // Chyby nesou strojový kód — přeložíme ho a pojmenujeme dotčené karty, ať uživatel
    // nemusí hádat, proč se nezaúčtovalo (dřív se ukazoval jen počet).
    if (r.errors?.length) {
      const byCode = new Map<string, number[]>()
      for (const e of r.errors) byCode.set(e.code, [...(byCode.get(e.code) ?? []), e.asset_id])
      const detail = [...byCode.entries()]
        .map(([code, ids]) => `${t(postingErrorI18nKey(code))} (#${ids.join(', #')})`)
        .join(' ')
      toast.warning(`${t('accounting.assets.book.result_with_errors', {
        booked: r.booked, skipped: r.skipped, errors: r.errors.length,
      })} ${detail}`)
    } else {
      toast.success(t('accounting.assets.book.result', { booked: r.booked, skipped: r.skipped }))
    }
    await load()
  } catch (e: any) {
    toast.error(apiErrorMessage(e))
  } finally {
    bookingDepreciation.value = false
  }
}

// ── Kurzové rozdíly ────────────────────────────────────────────────────────
const fxPreview = ref<FxPreview | null>(null)
const fxLoading = ref(false)
interface BankRowForm { account_code: string; currency_code: string; foreign_balance: number | null }
const bankRows = ref<BankRowForm[]>([])
const fxLoaded = ref(false)

function validBankRows() {
  return bankRows.value
    .filter(r => r.account_code.trim() && r.currency_code.trim() && r.foreign_balance !== null)
    .map(r => ({
      account_code: r.account_code.trim(),
      currency_code: r.currency_code.trim().toUpperCase(),
      foreign_balance: Number(r.foreign_balance),
    }))
}

async function loadFxPreview(withRows: boolean) {
  fxLoading.value = true
  try {
    fxPreview.value = await closingApi.fxPreview(periodId, withRows ? validBankRows() : undefined)
    if (!withRows) {
      const lines = fxPreview.value.bank?.lines ?? []
      if (lines.length) {
        bankRows.value = lines.map(l => ({
          account_code: l.account_code ?? '',
          currency_code: l.currency_code ?? '',
          foreign_balance: l.foreign_balance ?? null,
        }))
      } else {
        // R10b poloautomat: předvyplnit návrhy devizových zůstatků z deníku k D —
        // account_code je rovnou účet osnovy, uživatel jen zkontroluje/upraví.
        bankRows.value = (fxPreview.value.proposals ?? [])
          .filter(p => p.currency_code && p.currency_code !== 'CZK')
          .map(p => ({
            account_code: p.account_code,
            currency_code: p.currency_code,
            foreign_balance: p.foreign_balance,
          }))
      }
    }
    fxLoaded.value = true
  } catch (e: any) {
    handleApiError(e)
  } finally {
    fxLoading.value = false
  }
}

watch(selected, (k) => {
  if (k !== 'fx_revaluation' || fxLoaded.value) return
  const st = step('fx_revaluation')?.status ?? 'pending'
  if (state.value?.period.status === 'closing' || st !== 'pending') {
    loadFxPreview(false)
  }
})

function addBankRow() {
  bankRows.value.push({ account_code: '', currency_code: '', foreign_balance: null })
}
function removeBankRow(i: number) {
  bankRows.value.splice(i, 1)
}

function bookFx() {
  mutate(() => closingApi.runStep(periodId, 'fx_revaluation', {
    row_version: rowVersion.value,
    bank_rows: validBankRows(),
  }), t('accounting.closing.fx.booked')).then(ok => { if (ok) loadFxPreview(true) })
}

function revertFx() {
  if (!confirm(t('accounting.closing.revert.confirm'))) return
  mutate(() => closingApi.revertStep(periodId, 'fx_revaluation', rowVersion.value),
    t('accounting.closing.revert.done'))
}

// ── Asistent dohadů / časového rozlišení ───────────────────────────────────
const ASSIST_RULES: Record<'estimates' | 'deferrals', string[]> = {
  estimates: ['estimate.asset', 'estimate.liability'],
  deferrals: [
    'accrual.prepaid.expense',
    'accrual.accrued.expense',
    'accrual.deferred.revenue',
    'accrual.accrued.revenue',
    'accrual.small_asset.defer',
  ],
}

const assistForm = reactive({
  rule_key: '',
  amount: null as number | null,
  description: '',
  counter_account: '',
})

function ruleLabel(ruleKey: string): string {
  const k = `accounting.closing.assist.rules.${ruleKey.replace(/\./g, '_')}`
  const v = t(k)
  return v === k ? ruleKey : v
}

const pickableAccounts = computed(() =>
  accounts.value.filter(a => a.is_active).sort((a, b) => a.account_code.localeCompare(b.account_code)),
)

function createAssisted(stepKey: 'estimates' | 'deferrals') {
  if (!assistForm.rule_key || !(Number(assistForm.amount) > 0) || !assistForm.description.trim()) {
    toast.warning(t('accounting.closing.assist.fields_required'))
    return
  }
  mutate(() => closingApi.createEntry(periodId, {
    row_version: rowVersion.value,
    step: stepKey,
    rule_key: assistForm.rule_key,
    amount: Number(assistForm.amount),
    description: assistForm.description.trim(),
    ...(assistForm.counter_account.trim() ? { counter_account: assistForm.counter_account.trim() } : {}),
  }), t('accounting.closing.assist.created')).then(ok => {
    if (ok) Object.assign(assistForm, { rule_key: '', amount: null, description: '', counter_account: '' })
  })
}

function assistedEntries(stepKey: ClosingStepKey): AssistedEntryRef[] {
  // `Array.isArray`, ne `?? []`: krok s prázdným payloadem přišel z backendu jako `[]`
  // a `[].entries` není `undefined`, ale zděděná `Array.prototype.entries` — nullish
  // operátor ji propustil a `.map()` pak shodil celou stránku uzávěrky na bílo.
  const raw = step(stepKey)?.payload?.entries
  if (!Array.isArray(raw)) return []
  // Backend ukládá klíč entry_id (ClosingService::createAssistedEntry) — sjednotit na id.
  return raw.map(e => (typeof e === 'number'
    ? { id: e }
    : { ...e, id: e.entry_id ?? e.id }))
}

function reverseAssisted(entryId: number) {
  if (!confirm(t('accounting.closing.assist.reverse_confirm', { id: entryId }))) return
  mutate(() => closingApi.reverseEntry(periodId, entryId, rowVersion.value),
    t('accounting.closing.assist.reversed'))
}

// ── §DM / Task 11: časové rozlišení drobného majetku (381 = volitelná politika) ──
const saAccrualPreview = ref<SmallAssetAccrualPreview | null>(null)
const saAccrualLoading = ref(false)
const saAccrualForm = reactive<{ mode: SmallAssetAccrualMode; pct: number | null; materiality_limit: number | null }>({ mode: 'none', pct: 50, materiality_limit: null })

// Task 14: fromSaved=true → náhled se zeptá bez režimu, backend dosadí uloženou
// účetní politiku firmy (small_asset_accrual_mode/pct) a formulář se z ní předvyplní.
// Ruční přepočet (tlačítko) posílá režim z formuláře.
async function loadSmallAssetAccrual(fromSaved = false) {
  saAccrualLoading.value = true
  try {
    const p = await closingApi.smallAssetAccrualPreview(
      periodId,
      fromSaved ? undefined : saAccrualForm.mode,
      fromSaved ? undefined : (saAccrualForm.mode === 'flat_pct' ? saAccrualForm.pct : undefined),
      fromSaved ? undefined : (saAccrualForm.mode === 'flat_pct' ? saAccrualForm.materiality_limit : undefined),
    )
    saAccrualPreview.value = p
    saAccrualForm.mode = p.mode
    if (p.pct != null) saAccrualForm.pct = p.pct
    if (p.materiality?.limit != null) saAccrualForm.materiality_limit = p.materiality.limit
  } catch {
    toast.error(t('accounting.closing.small_asset.load_failed'))
  } finally {
    saAccrualLoading.value = false
  }
}

function runSmallAssetAccrual() {
  if (saAccrualForm.mode === 'flat_pct' && !(Number(saAccrualForm.pct) >= 0 && Number(saAccrualForm.pct) <= 100)) {
    toast.warning(t('accounting.closing.small_asset.pct_invalid'))
    return
  }
  if (saAccrualForm.mode === 'flat_pct' && !(Number(saAccrualForm.materiality_limit) > 0)) {
    toast.warning(t('accounting.closing.small_asset.materiality_required'))
    return
  }
  mutate(() => closingApi.runStep(periodId, 'deferrals', {
    row_version: rowVersion.value,
    small_asset_accrual: {
      mode: saAccrualForm.mode,
      ...(saAccrualForm.mode === 'flat_pct' ? { pct: Number(saAccrualForm.pct), materiality_limit: Number(saAccrualForm.materiality_limit) } : {}),
    },
  }), t('accounting.closing.small_asset.posted')).then(ok => {
    if (ok) loadSmallAssetAccrual()
  })
}

// ── §DČR / Task 12: časové rozlišení nákladů příštích období (381 z označených faktur) ──
const peAccrualPreview = ref<PrepaidExpenseAccrualPreview | null>(null)
const peAccrualLoading = ref(false)

async function loadPrepaidExpenseAccrual() {
  peAccrualLoading.value = true
  try {
    peAccrualPreview.value = await closingApi.prepaidExpenseAccrualPreview(periodId)
  } catch {
    toast.error(t('accounting.closing.prepaid_expense.load_failed'))
  } finally {
    peAccrualLoading.value = false
  }
}

function runPrepaidExpenseAccrual() {
  mutate(() => closingApi.runStep(periodId, 'deferrals', {
    row_version: rowVersion.value,
    prepaid_expense_accrual: true,
  }), t('accounting.closing.prepaid_expense.posted')).then(ok => {
    if (ok) loadPrepaidExpenseAccrual()
  })
}

// ── K10: návrh dohadných položek pasivních (389) ───────────────────────────
const estimatesSuggest = ref<EstimatesSuggest | null>(null)
const estimatesSuggestLoading = ref(false)

async function loadEstimatesSuggest() {
  estimatesSuggestLoading.value = true
  try {
    estimatesSuggest.value = await closingApi.estimatesSuggest(periodId)
  } catch (e: any) {
    handleApiError(e)
  } finally {
    estimatesSuggestLoading.value = false
  }
}

// Předvyplní asistentský formulář z návrhu — účetní částku/protiúčet upraví a potvrdí.
function applyEstimateSuggestion(it: EstimateSuggestItem) {
  Object.assign(assistForm, {
    rule_key: it.rule_key,
    amount: it.suggested_amount,
    description: it.description,
    counter_account: it.counter_account ?? '',
  })
}

// ── D9: opravné položky k pohledávkám ──────────────────────────────────────
const provisionsPreview = ref<ProvisionsPreview | null>(null)
const provisionsLoading = ref(false)
/** Paragrafy zákona o rezervách, které mají v tabulce C DPPO vlastní řádek. */
const LEGAL_SECTIONS = ['8', '8a', '8b', '8c'] as const

const provisionInputs = reactive<Record<number, { legal: number | null; acct: number | null; section: LegalProvisionSection }>>({})

async function loadProvisions() {
  provisionsLoading.value = true
  try {
    const p = await closingApi.provisionsPreview(periodId)
    provisionsPreview.value = p
    for (const it of p.items) {
      if (provisionInputs[it.invoice_id]) continue
      provisionInputs[it.invoice_id] = {
        legal: it.existing ? it.existing.legal_amount : it.suggested_legal_amount,
        acct: it.existing ? it.existing.acct_amount : it.suggested_acct_amount,
        // Už zaúčtovaná volba účetní má přednost před návrhem systému.
        section: it.existing?.legal_section ?? it.legal_section ?? null,
      }
    }
  } catch (e: any) {
    handleApiError(e)
  } finally {
    provisionsLoading.value = false
  }
}

function runProvisions() {
  const items = (provisionsPreview.value?.items ?? []).map(it => ({
    invoice_id: it.invoice_id,
    document_no: it.document_no,
    legal_amount: Number(provisionInputs[it.invoice_id]?.legal ?? 0) || 0,
    acct_amount: Number(provisionInputs[it.invoice_id]?.acct ?? 0) || 0,
    legal_section: provisionInputs[it.invoice_id]?.section ?? null,
  }))
  mutate(() => closingApi.runStep(periodId, 'provisions', { row_version: rowVersion.value, items }),
    t('accounting.closing.provisions.booked')).then(ok => { if (ok) loadProvisions() })
}

// ── D11: splatná daň z příjmů (591/341) ────────────────────────────────────
const incomeTaxPreview = ref<IncomeTaxPreview | null>(null)
const incomeTaxAmount = ref<number | null>(null)

async function loadIncomeTax() {
  try {
    const p = await closingApi.incomeTaxPreview(periodId)
    incomeTaxPreview.value = p
    incomeTaxAmount.value = p.existing_amount ?? p.suggested_amount ?? null
  } catch (e: any) {
    handleApiError(e)
  }
}

function bookIncomeTax() {
  if (!(Number(incomeTaxAmount.value) > 0)) {
    toast.warning(t('accounting.closing.income_tax.amount_required'))
    return
  }
  mutate(() => closingApi.runStep(periodId, 'income_tax', { row_version: rowVersion.value, amount: Number(incomeTaxAmount.value) }),
    t('accounting.closing.income_tax.booked')).then(ok => { if (ok) loadIncomeTax() })
}

watch(selected, (k) => {
  if (k === 'provisions' && !provisionsPreview.value) loadProvisions()
  if (k === 'income_tax' && !incomeTaxPreview.value) loadIncomeTax()
  if (k === 'deferrals' && !peAccrualPreview.value) loadPrepaidExpenseAccrual()
  // Task 14: předvyplň účetní politiku rozlišení drobného majetku z uloženého nastavení firmy.
  if (k === 'deferrals' && !saAccrualPreview.value) loadSmallAssetAccrual(true)
})

// ── D10: rozdělení výsledku hospodaření (431 → 428/429/364…) ────────────────
const showProfitDistribution = ref(false)
const pdPreview = ref<ProfitDistributionPreview | null>(null)
const pdAllocations = ref<ProfitDistributionAllocation[]>([])
const pdDecisionDate = ref('')
const pdWithholdingRate = ref(0.15)

const pdSum = computed(() => pdAllocations.value.reduce((s, a) => s + (Number(a.amount) || 0), 0))
const pdRemaining = computed(() => Math.abs(pdPreview.value?.available_profit ?? 0) - pdSum.value)

async function openProfitDistribution() {
  showProfitDistribution.value = true
  try {
    pdPreview.value = await closingApi.profitDistributionPreview(periodId)
    pdWithholdingRate.value = pdPreview.value.withholding_rate
    if (!pdAllocations.value.length) {
      pdAllocations.value = [{ account_code: pdPreview.value.is_loss ? '429' : '428', amount: 0, kind: pdPreview.value.is_loss ? 'loss_coverage' : 'retained' }]
    }
  } catch (e: any) {
    handleApiError(e)
    showProfitDistribution.value = false
  }
}

function addAllocation() {
  pdAllocations.value.push({ account_code: '', amount: 0, kind: 'retained' })
}
function removeAllocation(i: number) {
  pdAllocations.value.splice(i, 1)
}

function runProfitDistribution() {
  if (!pdDecisionDate.value) {
    toast.warning(t('accounting.closing.profit_distribution.date_required'))
    return
  }
  mutate(() => closingApi.profitDistribution(periodId, {
    decision_date: pdDecisionDate.value,
    target_row_version: pdPreview.value?.target_period.row_version ?? 0,
    allocations: pdAllocations.value
      .filter(a => a.account_code.trim() && Number(a.amount) > 0)
      .map(a => ({ account_code: a.account_code.trim(), amount: Number(a.amount), kind: a.kind })),
    withholding_rate: Number(pdWithholdingRate.value),
  }), t('accounting.closing.profit_distribution.booked')).then(ok => { if (ok) openProfitDistribution() })
}

function revertProfitDistribution() {
  if (!confirm(t('accounting.closing.revert.confirm'))) return
  mutate(() => closingApi.profitDistributionRevert(periodId, pdPreview.value?.target_period.row_version ?? 0),
    t('accounting.closing.revert.done')).then(ok => { if (ok) openProfitDistribution() })
}

// ── Krok zásob (uzávěrka způsobem B) ───────────────────────────────────────
// Zaúčtování/přepočet konečného stavu zásob k rozvahovému dni. Idempotentní
// (re-run = in-place rewrite), takže tlačítko slouží i jako „přepočítat náhled".
// Spouští se VÝHRADNĚ akcí uživatele (žádné auto-run).
function bookStock() {
  mutate(() => closingApi.runStep(periodId, 'stock', { row_version: rowVersion.value }),
    t('accounting.closing.stock.posted'))
}
function revertStock() {
  if (!confirm(t('accounting.closing.revert.confirm'))) return
  mutate(() => closingApi.revertStep(periodId, 'stock', rowVersion.value),
    t('accounting.closing.revert.done'))
}

// ── Uzavření knih / otevření nového roku ───────────────────────────────────
const showCloseConfirm = ref(false)
const showOpenConfirm = ref(false)
// EP-10b: override nezaúčtovaných dokladů — dialog s doloženým důvodem, jen s oprávněním.
const showUnpostedOverride = ref(false)
const overrideReason = ref('')

async function closeBooks(override = false) {
  showCloseConfirm.value = false
  busy.value = true
  try {
    await closingApi.close(periodId, rowVersion.value,
      override ? { override_unposted: true, override_reason: overrideReason.value.trim() } : undefined)
    toast.success(t('accounting.closing.close.done'))
    showUnpostedOverride.value = false
    overrideReason.value = ''
    await load()
  } catch (e: any) {
    if (e?.response?.data?.error?.code === 'unposted_documents_block'
      && auth.canWrite('accounting.periods.close_override')) {
      showUnpostedOverride.value = true // vyžádá důvod a nabídne override
    } else {
      handleApiError(e)
    }
  } finally {
    busy.value = false
  }
}

function openNext() {
  showOpenConfirm.value = false
  mutate(() => closingApi.openNext(periodId, rowVersion.value), t('accounting.closing.open.done'))
}

function revertStep(key: ClosingStepKey) {
  if (!confirm(t('accounting.closing.revert.confirm'))) return
  mutate(() => closingApi.revertStep(periodId, key, rowVersion.value), t('accounting.closing.revert.done'))
}

// ── UI helpery ─────────────────────────────────────────────────────────────
function statusLabel(status: string): string {
  return t(`accounting.closing.status.${status}`)
}
function statusBadge(status: string): string {
  if (status === 'open') return 'bg-success-50 text-success-600'
  if (status === 'closing') return 'bg-warning-50 text-warning-600'
  if (status === 'approved') return 'bg-purple-50 text-purple-700'
  return 'bg-neutral-100 text-neutral-500'
}
function stepIcon(key: ClosingStepKey): string {
  if (!stepApplicable(key)) return '–'
  const st = step(key)?.status ?? 'pending'
  if (st === 'done') return '✓'
  if (st === 'skipped') return '–'
  return String(STEP_ORDER.indexOf(key) + 1)
}
function stepIconClass(key: ClosingStepKey): string {
  if (!stepApplicable(key)) return 'bg-neutral-100 text-neutral-400'
  const st = step(key)?.status ?? 'pending'
  if (st === 'done') return 'bg-success-50 text-success-600'
  if (st === 'skipped') return 'bg-neutral-100 text-neutral-400'
  return 'bg-neutral-100 text-neutral-500'
}

const isClosing = computed(() => state.value?.period.status === 'closing')
// Past #37: „Otevřít nový rok" jde i nad schváleným (approved) obdobím — je to technický
// přenos zůstatků do N+1, do knih schváleného období nezasahuje. Serverová brána is
// state.can_open_next; tady jen povolíme oba stavy pro disabled tlačítka.
const canOpenNextStage = computed(() => ['closed', 'approved'].includes(state.value?.period.status ?? ''))
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('accounting.closing.title') }}</h1>
        <p v-if="state" class="text-sm text-neutral-500 mt-0.5">
          {{ state.period.fiscal_year }} · {{ formatDate(state.period.starts_on) }} – {{ formatDate(state.period.ends_on) }}
          <span class="text-xs px-2 py-0.5 rounded font-medium ml-2" :class="statusBadge(state.period.status)">
            {{ statusLabel(state.period.status) }}
          </span>
          <span v-if="closeBooksPayload?.profit !== undefined" class="ml-2">
            {{ t('accounting.closing.close.profit') }}:
            <strong class="font-mono">{{ formatMoney(closeBooksPayload.profit) }}</strong>
          </span>
        </p>
      </div>
      <div class="flex items-center gap-2">
        <RouterLink to="/accounting/periods" class="text-sm text-neutral-500 hover:text-neutral-700">
          {{ t('common.back') }}
        </RouterLink>
        <RouterLink v-if="auth.can('accounting')" :to="{ name: 'accounting-statement-notes', params: { id: periodId } }"
          :class="btnOutline('neutral')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
          {{ t('accounting.statement_notes.title') }}
        </RouterLink>
        <RouterLink v-if="auth.can('reports.export')" :to="{ name: 'accounting-closing-package', params: { id: periodId } }"
          :class="btnOutline('neutral')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
          {{ t('accounting.closing_package.open_link') }}
        </RouterLink>
        <button v-if="state?.period.status === 'open' && (state?.can_start ?? true) && auth.canWrite('accounting.periods.close')"
          @click="startClosing" :disabled="busy" :class="btnFilled('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.play" /></svg>
          {{ t('accounting.closing.start') }}
        </button>
        <button v-if="isClosing && auth.canWrite('accounting.periods.close')" @click="abortClosing" :disabled="busy" :class="btnOutline('danger')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
          {{ t('accounting.closing.abort') }}
        </button>
      </div>
    </div>

    <div v-if="loading && !state" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <div v-else-if="state" class="grid grid-cols-1 lg:grid-cols-[280px_1fr] gap-4">
      <!-- Levý sloupec: kroky -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-2 h-fit">
        <button v-for="key in STEP_ORDER" :key="key" @click="selected = key"
          class="cursor-pointer w-full flex items-center gap-3 px-3 py-2.5 rounded-md text-left text-sm"
          :class="selected === key ? 'bg-primary-50 text-primary-700 font-medium' : 'hover:bg-neutral-50'">
          <span class="w-6 h-6 rounded-full flex items-center justify-center text-xs font-semibold shrink-0"
            :class="stepIconClass(key)">{{ stepIcon(key) }}</span>
          <span class="flex-1" :class="stepApplicable(key) ? '' : 'text-neutral-400'">
            {{ t(`accounting.closing.steps.${key}.title`) }}
          </span>
          <span v-if="!stepApplicable(key)" class="text-xs text-neutral-400">{{ t('accounting.closing.step_not_applicable') }}</span>
          <span v-else-if="step(key)?.status === 'skipped'" class="text-xs text-neutral-400">{{ t('accounting.closing.step_skipped') }}</span>
        </button>
      </div>

      <!-- Panel kroku -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 space-y-4 min-w-0">
        <div>
          <h2 class="text-lg font-semibold">{{ t(`accounting.closing.steps.${selected}.title`) }}</h2>
          <p class="text-sm text-neutral-500 mt-0.5">{{ t(`accounting.closing.steps.${selected}.description`) }}</p>
        </div>

        <!-- 1) Precheck -->
        <template v-if="selected === 'precheck'">
          <div class="flex items-center gap-2">
            <button @click="runPrecheck" :disabled="busy || !['open', 'closing'].includes(state.period.status)"
              :class="btnFilled('primary')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.play" /></svg>
              {{ t('accounting.closing.precheck_run') }}
            </button>
            <span v-if="precheckHasErrors" class="text-xs px-2 py-0.5 rounded bg-danger-50 text-danger-600 font-medium">
              {{ t('accounting.closing.precheck_errors') }}
            </span>
            <span v-else-if="precheckStale" class="text-xs px-2 py-0.5 rounded bg-warning-50 text-warning-700 font-medium">
              {{ t('accounting.closing.precheck_stale') }}
            </span>
          </div>
          <div v-if="precheckStale" class="rounded-lg border border-warning-200 bg-warning-50 dark:bg-warning-500/[0.06] px-3 py-2 text-sm text-warning-800 dark:text-warning-200">
            {{ t('accounting.closing.precheck_stale_hint') }}
          </div>
          <div v-if="precheckItems.length" class="overflow-x-auto" :class="{ 'opacity-50': precheckStale }">
            <table class="w-full text-sm">
              <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
                <tr>
                  <!-- Šířky explicitně: popis kontroly je tu to podstatné, ale sám by
                       o místo prohrál s dlouhými hodnotami vpravo a smrskl se na
                       dvouslovné řádky. -->
                  <th class="px-3 py-2 text-left font-medium w-28">{{ t('accounting.closing.precheck_severity') }}</th>
                  <th class="px-3 py-2 text-left font-medium w-2/5">{{ t('accounting.closing.precheck_check') }}</th>
                  <th class="px-3 py-2 text-right font-medium">{{ t('accounting.closing.precheck_value') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="c in precheckItems" :key="c.key">
                  <td class="px-3 py-1.5">
                    <span class="text-xs px-2 py-0.5 rounded font-medium" :class="c.ok ? 'bg-success-50 text-success-600' : severityClass(c.severity)">
                      {{ c.ok ? t('accounting.closing.severity.ok') : t(`accounting.closing.severity.${c.severity}`) }}
                    </span>
                  </td>
                  <td class="px-3 py-1.5">{{ checkLabel(c.key) }}</td>
                  <td class="px-3 py-1.5 text-right text-xs">
                    <!-- Pravidlo: SEZNAM jde vždy do popupu, inline zůstávají jen skalární
                         hodnoty. Dřív tu byly whitelisty podle klíče kontroly, které
                         vypisovaly 15–20 dokladů inline a zbytek schovaly do „+N" — u firmy
                         s tisícem nálezů je to pořád zeď textu a navíc se to chovalo jinak
                         než ostatní kontroly. CheckFindings rozhoduje podle TVARU dat, takže
                         nová kontrola se zachová správně, aniž by o ní kdokoli věděl. -->
                    <CheckFindings v-if="c.value !== null && typeof c.value === 'object'"
                      :check-key="c.key" :label="checkLabel(c.key)"
                      :value="c.value" :export-url="checkExportUrl(c.key)"
                      :list-link="docListLink(c.key)" :period-id="periodId" />
                    <span v-else class="font-mono break-all">{{ checkValue(c.value) }}</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <EmptyState v-else dense accent="neutral" icon="clipboardCheck" :title="t('accounting.closing.precheck_empty')" />
        </template>

        <!-- 2) Odpisy -->
        <template v-else-if="selected === 'depreciation'">
          <!-- Bez odpisovaného majetku v období není co potvrzovat ani přeskakovat. -->
          <p v-if="!depreciationRequired" class="text-sm text-neutral-500">
            {{ t('accounting.closing.depreciation_not_applicable') }}
          </p>
          <p v-else class="text-sm text-neutral-600">
            {{ t('accounting.closing.depreciation_hint') }}
            <RouterLink to="/accounting/assets" class="text-primary-600 hover:text-primary-700 hover:underline">
              {{ t('accounting.closing.depreciation_link') }}
            </RouterLink>
          </p>
          <!-- Zaúčtování přímo z průvodce. Modul Majetek účtuje striktně do OTEVŘENÉHO
               období, takže po zahájení uzávěrky tam odpisy neprojdou (period_not_open) —
               tenhle endpoint je jediný, který smí zapsat do stavu „Uzavírá se". -->
          <div v-if="depreciationRequired && isClosing" class="mt-3">
            <button @click="bookDepreciation" :disabled="busy || bookingDepreciation" :class="btnOutline('primary')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.play" /></svg>
              {{ t('accounting.closing.depreciation_book') }}
            </button>
            <p class="mt-1 text-xs text-neutral-500">{{ t('accounting.closing.depreciation_book_hint') }}</p>
          </div>
          <div v-if="depreciationRequired && step('depreciation')?.status === 'pending' && isClosing" class="space-y-3">
            <input v-model="stepNote.depreciation" type="text" :placeholder="t('accounting.closing.note_placeholder')"
              class="w-full max-w-md h-9 px-3 border border-neutral-300 rounded-md text-sm" />
            <div class="flex gap-2">
              <button @click="confirmStep('depreciation', 'done')" :disabled="busy" :class="btnFilled('success')">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
                {{ t('accounting.closing.step_confirm') }}
              </button>
              <button @click="confirmStep('depreciation', 'skipped')" :disabled="busy" :class="btnOutline('neutral')">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
                {{ t('accounting.closing.step_skip') }}
              </button>
            </div>
          </div>
          <div v-else-if="depreciationRequired && step('depreciation')?.status !== 'pending'" class="text-sm text-success-600">
            {{ t('accounting.closing.step_done_at', { date: formatDate(step('depreciation')?.done_at) }) }}
          </div>
        </template>

        <!-- 3) Kurzové rozdíly -->
        <template v-else-if="selected === 'fx_revaluation'">
          <div v-if="!isClosing && step('fx_revaluation')?.status === 'pending'" class="text-sm text-neutral-500">
            {{ t('accounting.closing.fx.needs_closing') }}
          </div>
          <template v-else>
            <div v-if="fxLoading" class="text-sm text-neutral-500">{{ t('common.loading') }}</div>
            <template v-else-if="fxPreview">
              <div v-if="fxPreview.rate_info?.length" class="flex flex-wrap gap-2 text-xs text-neutral-500">
                <span v-for="ri in fxPreview.rate_info" :key="ri.currency"
                  class="px-2 py-0.5 rounded bg-neutral-100">
                  {{ ri.currency }}: {{ ri.rate }} ({{ formatDate(ri.rate_date) }})<template v-if="ri.fallback_used"> ⚠</template>
                </span>
              </div>

              <!-- Saldokonto -->
              <div v-if="fxPreview.saldo?.detail?.length" class="overflow-x-auto">
                <table class="w-full text-sm">
                  <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
                    <tr>
                      <th class="px-3 py-2 text-left font-medium">{{ t('accounting.closing.fx.col_document') }}</th>
                      <th class="px-3 py-2 text-left font-medium w-20">{{ t('accounting.closing.fx.col_currency') }}</th>
                      <th class="px-3 py-2 text-right font-medium w-32">{{ t('accounting.closing.fx.col_remaining') }}</th>
                      <th class="px-3 py-2 text-right font-medium w-28">{{ t('accounting.closing.fx.col_doc_rate') }}</th>
                      <th class="px-3 py-2 text-right font-medium w-28">{{ t('accounting.closing.fx.col_cnb_rate') }}</th>
                      <th class="px-3 py-2 text-right font-medium w-32">{{ t('accounting.closing.fx.col_diff') }}</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-neutral-100">
                    <tr v-for="(d, i) in fxPreview.saldo.detail" :key="i">
                      <td class="px-3 py-1.5">
                        {{ t(`accounting.closing.fx.doc_type.${d.doc_type}`) }} {{ d.varsymbol || `#${d.doc_id}` }}
                      </td>
                      <td class="px-3 py-1.5">{{ d.currency_code }}</td>
                      <td class="px-3 py-1.5 text-right font-mono">{{ formatMoney(d.remaining_foreign, d.currency_code) }}</td>
                      <td class="px-3 py-1.5 text-right font-mono">{{ d.fx_rate }}</td>
                      <td class="px-3 py-1.5 text-right font-mono">{{ d.rate_cnb }}</td>
                      <td class="px-3 py-1.5 text-right font-mono" :class="d.diff >= 0 ? 'text-success-600' : 'text-danger-500'">
                        {{ formatMoney(d.diff) }}
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <EmptyState v-else dense accent="neutral" icon="swap" :title="t('accounting.closing.fx.no_open_items')" />

              <!-- Banka / valutová pokladna -->
              <div>
                <div class="flex items-center justify-between mb-2">
                  <h3 class="text-sm font-medium text-neutral-700">{{ t('accounting.closing.fx.bank_section') }}</h3>
                  <button @click="addBankRow"
                    class="cursor-pointer text-xs text-primary-600 hover:text-primary-700 font-medium">
                    + {{ t('accounting.closing.fx.add_cash_row') }}
                  </button>
                </div>
                <datalist :id="`${pageId}-closing-fx-coa-options`">
                  <option v-for="a in pickableAccounts" :key="a.id" :value="a.account_code">{{ a.account_code }} — {{ a.name }}</option>
                </datalist>
                <div class="space-y-2">
                  <div v-for="(r, i) in bankRows" :key="i" class="grid grid-cols-12 gap-2 items-center">
                    <input v-model="r.account_code" :list="`${pageId}-closing-fx-coa-options`" type="text"
                      :placeholder="t('accounting.closing.fx.account')"
                      class="col-span-4 h-9 px-2 border border-neutral-300 rounded-md text-sm font-mono" />
                    <input v-model="r.currency_code" type="text" maxlength="3"
                      :placeholder="t('accounting.closing.fx.col_currency')"
                      class="col-span-3 h-9 px-2 border border-neutral-300 rounded-md text-sm font-mono uppercase" />
                    <input v-model.number="r.foreign_balance" type="number" step="0.01"
                      :placeholder="t('accounting.closing.fx.foreign_balance')"
                      class="col-span-4 h-9 px-2 border border-neutral-300 rounded-md text-sm text-right" />
                    <button @click="removeBankRow(i)"
                      class="col-span-1 cursor-pointer text-danger-500 hover:text-danger-600">✕</button>
                  </div>
                  <div v-if="!bankRows.length" class="text-xs text-neutral-400">{{ t('accounting.closing.fx.no_bank_rows') }}</div>
                </div>
              </div>

              <!-- Součty + akce -->
              <div class="flex flex-wrap items-center justify-between gap-3 border-t border-neutral-200 pt-3">
                <div class="flex items-center gap-4 text-sm">
                  <span class="text-neutral-500">Σ 563:
                    <strong class="font-mono text-danger-500">{{ formatMoney(fxPreview.totals?.loss) }}</strong></span>
                  <span class="text-neutral-500">Σ 663:
                    <strong class="font-mono text-success-600">{{ formatMoney(fxPreview.totals?.gain) }}</strong></span>
                </div>
                <div class="flex gap-2">
                  <button @click="loadFxPreview(true)" :disabled="fxLoading" :class="btnOutline('neutral')">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>
                    {{ t('accounting.closing.fx.recalc') }}
                  </button>
                  <button v-if="isClosing" @click="bookFx" :disabled="busy" :class="btnFilled('success')">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
                    {{ t('accounting.closing.fx.book') }}
                  </button>
                  <button v-if="state.can_revert_fx_revaluation && auth.canWrite('accounting.periods.close')" @click="revertFx" :disabled="busy" :class="btnOutline('danger')">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.uturn" /></svg>
                    {{ t('accounting.closing.fx.revert') }}
                  </button>
                </div>
              </div>
            </template>
          </template>
        </template>

        <!-- 4/5) Dohady + časové rozlišení -->
        <template v-else-if="selected === 'estimates' || selected === 'deferrals'">
          <div v-if="!isClosing && step(selected)?.status === 'pending'" class="text-sm text-neutral-500">
            {{ t('accounting.closing.assist.needs_closing') }}
          </div>
          <template v-else>
            <!-- Auto-návrh dohadů (K10) — jen krok estimates, opakující se náklad bez faktury -->
            <div v-if="selected === 'estimates' && isClosing" class="border border-neutral-200 rounded-md p-3 space-y-3 max-w-3xl">
              <div class="flex flex-wrap items-center gap-2">
                <button @click="loadEstimatesSuggest" :disabled="busy || estimatesSuggestLoading" :class="btnOutline('neutral')">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>
                  {{ t('accounting.closing.estimates.suggest') }}
                </button>
                <span v-if="estimatesSuggest" class="text-xs text-neutral-500">
                  {{ t('accounting.closing.provisions.as_of') }}: {{ formatDate(estimatesSuggest.as_of) }}
                </span>
              </div>
              <p class="text-xs text-neutral-500">{{ t('accounting.closing.estimates.suggest_hint') }}</p>

              <div v-if="estimatesSuggest && estimatesSuggest.items.length" class="overflow-x-auto">
                <table class="w-full text-sm">
                  <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
                    <tr>
                      <th class="px-2 py-2 text-left font-medium">{{ t('accounting.closing.estimates.col_vendor') }}</th>
                      <th class="px-2 py-2 text-right font-medium">{{ t('accounting.closing.estimates.col_last_invoice') }}</th>
                      <th class="px-2 py-2 text-right font-medium">{{ t('accounting.closing.estimates.col_months') }}</th>
                      <th class="px-2 py-2 text-center font-medium">{{ t('accounting.closing.assist.counter_account') }}</th>
                      <th class="px-2 py-2 text-right font-medium">{{ t('accounting.closing.estimates.col_suggested') }}</th>
                      <th class="px-2 py-2"></th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-neutral-100">
                    <tr v-for="it in estimatesSuggest.items" :key="it.vendor_id">
                      <td class="px-2 py-1.5">{{ it.vendor_name }}</td>
                      <td class="px-2 py-1.5 text-right whitespace-nowrap">{{ formatDate(it.last_invoice_date) }}</td>
                      <td class="px-2 py-1.5 text-right">{{ it.months_present }}</td>
                      <td class="px-2 py-1.5 text-center font-mono">{{ it.counter_account || '—' }}</td>
                      <td class="px-2 py-1.5 text-right font-mono">{{ formatMoney(it.suggested_amount) }}</td>
                      <td class="px-2 py-1.5 text-right">
                        <button @click="applyEstimateSuggestion(it)" :disabled="busy" class="cursor-pointer text-xs text-primary-600 hover:text-primary-700 font-medium">
                          {{ t('accounting.closing.estimates.apply') }}
                        </button>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <EmptyState v-else-if="estimatesSuggest" dense accent="neutral" icon="cycle" :title="t('accounting.closing.estimates.none')" />
            </div>

            <!-- Asistent -->
            <div v-if="isClosing" class="grid grid-cols-1 sm:grid-cols-2 gap-3 max-w-2xl">
              <div class="sm:col-span-2">
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.closing.assist.rule') }}</label>
                <select v-model="assistForm.rule_key" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm bg-surface">
                  <option value="">—</option>
                  <option v-for="rk in ASSIST_RULES[selected]" :key="rk" :value="rk">{{ ruleLabel(rk) }}</option>
                </select>
              </div>
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.closing.assist.amount') }}</label>
                <input v-model.number="assistForm.amount" type="number" step="0.01" min="0"
                  class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm text-right" />
              </div>
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.closing.assist.counter_account') }}</label>
                <input v-model="assistForm.counter_account" :list="`${pageId}-closing-assist-coa-options`" type="text"
                  class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
                <datalist :id="`${pageId}-closing-assist-coa-options`">
                  <option v-for="a in pickableAccounts" :key="a.id" :value="a.account_code">{{ a.account_code }} — {{ a.name }}</option>
                </datalist>
              </div>
              <div class="sm:col-span-2">
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.closing.assist.description') }}</label>
                <input v-model="assistForm.description" type="text"
                  class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
              </div>
              <div class="sm:col-span-2">
                <button @click="createAssisted(selected)" :disabled="busy" :class="btnFilled('primary')">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
                  {{ t('accounting.closing.assist.create') }}
                </button>
              </div>
            </div>

            <!-- §DM / Task 11: časové rozlišení drobného majetku (381) — volitelná politika -->
            <div v-if="selected === 'deferrals'" class="border border-neutral-200 rounded-md p-3 space-y-3">
              <div class="flex flex-wrap items-center gap-2">
                <h3 class="text-sm font-medium text-neutral-700 mr-auto">{{ t('accounting.closing.small_asset.title') }}</h3>
                <select v-model="saAccrualForm.mode" class="h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
                  <option value="none">{{ t('accounting.closing.small_asset.mode_none') }}</option>
                  <option value="pro_rata">{{ t('accounting.closing.small_asset.mode_pro_rata') }}</option>
                  <option value="flat_pct">{{ t('accounting.closing.small_asset.mode_flat_pct') }}</option>
                </select>
                <input v-if="saAccrualForm.mode === 'flat_pct'" v-model.number="saAccrualForm.pct" type="number" step="0.01" min="0" max="100"
                  :title="t('accounting.closing.small_asset.pct_label')"
                  class="h-9 w-20 px-2 border border-neutral-300 rounded-md text-sm text-right" />
                <input v-if="saAccrualForm.mode === 'flat_pct'" v-model.number="saAccrualForm.materiality_limit" type="number" step="0.01" min="0"
                  :placeholder="t('accounting.closing.small_asset.materiality_ph')" :title="t('accounting.closing.small_asset.materiality_label')"
                  class="h-9 w-36 px-2 border border-neutral-300 rounded-md text-sm text-right" />
                <button @click="loadSmallAssetAccrual()" :disabled="busy || saAccrualLoading" :class="btnOutline('neutral')">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>
                  {{ t('accounting.closing.small_asset.reload') }}
                </button>
              </div>
              <p class="text-xs text-neutral-500">{{ t('accounting.closing.small_asset.hint') }}</p>
              <p v-if="saAccrualForm.mode === 'flat_pct'" class="text-xs text-neutral-500">{{ t('accounting.closing.small_asset.materiality_hint') }}</p>
              <p v-if="saAccrualForm.mode === 'flat_pct' && saAccrualPreview?.materiality && !saAccrualPreview.materiality.passes"
                class="text-xs text-warning-700 bg-warning-50 dark:bg-warning-500/[0.06] border border-warning-200 rounded px-2 py-1">
                {{ saAccrualPreview.materiality.limit == null
                  ? t('accounting.closing.small_asset.materiality_missing')
                  : t('accounting.closing.small_asset.materiality_over', { base: formatMoney(saAccrualPreview.materiality.base), limit: formatMoney(saAccrualPreview.materiality.limit) }) }}
              </p>
              <template v-if="saAccrualPreview">
                <div class="overflow-x-auto" v-if="saAccrualPreview.items.length">
                  <table class="w-full text-sm">
                    <thead>
                      <tr class="text-left text-neutral-500 border-b border-neutral-200">
                        <th class="py-1 pr-2">{{ t('accounting.closing.small_asset.col_name') }}</th>
                        <th class="py-1 px-2">{{ t('accounting.closing.small_asset.col_acquired') }}</th>
                        <th class="py-1 px-2 text-right">{{ t('accounting.closing.small_asset.col_price') }}</th>
                        <th class="py-1 pl-2 text-right">{{ t('accounting.closing.small_asset.col_deferred') }}</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="it in saAccrualPreview.items" :key="it.small_asset_id" class="border-b border-neutral-100">
                        <td class="py-1 pr-2">{{ it.name }}</td>
                        <td class="py-1 px-2">{{ formatDate(it.acquisition_date) }}</td>
                        <td class="py-1 px-2 text-right font-mono">{{ formatMoney(it.price) }}</td>
                        <td class="py-1 pl-2 text-right font-mono">{{ it.deferred_amount == null ? '—' : formatMoney(it.deferred_amount) }}</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
                <EmptyState v-else dense accent="neutral" icon="box" :title="t('accounting.closing.small_asset.no_cards')" />
                <div class="text-sm space-y-1">
                  <div class="flex justify-between"><span>{{ t('accounting.closing.small_asset.total_deferred') }}</span><span class="font-mono font-medium">{{ formatMoney(saAccrualPreview.total) }}</span></div>
                  <div class="flex justify-between text-neutral-500"><span>{{ t('accounting.closing.small_asset.cards_total') }}</span><span class="font-mono">{{ formatMoney(saAccrualPreview.cards_total) }}</span></div>
                  <div class="flex justify-between text-neutral-500"><span>{{ t('accounting.closing.small_asset.breakdown_501') }}</span><span class="font-mono">{{ formatMoney(saAccrualPreview.breakdown_501_small_asset) }}</span></div>
                  <div v-if="Math.abs(saAccrualPreview.cards_vs_501_diff) >= 0.01" class="flex justify-between text-amber-600">
                    <span>{{ t('accounting.closing.small_asset.diff') }}</span><span class="font-mono">{{ formatMoney(saAccrualPreview.cards_vs_501_diff) }}</span>
                  </div>
                </div>
                <div v-if="saAccrualPreview.existing" class="text-xs text-success-600">
                  {{ t('accounting.closing.small_asset.existing', { amount: formatMoney(saAccrualPreview.existing.amount ?? 0) }) }}
                </div>
                <button v-if="isClosing" @click="runSmallAssetAccrual" :disabled="busy" :class="btnFilled('primary')">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
                  {{ t('accounting.closing.small_asset.post') }}
                </button>
              </template>
            </div>

            <!-- §DČR / Task 12: časové rozlišení nákladů příštích období (381) z označených faktur -->
            <div v-if="selected === 'deferrals'" class="border border-neutral-200 rounded-md p-3 space-y-3">
              <div class="flex flex-wrap items-center gap-2">
                <h3 class="text-sm font-medium text-neutral-700 mr-auto">{{ t('accounting.closing.prepaid_expense.title') }}</h3>
                <button @click="loadPrepaidExpenseAccrual" :disabled="busy || peAccrualLoading" :class="btnOutline('neutral')">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>
                  {{ t('accounting.closing.prepaid_expense.reload') }}
                </button>
              </div>
              <p class="text-xs text-neutral-500">{{ t('accounting.closing.prepaid_expense.hint') }}</p>
              <template v-if="peAccrualPreview">
                <div class="overflow-x-auto" v-if="peAccrualPreview.items.length">
                  <table class="w-full text-sm">
                    <thead>
                      <tr class="text-left text-neutral-500 border-b border-neutral-200">
                        <th class="py-1 pr-2">{{ t('accounting.closing.prepaid_expense.col_document') }}</th>
                        <th class="py-1 px-2">{{ t('accounting.closing.prepaid_expense.col_period') }}</th>
                        <th class="py-1 px-2 text-right">{{ t('accounting.closing.prepaid_expense.col_account') }}</th>
                        <th class="py-1 pl-2 text-right">{{ t('accounting.closing.prepaid_expense.col_deferred') }}</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="it in peAccrualPreview.items" :key="it.item_id" class="border-b border-neutral-100">
                        <td class="py-1 pr-2">{{ it.vendor_invoice_number }}<span class="block text-xs text-neutral-400">{{ it.description }}</span></td>
                        <td class="py-1 px-2 whitespace-nowrap">{{ formatDate(it.accrual_from) }} – {{ formatDate(it.accrual_to) }}</td>
                        <td class="py-1 px-2 text-right font-mono">{{ it.credit_account }}</td>
                        <td class="py-1 pl-2 text-right font-mono">{{ formatMoney(it.deferred_amount) }}</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
                <EmptyState v-else dense accent="neutral" icon="doc" :title="t('accounting.closing.prepaid_expense.no_items')" />
                <div class="text-sm space-y-1">
                  <div class="flex justify-between"><span>{{ t('accounting.closing.prepaid_expense.total_deferred') }}</span><span class="font-mono font-medium">{{ formatMoney(peAccrualPreview.total) }}</span></div>
                </div>
                <div v-if="peAccrualPreview.existing" class="text-xs text-success-600">
                  {{ t('accounting.closing.prepaid_expense.existing', { amount: formatMoney(peAccrualPreview.existing.amount ?? 0) }) }}
                </div>
                <button v-if="isClosing" @click="runPrepaidExpenseAccrual" :disabled="busy" :class="btnFilled('primary')">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
                  {{ t('accounting.closing.prepaid_expense.post') }}
                </button>
              </template>
            </div>

            <!-- Vytvořené zápisy -->
            <div v-if="assistedEntries(selected).length">
              <h3 class="text-sm font-medium text-neutral-700 mb-2">{{ t('accounting.closing.assist.created_entries') }}</h3>
              <div class="divide-y divide-neutral-100 border border-neutral-200 rounded-md">
                <div v-for="e in assistedEntries(selected)" :key="e.id"
                  class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                  <div>
                    <span class="font-mono">{{ e.document_no || `#${e.id}` }}</span>
                    <span v-if="e.description" class="text-neutral-500 ml-2">{{ e.description }}</span>
                  </div>
                  <div class="flex items-center gap-3">
                    <span v-if="e.amount !== undefined" class="font-mono">{{ formatMoney(e.amount) }}</span>
                    <button v-if="isClosing" @click="reverseAssisted(e.id)" :disabled="busy"
                      class="cursor-pointer text-xs text-danger-500 hover:text-danger-600 font-medium">
                      {{ t('accounting.closing.assist.reverse') }}
                    </button>
                  </div>
                </div>
              </div>
            </div>

            <!-- Confirm / skip -->
            <div v-if="step(selected)?.status === 'pending' && isClosing" class="flex gap-2 border-t border-neutral-200 pt-3">
              <button @click="confirmStep(selected, 'done')" :disabled="busy" :class="btnFilled('success')">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
                {{ t('accounting.closing.step_confirm') }}
              </button>
              <button @click="confirmStep(selected, 'skipped')" :disabled="busy" :class="btnOutline('neutral')">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
                {{ t('accounting.closing.step_skip') }}
              </button>
            </div>
            <div v-else-if="step(selected)?.status !== 'pending'" class="text-sm text-success-600">
              {{ t('accounting.closing.step_done_at', { date: formatDate(step(selected)?.done_at) }) }}
            </div>
          </template>
        </template>

        <!-- D9) Opravné položky k pohledávkám -->
        <template v-else-if="selected === 'provisions'">
          <div v-if="!isClosing && step('provisions')?.status === 'pending'" class="text-sm text-neutral-500">
            {{ t('accounting.closing.assist.needs_closing') }}
          </div>
          <template v-else>
            <div class="flex flex-wrap items-center gap-2">
              <button @click="loadProvisions" :disabled="busy || provisionsLoading" :class="btnOutline('neutral')">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>
                {{ t('accounting.closing.provisions.reload') }}
              </button>
              <span v-if="provisionsPreview" class="text-xs text-neutral-500">
                {{ t('accounting.closing.provisions.as_of') }}: {{ formatDate(provisionsPreview.as_of) }}
              </span>
            </div>
            <p class="text-xs text-neutral-500">{{ t('accounting.closing.provisions.rule_hint') }}</p>

            <div v-if="provisionsPreview && provisionsPreview.items.length" class="overflow-x-auto">
              <table class="w-full text-sm">
                <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
                  <tr>
                    <th class="px-2 py-2 text-left font-medium">{{ t('accounting.closing.provisions.col_document') }}</th>
                    <th class="px-2 py-2 text-left font-medium">{{ t('accounting.closing.provisions.col_partner') }}</th>
                    <th class="px-2 py-2 text-right font-medium">{{ t('accounting.closing.provisions.col_due') }}</th>
                    <th class="px-2 py-2 text-right font-medium">{{ t('accounting.closing.provisions.col_months') }}</th>
                    <th class="px-2 py-2 text-right font-medium">{{ t('accounting.closing.provisions.col_remaining') }}</th>
                    <th class="px-2 py-2 text-center font-medium">{{ t('accounting.closing.provisions.col_suggestion') }}</th>
                    <th class="px-2 py-2 text-right font-medium w-32">{{ t('accounting.closing.provisions.col_legal') }}</th>
                    <th class="px-2 py-2 text-left font-medium w-28">{{ t('accounting.closing.provisions.col_section') }}</th>
                    <th class="px-2 py-2 text-right font-medium w-32">{{ t('accounting.closing.provisions.col_acct') }}</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                  <tr v-for="it in provisionsPreview.items" :key="it.invoice_id">
                    <td class="px-2 py-1.5 font-mono">{{ it.document_no || `#${it.invoice_id}` }}</td>
                    <td class="px-2 py-1.5">{{ it.partner_name }}</td>
                    <td class="px-2 py-1.5 text-right whitespace-nowrap">{{ formatDate(it.due_date) }}</td>
                    <td class="px-2 py-1.5 text-right">{{ it.months_overdue }}</td>
                    <td class="px-2 py-1.5 text-right font-mono">{{ formatMoney(it.remaining) }}</td>
                    <td class="px-2 py-1.5 text-center text-xs">
                      <span v-if="it.legal_section" class="px-2 py-0.5 rounded bg-primary-50 text-primary-700 font-medium">
                        §{{ it.legal_section }} · {{ Math.round(it.suggested_legal_pct * 100) }}&nbsp;%
                      </span>
                      <span v-else class="text-neutral-400">—</span>
                      <div v-if="it.potentially_time_barred" class="mt-1 text-warning-700 normal-case whitespace-normal">
                        {{ t('accounting.closing.provisions.time_barred_warning') }}
                      </div>
                    </td>
                    <td class="px-2 py-1.5">
                      <input v-if="provisionInputs[it.invoice_id]" v-model.number="provisionInputs[it.invoice_id].legal"
                        type="number" step="0.01" min="0" :disabled="!isClosing"
                        class="w-28 h-8 px-2 border border-neutral-300 rounded-md text-sm text-right font-mono" />
                    </td>
                    <!-- Paragraf ZoR — bez něj se zákonná OP nedostane do rozpadu tabulky C
                         přílohy č. 1 II. oddílu DPPO (VetaG, ř. 3–11). -->
                    <td class="px-2 py-1.5">
                      <select v-if="provisionInputs[it.invoice_id]" v-model="provisionInputs[it.invoice_id].section"
                        :disabled="!isClosing || !Number(provisionInputs[it.invoice_id].legal)"
                        class="w-24 h-8 px-1 border border-neutral-300 rounded-md text-sm bg-surface">
                        <option :value="null">{{ t('accounting.closing.provisions.section_none') }}</option>
                        <option v-for="s in LEGAL_SECTIONS" :key="s" :value="s">§&nbsp;{{ s }}</option>
                      </select>
                    </td>
                    <td class="px-2 py-1.5">
                      <input v-if="provisionInputs[it.invoice_id]" v-model.number="provisionInputs[it.invoice_id].acct"
                        type="number" step="0.01" min="0" :disabled="!isClosing"
                        class="w-28 h-8 px-2 border border-neutral-300 rounded-md text-sm text-right font-mono" />
                    </td>
                  </tr>
                </tbody>
                <tfoot v-if="provisionsPreview.totals" class="text-xs text-neutral-600 border-t-2 border-neutral-200">
                  <tr>
                    <td class="px-2 py-2 font-medium" colspan="4">{{ t('accounting.closing.provisions.total') }}</td>
                    <td class="px-2 py-2 text-right font-mono">{{ formatMoney(provisionsPreview.totals.remaining) }}</td>
                    <td></td>
                    <td class="px-2 py-2 text-right font-mono">{{ formatMoney(provisionsPreview.totals.suggested_legal) }}</td>
                    <td></td>
                    <td></td>
                  </tr>
                </tfoot>
              </table>
            </div>
            <EmptyState v-else-if="provisionsPreview" dense accent="neutral" icon="coin" :title="t('accounting.closing.provisions.none')" />

            <div class="flex flex-wrap gap-2 border-t border-neutral-200 pt-3">
              <button v-if="isClosing" @click="runProvisions" :disabled="busy" :class="btnFilled('primary')">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
                {{ t('accounting.closing.provisions.book') }}
              </button>
              <button v-if="isClosing && step('provisions')?.status === 'pending'" @click="confirmStep('provisions', 'skipped')" :disabled="busy" :class="btnOutline('neutral')">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
                {{ t('accounting.closing.step_skip') }}
              </button>
              <button v-if="isClosing && step('provisions')?.status !== 'pending' && auth.canWrite('accounting.periods.close')" @click="revertStep('provisions')" :disabled="busy" :class="btnOutline('danger')">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.uturn" /></svg>
                {{ t('accounting.closing.revert.button') }}
              </button>
            </div>
            <div v-if="step('provisions')?.status === 'done'" class="text-sm text-success-600">
              {{ t('accounting.closing.step_done_at', { date: formatDate(step('provisions')?.done_at) }) }}
            </div>
          </template>
        </template>

        <!-- D11) Splatná daň z příjmů -->
        <template v-else-if="selected === 'income_tax'">
          <div v-if="!isClosing && step('income_tax')?.status === 'pending'" class="text-sm text-neutral-500">
            {{ t('accounting.closing.assist.needs_closing') }}
          </div>
          <template v-else>
            <div v-if="incomeTaxPreview" class="text-sm space-y-1">
              <div v-if="!incomeTaxPreview.applicable" class="rounded-md bg-warning-50 px-3 py-2 text-warning-800">
                {{ t('accounting.closing.income_tax.not_applicable_fo') }}
              </div>
              <template v-else>
              <div v-if="incomeTaxPreview.suggested_source === 'finalized_return'">
                {{ t('accounting.closing.income_tax.suggested') }}:
                <strong class="font-mono">{{ formatMoney(incomeTaxPreview.suggested_amount || 0) }}</strong>
              </div>
              <div v-else-if="incomeTaxPreview.suggested_source === 'computed_from_ledger'" class="text-warning-700">
                {{ t('accounting.closing.income_tax.computed_from_ledger') }}:
                <strong class="font-mono">{{ formatMoney(incomeTaxPreview.suggested_amount || 0) }}</strong>
              </div>
              <!-- Uzávěrka zásob (krok 8) je AŽ ZA tímhle krokem, přitom mění náklady:
                   způsobem B se konečný stav zásob odúčtuje z 501/504, takže základ daně
                   po ní může být úplně jiný (i ztráta → zisk). Dokud krok neproběhl, je
                   dopočet z účetnictví předběžný a nesmí vypadat jako hotové číslo. -->
              <div v-if="stockStepPending" class="rounded-md bg-warning-50 px-3 py-2 text-warning-800">
                {{ t('accounting.closing.income_tax.stock_pending') }}
              </div>
              <div v-else class="text-neutral-500">{{ t('accounting.closing.income_tax.no_return') }}</div>
              <div class="text-xs text-neutral-500">
                341: <span class="font-mono">{{ formatMoney(incomeTaxPreview.balance_341) }}</span> ·
                591: <span class="font-mono">{{ formatMoney(incomeTaxPreview.balance_591) }}</span>
              </div>
              <RouterLink :to="{ path: '/accounting/reports/tax-base-adjustments', query: { fiscal_year: incomeTaxPreview.period.fiscal_year } }"
                class="text-primary-600 hover:text-primary-700 hover:underline inline-block">
                {{ t('accounting.closing.income_tax.tax_base_link') }}
              </RouterLink>
              </template>
            </div>
            <div v-if="incomeTaxPreview?.applicable !== false" class="max-w-xs">
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.closing.income_tax.amount') }} (591 / 341)</label>
              <input v-model.number="incomeTaxAmount" type="number" step="0.01" min="0" :disabled="!isClosing"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm text-right font-mono" />
            </div>
            <div class="flex flex-wrap gap-2 border-t border-neutral-200 pt-3">
              <button v-if="isClosing && incomeTaxPreview?.applicable !== false" @click="bookIncomeTax" :disabled="busy" :class="btnFilled('primary')">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
                {{ t('accounting.closing.income_tax.book') }}
              </button>
              <button v-if="isClosing && step('income_tax')?.status === 'pending'" @click="confirmStep('income_tax', 'skipped')" :disabled="busy" :class="btnOutline('neutral')">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
                {{ t('accounting.closing.step_skip') }}
              </button>
              <button v-if="isClosing && step('income_tax')?.status !== 'pending' && auth.canWrite('accounting.periods.close')" @click="revertStep('income_tax')" :disabled="busy" :class="btnOutline('danger')">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.uturn" /></svg>
                {{ t('accounting.closing.revert.button') }}
              </button>
            </div>
            <div v-if="step('income_tax')?.status === 'done'" class="text-sm text-success-600">
              {{ t('accounting.closing.step_done_at', { date: formatDate(step('income_tax')?.done_at) }) }}
            </div>
          </template>
        </template>

        <!-- Zásoby (SKLAD §3.4 — uzávěrka způsobem B) -->
        <template v-else-if="selected === 'stock'">
          <!-- Firma bez skladu v podvojném účetnictví: krok se přeskakuje sám, neblokuje. -->
          <p v-if="!stockRequired" class="text-sm text-neutral-500">
            {{ t('accounting.closing.stock.not_applicable') }}
          </p>
          <div v-else-if="!isClosing && step('stock')?.status === 'pending'" class="text-sm text-neutral-500">
            {{ t('accounting.closing.stock.needs_closing') }}
          </div>
          <template v-else>
            <p class="text-sm text-neutral-600">{{ t('accounting.closing.stock.hint') }}</p>

            <template v-if="stockTotals">
              <!-- Konečný stav zásob k rozvahovému dni -->
              <div class="overflow-x-auto">
                <h3 class="text-sm font-medium text-neutral-700 mb-2">{{ t('accounting.closing.stock.closing_title') }}</h3>
                <table class="w-full text-sm">
                  <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
                    <tr>
                      <th class="px-3 py-2 text-left font-medium">{{ t('accounting.closing.stock.col_type') }}</th>
                      <th class="px-3 py-2 text-right font-medium w-32">{{ t('accounting.closing.stock.col_qty') }}</th>
                      <th class="px-3 py-2 text-right font-medium w-40">{{ t('accounting.closing.stock.col_value') }}</th>
                    </tr>
                  </thead>
                  <tbody class="divide-y divide-neutral-100">
                    <tr>
                      <td class="px-3 py-1.5">{{ t('accounting.closing.stock.type_material') }}</td>
                      <td class="px-3 py-1.5 text-right font-mono">{{ stockTotals.closing_qty?.material ?? 0 }}</td>
                      <td class="px-3 py-1.5 text-right font-mono">{{ formatMoney(stockTotals.closing.material) }}</td>
                    </tr>
                    <tr>
                      <td class="px-3 py-1.5">{{ t('accounting.closing.stock.type_goods') }}</td>
                      <td class="px-3 py-1.5 text-right font-mono">{{ stockTotals.closing_qty?.goods ?? 0 }}</td>
                      <td class="px-3 py-1.5 text-right font-mono">{{ formatMoney(stockTotals.closing.goods) }}</td>
                    </tr>
                    <tr>
                      <td class="px-3 py-1.5">{{ t('accounting.closing.stock.type_product') }}</td>
                      <td class="px-3 py-1.5 text-right font-mono">{{ stockTotals.closing_qty?.product ?? 0 }}</td>
                      <td class="px-3 py-1.5 text-right font-mono">{{ formatMoney(stockTotals.closing.product) }}</td>
                    </tr>
                  </tbody>
                  <tfoot class="text-xs text-neutral-600 border-t-2 border-neutral-200">
                    <tr>
                      <td class="px-3 py-2 font-medium" colspan="2">{{ t('accounting.closing.stock.closing_total') }}</td>
                      <td class="px-3 py-2 text-right font-mono font-semibold">{{ formatMoney(stockClosingTotal) }}</td>
                    </tr>
                  </tfoot>
                </table>
              </div>

              <!-- Inventurní manko / přebytek + rozdíl -->
              <div class="text-sm space-y-1 border-t border-neutral-200 pt-3 max-w-md">
                <div class="flex justify-between">
                  <span class="text-neutral-500">{{ t('accounting.closing.stock.shortage') }}</span>
                  <span class="font-mono text-danger-500">{{ formatMoney(stockShortageTotal) }}</span>
                </div>
                <div class="flex justify-between">
                  <span class="text-neutral-500">{{ t('accounting.closing.stock.surplus') }}</span>
                  <span class="font-mono text-success-600">{{ formatMoney(stockSurplusTotal) }}</span>
                </div>
                <div class="flex justify-between font-medium border-t border-neutral-100 pt-1">
                  <span>{{ t('accounting.closing.stock.net_diff') }}</span>
                  <span class="font-mono" :class="stockNetDiff >= 0 ? 'text-success-600' : 'text-danger-500'">{{ formatMoney(stockNetDiff) }}</span>
                </div>
              </div>

              <!-- Zaúčtované sloty (closing/shortage/surplus) -->
              <div v-if="Object.keys(stockEntryIds).length" class="border-t border-neutral-200 pt-3">
                <h3 class="text-sm font-medium text-neutral-700 mb-2">{{ t('accounting.closing.stock.posted_slots') }}</h3>
                <div class="flex flex-wrap gap-2 text-xs">
                  <RouterLink v-for="(id, slot) in stockEntryIds" :key="slot"
                    :to="{ path: '/accounting/journal', query: { period_id: periodId, source_type: 'closing' } }"
                    class="px-2 py-1 rounded bg-neutral-100 hover:bg-neutral-200 font-mono text-primary-600">
                    {{ t(`accounting.closing.stock.slot_${slot}`) }}: #{{ id }}
                  </RouterLink>
                </div>
              </div>

              <!-- Podklady k ověření (příjemky bez faktury, faktury bez příjemky) -->
              <div v-if="stockWarnings.length" class="border-t border-neutral-200 pt-3 space-y-1">
                <h3 class="text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.closing.stock.warnings_title') }}</h3>
                <div v-for="w in stockWarnings" :key="w.key" class="text-sm text-warning-700 flex items-start gap-2">
                  <span class="text-xs px-1.5 py-0.5 rounded bg-warning-50 shrink-0 font-mono">{{ w.items.length }}</span>
                  <span>{{ w.message }}</span>
                </div>
              </div>
            </template>
            <EmptyState v-else dense accent="neutral" icon="warehouse" :title="t('accounting.closing.stock.no_data')" />

            <!-- Akce (manuální — žádné auto-run) -->
            <div class="flex flex-wrap gap-2 border-t border-neutral-200 pt-3">
              <button v-if="isClosing" @click="bookStock" :disabled="busy" :class="btnFilled('primary')">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
                {{ t('accounting.closing.stock.post') }}
              </button>
              <button v-if="state.can_revert_stock && auth.canWrite('accounting.periods.close')" @click="revertStock" :disabled="busy" :class="btnOutline('danger')">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.uturn" /></svg>
                {{ t('accounting.closing.stock.revert') }}
              </button>
            </div>
            <div v-if="step('stock')?.status === 'done'" class="text-sm text-success-600">
              {{ t('accounting.closing.step_done_at', { date: formatDate(step('stock')?.done_at) }) }}
            </div>
            <div v-else-if="step('stock')?.status === 'skipped'" class="text-sm text-neutral-500">
              {{ t('accounting.closing.stock.skipped') }}
            </div>
          </template>
        </template>

        <!-- 6) Uzavření knih -->
        <template v-else-if="selected === 'close_books'">
          <template v-if="step('close_books')?.status === 'done'">
            <div class="text-sm space-y-1">
              <div class="text-success-600 font-medium">{{ t('accounting.closing.close.done') }}</div>
              <div>{{ t('accounting.closing.close.document_no') }}:
                <span class="font-mono font-semibold">{{ closeBooksPayload?.document_no }}</span></div>
              <div v-if="closeBooksPayload?.profit !== undefined">{{ t('accounting.closing.close.profit') }}:
                <span class="font-mono font-semibold">{{ formatMoney(closeBooksPayload.profit) }}</span></div>
              <RouterLink :to="{ path: '/accounting/journal', query: { period_id: periodId, source_type: 'closing' } }"
                class="text-primary-600 hover:text-primary-700 hover:underline inline-block mt-1">
                {{ t('accounting.closing.close.journal_link') }}
              </RouterLink>
            </div>
            <button v-if="state.can_revert_close_books && auth.canWrite('accounting.periods.close')" @click="revertStep('close_books')" :disabled="busy" :class="btnOutline('danger')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.uturn" /></svg>
              {{ t('accounting.closing.revert.close_books') }}
            </button>
          </template>
          <template v-else>
            <p class="text-sm text-neutral-600">{{ t('accounting.closing.close.hint') }}</p>
            <div v-if="!auth.canWrite('accounting.periods.close')" class="text-sm text-neutral-400">{{ t('accounting.periods.admin_only') }}</div>
            <!-- Zašedlé tlačítko bez důvodu je slepá ulička: blokující nález sedí v kroku 1
                 a uživatel u kroku 9 nemá jak zjistit, co mu brání. Vypíšeme ho jmenovitě. -->
            <div v-if="auth.canWrite('accounting.periods.close') && closeBlockedReason"
              class="rounded-md border border-warning-500/30 bg-warning-50 px-3 py-2 text-sm text-warning-800">
              {{ closeBlockedReason }}
            </div>
            <button v-if="auth.canWrite('accounting.periods.close')" @click="showCloseConfirm = true"
              :disabled="busy || !isClosing || !(state.can_close ?? true) || precheckHasErrors"
              :class="btnFilled('warning')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.badgeCheck" /></svg>
              {{ t('accounting.closing.close.button') }}
            </button>
          </template>
        </template>

        <!-- 7) Otevření nového roku -->
        <template v-else-if="selected === 'open_next'">
          <template v-if="step('open_next')?.status === 'done'">
            <div class="text-sm space-y-1">
              <div class="text-success-600 font-medium">{{ t('accounting.closing.open.done') }}</div>
              <div v-if="openNextPayload?.fx_reversal_entry_id" class="text-neutral-500">
                {{ t('accounting.closing.open.fx_reversal_created') }}
              </div>
            </div>
            <button v-if="state.can_revert_open_next && auth.canWrite('accounting.periods.close')" @click="revertStep('open_next')" :disabled="busy" :class="btnOutline('danger')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.uturn" /></svg>
              {{ t('accounting.closing.revert.open_next') }}
            </button>
          </template>
          <template v-else>
            <p class="text-sm text-neutral-600">{{ t('accounting.closing.open.hint') }}</p>
            <p class="text-xs text-neutral-500">{{ t('accounting.closing.open.fx_reversal_note') }}</p>
            <div v-if="!auth.canWrite('accounting.periods.close')" class="text-sm text-neutral-400">{{ t('accounting.periods.admin_only') }}</div>
            <button v-else @click="showOpenConfirm = true"
              :disabled="busy || !canOpenNextStage || !(state.can_open_next ?? true) || step('close_books')?.status !== 'done'"
              :class="btnFilled('success')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.play" /></svg>
              {{ t('accounting.closing.open.button') }}
            </button>
          </template>
        </template>
      </div>
    </div>

    <!-- D10) Rozdělení výsledku hospodaření (nad approved/open/closing obdobím).
         `closing` je tu schválně: kdo zahájil uzávěrku dřív, než rozdělil loňský výsledek,
         se jinak zasekne — uzavření knih blokuje precheck a přerušit uzávěrku po prvních
         zápisech už nejde. -->
    <div v-if="state && ['approved', 'open', 'closing'].includes(state.period.status)"
      class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 mt-4 space-y-3">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h2 class="text-lg font-semibold">{{ t('accounting.closing.profit_distribution.title') }}</h2>
          <p class="text-sm text-neutral-500 mt-0.5">{{ t('accounting.closing.profit_distribution.description') }}</p>
        </div>
        <button v-if="!showProfitDistribution" @click="openProfitDistribution" :disabled="busy" :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.coin" /></svg>
          {{ t('accounting.closing.profit_distribution.open') }}
        </button>
      </div>

      <template v-if="showProfitDistribution && pdPreview">
        <div class="text-sm flex flex-wrap gap-x-6 gap-y-1">
          <div>{{ t('accounting.closing.profit_distribution.target_period') }}: <strong>{{ pdPreview.target_period.fiscal_year }}</strong></div>
          <div>431: <strong class="font-mono">{{ formatMoney(pdPreview.balance_431) }}</strong></div>
          <div :class="pdPreview.is_loss ? 'text-danger-600' : 'text-success-600'">
            {{ pdPreview.is_loss ? t('accounting.closing.profit_distribution.loss') : t('accounting.closing.profit_distribution.available') }}:
            <strong class="font-mono">{{ formatMoney(Math.abs(pdPreview.available_profit)) }}</strong>
          </div>
          <div v-if="!pdPreview.is_loss">
            {{ t('accounting.closing.profit_distribution.resources_limit') }}:
            <strong class="font-mono">{{ formatMoney(pdPreview.distributable_resources) }}</strong>
          </div>
        </div>

        <div v-if="pdPreview.existing_entry_id" class="text-sm text-warning-600 flex items-center gap-2">
          {{ t('accounting.closing.profit_distribution.already_booked') }}
          <button v-if="auth.canWrite('accounting.periods.close')" @click="revertProfitDistribution" :disabled="busy"
            class="cursor-pointer text-danger-500 hover:text-danger-600 font-medium">
            {{ t('accounting.closing.revert.button') }}
          </button>
        </div>

        <template v-else>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 max-w-lg">
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.closing.profit_distribution.decision_date') }}</label>
              <DateInput v-model="pdDecisionDate" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.closing.profit_distribution.withholding_rate') }}</label>
              <input v-model.number="pdWithholdingRate" type="number" step="0.01" min="0" max="1"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm text-right font-mono" />
            </div>
          </div>

          <div class="space-y-2">
            <p class="text-xs text-neutral-500">{{ t('accounting.closing.profit_distribution.shareholder_row_hint') }}</p>
            <div v-for="(a, i) in pdAllocations" :key="i" class="flex flex-wrap items-end gap-2">
              <div>
                <label class="block text-xs text-neutral-500 mb-0.5">{{ t('accounting.closing.profit_distribution.col_account') }}</label>
                <input v-model="a.account_code" :list="`${pageId}-pd-coa-options`" type="text"
                  class="w-24 h-9 px-2 border border-neutral-300 rounded-md text-sm font-mono" />
              </div>
              <div>
                <label class="block text-xs text-neutral-500 mb-0.5">{{ t('accounting.closing.profit_distribution.col_kind') }}</label>
                <select v-model="a.kind" class="h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
                  <option value="retained">{{ t('accounting.closing.profit_distribution.kind.retained') }}</option>
                  <option value="fund">{{ t('accounting.closing.profit_distribution.kind.fund') }}</option>
                  <option value="shares">{{ t('accounting.closing.profit_distribution.kind.shares') }}</option>
                  <option value="loss_coverage">{{ t('accounting.closing.profit_distribution.kind.loss_coverage') }}</option>
                </select>
              </div>
              <div>
                <label class="block text-xs text-neutral-500 mb-0.5">{{ t('accounting.closing.profit_distribution.col_amount') }}</label>
                <input v-model.number="a.amount" type="number" step="0.01" min="0"
                  class="w-36 h-9 px-2 border border-neutral-300 rounded-md text-sm text-right font-mono" />
              </div>
              <button @click="removeAllocation(i)" :disabled="pdAllocations.length <= 1"
                class="cursor-pointer h-9 px-2 text-danger-500 hover:text-danger-600 disabled:opacity-30">✕</button>
            </div>
            <datalist :id="`${pageId}-pd-coa-options`">
              <option v-for="a in pickableAccounts" :key="a.id" :value="a.account_code">{{ a.account_code }} — {{ a.name }}</option>
            </datalist>
            <button @click="addAllocation" :class="btnOutline('neutral')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
              {{ t('accounting.closing.profit_distribution.add_allocation') }}
            </button>
          </div>

          <div class="text-sm border-t border-neutral-200 pt-2">
            {{ t('accounting.closing.profit_distribution.sum') }}: <strong class="font-mono">{{ formatMoney(pdSum) }}</strong>
            <span class="mx-2 text-neutral-300">·</span>
            {{ t('accounting.closing.profit_distribution.remaining') }}:
            <strong class="font-mono" :class="Math.abs(pdRemaining) < 0.005 ? 'text-success-600' : 'text-danger-600'">{{ formatMoney(pdRemaining) }}</strong>
          </div>

          <button v-if="auth.canWrite('accounting.periods.close')" @click="runProfitDistribution" :disabled="busy || Math.abs(pdRemaining) >= 0.005" :class="btnFilled('primary')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ t('accounting.closing.profit_distribution.book') }}
          </button>
        </template>
      </template>
    </div>

    <!-- Confirm modal: uzavření knih -->
    <div v-if="showCloseConfirm" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-md w-full p-5">
        <h3 class="text-lg font-semibold mb-3">{{ t('accounting.closing.close.confirm_title') }}</h3>
        <p class="text-sm text-neutral-600 mb-4">
          {{ t('accounting.closing.close.confirm_text', { date: formatDate(state?.period.ends_on), year: state?.period.fiscal_year }) }}
        </p>
        <div class="flex justify-end gap-2">
          <button @click="showCloseConfirm = false" :class="btnOutline('neutral')">
            {{ t('common.cancel') }}
          </button>
          <button @click="closeBooks()" :disabled="busy" :class="btnFilled('warning')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.badgeCheck" /></svg>
            {{ t('accounting.closing.close.button') }}
          </button>
        </div>
      </div>
    </div>

    <!-- EP-10b: override modal — uzavření přes nezaúčtované doklady s doloženým důvodem -->
    <div v-if="showUnpostedOverride" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-md w-full p-5">
        <h3 class="text-lg font-semibold mb-2">{{ t('accounting.closing.close.override_title') }}</h3>
        <p class="text-sm text-neutral-600 mb-3">{{ t('accounting.closing.close.override_text') }}</p>
        <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.closing.close.override_reason') }}</label>
        <textarea v-model="overrideReason" rows="3" maxlength="500"
          :placeholder="t('accounting.closing.close.override_reason_ph')"
          class="w-full px-2 py-1.5 border border-neutral-300 rounded-md text-sm bg-surface"></textarea>
        <div class="flex justify-end gap-2 mt-4">
          <button @click="showUnpostedOverride = false" :class="btnOutline('neutral')">
            {{ t('common.cancel') }}
          </button>
          <button @click="closeBooks(true)" :disabled="busy || overrideReason.trim().length < 3" :class="btnFilled('danger')">
            {{ t('accounting.closing.close.override_button') }}
          </button>
        </div>
      </div>
    </div>

    <!-- Confirm modal: otevření nového roku -->
    <div v-if="showOpenConfirm" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-md w-full p-5">
        <h3 class="text-lg font-semibold mb-3">{{ t('accounting.closing.open.confirm_title') }}</h3>
        <p class="text-sm text-neutral-600 mb-4">
          {{ t('accounting.closing.open.confirm_text', { year: (state?.period.fiscal_year ?? 0) + 1 }) }}
        </p>
        <div class="flex justify-end gap-2">
          <button @click="showOpenConfirm = false" :class="btnOutline('neutral')">
            {{ t('common.cancel') }}
          </button>
          <button @click="openNext" :disabled="busy" :class="btnFilled('success')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.play" /></svg>
            {{ t('accounting.closing.open.button') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
