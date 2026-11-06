<script setup lang="ts">
import ClientItems from './ClientItems.vue'
import { ref, computed, onMounted, onBeforeUnmount, watch, nextTick, useId } from 'vue'
import { useRoute, useRouter, RouterLink, onBeforeRouteLeave } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  stockApi,
  type StockItemPayload,
  type StockItemPackaging,
  type StockItemPackagingPayload,
  type StockItemCustomerPrice,
  type StockItemCustomerPricePayload,
  type StockCustomerPriceType,
  type StockItemPriceLevelRow,
  type StockItemPriceLevelPayload,
} from '@/api/stock'
import {
  eshopApi,
  type Manufacturer,
  type Category,
  type Tag,
  type Attribute,
  type AttributeOption,
  type ProductI18nRow,
  type ProductPricingBase,
  type ProductMedia,
  type ProductUpdatePayload,
  type ProductEditorPayload,
  type ProductAttributeRow,
  type ProductPrice,
  type ProductVendor,
  type PriceMode,
  type PriceRounding,
  type ProductPromoPrice,
  type ProductPromoPricePayload,
  type PromoQtyMode,
  type PromoState,
  type EshopLocale,
  type EshopCurrency,
  type PackagingUnit,
} from '@/api/eshop'
import { clientsApi, type Client } from '@/api/clients'
import { codebooksApi, type Country, type VatRate, type Unit } from '@/api/codebooks'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { apiErrorMessage } from '@/api/errors'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import ClientSearchSelect from '@/components/ui/ClientSearchSelect.vue'
import { formatUnitPrice } from '@/composables/useFormat'
import { roundDerivedPrice, unitPriceText } from '@/utils/unitPrice'
import EmptyState from '@/components/ui/EmptyState.vue'
import DateInput from '@/components/ui/DateInput.vue'
import MarkdownEditor from '@/components/ui/MarkdownEditor.vue'
import ProductRelationsPanel from '@/components/stock/ProductRelationsPanel.vue'
import ProductVariantInheritance from '@/components/stock/ProductVariantInheritance.vue'
import ProductPostingDefaults from '@/components/stock/ProductPostingDefaults.vue'
import { useSupplierStore } from '@/stores/supplier'
import {
  ALL_CURRENCIES,
  cloneRuleSet,
  diffPriceLevelRules,
  invalidRule,
  ruleSetFrom,
  type LevelRuleSet,
  type LevelRuleType,
  type LevelRuleValue,
} from './itemPriceLevelRules'
import { formatAdjustment, invalidAdjustment, joinAdjustment, splitAdjustment, type AdjustmentKind } from '@/pages/eshop/priceAdjustment'
import type { ProductVariantContext, ProductWithMasterContext } from '@/api/productMasters'

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const toast = useToast()
const auth = useAuthStore()
const supplierStore = useSupplierStore()
const pageId = useId()
const tabList = ref<HTMLElement | null>(null)

const isEdit = computed(() => route.params.id !== undefined && route.params.id !== 'new')
const itemId = computed(() => (isEdit.value ? Number(route.params.id) : null))
const canWriteEshop = computed(() => auth.canWrite('eshop.write'))
const canSaveEditor = computed(() => auth.canWrite('stock.items.write') && (!isEdit.value || canWriteEshop.value))

// ── Taby ────────────────────────────────────────────────────────────────
type Tab = 'general' | 'intrastat' | 'accounting' | 'languages' | 'master' | 'relations' | 'categories' | 'parameters' | 'prices' | 'vendors' | 'attachments'
// Účtování (výchozí účet a dimenze karty) patří k podvojnému účetnictví.
const accountingTabVisible = computed(() => supplierStore.currentSupplier?.accounting_mode === 'double_entry')
const tabs = computed<Tab[]>(() => [
  'general', 'languages', 'master', 'relations', 'categories', 'parameters', 'prices', 'vendors', 'attachments', 'intrastat',
  ...(accountingTabVisible.value ? ['accounting' as const] : []),
])
const tab = ref<Tab>((tabs.value as string[]).includes(String(route.query.tab)) ? (route.query.tab as Tab) : 'general')
const postingDefaults = ref<InstanceType<typeof ProductPostingDefaults> | null>(null)
watch(tab, (v) => {
  if (route.query.tab !== v) {
    router.replace({ query: { ...route.query, tab: v } })
  }

  void nextTick(showActiveTab)
})
function showActiveTab() {
  const list = tabList.value
  const active = list?.querySelector<HTMLElement>('[aria-selected="true"]')
  if (!list || !active) return
  const relativeLeft = active.getBoundingClientRect().left - list.getBoundingClientRect().left
  if (relativeLeft < 0) list.scrollLeft += relativeLeft
  else if (relativeLeft + active.offsetWidth > list.clientWidth) {
    list.scrollLeft += relativeLeft + active.offsetWidth - list.clientWidth
  }
}

function onTabKey(event: KeyboardEvent, current: Tab) {
  const list = tabs.value
  const index = list.indexOf(current)
  let next = -1
  if (event.key === 'ArrowRight') {
    next = (index + 1) % list.length
  } else if (event.key === 'ArrowLeft') {
    next = (index + list.length - 1) % list.length
  } else if (event.key === 'Home') {
    next = 0
  } else if (event.key === 'End') {
    next = list.length - 1
  }

  if (next < 0) return

  event.preventDefault()
  tab.value = tabs.value[next]!
  void nextTick(() => {
    tabList.value?.querySelector<HTMLElement>('[aria-selected="true"]')?.focus()
  })
}

// ── Číselníky ───────────────────────────────────────────────────────────
const vatRates = ref<VatRate[]>([])
const currencies = ref<EshopCurrency[]>([])
const units = ref<Unit[]>([])
const countries = ref<Country[]>([])
const locales = ref<EshopLocale[]>([])
const manufacturers = ref<Manufacturer[]>([])
const categories = ref<Category[]>([])
const tags = ref<Tag[]>([])
const attributes = ref<Attribute[]>([])
const attrOptions = ref<Record<number, AttributeOption[]>>({})

const submitting = ref(false)
const error = ref('')
const errors = ref<Record<string, string[]>>({})
const skuTouched = ref(false)
const rowVersion = ref(0)
const editorLoaded = ref(false)
const variantContext = ref<ProductVariantContext | null>(null)
const savedSnapshot = ref('')

// ── Základní pole (skladová karta) ──────────────────────────────────────
const form = ref<StockItemPayload>({
  sku: '',
  name: '',
  item_type: 'goods',
  eet_item_kind: 'standard',
  unit: 'ks',
  tracking_mode: 'none',
  ean: null,
  vat_rate_id: null,
  sale_price_without_vat: null,
  min_qty: null,
  intrastat_cn8_code: null,
  intrastat_country_of_origin: null,
  intrastat_net_mass_kg: null,
  intrastat_supplementary_unit: null,
  intrastat_supplementary_unit_coefficient: null,
  is_active: true,
  note: null,
})

// ── E-shopová pole (agregát) ────────────────────────────────────────────
const eshop = ref({
  manufacturer_id: null as number | null,
  warranty_months: null as number | null,
  delivery_days: null as number | null,
  export_eshop: false,
  is_stocked: true,
  weight_g: null as number | null,
  pricing_base: 'weighted_avg' as ProductPricingBase,
})

// ── Jazyky ──────────────────────────────────────────────────────────────
// Nabídku vede číselník jazyků e-shopu (stock_locales, migrace 1370), ne pevný
// seznam v kódu. Archivovaný jazyk se nenabízí, ale už uložený překlad v něm
// zůstává editovatelný — proto se do popisků sahá přes localeLabel().
const i18nRows = ref<ProductI18nRow[]>([])
const newLocale = ref('')
const selectedLocale = ref('cs')
const persistedLocales = ref<Set<string>>(new Set())
const freeLocales = computed(() =>
  locales.value.filter(l => !l.archived && !i18nRows.value.some(r => r.locale === l.code)))
const orderedI18nRows = computed(() => [
  ...i18nRows.value.filter(r => r.locale === 'cs'),
  ...i18nRows.value.filter(r => r.locale !== 'cs'),
])
function localeLabel(code: string): string {
  return locales.value.find(l => l.code === code)?.name ?? code.toUpperCase()
}
function emptyI18nRow(locale: string): ProductI18nRow {
  return { locale, name: null, short_desc: null, description: null, seo_title: null, seo_description: null, seo_slug: null }
}
function ensureCzechRow() {
  if (!i18nRows.value.some(r => r.locale === 'cs')) i18nRows.value.unshift(emptyI18nRow('cs'))
}
function addLocale() {
  const l = newLocale.value
  if (!l || i18nRows.value.some(r => r.locale === l)) return
  i18nRows.value.push(emptyI18nRow(l))
  selectedLocale.value = l
  newLocale.value = ''
}
function removeLocale(l: string) {
  i18nRows.value = i18nRows.value.filter(r => r.locale !== l)
  if (selectedLocale.value === l) {
    selectedLocale.value = i18nRows.value.some(r => r.locale === 'cs')
      ? 'cs'
      : (i18nRows.value[0]?.locale ?? '')
  }
}

// ── Kategorie & štítky ──────────────────────────────────────────────────
const selectedCategoryIds = ref<number[]>([])
const primaryCategoryId = ref<number | null>(null)
const selectedTagIds = ref<number[]>([])
function toggleCategory(id: number) {
  const idx = selectedCategoryIds.value.indexOf(id)
  if (idx === -1) {
    selectedCategoryIds.value.push(id)
    if (primaryCategoryId.value === null) primaryCategoryId.value = id
  } else {
    selectedCategoryIds.value.splice(idx, 1)
    if (primaryCategoryId.value === id) primaryCategoryId.value = selectedCategoryIds.value[0] ?? null
  }
}
function toggleTag(id: number) {
  const idx = selectedTagIds.value.indexOf(id)
  if (idx === -1) selectedTagIds.value.push(id)
  else selectedTagIds.value.splice(idx, 1)
}

// ── Parametry (atributy) ────────────────────────────────────────────────
interface AttrState {
  value_text: string | null
  value_num: string | null
  value_bool: boolean | null
  option_id: number | null
  option_ids: number[]
}
const attrValues = ref<Record<number, AttrState>>({})
function ensureAttrState(id: number): AttrState {
  if (!attrValues.value[id]) {
    attrValues.value[id] = { value_text: null, value_num: null, value_bool: false, option_id: null, option_ids: [] }
  }
  return attrValues.value[id]
}
function toggleAttrOption(attrId: number, optId: number) {
  const st = ensureAttrState(attrId)
  const idx = st.option_ids.indexOf(optId)
  if (idx === -1) st.option_ids.push(optId)
  else st.option_ids.splice(idx, 1)
}

// ── Ceny (per měna) ─────────────────────────────────────────────────────
interface PriceRow {
  id: number | null
  currency_code: string
  price_mode: PriceMode
  markup_pct: string | null
  fixed_price: string | null
  rounding: PriceRounding
  computed_price: string | null
  computed_base: string | null
  computed_rate: string | null
  computed_at: string | null
  is_manual_override: boolean
  use_pricing_rules: boolean
}
const ROUNDING_MODES: PriceRounding[] = ['none', '0.01', '0.10', '0.50', '1', '9_ending']
const prices = ref<PriceRow[]>([])
const recomputing = ref(false)
const configuredCurrencyCodes = computed(() => new Set(currencies.value.map(c => c.code.toUpperCase())))

/**
 * Měny bere karta z číselníku PRODEJNÍCH měn e-shopu (/eshop/currencies), ne
 * z měnových účtů dodavatele: prodejní měna je prezentace ceny zákazníkovi
 * a s tím, kam přistanou peníze, nesouvisí — zboží se dá nacenit v GBP
 * a zákazník zaplatí kartou na eurový účet. Prázdný řetězec znamená „vyber měnu".
 */
const defaultCurrency = computed(() =>
  currencies.value.find(c => c.is_default)?.code ?? currencies.value[0]?.code ?? '')
/**
 * Volby měny pro jeden řádek. Uloženou měnu, která v číselníku není (dřív šlo
 * napsat cokoliv), do nabídky přidáme — jinak by se select tvářil prázdně
 * a uložení by hodnotu tiše přepsalo.
 */
function currencyOptions(current: string): { code: string; known: boolean }[] {
  const opts = currencies.value.map(c => ({ code: c.code, known: true }))
  if (current && !opts.some(o => o.code === current)) opts.unshift({ code: current, known: false })
  return opts
}
function priceRowFrom(p: ProductPrice): PriceRow {
  return {
    id: p.id,
    currency_code: p.currency_code,
    price_mode: p.price_mode,
    markup_pct: p.markup_pct,
    fixed_price: unitPriceText(p.fixed_price),
    rounding: p.rounding,
    computed_price: unitPriceText(p.computed_price),
    computed_base: p.computed_base,
    computed_rate: p.computed_rate,
    computed_at: p.computed_at,
    is_manual_override: p.is_manual_override,
    use_pricing_rules: p.use_pricing_rules ?? false,
  }
}
function emptyPriceRow(currencyCode: string): PriceRow {
  return {
    id: null,
    currency_code: currencyCode,
    price_mode: 'markup',
    markup_pct: null,
    fixed_price: null,
    rounding: 'none',
    computed_price: null,
    computed_base: null,
    computed_rate: null,
    computed_at: null,
    is_manual_override: false,
    use_pricing_rules: false,
  }
}
function withConfiguredCurrencyRows(rows: PriceRow[]): PriceRow[] {
  const byCurrency = new Map(rows.map(row => [row.currency_code.toUpperCase(), row]))
  const result = currencies.value.map(currency =>
    byCurrency.get(currency.code.toUpperCase()) ?? emptyPriceRow(currency.code))
  const configured = configuredCurrencyCodes.value
  result.push(...rows.filter(row => !configured.has(row.currency_code.toUpperCase())))
  return result
}
function removePriceRow(idx: number) {
  const row = prices.value[idx]
  if (configuredCurrencyCodes.value.has(row.currency_code.toUpperCase())) {
    prices.value.splice(idx, 1, emptyPriceRow(row.currency_code))
  } else {
    prices.value.splice(idx, 1)
  }
}
async function recomputePrices() {
  if (!itemId.value || !editorLoaded.value || !canWriteEshop.value) return
  recomputing.value = true
  try {
    const result = await eshopApi.updatePricesVersioned(
      itemId.value,
      rowVersion.value,
      meaningfulPriceRows().map(pricePayloadFrom),
    )
    prices.value = withConfiguredCurrencyRows(result.prices.map(priceRowFrom))
    rowVersion.value = result.row_version
    toast.success(t('common.saved'))
  } catch (err: any) {
    toast.error(mapError(err))
  } finally {
    recomputing.value = false
  }
}
/** Prázdné řádky aktivních měn jsou jen připravená pole editoru. */
function meaningfulPriceRows(): PriceRow[] {
  return prices.value.filter(r =>
    r.id !== null
    || r.is_manual_override
    || r.use_pricing_rules
    || String(r.markup_pct ?? '').trim() !== ''
    || String(r.fixed_price ?? '').trim() !== '')
}

function i18nRowHasContent(r: ProductI18nRow): boolean {
  return [r.name, r.short_desc, r.description, r.seo_title, r.seo_description, r.seo_slug]
    .some(v => String(v ?? '').trim() !== '')
}

function meaningfulI18nRows(): ProductI18nRow[] {
  return i18nRows.value.filter(r => persistedLocales.value.has(r.locale) || i18nRowHasContent(r))
}

function pricePayloadFrom(r: PriceRow) {
  return {
    currency_code: r.currency_code.trim().toUpperCase(),
    price_mode: r.price_mode,
    markup_pct: r.price_mode !== 'fixed' ? (r.markup_pct === '' ? null : r.markup_pct) : null,
    fixed_price: r.price_mode === 'fixed' || r.is_manual_override ? (r.fixed_price === '' ? null : r.fixed_price) : null,
    rounding: r.rounding,
    is_manual_override: r.is_manual_override,
    use_pricing_rules: r.use_pricing_rules,
  }
}

// ── Akční (promoční) ceny ───────────────────────────────────────────────
// Tři nezávislé, každý volitelný limit: časové okno, množstevní strop a sama
// akční cena. Strop v režimu „stock" se čte živě ze skladu, v režimu „limited"
// je to pevný rozpočet odečítaný prodejem. Dopočtené qty_remaining/state
// posílá backend (jediný zdroj pravdy je EffectivePriceResolver).
interface PromoRow {
  id: number | null
  currency_code: string
  promo_price: string
  label: string | null
  valid_from: string | null
  valid_to: string | null
  qty_mode: PromoQtyMode
  qty_limit: string | null
  is_active: boolean
  note: string | null
  qty_remaining: string | null
  state: PromoState | null
}
const QTY_MODES: PromoQtyMode[] = ['stock', 'limited', 'unlimited']
const promos = ref<PromoRow[]>([])

function promoRowFrom(p: ProductPromoPrice): PromoRow {
  return {
    id: p.id,
    currency_code: p.currency_code,
    promo_price: unitPriceText(p.promo_price),
    label: p.label,
    valid_from: p.valid_from,
    valid_to: p.valid_to,
    qty_mode: p.qty_mode,
    qty_limit: p.qty_limit,
    is_active: p.is_active,
    note: p.note,
    qty_remaining: p.qty_remaining,
    state: p.state,
  }
}
function addPromoRow() {
  promos.value.push({
    id: null,
    currency_code: prices.value[0]?.currency_code || defaultCurrency.value,
    promo_price: '',
    label: null,
    valid_from: null,
    valid_to: null,
    qty_mode: 'stock',
    qty_limit: null,
    is_active: true,
    note: null,
    qty_remaining: null,
    state: null,
  })
}
function removePromoRow(idx: number) {
  promos.value.splice(idx, 1)
}
function promoPayloadFrom(r: PromoRow): ProductPromoPricePayload {
  return {
    id: r.id,
    currency_code: r.currency_code.trim().toUpperCase() || defaultCurrency.value,
    promo_price: String(r.promo_price ?? '').trim(),
    label: r.label === '' ? null : r.label,
    valid_from: r.valid_from === '' ? null : r.valid_from,
    valid_to: r.valid_to === '' ? null : r.valid_to,
    qty_mode: r.qty_mode,
    qty_limit: r.qty_mode === 'limited' ? (r.qty_limit === '' ? null : r.qty_limit) : null,
    is_active: r.is_active,
    note: r.note === '' ? null : r.note,
  }
}
/** Barva odznaku stavu akce — sémantika shodná se zbytkem UI. */
function promoStateClass(state: PromoState | null): string {
  switch (state) {
    case 'active': return 'bg-success-50 text-success-600 border-success-500/40'
    case 'scheduled': return 'bg-primary-50 text-primary-600 border-primary-500/40'
    case 'exhausted': return 'bg-warning-50 text-warning-600 border-warning-500/40'
    case 'expired':
    case 'disabled': return 'bg-neutral-100 text-neutral-500 border-neutral-300'
    default: return 'bg-neutral-100 text-neutral-500 border-neutral-300'
  }
}

// ── Dodavatelé ──────────────────────────────────────────────────────────
interface VendorRow {
  id: number | null
  client_id: number | null
  vendor_sku: string | null
  purchase_price: string | null
  currency_code: string
  delivery_days: number | null
  stock_qty: string | null
  is_preferred: boolean
  note: string | null
}
const vendors = ref<VendorRow[]>([])
const vendorClients = ref<Client[]>([])

function vendorRowFrom(v: ProductVendor): VendorRow {
  return {
    id: v.id,
    client_id: v.client_id,
    vendor_sku: v.vendor_sku,
    purchase_price: unitPriceText(v.purchase_price),
    currency_code: v.currency_code,
    delivery_days: v.delivery_days,
    stock_qty: v.stock_qty,
    is_preferred: v.is_preferred,
    note: v.note,
  }
}
function addVendorRow() {
  vendors.value.push({
    id: null,
    client_id: null,
    vendor_sku: null,
    purchase_price: null,
    currency_code: defaultCurrency.value,
    delivery_days: null,
    stock_qty: null,
    is_preferred: vendors.value.length === 0,
    note: null,
  })
}
function removeVendorRow(idx: number) {
  vendors.value.splice(idx, 1)
}
function setPreferredVendor(idx: number) {
  vendors.value.forEach((v, i) => { v.is_preferred = i === idx })
}
function vendorPayloadFrom(r: VendorRow) {
  return {
    client_id: r.client_id as number,
    vendor_sku: r.vendor_sku && r.vendor_sku.trim() !== '' ? r.vendor_sku : null,
    purchase_price: r.purchase_price === '' ? null : r.purchase_price,
    currency_code: r.currency_code.trim().toUpperCase(),
    delivery_days: numOrNull(r.delivery_days),
    stock_qty: r.stock_qty === '' ? null : r.stock_qty,
    is_preferred: r.is_preferred,
    note: r.note && r.note.trim() !== '' ? r.note : null,
  }
}

// ── Balení (issue #17) ──────────────────────────────────────────────────
// Nadřazené jednotky karty: kód z číselníku balení firmy, přesný poměr
// k základní jednotce a EAN balení. Ukládá se společným Uložit (vlastní PUT
// po uložení karty); poměr a odebrání hlídá backend proti vystaveným dokladům.
interface PackagingRow {
  unit_code: string
  factor: string
  ean: string | null
  in_use: boolean
}
const packagingCodebook = ref<PackagingUnit[]>([])
const packagingRows = ref<PackagingRow[]>([])
const defaultSaleUnit = ref<string | null>(null)
const packagingLoaded = ref(false)
const savedPackagingSnapshot = ref('')

function sameCode(a: string | null | undefined, b: string | null | undefined): boolean {
  return String(a ?? '').trim().toLowerCase() === String(b ?? '').trim().toLowerCase()
}
function normalizeDecimal(value: string | number | null | undefined): string {
  return String(value ?? '').trim().replace(/\s+/g, '').replace(',', '.')
}
/** Aktivní balení z číselníku, bez základní jednotky a kódů z ostatních řádků; uložený kód vždy. */
function packagingOptions(current: string): { code: string; name: string; known: boolean }[] {
  const taken = packagingRows.value.map(r => r.unit_code).filter(code => code && !sameCode(code, current))
  const opts = packagingCodebook.value
    .filter(u => u.is_active && !sameCode(u.code, form.value.unit) && !taken.some(code => sameCode(code, u.code)))
    .map(u => ({ code: u.code, name: u.name, known: true }))
  if (current && !opts.some(o => sameCode(o.code, current))) {
    const known = packagingCodebook.value.find(u => sameCode(u.code, current))
    opts.unshift({ code: current, name: known?.name ?? current, known: !!known })
  }
  return opts
}
const activePackagingCount = computed(() => packagingCodebook.value.filter(u => u.is_active).length)
function addPackagingRow() {
  packagingRows.value.push({ unit_code: '', factor: '', ean: null, in_use: false })
}
function removePackagingRow(idx: number) {
  const [row] = packagingRows.value.splice(idx, 1)
  if (row && sameCode(defaultSaleUnit.value, row.unit_code)) defaultSaleUnit.value = null
}
watch(() => packagingRows.value.map(r => r.unit_code), (codes) => {
  if (defaultSaleUnit.value && !codes.some(code => sameCode(code, defaultSaleUnit.value))) defaultSaleUnit.value = null
})
function applyPackaging(p: StockItemPackaging) {
  packagingRows.value = (p.units ?? []).map(u => ({ unit_code: u.unit_code, factor: u.factor, ean: u.ean, in_use: !!u.in_use }))
  defaultSaleUnit.value = p.default_sale_unit
}
function buildPackagingPayload(): StockItemPackagingPayload {
  const units = packagingRows.value.map(r => ({
    unit_code: String(r.unit_code ?? '').trim(),
    factor: normalizeDecimal(r.factor),
    ean: nullableCode(r.ean),
  }))
  const preferred = units.find(u => sameCode(u.unit_code, defaultSaleUnit.value))
  return { default_sale_unit: preferred ? preferred.unit_code : null, units }
}
function packagingSnapshot(): string {
  return JSON.stringify(buildPackagingPayload())
}
const packagingDirty = computed(() => packagingLoaded.value && packagingSnapshot() !== savedPackagingSnapshot.value)
function validatePackaging(): string | null {
  const seen: string[] = []
  for (const row of packagingRows.value) {
    const code = String(row.unit_code ?? '').trim()
    const factor = Number(normalizeDecimal(row.factor))
    if (!code || normalizeDecimal(row.factor) === '' || !Number.isFinite(factor) || factor <= 0) return t('stock.packaging.code_required')
    if (sameCode(code, form.value.unit)) return t('stock.packaging.same_as_base')
    if (seen.some(c => sameCode(c, code))) return t('stock.packaging.duplicate_code', { code })
    seen.push(code)
  }
  return null
}

// ── Individuální ceny zákazníků (issue #17) ─────────────────────────────
// Cena je vždy za ZÁKLADNÍ jednotku bez DPH. Zákaznická cena nahrazuje
// standardní cenu jako základ, akce se použije jen když je levnější. To vše
// počítá backend (EffectivePriceResolver); tady je jen náhled.
interface CustomerPriceRow {
  id: number | null
  client_id: number | null
  client_name: string | null
  currency_code: string
  price_type: StockCustomerPriceType
  fixed_price: string | null
  discount_pct: string | null
  valid_from: string | null
  valid_to: string | null
  note: string | null
  resulting_price: string | null
  /** Otisk ceny při načtení. Dokud sedí, platí dopočet backendu (zohledňuje platnost). */
  loadedKey: string | null
}
const customerPrices = ref<CustomerPriceRow[]>([])
const customerPricesLoaded = ref(false)
const savedCustomerPricesSnapshot = ref('')
const CUSTOMER_PRICE_TYPES: StockCustomerPriceType[] = ['fixed', 'discount_pct']

function customerPriceKey(r: CustomerPriceRow): string {
  return JSON.stringify([r.currency_code, r.price_type, normalizeDecimal(r.fixed_price), normalizeDecimal(r.discount_pct), r.valid_from, r.valid_to])
}
function customerPriceRowFrom(p: StockItemCustomerPrice): CustomerPriceRow {
  const row: CustomerPriceRow = {
    id: p.id,
    client_id: p.client_id,
    client_name: p.client_name,
    currency_code: p.currency_code,
    price_type: p.price_type,
    fixed_price: unitPriceText(p.fixed_price),
    discount_pct: p.discount_pct,
    valid_from: p.valid_from,
    valid_to: p.valid_to,
    note: p.note,
    resulting_price: p.resulting_price,
    loadedKey: null,
  }
  row.loadedKey = customerPriceKey(row)
  return row
}
function addCustomerPriceRow() {
  customerPrices.value.push({
    id: null,
    client_id: null,
    client_name: null,
    currency_code: defaultCurrency.value || 'CZK',
    price_type: 'fixed',
    fixed_price: '',
    discount_pct: '',
    valid_from: null,
    valid_to: null,
    note: null,
    resulting_price: null,
    loadedKey: null,
  })
}
function removeCustomerPriceRow(idx: number) {
  customerPrices.value.splice(idx, 1)
}
function customerPricePayloadFrom(r: CustomerPriceRow): StockItemCustomerPricePayload {
  const fixed = normalizeDecimal(r.fixed_price)
  const pct = normalizeDecimal(r.discount_pct)
  return {
    client_id: r.client_id as number,
    currency_code: r.currency_code.trim().toUpperCase(),
    price_type: r.price_type,
    fixed_price: r.price_type === 'fixed' ? (fixed === '' ? null : fixed) : null,
    discount_pct: r.price_type === 'discount_pct' ? (pct === '' ? null : pct) : null,
    valid_from: r.valid_from || null,
    valid_to: r.valid_to || null,
    note: r.note && r.note.trim() !== '' ? r.note.trim() : null,
  }
}
function customerPricesSnapshot(): string {
  return JSON.stringify(customerPrices.value.map(customerPricePayloadFrom))
}
const customerPricesDirty = computed(() => customerPricesLoaded.value && customerPricesSnapshot() !== savedCustomerPricesSnapshot.value)
/** Standardní cena za základní jednotku v dané měně, základ pro náhled slevy. */
function standardPriceFor(currency: string): number | null {
  const row = prices.value.find(p => p.currency_code.toUpperCase() === currency.toUpperCase())
  if (row?.computed_price != null && row.computed_price !== '') return Number(row.computed_price)
  const sale = normalizeDecimal(form.value.sale_price_without_vat)
  if (currency.toUpperCase() === 'CZK' && sale !== '' && Number.isFinite(Number(sale))) return Number(sale)
  return null
}
function customerPricePreview(r: CustomerPriceRow): number | null {
  if (r.loadedKey !== null && r.loadedKey === customerPriceKey(r) && r.resulting_price != null) return Number(r.resulting_price)
  if (r.price_type === 'fixed') {
    const fixed = normalizeDecimal(r.fixed_price)
    return fixed === '' || !Number.isFinite(Number(fixed)) ? null : Number(fixed)
  }
  const pct = normalizeDecimal(r.discount_pct)
  const standard = standardPriceFor(r.currency_code)
  if (standard === null || pct === '' || !Number.isFinite(Number(pct))) return null
  return roundDerivedPrice(standard * (1 - Number(pct) / 100))
}
function validateCustomerPrices(): string | null {
  const seen = new Set<string>()
  for (const r of customerPrices.value) {
    if (!r.client_id) return t('stock.customer_prices.client_required')
    if (!r.currency_code) return t('eshop.prices.currency_required')
    const raw = normalizeDecimal(r.price_type === 'fixed' ? r.fixed_price : r.discount_pct)
    const value = Number(raw)
    if (raw === '' || !Number.isFinite(value) || value < 0 || (r.price_type === 'discount_pct' && value > 100)) {
      return t('stock.customer_prices.value_invalid')
    }
    if (r.valid_from && r.valid_to && r.valid_from > r.valid_to) return t('stock.customer_prices.dates_invalid')
    const key = `${r.client_id}|${r.currency_code.toUpperCase()}`
    if (seen.has(key)) {
      return t('stock.customer_prices.duplicate', { client: r.client_name ?? `#${r.client_id}`, currency: r.currency_code.toUpperCase() })
    }
    seen.add(key)
  }
  return null
}

// ── Cenové hladiny: výjimky této karty ─────────────────────────────────
// Backend vrací řádek per aktivní hladina × měna karty se zděděnou cenou a jejím
// zdrojem. Výjimky karty (pravidla match_type='product') se načítají VŠECHNY
// z pravidel hladiny, ne jen to, které v dané měně vyhrává: „Sleva pro produkt"
// (bez měny) + pevné ceny per měna. Uložení posílá jen rozdíl proti načtenému
// stavu (itemPriceLevelRules.ts), takže nic, na co uživatel nesáhl, nezmizí.
type PriceLevelMode = 'inherit' | LevelRuleType
interface PriceLevelOverride {
  level_id: number
  code: string
  name: string
  is_active: boolean
  /** Upravované výjimky karty v hladině podle měny ('' = sleva pro produkt). */
  rules: LevelRuleSet
  /** Sleva, nebo přirážka pro produkt; drží volbu i při prázdném poli. */
  kind: AdjustmentKind
  /** Rozepsaná kladná hodnota slevy / přirážky pro produkt (validuje se proti `kind`). */
  discountInput: string
}
const priceLevelRows = ref<StockItemPriceLevelRow[]>([])
const priceLevelOverrides = ref<PriceLevelOverride[]>([])
const loadedPriceLevelRules = ref<Record<number, LevelRuleSet>>({})
const priceLevelsLoaded = ref(false)
const priceLevelGroups = computed(() => priceLevelOverrides.value.map(o => ({
  o,
  rows: priceLevelRows.value.filter(r => r.price_level_id === o.level_id),
})))

interface ItemPriceLevelsData {
  rows: StockItemPriceLevelRow[]
  rules: Record<number, LevelRuleSet>
}
/** Náhled karty + všechna pravidla karty v zobrazených hladinách. Selhání čehokoli = nic se neukládá. */
async function fetchPriceLevels(id: number): Promise<ItemPriceLevelsData> {
  const rows = await stockApi.getItemPriceLevels(id)
  const levelIds = [...new Set(rows.map(r => r.price_level_id))]
  const lists = await Promise.all(levelIds.map(levelId => eshopApi.getPriceLevelRules(levelId)))
  const rules: Record<number, LevelRuleSet> = {}
  levelIds.forEach((levelId, i) => {
    rules[levelId] = ruleSetFrom((lists[i] ?? []).filter(rule => rule.match_type === 'product' && rule.match_id === id))
  })
  return { rows, rules }
}
function applyPriceLevels(data: ItemPriceLevelsData) {
  priceLevelRows.value = data.rows
  const loaded: Record<number, LevelRuleSet> = {}
  const byLevel = new Map<number, PriceLevelOverride>()
  for (const r of data.rows) {
    if (byLevel.has(r.price_level_id)) continue
    loaded[r.price_level_id] = cloneRuleSet(data.rules[r.price_level_id] ?? {})
    byLevel.set(r.price_level_id, {
      level_id: r.price_level_id, code: r.code, name: r.name, is_active: r.is_active,
      rules: cloneRuleSet(data.rules[r.price_level_id] ?? {}),
      ...productAdjustment(data.rules[r.price_level_id]?.[ALL_CURRENCIES]),
    })
  }
  loadedPriceLevelRules.value = loaded
  priceLevelOverrides.value = [...byLevel.values()]
}
function editedPriceLevelRules(): Record<number, LevelRuleSet> {
  return Object.fromEntries(priceLevelOverrides.value.map(o => [o.level_id, o.rules]))
}
/** Jen změny: remove pro zrušená pravidla, upsert pro nová / změněná; nedotčená hladina nic. */
function priceLevelPayload(): StockItemPriceLevelPayload[] {
  return diffPriceLevelRules(loadedPriceLevelRules.value, editedPriceLevelRules())
}
const priceLevelsDirty = computed(() => priceLevelsLoaded.value && priceLevelPayload().length > 0)

/**
 * Sleva nebo přirážka pro produkt (pravidlo bez měny): samostatné pole, platí ve všech
 * měnách hladiny. Pole ukazuje kladnou hodnotu, znaménko nese `o.kind`.
 */
function productAdjustment(rule: LevelRuleValue | undefined): { kind: AdjustmentKind; discountInput: string } {
  const { kind, value } = splitAdjustment(rule?.rule_type === 'discount_pct' ? rule.value : '')
  return { kind, discountInput: value }
}
function levelDiscount(o: PriceLevelOverride): string {
  return o.discountInput
}
function setLevelDiscount(o: PriceLevelOverride, value: string) {
  o.discountInput = value.trim() === '' ? '' : value
  if (value.trim() === '') delete o.rules[ALL_CURRENCIES]
  else o.rules[ALL_CURRENCIES] = { rule_type: 'discount_pct', value: joinAdjustment(o.kind, value) }
}
function setLevelKind(o: PriceLevelOverride, kind: AdjustmentKind) {
  const value = levelDiscount(o)
  o.kind = kind
  if (value !== '') setLevelDiscount(o, value)
}
function priceLevelMode(o: PriceLevelOverride, currency: string): PriceLevelMode {
  return o.rules[currency.toUpperCase()]?.rule_type ?? 'inherit'
}
/** Sleva jen pro jednu měnu se nabízí, jen když ji karta už má (vznikla v editoru hladiny). */
function priceLevelModes(o: PriceLevelOverride, currency: string): PriceLevelMode[] {
  return priceLevelMode(o, currency) === 'discount_pct' ? ['inherit', 'fixed', 'discount_pct'] : ['inherit', 'fixed']
}
function priceLevelModeLabel(o: PriceLevelOverride, mode: PriceLevelMode): string {
  if (mode === 'inherit' && levelDiscount(o) !== '') return t('stock.price_levels.override_level_discount')
  return t('stock.price_levels.override_' + mode)
}
/** Změna jedné měny se týká JEN její výjimky; slevu pro produkt nikdy neruší. */
function setPriceLevelMode(o: PriceLevelOverride, currency: string, mode: PriceLevelMode) {
  const key = currency.toUpperCase()
  if (mode === 'inherit') {
    delete o.rules[key]
    return
  }
  const current = o.rules[key]
  o.rules[key] = { rule_type: mode, value: current?.rule_type === mode ? current.value : '' }
}
function priceLevelRowValue(o: PriceLevelOverride, currency: string): string {
  return o.rules[currency.toUpperCase()]?.value ?? ''
}
function setPriceLevelRowValue(o: PriceLevelOverride, currency: string, value: string) {
  const rule = o.rules[currency.toUpperCase()]
  if (rule) rule.value = value
}
/** „−10 %“ sleva, „+45 %“ přirážka (záporná sleva). */
function formatLevelPct(value: string | number): string {
  return formatAdjustment(value, n => new Intl.NumberFormat(locale.value, { maximumFractionDigits: 3 }).format(n))
}
/**
 * Název kategorie / výrobce zdroje, pokud je k dispozici: source_label, jinak
 * match_label pravidla, jinak dohledání match_id v číselníku. Pravidlo řádku je to
 * uplatněné, takže sedí na zděděný zdroj jen tehdy, když karta vlastní výjimku nemá.
 */
function priceLevelSourceLabel(r: StockItemPriceLevelRow, source: StockItemPriceLevelRow['source']): string | null {
  if (r.source_label) return r.source_label
  const rule = r.source === source ? r.rule : null
  if (!rule || rule.match_type !== source) return null
  if (rule.match_label) return rule.match_label
  if (rule.match_id == null) return null
  if (source === 'category') {
    const category = categories.value.find(c => c.id === rule.match_id)
    return category ? (category.path || category.name) : null
  }
  return manufacturers.value.find(m => m.id === rule.match_id)?.name ?? null
}
/** Zdroj ceny, kterou by karta měla bez vlastní výjimky (inherited_source; starší odpověď = source). */
function priceLevelSourceText(r: StockItemPriceLevelRow): string {
  const source = r.inherited_source ?? r.source
  let base: string
  switch (source) {
    case 'product': return t('stock.price_levels.source_product')
    case 'category': {
      const label = priceLevelSourceLabel(r, source)
      base = label ? t('stock.price_levels.source_category_label', { label }) : t('stock.price_levels.source_category')
      break
    }
    case 'manufacturer': {
      const label = priceLevelSourceLabel(r, source)
      base = label ? t('stock.price_levels.source_manufacturer_label', { label }) : t('stock.price_levels.source_manufacturer')
      break
    }
    case 'default':
      base = t('stock.price_levels.source_default')
      break
    default:
      return t('stock.price_levels.source_none')
  }
  const pct = source === 'default'
    ? (r.default_discount_pct ?? null)
    : (r.source === source && r.rule?.rule_type === 'discount_pct' ? r.rule.discount_pct : null)
  return pct != null && pct !== '' ? t('stock.price_levels.source_pct', { source: base, pct: formatLevelPct(pct) }) : base
}
/** Zděděná cena (bez výjimky karty); undefined = starší odpověď bez inherited_price. */
function priceLevelInheritedPrice(r: StockItemPriceLevelRow): number | null | undefined {
  if (r.inherited_price !== undefined) return r.inherited_price === null ? null : Number(r.inherited_price)
  if (r.source === 'product') return undefined
  return r.resulting_price === null ? null : Number(r.resulting_price)
}
function discountedPrice(standard: string | null, pctRaw: string): number | null {
  const pct = normalizeDecimal(pctRaw)
  if (standard === null || pct === '' || !Number.isFinite(Number(pct))) return null
  return roundDerivedPrice(Number(standard) * (1 - Number(pct) / 100))
}
/**
 * Náhled výsledné ceny: výjimka měny → sleva pro produkt → zděděná cena.
 * null + pending = zděděnou cenu bez výjimky dopočítá až backend po uložení.
 */
function priceLevelPreview(o: PriceLevelOverride, r: StockItemPriceLevelRow): { value: number | null; pending: boolean } {
  const own = o.rules[r.currency_code.toUpperCase()]
  if (own?.rule_type === 'fixed') {
    const fixed = normalizeDecimal(own.value)
    return { value: fixed === '' || !Number.isFinite(Number(fixed)) ? null : Number(fixed), pending: false }
  }
  if (own?.rule_type === 'discount_pct') return { value: discountedPrice(r.standard_price, own.value), pending: false }
  const levelRule = o.rules[ALL_CURRENCIES]
  if (levelRule?.rule_type === 'discount_pct') return { value: discountedPrice(r.standard_price, levelRule.value), pending: false }
  const inherited = priceLevelInheritedPrice(r)
  return inherited === undefined ? { value: null, pending: true } : { value: inherited, pending: false }
}
function validatePriceLevels(): string | null {
  for (const o of priceLevelOverrides.value) {
    if (levelDiscount(o) !== '' && invalidAdjustment(o.kind, levelDiscount(o))) {
      return t('stock.price_levels.value_invalid', { level: o.name })
    }
    for (const [key, rule] of Object.entries(o.rules)) {
      if (invalidRule(key, rule)) return t('stock.price_levels.value_invalid', { level: o.name })
    }
  }
  return null
}

/**
 * Balení a zákaznické ceny mají vlastní endpointy. Když se nenačtou, editor
 * zůstane použitelný, ale tyto sekce se neukládají. Prázdná sada by jinak
 * při uložení smazala data, která uživatel vůbec neviděl.
 */
async function loadItemExtras(id: number) {
  const [codebook, packaging, customer, levels] = await Promise.all([
    Promise.resolve().then(() => eshopApi.listPackagingUnits()).catch(() => [] as PackagingUnit[]),
    Promise.resolve().then(() => stockApi.getItemPackaging(id)).catch(() => null),
    Promise.resolve().then(() => stockApi.getCustomerPrices(id)).catch(() => null),
    Promise.resolve().then(() => fetchPriceLevels(id)).catch(() => null),
  ])
  priceLevelsLoaded.value = levels !== null
  applyPriceLevels(levels ?? { rows: [], rules: {} })
  packagingCodebook.value = codebook
  packagingLoaded.value = packaging !== null
  if (packaging) applyPackaging(packaging)
  else { packagingRows.value = []; defaultSaleUnit.value = null }
  savedPackagingSnapshot.value = packagingSnapshot()
  customerPricesLoaded.value = customer !== null
  customerPrices.value = (customer ?? []).map(customerPriceRowFrom)
  savedCustomerPricesSnapshot.value = customerPricesSnapshot()
}

/** Uloží změněné balení a zákaznické ceny po uložení karty. false = chyba (zobrazená). */
async function saveItemExtras(id: number): Promise<boolean> {
  if (packagingDirty.value) {
    const submitted = packagingSnapshot()
    try {
      const result = await stockApi.replaceItemPackaging(id, buildPackagingPayload())
      if (packagingSnapshot() === submitted) {
        if (result && Array.isArray(result.units)) applyPackaging(result)
        savedPackagingSnapshot.value = packagingSnapshot()
      } else {
        savedPackagingSnapshot.value = submitted
      }
    } catch (e: any) {
      error.value = mapError(e)
      tab.value = 'general'
      return false
    }
  }
  if (customerPricesDirty.value) {
    const submitted = customerPricesSnapshot()
    try {
      await stockApi.replaceCustomerPrices(id, customerPrices.value.map(customerPricePayloadFrom))
      if (customerPricesSnapshot() === submitted) {
        const fresh = await stockApi.getCustomerPrices(id).catch(() => null)
        if (fresh) customerPrices.value = fresh.map(customerPriceRowFrom)
        savedCustomerPricesSnapshot.value = customerPricesSnapshot()
      } else {
        savedCustomerPricesSnapshot.value = submitted
      }
    } catch (e: any) {
      error.value = mapError(e)
      tab.value = 'prices'
      return false
    }
  }
  if (postingDefaults.value?.dirty) {
    const message = await postingDefaults.value.save(id)
    if (message !== null) {
      error.value = message
      tab.value = 'accounting'
      return false
    }
  }
  if (priceLevelsDirty.value) {
    const submitted = JSON.stringify(editedPriceLevelRules())
    try {
      await stockApi.replaceItemPriceLevels(id, priceLevelPayload())
      // Odeslaný stav je teď v DB; další úpravy se porovnávají proti němu.
      loadedPriceLevelRules.value = JSON.parse(submitted) as Record<number, LevelRuleSet>
      if (JSON.stringify(editedPriceLevelRules()) === submitted) {
        const fresh = await fetchPriceLevels(id).catch(() => null)
        if (fresh) applyPriceLevels(fresh)
      }
    } catch (e: any) {
      error.value = mapError(e)
      tab.value = 'prices'
      return false
    }
  }
  return true
}

// ── Přílohy (média) ─────────────────────────────────────────────────────
const media = ref<ProductMedia[]>([])
const uploading = ref(false)
const mediaSaveState = ref<'idle' | 'saving' | 'saved' | 'error'>('idle')
const fileInput = ref<HTMLInputElement | null>(null)

function mapError(e: any): string {
  const code = e?.response?.data?.error?.code
  if (code) {
    const key = code.startsWith('eshop.error.') ? code : `eshop.error.${code}`
    const localized = t(key)
    if (localized !== key) return localized
  }
  return apiErrorMessage(e, t('common.error'))
}

/** Jednoduchý slug z názvu (bez diakritiky, VELKÁ) — zrcadlí BE fallback, jen pro live náhled. */
function slugFromName(name: string): string {
  const map: Record<string, string> = {
    á: 'a', č: 'c', ď: 'd', é: 'e', ě: 'e', í: 'i', ň: 'n',
    ó: 'o', ř: 'r', š: 's', ť: 't', ú: 'u', ů: 'u', ý: 'y', ž: 'z',
  }
  let s = name.toLowerCase()
  s = s.replace(/[áčďéěíňóřšťúůýž]/g, c => map[c] ?? c)
  s = s.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '')
  return s.toUpperCase().slice(0, 50)
}
function onNameInput() {
  if (!skuTouched.value && !isEdit.value) {
    form.value.sku = form.value.name ? slugFromName(form.value.name) : ''
  }
}
function onSkuInput() { skuTouched.value = true }

function numOrNull(v: number | null | string): number | null {
  if (v === null || v === '' || v === undefined) return null
  const n = Number(v)
  return Number.isFinite(n) ? n : null
}

async function loadCodebooks() {
  const [vr, cur, un, countriesList, loc, mf, cat, tg, attr, vc] = await Promise.all([
    codebooksApi.vatRates('CZ').catch(() => []),
    eshopApi.listCurrencies({ active: 1 }).catch(() => [] as EshopCurrency[]),
    codebooksApi.units().catch(() => [] as Unit[]),
    codebooksApi.countries().catch(() => [] as Country[]),
    eshopApi.listLocales().catch(() => [] as EshopLocale[]),
    eshopApi.listManufacturers().catch(() => []),
    eshopApi.listCategories().catch(() => []),
    eshopApi.listTags().catch(() => []),
    eshopApi.listAttributes().catch(() => []),
    clientsApi.list({ role: 'vendors', per_page: 500, sort: 'name' }).then(r => r.data).catch(() => [] as Client[]),
  ])
  vatRates.value = vr
  currencies.value = cur
  units.value = un
  countries.value = countriesList
  locales.value = loc
  manufacturers.value = mf
  categories.value = cat
  tags.value = tg
  attributes.value = attr
  vendorClients.value = vc
  // Volby pro enum (výběr) i text (našeptávač) atributy
  const optionAttrs = attr.filter(a => a.data_type === 'enum' || a.data_type === 'text')
  await Promise.all(optionAttrs.map(async (a) => {
    try { attrOptions.value[a.id] = await eshopApi.listAttributeOptions(a.id) } catch { attrOptions.value[a.id] = [] }
  }))
  // Inicializace stavu atributů (prázdné)
  for (const a of attr) ensureAttrState(a.id)
}

async function loadProduct(id: number) {
  editorLoaded.value = false
  const p = await eshopApi.getProduct(id) as ProductWithMasterContext
  variantContext.value = p.variant ? {
    ...p.variant,
    master_name: p.master?.name ?? p.variant.master_name,
    effective: p.variant.effective ?? p.effective ?? undefined,
  } : null
  rowVersion.value = p.row_version
  // základní pole
  form.value = {
    sku: p.sku,
    name: p.name,
    item_type: p.item_type,
    eet_item_kind: p.eet_item_kind ?? 'standard',
    unit: p.unit,
    tracking_mode: p.tracking_mode ?? 'none',
    ean: p.ean,
    vat_rate_id: p.vat_rate_id,
    sale_price_without_vat: unitPriceText(p.sale_price_without_vat),
    min_qty: p.min_qty,
    intrastat_cn8_code: p.intrastat_cn8_code,
    intrastat_country_of_origin: p.intrastat_country_of_origin,
    intrastat_net_mass_kg: p.intrastat_net_mass_kg,
    intrastat_supplementary_unit: p.intrastat_supplementary_unit,
    intrastat_supplementary_unit_coefficient: p.intrastat_supplementary_unit_coefficient,
    is_active: p.is_active,
    note: p.note,
  }
  skuTouched.value = true
  // e-shopová pole
  eshop.value = {
    manufacturer_id: p.manufacturer_id ?? null,
    warranty_months: p.warranty_months ?? null,
    delivery_days: p.delivery_days ?? null,
    export_eshop: !!p.export_eshop,
    is_stocked: p.is_stocked ?? true,
    weight_g: p.weight_g ?? null,
    pricing_base: p.pricing_base ?? 'weighted_avg',
  }
  // jazyky
  i18nRows.value = (p.i18n ?? []).map(r => ({
    locale: r.locale,
    name: r.name ?? null,
    short_desc: r.short_desc ?? null,
    description: r.description ?? null,
    seo_title: r.seo_title ?? null,
    seo_description: r.seo_description ?? null,
    seo_slug: r.seo_slug ?? null,
  }))
  persistedLocales.value = new Set(i18nRows.value.map(r => r.locale))
  ensureCzechRow()
  // kategorie & štítky
  selectedCategoryIds.value = (p.categories ?? []).map(c => c.category_id)
  primaryCategoryId.value = (p.categories ?? []).find(c => c.is_primary)?.category_id ?? null
  selectedTagIds.value = [...(p.tag_ids ?? [])]
  // atributy
  for (const row of p.attributes ?? []) {
    const st = ensureAttrState(row.attribute_id)
    if (row.option_id != null) {
      st.option_id = row.option_id
      if (!st.option_ids.includes(row.option_id)) st.option_ids.push(row.option_id)
    }
    if (row.value_text != null) st.value_text = row.value_text
    if (row.value_num != null) st.value_num = row.value_num
    if (row.value_bool != null) st.value_bool = row.value_bool
  }
  // média
  media.value = [...(p.media ?? [])].sort((a, b) => a.display_order - b.display_order)
  // ceny + akční ceny + dodavatelé (samostatné endpointy)
  const [pr, pp, vn] = await Promise.all([
    eshopApi.getPrices(id),
    eshopApi.getPromoPrices(id),
    eshopApi.getVendors(id),
  ])
  prices.value = withConfiguredCurrencyRows(pr.map(priceRowFrom))
  promos.value = pp.map(promoRowFrom)
  vendors.value = vn.map(vendorRowFrom)
  editorLoaded.value = true
}

/** Čeština a aktivní měny jsou připravené k psaní, prázdné se ale neukládají. */
function prepareEditorRows() {
  ensureCzechRow()
  selectedLocale.value = 'cs'
  prices.value = withConfiguredCurrencyRows(prices.value)
}

onMounted(async () => {
  if (auth.isClientRole) return
  try {
    await loadCodebooks()
    if (isEdit.value && itemId.value) {
      await loadProduct(itemId.value)
      await loadItemExtras(itemId.value)
    }
    prepareEditorRows()
    markSaved()
    await nextTick()
    showActiveTab()
  } catch (e: any) {
    error.value = mapError(e)
  }
})

// ── Sestavení payloadů ──────────────────────────────────────────────────
function buildProductPayload(): Omit<ProductUpdatePayload, 'row_version'> {
  const attrRows: ProductAttributeRow[] = []
  for (const a of attributes.value) {
    if (a.archived) continue
    const st = attrValues.value[a.id]
    if (!st) continue
    if (a.data_type === 'text') {
      if (st.value_text && st.value_text.trim() !== '') attrRows.push({ attribute_id: a.id, value_text: st.value_text })
    } else if (a.data_type === 'number') {
      if (st.value_num !== null && String(st.value_num).trim() !== '') attrRows.push({ attribute_id: a.id, value_num: String(st.value_num) })
    } else if (a.data_type === 'bool') {
      attrRows.push({ attribute_id: a.id, value_bool: !!st.value_bool })
    } else if (a.data_type === 'enum') {
      if (a.is_multivalue) {
        for (const oid of st.option_ids) attrRows.push({ attribute_id: a.id, option_id: oid })
      } else if (st.option_id != null) {
        attrRows.push({ attribute_id: a.id, option_id: st.option_id })
      }
    }
  }
  return {
    manufacturer_id: eshop.value.manufacturer_id,
    warranty_months: numOrNull(eshop.value.warranty_months),
    delivery_days: numOrNull(eshop.value.delivery_days),
    export_eshop: eshop.value.export_eshop,
    is_stocked: eshop.value.is_stocked,
    weight_g: numOrNull(eshop.value.weight_g),
    pricing_base: eshop.value.pricing_base,
    i18n: meaningfulI18nRows(),
    categories: selectedCategoryIds.value.map((id, idx) => ({
      category_id: id,
      is_primary: id === primaryCategoryId.value,
      display_order: (idx + 1) * 10,
    })),
    tag_ids: selectedTagIds.value,
    attributes: attrRows,
  }
}

function nullableCode(value: string | null | undefined, upper = false): string | null {
  const normalized = String(value ?? '').trim()
  return normalized === '' ? null : (upper ? normalized.toUpperCase() : normalized)
}

function buildItemPayload(): StockItemPayload {
  const supplementaryUnit = nullableCode(form.value.intrastat_supplementary_unit, true)
  return {
    ...form.value,
    intrastat_cn8_code: nullableCode(form.value.intrastat_cn8_code),
    intrastat_country_of_origin: nullableCode(form.value.intrastat_country_of_origin, true),
    intrastat_net_mass_kg: form.value.intrastat_net_mass_kg === '' ? null : form.value.intrastat_net_mass_kg,
    intrastat_supplementary_unit: supplementaryUnit,
    intrastat_supplementary_unit_coefficient: supplementaryUnit === 'ZZZ' || form.value.intrastat_supplementary_unit_coefficient === '' ? null : form.value.intrastat_supplementary_unit_coefficient,
  }
}

function snapshot(): string {
  return JSON.stringify({
    item: buildItemPayload(),
    product: buildProductPayload(),
    prices: meaningfulPriceRows().map(pricePayloadFrom),
    promos: promos.value.map(promoPayloadFrom),
    vendors: vendors.value.map(vendorPayloadFrom),
  })
}
function markSaved(snapshotValue = snapshot()) {
  savedSnapshot.value = snapshotValue
}
const isDirty = computed(() => savedSnapshot.value !== ''
  && (snapshot() !== savedSnapshot.value || packagingDirty.value || customerPricesDirty.value || priceLevelsDirty.value
    || !!postingDefaults.value?.dirty))
function confirmDiscard(): boolean {
  return !isDirty.value || window.confirm(t('stock.items.editor_ux.discard_changes'))
}
function onBeforeUnload(event: BeforeUnloadEvent) {
  if (!isDirty.value) return
  event.preventDefault()
  event.returnValue = ''
}
onBeforeRouteLeave(() => auth.isClientRole || confirmDiscard())
onMounted(() => {
  if (auth.isClientRole) return
  window.addEventListener('beforeunload', onBeforeUnload)
  window.addEventListener('resize', showActiveTab)
})
onBeforeUnmount(() => {
  window.removeEventListener('beforeunload', onBeforeUnload)
  window.removeEventListener('resize', showActiveTab)
})

/**
 * Řádek bez měny by backend odmítl 400 za celý PUT — tedy včetně už vyplněných
 * jazyků a kategorií. Chytáme to dřív a rovnou přepneme na tab, kde chyba je.
 */
function validateBeforeSubmit(): boolean {
  const unnamedTranslation = i18nRows.value.find(r => i18nRowHasContent(r) && String(r.name ?? '').trim() === '')
  if (unnamedTranslation) {
    error.value = t('eshop.languages.translation_name_required', { language: localeLabel(unnamedTranslation.locale) })
    selectedLocale.value = unnamedTranslation.locale
    tab.value = 'languages'
    return false
  }
  if (prices.value.some(p => !p.currency_code) || promos.value.some(p => !p.currency_code)) {
    error.value = t('eshop.prices.currency_required')
    tab.value = 'prices'
    return false
  }
  if (vendors.value.some(v => !v.client_id)) {
    error.value = t('eshop.vendors.client_required')
    tab.value = 'vendors'
    return false
  }
  const packagingError = packagingLoaded.value ? validatePackaging() : null
  if (packagingError) {
    error.value = packagingError
    tab.value = 'general'
    return false
  }
  const customerPriceError = customerPricesLoaded.value ? validateCustomerPrices() : null
  if (customerPriceError) {
    error.value = customerPriceError
    tab.value = 'prices'
    return false
  }
  const priceLevelError = priceLevelsLoaded.value ? validatePriceLevels() : null
  if (priceLevelError) {
    error.value = priceLevelError
    tab.value = 'prices'
    return false
  }
  return true
}

async function submit() {
  if (submitting.value || !canSaveEditor.value || (isEdit.value && !editorLoaded.value)) return
  error.value = ''
  errors.value = {}
  if (isEdit.value && !validateBeforeSubmit()) return
  submitting.value = true
  try {
    if (isEdit.value && itemId.value) {
      const submittedSnapshot = snapshot()
      const payload: ProductEditorPayload = {
        row_version: rowVersion.value,
        item: buildItemPayload(),
        product: buildProductPayload(),
        prices: meaningfulPriceRows().map(pricePayloadFrom),
        promo_prices: promos.value.map(promoPayloadFrom),
        vendors: vendors.value.map(vendorPayloadFrom),
      }
      const saved = await eshopApi.saveProductEditor(itemId.value, payload)
      rowVersion.value = saved.row_version
      if (await saveItemExtras(itemId.value)) toast.success(t('common.saved'))
      if (snapshot() === submittedSnapshot) {
        await loadProduct(itemId.value)
        markSaved()
      } else {
        markSaved(submittedSnapshot)
      }
    } else {
      const submittedSnapshot = snapshot()
      const created = await stockApi.createItem(buildItemPayload())
      // Po založení přejdi na editaci, kde jsou dostupné e-shopové taby.
      if (snapshot() === submittedSnapshot) {
        markSaved(submittedSnapshot)
        router.push(`/stock/items/${created.id}/edit`)
      } else {
        markSaved(submittedSnapshot)
      }
    }
  } catch (e: any) {
    const data = e?.response?.data?.error
    if (data?.code === 'sku_taken' || data?.code === 'stock.error.sku_taken') {
      error.value = t('stock.items.sku_taken')
    } else {
      error.value = mapError(e)
      if (data?.fields) errors.value = data.fields
    }
  } finally {
    submitting.value = false
  }
}

// ── Přílohy: akce ───────────────────────────────────────────────────────
function triggerUpload() { fileInput.value?.click() }
async function onFilesPicked(e: Event) {
  const input = e.target as HTMLInputElement
  if (!input.files || input.files.length === 0 || !itemId.value || !canWriteEshop.value) return
  uploading.value = true
  mediaSaveState.value = 'saving'
  try {
    await eshopApi.uploadMedia(itemId.value, Array.from(input.files))
    media.value = (await eshopApi.listMedia(itemId.value)).sort((a, b) => a.display_order - b.display_order)
    toast.success(t('common.saved'))
    mediaSaveState.value = 'saved'
  } catch (err: any) {
    mediaSaveState.value = 'error'
    toast.error(mapError(err))
  } finally {
    uploading.value = false
    input.value = ''
  }
}
async function moveMedia(idx: number, dir: -1 | 1) {
  const target = idx + dir
  if (target < 0 || target >= media.value.length || !itemId.value || !canWriteEshop.value) return
  const arr = [...media.value]
  const tmp = arr[idx]; arr[idx] = arr[target]; arr[target] = tmp
  media.value = arr
  mediaSaveState.value = 'saving'
  try {
    await eshopApi.reorderMedia(itemId.value, arr.map(m => m.id))
    mediaSaveState.value = 'saved'
  } catch (err: any) {
    mediaSaveState.value = 'error'
    toast.error(mapError(err))
  }
}
async function setPrimaryMedia(m: ProductMedia) {
  if (!itemId.value || !canWriteEshop.value) return
  mediaSaveState.value = 'saving'
  try {
    await eshopApi.updateMedia(m.id, { is_primary: true })
    media.value = (await eshopApi.listMedia(itemId.value)).sort((a, b) => a.display_order - b.display_order)
    mediaSaveState.value = 'saved'
  } catch (err: any) {
    mediaSaveState.value = 'error'
    toast.error(mapError(err))
  }
}
async function toggleMediaExport(m: ProductMedia) {
  if (!canWriteEshop.value) return
  mediaSaveState.value = 'saving'
  try {
    await eshopApi.updateMedia(m.id, { export_eshop: !m.export_eshop })
    m.export_eshop = !m.export_eshop
    mediaSaveState.value = 'saved'
  } catch (err: any) {
    mediaSaveState.value = 'error'
    toast.error(mapError(err))
  }
}
async function removeMedia(m: ProductMedia) {
  if (!canWriteEshop.value || !confirm(t('eshop.attachments.delete_confirm')) || !itemId.value) return
  mediaSaveState.value = 'saving'
  try {
    await eshopApi.deleteMedia(m.id)
    media.value = media.value.filter(x => x.id !== m.id)
    mediaSaveState.value = 'saved'
  } catch (err: any) {
    mediaSaveState.value = 'error'
    toast.error(mapError(err))
  }
}
function onImgError(e: Event) {
  (e.target as HTMLImageElement).style.display = 'none'
}
</script>

<template>
  <ClientItems v-if="auth.isClientRole" mode="editor" />
  <div v-else class="max-w-7xl">
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-semibold">{{ isEdit ? t('stock.items.edit_title') : t('stock.items.new_title') }}</h1>
      <RouterLink to="/stock/items" class="text-sm text-neutral-600 hover:text-neutral-900">{{ t('stock.item_detail.back_to_list') }}</RouterLink>
    </div>

    <form @submit.prevent="submit" autocomplete="off">
      <fieldset :disabled="submitting" class="min-w-0 m-0 border-0 p-0">
    <!-- Tab strip (e-shopové taby jen v editaci) -->
    <div v-if="isEdit" ref="tabList" role="tablist" :aria-label="t('stock.items.editor_ux.tabs_label')" class="border-b border-neutral-200 mb-4 flex gap-1 overflow-x-auto overflow-y-hidden">
      <button v-for="tt in tabs" :key="tt"
        type="button" role="tab" :id="`${pageId}-tab-${tt}`" :aria-controls="`${pageId}-panel-${tt}`" :tabindex="tab === tt ? 0 : -1" :aria-selected="tab === tt"
        @click="tab = tt" @keydown="onTabKey($event, tt)"
        class="cursor-pointer px-4 py-2 text-sm border-b-2 transition whitespace-nowrap"
        :class="tab === tt
          ? 'border-primary-600 text-primary-700 font-medium'
          : 'border-transparent text-neutral-600 hover:text-neutral-900'">
        {{ tt === 'general' ? t('eshop.item.tab_general')
          : tt === 'intrastat' ? t('stock.items.intrastat.tab')
          : tt === 'accounting' ? t('product_posting.tab')
          : tt === 'languages' ? t('eshop.item.tab_languages')
          : tt === 'master' ? t('eshop.item.tab_master')
          : tt === 'relations' ? t('eshop.item.tab_relations')
          : tt === 'categories' ? t('eshop.item.tab_categories')
          : tt === 'parameters' ? t('eshop.item.tab_parameters')
          : tt === 'prices' ? t('eshop.item.tab_prices')
          : tt === 'vendors' ? t('eshop.item.tab_vendors')
          : t('eshop.item.tab_attachments') }}
      </button>
    </div>
    <p v-else class="text-xs text-neutral-500 mb-4">{{ t('eshop.item.eshop_hint') }}</p>

      <!-- ═══════════ TAB: OBECNÉ ═══════════ -->
      <div v-show="tab === 'general'" role="tabpanel" :id="`${pageId}-panel-general`" :aria-labelledby="`${pageId}-tab-general`" class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
        <div class="p-5 space-y-4">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('stock.items.field_name') }} *</label>
            <input v-model="form.name" @input="onNameInput" required
              class="w-full h-10 px-3 border border-neutral-300 rounded-md focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
            <p v-if="errors.name" class="text-xs text-danger-500 mt-1">{{ errors.name[0] }}</p>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('stock.items.field_sku') }}</label>
              <input v-model="form.sku" @input="onSkuInput" maxlength="50"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
              <p class="text-xs text-neutral-500 mt-1">{{ t('stock.items.field_sku_hint') }}</p>
              <p v-if="errors.sku" class="text-xs text-danger-500 mt-1">{{ errors.sku[0] }}</p>
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('stock.items.field_type') }}</label>
              <select v-model="form.item_type" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none">
                <option value="material">{{ t('stock.item_type.material') }}</option>
                <option value="goods">{{ t('stock.item_type.goods') }}</option>
                <option value="product">{{ t('stock.item_type.product') }}</option>
                <option value="service">{{ t('stock.item_type.service') }}</option>
              </select>
              <p v-if="form.item_type === 'service'" class="text-xs text-neutral-500 mt-1" data-test="item-type-service-hint">{{ t('stock.items.field_type_service_hint') }}</p>
            </div>
          </div>

          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('stock.items.eet_kind.label') }}</label>
            <select v-model="form.eet_item_kind" data-test="stock-eet-kind" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none">
              <option value="standard">{{ t('stock.items.eet_kind.standard') }}</option>
              <option value="specific_voucher">{{ t('stock.items.eet_kind.specific_voucher') }}</option>
              <option value="stored_value">{{ t('stock.items.eet_kind.stored_value') }}</option>
            </select>
            <p class="text-xs text-neutral-500 mt-1">{{ form.eet_item_kind === 'stored_value' ? t('stock.items.eet_kind.hint_stored_value')
              : form.eet_item_kind === 'specific_voucher' ? t('stock.items.eet_kind.hint_specific_voucher') : t('stock.items.eet_kind.hint_standard') }}</p>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('stock.items.field_unit') }}</label>
              <input v-model="form.unit" maxlength="20" :list="`${pageId}-stock-item-units`"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
              <datalist :id="`${pageId}-stock-item-units`">
                <option v-for="u in units" :key="u.id" :value="u.code" />
              </datalist>
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('stock.items.field_ean') }}</label>
              <input v-model="form.ean" maxlength="20" class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
            </div>
            <div v-if="form.item_type !== 'service'">
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('stock.tracking.mode') }}</label>
              <select v-model="form.tracking_mode" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none">
                <option value="none">{{ t('stock.tracking.mode_none') }}</option>
                <option value="lot">{{ t('stock.tracking.mode_lot') }}</option>
                <option value="serial">{{ t('stock.tracking.mode_serial') }}</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('stock.items.field_vat_rate') }}</label>
              <select v-model="form.vat_rate_id" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none">
                <option :value="null">—</option>
                <option v-for="r in vatRates" :key="r.id" :value="r.id">{{ r.rate_percent }} %</option>
              </select>
            </div>
          </div>

          <!-- Balení (nadřazené jednotky) u základní jednotky, ukládá se společným Uložit -->
          <div v-if="isEdit" data-test="packaging-section" class="rounded-md border border-neutral-200 p-4 space-y-3">
            <div class="flex flex-wrap items-start justify-between gap-2">
              <div class="min-w-0">
                <h3 class="text-sm font-semibold text-neutral-800">{{ t('stock.packaging.title') }}</h3>
                <p class="text-xs text-neutral-500 mt-0.5">{{ t('stock.packaging.hint', { unit: form.unit || '—' }) }}</p>
              </div>
              <div class="flex flex-wrap items-center gap-2">
                <RouterLink to="/eshop?tab=packaging" :class="btnOutline('neutral')" class="whitespace-nowrap">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
                  {{ t('stock.packaging.manage_codebook') }}
                </RouterLink>
                <button type="button" @click="addPackagingRow" :disabled="!packagingLoaded" :class="btnOutline('primary')" class="whitespace-nowrap">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
                  {{ t('stock.packaging.add') }}
                </button>
              </div>
            </div>

            <p v-if="!packagingLoaded" class="text-xs text-warning-600">{{ t('stock.packaging.load_failed') }}</p>
            <p v-else-if="packagingRows.length === 0" class="text-xs text-neutral-500">
              {{ activePackagingCount === 0 ? t('stock.packaging.no_codebook') : t('stock.packaging.empty') }}
            </p>

            <div v-for="(row, idx) in packagingRows" :key="`packaging-${idx}`" data-test="packaging-row"
              class="grid grid-cols-[minmax(0,1fr)_auto] sm:grid-cols-[minmax(9rem,1fr)_auto_7rem_auto_minmax(9rem,1fr)_auto] items-center gap-2">
              <select v-model="row.unit_code" :disabled="row.in_use" :aria-label="t('stock.packaging.field_code')"
                class="col-span-2 sm:col-span-1 h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface disabled:bg-neutral-100 disabled:text-neutral-500">
                <option value="">{{ t('stock.packaging.select_code') }}</option>
                <option v-for="o in packagingOptions(row.unit_code)" :key="o.code" :value="o.code">{{ o.code }} · {{ o.name }}{{ o.known ? '' : ' ⚠' }}</option>
              </select>
              <div class="col-span-2 sm:contents flex items-center gap-2">
                <span class="text-sm text-neutral-500 whitespace-nowrap">{{ t('stock.packaging.factor_prefix', { code: row.unit_code || '?' }) }}</span>
                <input v-model="row.factor" :disabled="row.in_use" type="text" inputmode="decimal" :aria-label="t('stock.packaging.field_factor')"
                  class="w-28 sm:w-full h-9 px-2 border border-neutral-300 rounded-md text-sm font-mono text-right disabled:bg-neutral-100 disabled:text-neutral-500" />
                <span class="text-sm text-neutral-500 whitespace-nowrap">{{ form.unit }}</span>
              </div>
              <input v-model="row.ean" maxlength="20" :placeholder="t('stock.packaging.field_ean')" :aria-label="t('stock.packaging.field_ean')"
                class="h-9 px-2 border border-neutral-300 rounded-md text-sm font-mono" />
              <button type="button" @click="removePackagingRow(idx)" :disabled="row.in_use"
                :title="row.in_use ? t('stock.packaging.in_use_hint') : t('common.delete')"
                class="cursor-pointer text-neutral-400 hover:text-danger-500 px-1 disabled:cursor-not-allowed disabled:opacity-40">
                <svg class="w-4 h-4 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
              </button>
            </div>
            <p v-if="packagingRows.some(r => r.in_use)" class="text-xs text-neutral-500">{{ t('stock.packaging.in_use_hint') }}</p>

            <div v-if="packagingRows.some(r => r.unit_code)" class="max-w-sm">
              <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('stock.packaging.default_sale_unit') }}</label>
              <select v-model="defaultSaleUnit" data-test="packaging-default-unit" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
                <option :value="null">{{ t('stock.packaging.default_base', { unit: form.unit || '—' }) }}</option>
                <option v-for="r in packagingRows.filter(r => r.unit_code)" :key="r.unit_code" :value="r.unit_code">{{ r.unit_code }}</option>
              </select>
              <p class="text-xs text-neutral-500 mt-1">{{ t('stock.packaging.default_sale_unit_hint') }}</p>
            </div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('stock.items.field_sale_price') }}</label>
              <input v-model="form.sale_price_without_vat" type="number" step="any" min="0"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono text-right focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
            </div>
            <div v-if="form.item_type !== 'service'">
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('stock.items.field_min_qty') }}</label>
              <input v-model="form.min_qty" type="number" step="0.001" min="0"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono text-right focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
              <p class="text-xs text-neutral-500 mt-1">{{ t('stock.items.field_min_qty_hint') }}</p>
            </div>
          </div>

          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('stock.items.field_note') }}</label>
            <textarea v-model="form.note" rows="2" class="w-full px-3 py-2 border border-neutral-300 rounded-md focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none"></textarea>
          </div>

          <label class="inline-flex items-center gap-2 text-sm cursor-pointer">
            <input v-model="form.is_active" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            {{ t('stock.items.field_active') }}
          </label>

          <!-- E-shop parametry -->
          <div class="mt-2 pt-4 border-t border-neutral-200">
            <h3 class="text-sm font-semibold text-neutral-700 mb-3">{{ t('eshop.item.section_eshop') }}</h3>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('eshop.item.field_manufacturer') }}</label>
                <select v-model="eshop.manufacturer_id" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none">
                  <option :value="null">{{ t('eshop.item.none') }}</option>
                  <option v-for="m in manufacturers" :key="m.id" :value="m.id">{{ m.name }}</option>
                </select>
              </div>
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('eshop.item.field_pricing_base') }}</label>
                <select v-model="eshop.pricing_base" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none">
                  <option value="weighted_avg">{{ t('eshop.item.pricing_weighted_avg') }}</option>
                  <option value="last_purchase">{{ t('eshop.item.pricing_last_purchase') }}</option>
                  <option value="manual">{{ t('eshop.item.pricing_manual') }}</option>
                </select>
              </div>
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('eshop.item.field_weight_g') }}</label>
                <input v-model="eshop.weight_g" type="number" min="0" step="1"
                  class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono text-right focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
              </div>
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('eshop.item.field_warranty_months') }}</label>
                <input v-model="eshop.warranty_months" type="number" min="0" step="1"
                  class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono text-right focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
              </div>
              <div>
                <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('eshop.item.field_delivery_days') }}</label>
                <input v-model="eshop.delivery_days" type="number" min="0" step="1"
                  class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono text-right focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
              </div>
            </div>
            <div class="flex flex-wrap items-center gap-5 mt-3">
              <label v-if="form.item_type !== 'service'" class="inline-flex items-center gap-2 text-sm cursor-pointer">
                <input v-model="eshop.is_stocked" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
                {{ t('eshop.item.field_is_stocked') }}
              </label>
              <label class="inline-flex items-center gap-2 text-sm cursor-pointer">
                <input v-model="eshop.export_eshop" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
                {{ t('eshop.item.field_export') }}
              </label>
            </div>
            <div v-if="isEdit && itemId && !eshop.is_stocked" class="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-primary-500/20 bg-primary-50/50 px-3 py-2 text-sm dark:bg-primary-950/20">
              <p class="text-neutral-700">{{ t('eshop.sets.editor_hint') }}</p>
              <RouterLink :to="`/eshop/sets/${itemId}`" :class="btnOutline('primary')">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.tag" /></svg>
                {{ t('eshop.sets.open') }}
              </RouterLink>
            </div>
          </div>
        </div>
      </div>

      <!-- ═══════════ TAB: INTRASTAT ═══════════ -->
      <div v-if="isEdit" v-show="tab === 'intrastat'" role="tabpanel" :id="`${pageId}-panel-intrastat`" :aria-labelledby="`${pageId}-tab-intrastat`" class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
        <div class="p-5 space-y-5">
          <div>
            <h2 class="text-base font-semibold text-neutral-900">{{ t('stock.items.intrastat.title') }}</h2>
            <p class="mt-1 text-sm text-neutral-500">{{ t('stock.items.intrastat.hint') }}</p>
          </div>
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('stock.items.intrastat.cn8_code') }}</label>
              <input v-model.trim="form.intrastat_cn8_code" data-test="intrastat-cn8" inputmode="numeric" maxlength="8" pattern="[0-9]{8}"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
              <p class="mt-1 text-xs text-neutral-500">{{ t('stock.items.intrastat.cn8_hint') }}</p>
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('stock.items.intrastat.country_of_origin') }}</label>
              <select v-model="form.intrastat_country_of_origin" data-test="intrastat-country"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none">
                <option :value="null">{{ t('stock.items.intrastat.country_none') }}</option>
                <option value="QU">QU - {{ t('stock.items.intrastat.country_unknown') }}</option>
                <option value="QV">QV - {{ t('stock.items.intrastat.country_eu_unknown') }}</option>
                <option v-for="country in countries" :key="country.iso2" :value="country.iso2">{{ country.iso2 }} - {{ locale === 'en' ? country.name_en : country.name_cs }}</option>
              </select>
              <p class="mt-1 text-xs text-neutral-500">{{ t('stock.items.intrastat.country_hint') }}</p>
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('stock.items.intrastat.net_mass_kg') }}</label>
              <input v-model="form.intrastat_net_mass_kg" data-test="intrastat-net-mass" type="number" min="0" step="0.001" inputmode="decimal"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono text-right focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
              <p class="mt-1 text-xs text-neutral-500">{{ t('stock.items.intrastat.net_mass_hint') }}</p>
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('stock.items.intrastat.supplementary_unit') }}</label>
              <select v-model="form.intrastat_supplementary_unit" data-test="intrastat-unit"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface font-mono focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none">
                <option :value="null">{{ t('stock.items.intrastat.supplementary_unit_none') }}</option>
                <option v-for="code in ['CTM', 'NEL', 'CCT', 'GRM', 'GFI', 'KHO', 'KPO', 'KPH', 'KMA', 'KNI', 'KSH', 'KNE', 'KPP', 'KSD', 'KUR', 'MWH', 'LTR', 'LPA', 'MTR', 'MTK', 'MTQ', 'MQM', 'NPR', 'PCE', 'CEN', 'MIL', 'TJO', 'ZZZ']" :key="code" :value="code">{{ code }}</option>
              </select>
              <p class="mt-1 text-xs text-neutral-500">{{ t('stock.items.intrastat.supplementary_unit_hint') }}</p>
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('stock.items.intrastat.coefficient') }}</label>
              <input v-model="form.intrastat_supplementary_unit_coefficient" data-test="intrastat-coefficient" type="number" min="0" step="0.000001" inputmode="decimal"
                :disabled="nullableCode(form.intrastat_supplementary_unit, true) === 'ZZZ'"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono text-right disabled:bg-neutral-100 disabled:text-neutral-500 focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
              <p class="mt-1 text-xs text-neutral-500">{{ t('stock.items.intrastat.coefficient_hint', { unit: form.unit }) }}</p>
            </div>
          </div>
        </div>
      </div>

      <!-- ═══════════ TAB: ÚČTOVÁNÍ ═══════════ -->
      <div v-if="isEdit && itemId && accountingTabVisible" v-show="tab === 'accounting'" role="tabpanel" :id="`${pageId}-panel-accounting`" :aria-labelledby="`${pageId}-tab-accounting`" class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
        <ProductPostingDefaults ref="postingDefaults" :product-id="itemId" />
      </div>

      <!-- ═══════════ TAB: JAZYKY ═══════════ -->
      <div v-if="isEdit" v-show="tab === 'languages'" role="tabpanel" :id="`${pageId}-panel-languages`" :aria-labelledby="`${pageId}-tab-languages`" class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
        <div class="p-5 space-y-4">
          <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
              <label :for="`${pageId}-new-locale`" class="block text-xs font-medium text-neutral-500 mb-1">{{ t('eshop.languages.select_locale') }}</label>
              <select :id="`${pageId}-new-locale`" v-model="newLocale" @change="addLocale" :disabled="freeLocales.length === 0"
                class="h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface disabled:bg-neutral-100 disabled:text-neutral-500">
                <option value="">{{ t('eshop.languages.select_locale') }}</option>
                <option v-for="l in freeLocales" :key="l.code" :value="l.code">{{ l.name }} ({{ l.code.toUpperCase() }})</option>
              </select>
            </div>
            <RouterLink to="/eshop?tab=locales" :class="btnOutline('neutral')" class="whitespace-nowrap">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
              {{ t('eshop.languages.manage_locales') }}
            </RouterLink>
          </div>

          <div v-if="i18nRows.length" role="tablist" class="flex flex-wrap gap-2 border-b border-neutral-200 pb-3">
            <button v-for="row in orderedI18nRows" :key="row.locale" type="button" role="tab"
              :aria-selected="selectedLocale === row.locale" @click="selectedLocale = row.locale"
              class="cursor-pointer inline-flex items-center gap-2 rounded-md border px-3 py-1.5 text-sm transition whitespace-nowrap"
              :class="selectedLocale === row.locale
                ? 'border-primary-500 bg-primary-50 text-primary-700 font-medium'
                : 'border-neutral-300 text-neutral-600 hover:bg-neutral-50'">
              {{ localeLabel(row.locale) }}
              <span class="font-mono text-xs uppercase opacity-70">{{ row.locale }}</span>
            </button>
          </div>

          <EmptyState v-if="i18nRows.length === 0" dense accent="neutral" icon="doc"
            :title="t('eshop.languages.empty')" />

          <div v-for="row in i18nRows.filter(r => r.locale === selectedLocale)" :key="row.locale" role="tabpanel"
            :data-locale="row.locale" class="border border-neutral-200 rounded-md p-4 space-y-3">
            <div class="flex items-center justify-between">
              <span class="text-sm font-semibold">{{ localeLabel(row.locale) }} <span class="text-neutral-400 font-mono font-normal uppercase">{{ row.locale }}</span></span>
              <button type="button" @click="removeLocale(row.locale)" :title="t('eshop.languages.remove_locale')" class="cursor-pointer text-neutral-400 hover:text-danger-500 px-1">
                <svg class="w-4 h-4 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
              </button>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('eshop.languages.field_name') }}</label>
                <input v-model="row.name" type="text" maxlength="255" :required="i18nRowHasContent(row)"
                  class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
              </div>
              <div>
                <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('eshop.languages.field_seo_slug') }}</label>
                <input v-model="row.seo_slug" type="text" maxlength="255" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm font-mono" />
              </div>
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('eshop.languages.field_short_desc') }}</label>
              <input v-model="row.short_desc" type="text" maxlength="500" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('eshop.languages.field_description') }}</label>
              <MarkdownEditor v-model="row.description" :rows="6" />
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('eshop.languages.field_seo_title') }}</label>
                <input v-model="row.seo_title" type="text" maxlength="255" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
              </div>
              <div>
                <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('eshop.languages.field_seo_description') }}</label>
                <input v-model="row.seo_description" type="text" maxlength="500" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
              </div>
            </div>
          </div>
        </div>
      </div>

      <ProductVariantInheritance v-if="isEdit && itemId && variantContext" v-show="tab === 'master'"
        role="tabpanel" :id="`${pageId}-panel-master`" :aria-labelledby="`${pageId}-tab-master`"
        :item-id="itemId" :row-version="rowVersion" :variant="variantContext" :can-write="canSaveEditor"
        @updated="(value, version) => { variantContext = value; rowVersion = version }" />
      <section v-else-if="isEdit && tab === 'master'" role="tabpanel" :id="`${pageId}-panel-master`" :aria-labelledby="`${pageId}-tab-master`" class="rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm">
        <h2 class="text-lg font-semibold">{{ t('eshop.inheritance.title') }}</h2>
        <p class="mt-1 text-sm text-neutral-500">{{ t('eshop.inheritance.not_variant') }}</p>
        <RouterLink v-if="canSaveEditor" to="/eshop?tab=masters" :class="btnOutline('primary')" class="mt-4"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.link" /></svg>{{ t('eshop.inheritance.choose_master') }}</RouterLink>
      </section>

      <ProductRelationsPanel v-if="isEdit && itemId" v-show="tab === 'relations'" role="tabpanel"
        :id="`${pageId}-panel-relations`" :aria-labelledby="`${pageId}-tab-relations`"
        :item-id="itemId" :can-write="canSaveEditor"
        @updated="(version, previous) => { if (rowVersion === previous) rowVersion = version }" />

      <!-- ═══════════ TAB: KATEGORIE & ŠTÍTKY ═══════════ -->
      <div v-if="isEdit" v-show="tab === 'categories'" role="tabpanel" :id="`${pageId}-panel-categories`" :aria-labelledby="`${pageId}-tab-categories`" class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
        <div class="p-5 grid grid-cols-1 md:grid-cols-2 gap-6">
          <div>
            <h3 class="text-sm font-semibold text-neutral-700 mb-2">{{ t('eshop.product_categories.categories_title') }}</h3>
            <p v-if="categories.length === 0" class="text-sm text-neutral-500">{{ t('eshop.product_categories.empty_categories') }}</p>
            <div v-else class="border border-neutral-200 rounded-md divide-y divide-neutral-100 max-h-[24rem] overflow-y-auto scrollbar-slim">
              <div v-for="c in categories" :key="c.id" class="flex items-center gap-2 px-2 py-1.5 text-sm hover:bg-neutral-50">
                <input type="checkbox" :checked="selectedCategoryIds.includes(c.id)" @change="toggleCategory(c.id)" class="rounded border-neutral-300 text-primary-600" />
                <span :style="{ marginLeft: `${c.depth * 1}rem` }" class="flex-1 min-w-0 truncate">{{ c.name }}</span>
                <label v-if="selectedCategoryIds.includes(c.id)" class="inline-flex items-center gap-1 text-xs text-neutral-500 cursor-pointer shrink-0">
                  <input type="radio" name="primaryCat" :value="c.id" :checked="primaryCategoryId === c.id" @change="primaryCategoryId = c.id" class="text-primary-600" />
                  {{ t('eshop.product_categories.primary') }}
                </label>
              </div>
            </div>
          </div>
          <div>
            <h3 class="text-sm font-semibold text-neutral-700 mb-2">{{ t('eshop.product_categories.tags_title') }}</h3>
            <p v-if="tags.length === 0" class="text-sm text-neutral-500">{{ t('eshop.product_categories.empty_tags') }}</p>
            <div v-else class="flex flex-wrap gap-2">
              <button v-for="tg in tags" :key="tg.id" type="button" @click="toggleTag(tg.id)"
                class="cursor-pointer inline-flex items-center gap-1.5 px-2.5 h-8 rounded-full border text-sm transition"
                :class="selectedTagIds.includes(tg.id) ? 'border-primary-500 bg-primary-50 text-primary-700' : 'border-neutral-300 text-neutral-600 hover:bg-neutral-50'">
                <span v-if="tg.color" class="w-3 h-3 rounded-full border border-neutral-300/60" :style="{ backgroundColor: tg.color }"></span>
                {{ tg.name }}
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- ═══════════ TAB: PARAMETRY ═══════════ -->
      <div v-if="isEdit" v-show="tab === 'parameters'" role="tabpanel" :id="`${pageId}-panel-parameters`" :aria-labelledby="`${pageId}-tab-parameters`" class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
        <div class="p-5 space-y-4">
          <EmptyState v-if="attributes.filter(a => !a.archived).length === 0" dense accent="neutral" icon="tag"
            :title="t('eshop.parameters.empty')" />
          <div v-for="a in attributes.filter(a => !a.archived)" :key="a.id" class="grid grid-cols-1 sm:grid-cols-3 gap-3 items-start">
            <label class="text-sm font-medium text-neutral-700 pt-2">
              {{ a.name }}<span v-if="a.unit" class="text-neutral-400 font-normal"> ({{ a.unit }})</span>
            </label>
            <div class="sm:col-span-2">
              <!-- text (s volbami → našeptávač přes datalist, ale volný text povolen) -->
              <input v-if="a.data_type === 'text'" v-model="ensureAttrState(a.id).value_text" type="text"
                :list="(attrOptions[a.id] || []).length ? `${pageId}-attr-dl-${a.id}` : undefined"
                class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
              <!-- number -->
              <input v-else-if="a.data_type === 'number'" v-model="ensureAttrState(a.id).value_num" type="number" step="any"
                class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm font-mono text-right" />
              <!-- bool -->
              <label v-else-if="a.data_type === 'bool'" class="inline-flex items-center gap-2 text-sm cursor-pointer pt-1.5">
                <input v-model="ensureAttrState(a.id).value_bool" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
                {{ ensureAttrState(a.id).value_bool ? t('common.yes') : t('common.no') }}
              </label>
              <!-- enum single (našeptávaný výběr) -->
              <SearchableSelect v-else-if="a.data_type === 'enum' && !a.is_multivalue"
                :model-value="ensureAttrState(a.id).option_id"
                @update:model-value="ensureAttrState(a.id).option_id = $event"
                :options="(attrOptions[a.id] || []).map(o => ({ value: o.id, label: o.label }))"
                :placeholder="t('eshop.item.none')" :no-results-label="t('common.no_items')" />
              <!-- enum multivalue -->
              <div v-else class="flex flex-wrap gap-2">
                <button v-for="o in (attrOptions[a.id] || [])" :key="o.id" type="button" @click="toggleAttrOption(a.id, o.id)"
                  class="cursor-pointer px-2.5 h-8 rounded-full border text-sm transition"
                  :class="ensureAttrState(a.id).option_ids.includes(o.id) ? 'border-primary-500 bg-primary-50 text-primary-700' : 'border-neutral-300 text-neutral-600 hover:bg-neutral-50'">
                  {{ o.label }}
                </button>
              </div>
              <!-- Našeptávač hodnot pro textový atribut s definovanými volbami -->
              <datalist v-if="a.data_type === 'text' && (attrOptions[a.id] || []).length" :id="`${pageId}-attr-dl-${a.id}`">
                <option v-for="o in attrOptions[a.id]" :key="o.id" :value="o.label" />
              </datalist>
            </div>
          </div>
        </div>
      </div>

      <!-- ═══════════ TAB: CENY ═══════════ -->
      <div v-if="isEdit" v-show="tab === 'prices'" role="tabpanel" :id="`${pageId}-panel-prices`" :aria-labelledby="`${pageId}-tab-prices`" class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
        <div class="p-5 space-y-4">
          <div class="flex flex-wrap items-center gap-2">
            <button type="button" @click="recomputePrices" :disabled="recomputing || meaningfulPriceRows().length === 0 || !editorLoaded || !canWriteEshop" :class="btnOutline('neutral')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>
              {{ recomputing ? t('eshop.prices.recomputing') : t('eshop.prices.recompute') }}
            </button>
          </div>

          <EmptyState v-if="prices.length === 0" dense accent="neutral" icon="coin"
            :title="t('eshop.prices.empty')" />

          <div v-else class="overflow-x-auto scrollbar-slim">
            <table class="w-full text-sm border-collapse">
              <thead>
                <tr class="text-left text-xs text-neutral-500 border-b border-neutral-200">
                  <th class="py-2 pr-3 font-medium">{{ t('eshop.prices.col_currency') }}</th>
                  <th class="py-2 pr-3 font-medium">{{ t('eshop.prices.col_mode') }}</th>
                  <th class="py-2 pr-3 font-medium">{{ t('eshop.prices.col_value') }}</th>
                  <th class="py-2 pr-3 font-medium">{{ t('eshop.prices.col_rounding') }}</th>
                  <th class="py-2 pr-3 font-medium text-center">{{ t('eshop.prices.col_manual') }}</th>
                  <th class="py-2 pr-3 font-medium text-right">{{ t('eshop.prices.col_computed') }}</th>
                  <th class="py-2 pr-1 font-medium text-right">{{ t('eshop.prices.col_rate') }}</th>
                  <th class="py-2"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(p, idx) in prices" :key="p.id ?? `new-${idx}`" :data-currency="p.currency_code"
                  class="border-b border-neutral-100 align-top">
                  <td class="py-2 pr-3">
                    <div class="h-9 flex items-center gap-2 whitespace-nowrap">
                      <span class="font-mono font-semibold text-neutral-800">{{ p.currency_code }}</span>
                      <span v-if="!configuredCurrencyCodes.has(p.currency_code.toUpperCase())"
                        class="rounded-full bg-neutral-100 px-2 py-0.5 text-[11px] text-neutral-500">
                        {{ t('eshop.prices.inactive_currency') }}
                      </span>
                    </div>
                  </td>
                  <td class="py-2 pr-3">
                    <select v-model="p.price_mode" :disabled="p.use_pricing_rules" class="h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
                      <option value="markup">{{ t('eshop.prices.mode_markup') }}</option>
                      <option value="target_margin">{{ t('eshop.prices.mode_target_margin') }}</option>
                      <option value="fixed">{{ t('eshop.prices.mode_fixed') }}</option>
                    </select>
                    <label class="mt-2 flex items-center gap-2 text-xs text-neutral-600">
                      <input v-model="p.use_pricing_rules" type="checkbox" class="rounded border-neutral-300 text-primary-600">
                      {{ t('eshop.prices.use_pricing_rules') }}
                    </label>
                  </td>
                  <td class="py-2 pr-3">
                    <span v-if="p.use_pricing_rules && !p.is_manual_override" class="text-xs text-neutral-500">{{ t('eshop.prices.from_profile') }}</span>
                    <input v-else-if="p.price_mode !== 'fixed' && !p.is_manual_override" v-model="p.markup_pct" type="number" step="0.001" :min="p.price_mode === 'target_margin' ? 0 : -100" :max="p.price_mode === 'target_margin' ? 99.999 : 9999.999"
                      :placeholder="t('eshop.prices.markup_ph')"
                      class="w-28 h-9 px-2 border border-neutral-300 rounded-md text-sm font-mono text-right" />
                    <input v-else v-model="p.fixed_price" type="text" inputmode="decimal"
                      :placeholder="t('eshop.prices.fixed_ph')"
                      class="w-28 h-9 px-2 border border-neutral-300 rounded-md text-sm font-mono text-right" />
                  </td>
                  <td class="py-2 pr-3">
                    <select v-model="p.rounding" :disabled="p.use_pricing_rules && !p.is_manual_override" class="h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
                      <option v-for="rm in ROUNDING_MODES" :key="rm" :value="rm">{{ t('eshop.prices.rounding_' + (rm === '9_ending' ? '9_ending' : rm.replace('.', '_'))) }}</option>
                    </select>
                  </td>
                  <td class="py-2 pr-3 text-center">
                    <input v-model="p.is_manual_override" type="checkbox" class="rounded border-neutral-300 text-primary-600 mt-2" />
                  </td>
                  <td class="py-2 pr-3 text-right font-mono text-neutral-700">{{ p.computed_price ?? '—' }}</td>
                  <td class="py-2 pr-1 text-right font-mono text-neutral-500 text-xs">{{ p.computed_rate ?? '—' }}</td>
                  <td class="py-2 text-right">
                    <button v-if="p.id !== null" type="button" @click="removePriceRow(idx)" :title="t('common.delete')" class="cursor-pointer text-neutral-400 hover:text-danger-500 px-1">
                      <svg class="w-4 h-4 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <p class="text-xs text-neutral-500">{{ t('eshop.prices.hint') }}</p>

          <!-- ─────────── Akční (promoční) ceny ─────────── -->
          <div class="pt-5 mt-1 border-t border-neutral-200 space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <div>
                <h3 class="text-sm font-semibold text-neutral-800">{{ t('eshop.promo.title') }}</h3>
                <p class="text-xs text-neutral-500 mt-0.5">{{ t('eshop.promo.subtitle') }}</p>
              </div>
              <button type="button" @click="addPromoRow" :class="btnOutline('primary')" class="whitespace-nowrap">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
                {{ t('eshop.promo.add') }}
              </button>
            </div>

            <EmptyState v-if="promos.length === 0" dense accent="neutral" icon="tag"
              :title="t('eshop.promo.empty')" :message="t('eshop.promo.empty_hint')" />

            <!--
              Karty místo tabulky: deset sloupců se do šířky obsahu nevešlo a
              řádek se posouval vodorovným scrollbarem, takže „Platí do" i stav
              akce byly mimo obraz. Stejný vzor jako jazykové mutace výše.
            -->
            <div v-else class="space-y-3">
              <div v-for="(p, idx) in promos" :key="p.id ?? `new-promo-${idx}`"
                class="border border-neutral-200 rounded-md p-4 space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                  <div class="flex items-center gap-2">
                    <span v-if="p.state" class="inline-block px-2 py-0.5 rounded-full border text-xs whitespace-nowrap"
                      :class="promoStateClass(p.state)">{{ t('eshop.promo.state_' + p.state) }}</span>
                    <span v-else class="text-xs text-neutral-400">{{ t('eshop.promo.state_unsaved') }}</span>
                    <label class="flex items-center gap-1.5 text-xs text-neutral-600">
                      <input v-model="p.is_active" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
                      {{ t('eshop.promo.col_active') }}
                    </label>
                  </div>
                  <button type="button" @click="removePromoRow(idx)" :title="t('common.delete')" class="cursor-pointer text-neutral-400 hover:text-danger-500 px-1">
                    <svg class="w-4 h-4 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                  </button>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                  <div class="sm:col-span-2 lg:col-span-1">
                    <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('eshop.promo.col_label') }}</label>
                    <input v-model="p.label" type="text" maxlength="60" :placeholder="t('eshop.promo.label_ph')"
                      class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
                  </div>
                  <div>
                    <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('eshop.promo.col_currency') }}</label>
                    <select v-model="p.currency_code" required
                      class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm font-mono bg-surface">
                      <option value="">{{ t('eshop.prices.select_currency') }}</option>
                      <option v-for="c in currencyOptions(p.currency_code)" :key="c.code" :value="c.code">{{ c.code }}{{ c.known ? '' : ' ⚠' }}</option>
                    </select>
                  </div>
                  <div>
                    <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('eshop.promo.col_price') }}</label>
                    <input v-model="p.promo_price" type="text" inputmode="decimal" :placeholder="t('eshop.prices.fixed_ph')"
                      class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm font-mono text-right" />
                  </div>
                  <div>
                    <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('eshop.promo.col_from') }}</label>
                    <DateInput v-model="p.valid_from" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
                  </div>
                  <div>
                    <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('eshop.promo.col_to') }}</label>
                    <DateInput v-model="p.valid_to" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
                  </div>
                  <div>
                    <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('eshop.promo.col_qty_mode') }}</label>
                    <div class="flex flex-wrap items-center gap-2">
                      <select v-model="p.qty_mode" class="h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
                        <option v-for="m in QTY_MODES" :key="m" :value="m">{{ t('eshop.promo.qty_mode_' + m) }}</option>
                      </select>
                      <input v-if="p.qty_mode === 'limited'" v-model="p.qty_limit" type="number" step="1" min="1"
                        :placeholder="t('eshop.promo.qty_limit_ph')"
                        class="w-24 h-9 px-2 border border-neutral-300 rounded-md text-sm font-mono text-right" />
                      <span class="text-xs text-neutral-500 whitespace-nowrap">
                        {{ t('eshop.promo.col_remaining') }}:
                        <span class="font-mono text-neutral-700">{{ p.qty_mode === 'unlimited' ? '∞' : (p.qty_remaining ?? '—') }}</span>
                      </span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            <p class="text-xs text-neutral-500">{{ t('eshop.promo.hint') }}</p>
          </div>

          <!-- ─────────── Individuální ceny zákazníků ─────────── -->
          <div data-test="customer-prices" class="pt-5 mt-1 border-t border-neutral-200 space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <div>
                <h3 class="text-sm font-semibold text-neutral-800">{{ t('stock.customer_prices.title') }}</h3>
                <p class="text-xs text-neutral-500 mt-0.5">{{ t('stock.customer_prices.subtitle') }}</p>
              </div>
              <button type="button" @click="addCustomerPriceRow" :disabled="!customerPricesLoaded" :class="btnOutline('primary')" class="whitespace-nowrap">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
                {{ t('stock.customer_prices.add') }}
              </button>
            </div>

            <p v-if="!customerPricesLoaded" class="text-xs text-warning-600">{{ t('stock.customer_prices.load_failed') }}</p>
            <EmptyState v-else-if="customerPrices.length === 0" dense accent="neutral" icon="user"
              :title="t('stock.customer_prices.empty')" :message="t('stock.customer_prices.empty_hint')" />

            <div v-else class="space-y-3">
              <div v-for="(c, idx) in customerPrices" :key="c.id ?? `new-customer-price-${idx}`" data-test="customer-price-row"
                class="border border-neutral-200 rounded-md p-4 space-y-3">
                <div class="flex items-start justify-between gap-3">
                  <div class="flex-1 min-w-0">
                    <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('stock.customer_prices.field_client') }} *</label>
                    <ClientSearchSelect
                      :model-value="c.client_id"
                      :selected-label="c.client_name"
                      :placeholder="t('stock.customer_prices.select_client')"
                      @update:model-value="(v: number | null) => { c.client_id = v }"
                      @selected="(client) => { c.client_name = client?.company_name ?? null }" />
                  </div>
                  <button type="button" @click="removeCustomerPriceRow(idx)" :title="t('common.delete')" class="cursor-pointer text-neutral-400 hover:text-danger-500 px-1 pt-6">
                    <svg class="w-4 h-4 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                  </button>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                  <div>
                    <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('stock.customer_prices.field_currency') }}</label>
                    <select v-model="c.currency_code" required class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm font-mono bg-surface">
                      <option value="">{{ t('eshop.prices.select_currency') }}</option>
                      <option v-for="cur in currencyOptions(c.currency_code)" :key="cur.code" :value="cur.code">{{ cur.code }}{{ cur.known ? '' : ' ⚠' }}</option>
                    </select>
                  </div>
                  <div>
                    <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('stock.customer_prices.field_type') }}</label>
                    <select v-model="c.price_type" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
                      <option v-for="pt in CUSTOMER_PRICE_TYPES" :key="pt" :value="pt">{{ t('stock.customer_prices.type_' + pt) }}</option>
                    </select>
                  </div>
                  <div>
                    <template v-if="c.price_type === 'fixed'">
                      <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('stock.customer_prices.field_fixed_price', { unit: form.unit || '—' }) }}</label>
                      <input v-model="c.fixed_price" type="text" inputmode="decimal" :placeholder="t('eshop.prices.fixed_ph')"
                        class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm font-mono text-right" />
                    </template>
                    <template v-else>
                      <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('stock.customer_prices.field_discount_pct') }}</label>
                      <input v-model="c.discount_pct" type="text" inputmode="decimal"
                        class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm font-mono text-right" />
                    </template>
                  </div>
                  <div>
                    <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('stock.customer_prices.field_from') }}</label>
                    <DateInput v-model="c.valid_from" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
                  </div>
                  <div>
                    <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('stock.customer_prices.field_to') }}</label>
                    <DateInput v-model="c.valid_to" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
                  </div>
                  <div>
                    <span class="block text-xs font-medium text-neutral-500 mb-1">{{ t('stock.customer_prices.preview', { unit: form.unit || '—' }) }}</span>
                    <div class="h-9 flex items-center justify-end gap-2 rounded-md bg-neutral-50 px-2 font-mono text-sm text-neutral-800">
                      <span v-if="!c.id" class="mr-auto font-sans text-xs text-neutral-400">{{ t('stock.customer_prices.unsaved') }}</span>
                      {{ customerPricePreview(c) === null ? '—' : formatUnitPrice(customerPricePreview(c), c.currency_code || 'CZK') }}
                    </div>
                  </div>
                </div>
                <div>
                  <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('stock.customer_prices.field_note') }}</label>
                  <input v-model="c.note" type="text" maxlength="255" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
                </div>
              </div>
            </div>
            <p class="text-xs text-neutral-500">{{ t('stock.customer_prices.hint') }}</p>
          </div>

          <!-- ─────────── Cenové hladiny (ukládá společné Uložit) ─────────── -->
          <div data-test="price-levels" class="pt-5 mt-1 border-t border-neutral-200 space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <div>
                <h3 class="text-sm font-semibold text-neutral-800">{{ t('stock.price_levels.title') }}</h3>
                <p class="text-xs text-neutral-500 mt-0.5">{{ t('stock.price_levels.subtitle') }}</p>
              </div>
              <RouterLink to="/eshop?tab=price-levels" :class="btnOutline('neutral')" class="whitespace-nowrap">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
                {{ t('stock.price_levels.manage') }}
              </RouterLink>
            </div>

            <p v-if="!priceLevelsLoaded" class="text-xs text-warning-600">{{ t('stock.price_levels.load_failed') }}</p>
            <EmptyState v-else-if="priceLevelGroups.length === 0" dense accent="neutral" icon="tag"
              :title="t('stock.price_levels.empty')" :message="t('stock.price_levels.empty_hint')" />

            <div v-else class="space-y-3">
              <div v-for="g in priceLevelGroups" :key="g.o.level_id" data-test="price-level-row" class="border border-neutral-200 rounded-md">
                <div class="flex flex-wrap items-center gap-2 px-4 py-2 border-b border-neutral-100 bg-neutral-50 rounded-t-md">
                  <span class="font-medium text-neutral-800">{{ g.o.name }}</span>
                  <span class="font-mono text-xs text-neutral-500">{{ g.o.code }}</span>
                  <span v-if="!g.o.is_active" class="rounded-full bg-neutral-100 px-2 py-0.5 text-[11px] text-neutral-500">{{ t('stock.price_levels.inactive') }}</span>
                </div>
                <div data-test="price-level-discount" class="flex flex-wrap items-end gap-3 px-4 py-3 border-b border-neutral-100">
                  <div>
                    <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('stock.price_levels.discount_field') }}</label>
                    <div class="flex items-center gap-1">
                      <select :value="g.o.kind" @change="setLevelKind(g.o, ($event.target as HTMLSelectElement).value as AdjustmentKind)"
                        data-test="price-level-kind" :aria-label="t('eshop.price_levels.adjustment_kind')"
                        class="h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
                        <option value="discount">{{ t('eshop.price_levels.adjustment_discount') }}</option>
                        <option value="markup">{{ t('eshop.price_levels.adjustment_markup') }}</option>
                      </select>
                      <input :value="levelDiscount(g.o)" @input="setLevelDiscount(g.o, ($event.target as HTMLInputElement).value)"
                        type="text" inputmode="decimal" :aria-label="t('stock.price_levels.discount_field')"
                        class="w-24 h-9 px-2 border border-neutral-300 rounded-md text-sm font-mono text-right" />
                      <button v-if="levelDiscount(g.o) !== ''" type="button" @click="setLevelDiscount(g.o, '')"
                        :title="t('stock.price_levels.clear_discount')" :aria-label="t('stock.price_levels.clear_discount')"
                        class="cursor-pointer text-neutral-400 hover:text-danger-500 px-1">
                        <svg class="w-4 h-4 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
                      </button>
                    </div>
                  </div>
                  <p class="flex-1 min-w-[12rem] text-xs text-neutral-500">{{ t('stock.price_levels.discount_field_hint') }}</p>
                </div>
                <div class="overflow-x-auto scrollbar-slim">
                  <table class="w-full text-sm">
                    <thead>
                      <tr class="text-left text-xs text-neutral-500 border-b border-neutral-100">
                        <th class="py-2 px-3 font-medium">{{ t('stock.price_levels.col_currency') }}</th>
                        <th class="py-2 px-3 font-medium text-right">{{ t('stock.price_levels.col_standard') }}</th>
                        <th class="py-2 px-3 font-medium">{{ t('stock.price_levels.col_inherited') }}</th>
                        <th class="py-2 px-3 font-medium">{{ t('stock.price_levels.col_override') }}</th>
                        <th class="py-2 px-3 font-medium text-right">{{ t('stock.price_levels.col_result') }}</th>
                      </tr>
                    </thead>
                    <tbody>
                      <tr v-for="r in g.rows" :key="`${r.price_level_id}-${r.currency_code}`" class="border-b border-neutral-100 last:border-0 align-top">
                        <td class="py-2 px-3"><div class="h-9 flex items-center font-mono font-semibold text-neutral-800">{{ r.currency_code }}</div></td>
                        <td class="py-2 px-3"><div class="h-9 flex items-center justify-end font-mono text-neutral-600 whitespace-nowrap">{{ r.standard_price === null ? '—' : formatUnitPrice(Number(r.standard_price), r.currency_code) }}</div></td>
                        <td class="py-2 px-3">
                          <div class="min-h-9 flex flex-wrap items-center gap-x-2 text-xs text-neutral-600">
                            <span v-if="priceLevelInheritedPrice(r) != null" class="font-mono text-sm text-neutral-800 whitespace-nowrap">{{ formatUnitPrice(priceLevelInheritedPrice(r)!, r.currency_code) }}</span>
                            <span>{{ priceLevelSourceText(r) }}</span>
                          </div>
                        </td>
                        <td class="py-2 px-3">
                          <div class="flex flex-wrap items-center gap-2">
                            <select :value="priceLevelMode(g.o, r.currency_code)"
                              @change="setPriceLevelMode(g.o, r.currency_code, ($event.target as HTMLSelectElement).value as PriceLevelMode)"
                              class="h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
                              <option v-for="m in priceLevelModes(g.o, r.currency_code)" :key="m" :value="m">{{ priceLevelModeLabel(g.o, m) }}</option>
                            </select>
                            <input v-if="priceLevelMode(g.o, r.currency_code) !== 'inherit'"
                              :value="priceLevelRowValue(g.o, r.currency_code)"
                              @input="setPriceLevelRowValue(g.o, r.currency_code, ($event.target as HTMLInputElement).value)"
                              type="text" inputmode="decimal"
                              :aria-label="t('stock.price_levels.override_' + priceLevelMode(g.o, r.currency_code))"
                              :placeholder="priceLevelMode(g.o, r.currency_code) === 'fixed' ? t('eshop.prices.fixed_ph') : undefined"
                              class="w-28 h-9 px-2 border border-neutral-300 rounded-md text-sm font-mono text-right" />
                          </div>
                        </td>
                        <td class="py-2 px-3">
                          <div class="h-9 flex items-center justify-end font-mono text-neutral-800 whitespace-nowrap">
                            <span v-if="priceLevelPreview(g.o, r).pending" class="font-sans text-xs text-neutral-400">{{ t('stock.price_levels.after_save') }}</span>
                            <template v-else>{{ priceLevelPreview(g.o, r).value === null ? '—' : formatUnitPrice(priceLevelPreview(g.o, r).value, r.currency_code) }}</template>
                          </div>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
            <p class="text-xs text-neutral-500">{{ t('stock.price_levels.discount_shared_hint') }} {{ t('stock.price_levels.hint') }}</p>
          </div>
        </div>
      </div>

      <!-- ═══════════ TAB: DODAVATELÉ ═══════════ -->
      <div v-if="isEdit" v-show="tab === 'vendors'" role="tabpanel" :id="`${pageId}-panel-vendors`" :aria-labelledby="`${pageId}-tab-vendors`" class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
        <div class="p-5 space-y-4">
          <button type="button" @click="addVendorRow" :class="btnOutline('primary')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
            {{ t('eshop.vendors.add') }}
          </button>

          <!-- Bez dodavatelů v číselníku kontaktů nejde na kartu žádného navázat —
               proto odkaz do kontaktů, ne nabídka „přidej dodavatele" tady. -->
          <EmptyState v-if="vendorClients.length === 0" dense accent="neutral" icon="user"
            :title="t('eshop.vendors.no_vendor_clients')" />
          <EmptyState v-else-if="vendors.length === 0" dense accent="neutral" icon="user"
            :title="t('eshop.vendors.empty')" />

          <div v-for="(v, idx) in vendors" :key="v.id ?? `new-${idx}`" class="border border-neutral-200 rounded-md p-4 space-y-3">
            <div class="flex items-start justify-between gap-3">
              <div class="flex-1 min-w-0">
                <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('eshop.vendors.field_client') }} *</label>
                <select v-model="v.client_id" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
                  <option :value="null">{{ t('eshop.vendors.select_client') }}</option>
                  <option v-for="c in vendorClients" :key="c.id" :value="c.id">{{ c.company_name }}</option>
                </select>
              </div>
              <button type="button" @click="removeVendorRow(idx)" :title="t('common.delete')" class="cursor-pointer text-neutral-400 hover:text-danger-500 px-1 pt-6">
                <svg class="w-4 h-4 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
              </button>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
              <div>
                <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('eshop.vendors.field_vendor_sku') }}</label>
                <input v-model="v.vendor_sku" type="text" maxlength="64" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm font-mono" />
              </div>
              <div>
                <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('eshop.vendors.field_purchase_price') }}</label>
                <input v-model="v.purchase_price" type="text" inputmode="decimal" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm font-mono text-right" />
              </div>
              <div>
                <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('eshop.vendors.field_currency') }}</label>
                <select v-model="v.currency_code" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm font-mono bg-surface">
                  <option value="">{{ t('eshop.prices.select_currency') }}</option>
                  <option v-for="c in currencyOptions(v.currency_code)" :key="c.code" :value="c.code">{{ c.code }}{{ c.known ? '' : ' ⚠' }}</option>
                </select>
              </div>
              <div>
                <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('eshop.vendors.field_delivery_days') }}</label>
                <input v-model="v.delivery_days" type="number" min="0" step="1" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm font-mono text-right" />
              </div>
              <div>
                <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('eshop.vendors.field_stock_qty') }}</label>
                <input v-model="v.stock_qty" type="text" inputmode="decimal" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm font-mono text-right" />
              </div>
              <div class="flex items-end">
                <label class="inline-flex items-center gap-2 text-sm cursor-pointer h-9">
                  <input type="radio" name="preferredVendor" :checked="v.is_preferred" @change="setPreferredVendor(idx)" class="text-primary-600" />
                  {{ t('eshop.vendors.field_preferred') }}
                </label>
              </div>
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('eshop.vendors.field_note') }}</label>
              <input v-model="v.note" type="text" maxlength="255" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
            </div>
          </div>
        </div>
      </div>

      <!-- ═══════════ TAB: PŘÍLOHY ═══════════ -->
      <div v-if="isEdit" v-show="tab === 'attachments'" role="tabpanel" :id="`${pageId}-panel-attachments`" :aria-labelledby="`${pageId}-tab-attachments`" class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
        <div class="p-5 space-y-4">
          <div v-if="canWriteEshop" class="flex flex-wrap items-center gap-3">
            <input ref="fileInput" type="file" multiple class="hidden" @change="onFilesPicked" />
            <button type="button" @click="triggerUpload" :disabled="uploading" :class="btnFilled('primary')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.upload" /></svg>
              {{ uploading ? t('eshop.attachments.uploading') : t('eshop.attachments.upload') }}
            </button>
            <span class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium"
              :class="mediaSaveState === 'error'
                ? 'border-danger-200 bg-danger-50 text-danger-700'
                : mediaSaveState === 'saving'
                  ? 'border-warning-200 bg-warning-50 text-warning-700'
                  : 'border-success-200 bg-success-50 text-success-700'">
              <span class="h-1.5 w-1.5 rounded-full currentColor bg-current"></span>
              {{ t(`eshop.attachments.immediate_${mediaSaveState}`) }}
            </span>
          </div>
          <p class="text-sm text-neutral-500">{{ t('eshop.attachments.immediate_save_hint') }}</p>

          <EmptyState v-if="media.length === 0" dense accent="neutral" icon="folderOpen"
            :title="t('eshop.attachments.empty')" />

          <div v-else class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
            <div v-for="(m, idx) in media" :key="m.id" class="border border-neutral-200 rounded-lg overflow-hidden flex flex-col">
              <div class="relative aspect-square bg-neutral-100 flex items-center justify-center">
                <img v-if="m.media_type === 'image'" :src="eshopApi.mediaFileUrl(m.id)" :alt="m.alt_text || m.original_name"
                  class="w-full h-full object-cover" @error="onImgError" />
                <svg v-else class="w-10 h-10 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                  <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.doc" />
                </svg>
                <span v-if="m.is_primary" class="absolute top-1.5 left-1.5 text-[10px] px-1.5 py-0.5 rounded bg-primary-600 text-white font-medium">{{ t('eshop.attachments.primary') }}</span>
              </div>
              <div class="p-2 text-xs space-y-1.5">
                <div class="truncate font-medium" :title="m.original_name">{{ m.original_name }}</div>
                <div v-if="canWriteEshop" class="flex items-center justify-between gap-1">
                  <div class="flex items-center gap-0.5">
                    <button type="button" @click="moveMedia(idx, -1)" :disabled="idx === 0" :title="t('eshop.attachments.move_up')" class="cursor-pointer text-neutral-400 hover:text-primary-600 disabled:opacity-30 px-1">
                      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7" /></svg>
                    </button>
                    <button type="button" @click="moveMedia(idx, 1)" :disabled="idx === media.length - 1" :title="t('eshop.attachments.move_down')" class="cursor-pointer text-neutral-400 hover:text-primary-600 disabled:opacity-30 px-1">
                      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" /></svg>
                    </button>
                  </div>
                  <button type="button" @click="removeMedia(m)" :title="t('common.delete')" class="cursor-pointer text-neutral-400 hover:text-danger-500 px-1">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                  </button>
                </div>
                <div v-if="canWriteEshop" class="flex items-center justify-between gap-1 pt-1 border-t border-neutral-100">
                  <button type="button" @click="setPrimaryMedia(m)" :disabled="m.is_primary" class="cursor-pointer text-neutral-500 hover:text-primary-600 disabled:opacity-40 disabled:cursor-default">
                    {{ t('eshop.attachments.set_primary') }}
                  </button>
                  <label class="inline-flex items-center gap-1 cursor-pointer text-neutral-500">
                    <input type="checkbox" :checked="m.export_eshop" @change="toggleMediaExport(m)" class="rounded border-neutral-300 text-primary-600" />
                    {{ t('eshop.attachments.export') }}
                  </label>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Chyba + akční lišta -->
      <div v-if="error" class="mt-3 rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">{{ error }}</div>

      <div class="sticky bottom-[var(--app-footer-height,0px)] z-10 mt-4 -mx-2 px-2 py-3 bg-surface/95 border-t border-neutral-200 backdrop-blur flex flex-wrap justify-end gap-3">
        <RouterLink to="/stock/items" :class="btnOutline('neutral')" class="whitespace-nowrap">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
          {{ t('common.cancel') }}
        </RouterLink>
        <button v-if="!['attachments', 'master', 'relations'].includes(tab) && canSaveEditor" type="submit" :disabled="submitting || (isEdit && !editorLoaded)" :class="btnFilled('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
          {{ submitting ? t('common.saving') : (isEdit ? t('common.save') : t('common.create')) }}
        </button>
      </div>
      </fieldset>
    </form>
  </div>
</template>
