<script setup lang="ts">
import { ref, computed, onMounted, watch, useId } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { stockApi, type StockItemPayload } from '@/api/stock'
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
} from '@/api/eshop'
import { clientsApi, type Client } from '@/api/clients'
import { codebooksApi, type VatRate, type Unit } from '@/api/codebooks'
import { useToast } from '@/composables/useToast'
import { apiErrorMessage } from '@/api/errors'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import DateInput from '@/components/ui/DateInput.vue'
import MarkdownEditor from '@/components/ui/MarkdownEditor.vue'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const toast = useToast()
const pageId = useId()

const isEdit = computed(() => route.params.id !== undefined && route.params.id !== 'new')
const itemId = computed(() => (isEdit.value ? Number(route.params.id) : null))

// ── Taby ────────────────────────────────────────────────────────────────
type Tab = 'general' | 'languages' | 'categories' | 'parameters' | 'prices' | 'vendors' | 'attachments'
const tabs: Tab[] = ['general', 'languages', 'categories', 'parameters', 'prices', 'vendors', 'attachments']
const tab = ref<Tab>((tabs as string[]).includes(String(route.query.tab)) ? (route.query.tab as Tab) : 'general')
watch(tab, (v) => {
  if (route.query.tab !== v) router.replace({ query: { ...route.query, tab: v } })
})

// ── Číselníky ───────────────────────────────────────────────────────────
const vatRates = ref<VatRate[]>([])
const currencies = ref<EshopCurrency[]>([])
const units = ref<Unit[]>([])
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

// ── Základní pole (skladová karta) ──────────────────────────────────────
const form = ref<StockItemPayload>({
  sku: '',
  name: '',
  item_type: 'goods',
  unit: 'ks',
  ean: null,
  vat_rate_id: null,
  sale_price_without_vat: null,
  min_qty: null,
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
const freeLocales = computed(() =>
  locales.value.filter(l => !l.archived && !i18nRows.value.some(r => r.locale === l.code)))
function localeLabel(code: string): string {
  return locales.value.find(l => l.code === code)?.name ?? code.toUpperCase()
}
function addLocale() {
  const l = newLocale.value
  if (!l || i18nRows.value.some(r => r.locale === l)) return
  i18nRows.value.push({ locale: l, name: null, short_desc: null, description: null, seo_title: null, seo_description: null, seo_slug: null })
  newLocale.value = ''
}
function removeLocale(l: string) {
  i18nRows.value = i18nRows.value.filter(r => r.locale !== l)
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
}
const ROUNDING_MODES: PriceRounding[] = ['none', '0.01', '0.10', '0.50', '1', '9_ending']
const prices = ref<PriceRow[]>([])
const recomputing = ref(false)

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
/** Měny už použité na jiném řádku cen — jedna měna smí být na kartě jen jednou. */
function currencyTaken(code: string, selfIdx: number): boolean {
  return prices.value.some((p, i) => i !== selfIdx && p.currency_code === code)
}
function nextFreeCurrency(): string {
  const used = new Set(prices.value.map(p => p.currency_code))
  if (defaultCurrency.value && !used.has(defaultCurrency.value)) return defaultCurrency.value
  return currencies.value.find(c => !used.has(c.code))?.code ?? ''
}

function priceRowFrom(p: ProductPrice): PriceRow {
  return {
    id: p.id,
    currency_code: p.currency_code,
    price_mode: p.price_mode,
    markup_pct: p.markup_pct,
    fixed_price: p.fixed_price,
    rounding: p.rounding,
    computed_price: p.computed_price,
    computed_base: p.computed_base,
    computed_rate: p.computed_rate,
    computed_at: p.computed_at,
    is_manual_override: p.is_manual_override,
  }
}
function addPriceRow() {
  prices.value.push({
    id: null,
    currency_code: nextFreeCurrency(),
    price_mode: 'markup',
    markup_pct: null,
    fixed_price: null,
    rounding: 'none',
    computed_price: null,
    computed_base: null,
    computed_rate: null,
    computed_at: null,
    is_manual_override: false,
  })
}
function removePriceRow(idx: number) {
  prices.value.splice(idx, 1)
}
async function recomputePrices() {
  if (!itemId.value) return
  recomputing.value = true
  try {
    // Nejprve ulož aktuální nastavení řádků, ať přepočet vychází z editovaných hodnot.
    await eshopApi.updatePrices(itemId.value, meaningfulPriceRows().map(pricePayloadFrom))
    const rows = await eshopApi.recomputePrices(itemId.value)
    prices.value = rows.map(priceRowFrom)
    toast.success(t('common.saved'))
  } catch (err: any) {
    toast.error(mapError(err))
  } finally {
    recomputing.value = false
  }
}
/**
 * Řádky, které se opravdu ukládají. Předvyplněný řádek (jediná měna / jediný
 * jazyk) je jen UI zkratka — dokud do něj uživatel nic nenapsal, nemá vznikat
 * v datech. Platí i pro řádek, který uživatel přidal ručně a nechal prázdný.
 */
function meaningfulPriceRows(): PriceRow[] {
  return prices.value.filter(r =>
    r.id !== null
    || r.is_manual_override
    || String(r.markup_pct ?? '').trim() !== ''
    || String(r.fixed_price ?? '').trim() !== '')
}

function meaningfulI18nRows(): ProductI18nRow[] {
  return i18nRows.value.filter(r =>
    [r.name, r.short_desc, r.description, r.seo_title, r.seo_description, r.seo_slug]
      .some(v => String(v ?? '').trim() !== ''))
}

function pricePayloadFrom(r: PriceRow) {
  return {
    currency_code: r.currency_code.trim().toUpperCase(),
    price_mode: r.price_mode,
    markup_pct: r.price_mode === 'markup' ? (r.markup_pct === '' ? null : r.markup_pct) : null,
    fixed_price: r.price_mode === 'fixed' ? (r.fixed_price === '' ? null : r.fixed_price) : null,
    rounding: r.rounding,
    is_manual_override: r.is_manual_override,
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
    promo_price: p.promo_price,
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
    purchase_price: v.purchase_price,
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

// ── Přílohy (média) ─────────────────────────────────────────────────────
const media = ref<ProductMedia[]>([])
const uploading = ref(false)
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
  const [vr, cur, un, loc, mf, cat, tg, attr, vc] = await Promise.all([
    codebooksApi.vatRates('CZ').catch(() => []),
    eshopApi.listCurrencies({ active: 1 }).catch(() => [] as EshopCurrency[]),
    codebooksApi.units().catch(() => [] as Unit[]),
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
  const p = await eshopApi.getProduct(id)
  // základní pole
  form.value = {
    sku: p.sku,
    name: p.name,
    item_type: p.item_type,
    unit: p.unit,
    ean: p.ean,
    vat_rate_id: p.vat_rate_id,
    sale_price_without_vat: p.sale_price_without_vat,
    min_qty: p.min_qty,
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
    eshopApi.getPrices(id).catch(() => []),
    eshopApi.getPromoPrices(id).catch(() => []),
    eshopApi.getVendors(id).catch(() => []),
  ])
  prices.value = pr.map(priceRowFrom)
  promos.value = pp.map(promoRowFrom)
  vendors.value = vn.map(vendorRowFrom)
}

/**
 * S jediným jazykem (resp. měnou) v číselníku není co vybírat — přesto musel
 * uživatel řádek nejdřív ručně přidat, než mohl napsat popis nebo cenu.
 * Prázdný řádek proto předvyplníme; jde jen o UI zkratku, do dat se nedostane,
 * dokud v něm něco nevyplní (viz buildProductPayload / buildPricePayloads).
 *
 * Jen při načtení, ne watcherem: řádek smazaný uživatelem se nesmí sám vrátit.
 */
function prefillSingleOptions() {
  const active = locales.value.filter(l => !l.archived)
  if (active.length === 1 && i18nRows.value.length === 0) {
    i18nRows.value.push({
      locale: active[0].code, name: null, short_desc: null, description: null,
      seo_title: null, seo_description: null, seo_slug: null,
    })
  }
  if (currencies.value.length === 1 && prices.value.length === 0) {
    addPriceRow()
  }
}

onMounted(async () => {
  try {
    await loadCodebooks()
    if (isEdit.value && itemId.value) await loadProduct(itemId.value)
    prefillSingleOptions()
  } catch (e: any) {
    error.value = mapError(e)
  }
})

// ── Sestavení payloadů ──────────────────────────────────────────────────
function buildProductPayload(): ProductUpdatePayload {
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

/**
 * Řádek bez měny by backend odmítl 400 za celý PUT — tedy včetně už vyplněných
 * jazyků a kategorií. Chytáme to dřív a rovnou přepneme na tab, kde chyba je.
 */
function validateBeforeSubmit(): boolean {
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
  return true
}

async function submit() {
  error.value = ''
  errors.value = {}
  if (isEdit.value && !validateBeforeSubmit()) return
  submitting.value = true
  try {
    if (isEdit.value && itemId.value) {
      await stockApi.updateItem(itemId.value, form.value)
      await eshopApi.updateProduct(itemId.value, buildProductPayload())
      await eshopApi.updatePrices(itemId.value, meaningfulPriceRows().map(pricePayloadFrom))
      await eshopApi.updatePromoPrices(itemId.value, promos.value.map(promoPayloadFrom))
      await eshopApi.updateVendors(itemId.value, vendors.value.map(vendorPayloadFrom))
      toast.success(t('common.saved'))
      await loadProduct(itemId.value)
    } else {
      const created = await stockApi.createItem(form.value)
      // Po založení přejdi na editaci, kde jsou dostupné e-shopové taby.
      router.push(`/stock/items/${created.id}/edit`)
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
  if (!input.files || input.files.length === 0 || !itemId.value) return
  uploading.value = true
  try {
    await eshopApi.uploadMedia(itemId.value, Array.from(input.files))
    media.value = (await eshopApi.listMedia(itemId.value)).sort((a, b) => a.display_order - b.display_order)
    toast.success(t('common.saved'))
  } catch (err: any) {
    toast.error(mapError(err))
  } finally {
    uploading.value = false
    input.value = ''
  }
}
async function moveMedia(idx: number, dir: -1 | 1) {
  const target = idx + dir
  if (target < 0 || target >= media.value.length || !itemId.value) return
  const arr = [...media.value]
  const tmp = arr[idx]; arr[idx] = arr[target]; arr[target] = tmp
  media.value = arr
  try {
    await eshopApi.reorderMedia(itemId.value, arr.map(m => m.id))
  } catch (err: any) {
    toast.error(mapError(err))
  }
}
async function setPrimaryMedia(m: ProductMedia) {
  if (!itemId.value) return
  try {
    await eshopApi.updateMedia(m.id, { is_primary: true })
    media.value = (await eshopApi.listMedia(itemId.value)).sort((a, b) => a.display_order - b.display_order)
  } catch (err: any) {
    toast.error(mapError(err))
  }
}
async function toggleMediaExport(m: ProductMedia) {
  try {
    await eshopApi.updateMedia(m.id, { export_eshop: !m.export_eshop })
    m.export_eshop = !m.export_eshop
  } catch (err: any) {
    toast.error(mapError(err))
  }
}
async function removeMedia(m: ProductMedia) {
  if (!confirm(t('eshop.attachments.delete_confirm')) || !itemId.value) return
  try {
    await eshopApi.deleteMedia(m.id)
    media.value = media.value.filter(x => x.id !== m.id)
  } catch (err: any) {
    toast.error(mapError(err))
  }
}
function onImgError(e: Event) {
  (e.target as HTMLImageElement).style.display = 'none'
}
</script>

<template>
  <div class="max-w-4xl">
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-semibold">{{ isEdit ? t('stock.items.edit_title') : t('stock.items.new_title') }}</h1>
      <RouterLink to="/stock/items" class="text-sm text-neutral-600 hover:text-neutral-900">{{ t('stock.item_detail.back_to_list') }}</RouterLink>
    </div>

    <!-- Tab strip (e-shopové taby jen v editaci) -->
    <div v-if="isEdit" class="border-b border-neutral-200 mb-4 flex gap-1 overflow-x-auto">
      <button v-for="tt in tabs" :key="tt"
        @click="tab = tt"
        class="cursor-pointer px-4 py-2 text-sm border-b-2 transition whitespace-nowrap"
        :class="tab === tt
          ? 'border-primary-600 text-primary-700 font-medium'
          : 'border-transparent text-neutral-600 hover:text-neutral-900'">
        {{ tt === 'general' ? t('eshop.item.tab_general')
          : tt === 'languages' ? t('eshop.item.tab_languages')
          : tt === 'categories' ? t('eshop.item.tab_categories')
          : tt === 'parameters' ? t('eshop.item.tab_parameters')
          : tt === 'prices' ? t('eshop.item.tab_prices')
          : tt === 'vendors' ? t('eshop.item.tab_vendors')
          : t('eshop.item.tab_attachments') }}
      </button>
    </div>
    <p v-else class="text-xs text-neutral-500 mb-4">{{ t('eshop.item.eshop_hint') }}</p>

    <form @submit.prevent="submit" autocomplete="off">
      <!-- ═══════════ TAB: OBECNÉ ═══════════ -->
      <div v-show="tab === 'general'" class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
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
              </select>
            </div>
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
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('stock.items.field_vat_rate') }}</label>
              <select v-model="form.vat_rate_id" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none">
                <option :value="null">—</option>
                <option v-for="r in vatRates" :key="r.id" :value="r.id">{{ r.rate_percent }} %</option>
              </select>
            </div>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('stock.items.field_sale_price') }}</label>
              <input v-model="form.sale_price_without_vat" type="number" step="0.01" min="0"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md font-mono text-right focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none" />
            </div>
            <div>
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
              <label class="inline-flex items-center gap-2 text-sm cursor-pointer">
                <input v-model="eshop.is_stocked" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
                {{ t('eshop.item.field_is_stocked') }}
              </label>
              <label class="inline-flex items-center gap-2 text-sm cursor-pointer">
                <input v-model="eshop.export_eshop" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
                {{ t('eshop.item.field_export') }}
              </label>
            </div>
          </div>
        </div>
      </div>

      <!-- ═══════════ TAB: JAZYKY ═══════════ -->
      <div v-if="isEdit" v-show="tab === 'languages'" class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
        <div class="p-5 space-y-4">
          <div class="flex flex-wrap items-center gap-2">
            <select v-model="newLocale" :disabled="freeLocales.length === 0"
              class="h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface disabled:bg-neutral-100 disabled:text-neutral-500">
              <option value="">{{ t('eshop.languages.select_locale') }}</option>
              <option v-for="l in freeLocales" :key="l.code" :value="l.code">{{ l.name }} ({{ l.code.toUpperCase() }})</option>
            </select>
            <button type="button" @click="addLocale" :disabled="!newLocale" :class="btnOutline('primary')" class="whitespace-nowrap">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
              {{ t('eshop.languages.add_locale') }}
            </button>
            <RouterLink to="/eshop?tab=locales" :class="btnOutline('neutral')" class="whitespace-nowrap">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
              {{ t('eshop.languages.manage_locales') }}
            </RouterLink>
          </div>

          <EmptyState v-if="locales.length === 0" dense accent="neutral" icon="doc"
            :title="t('eshop.languages.no_codebook')" :message="t('eshop.languages.no_codebook_hint')" />
          <EmptyState v-else-if="i18nRows.length === 0" dense accent="neutral" icon="doc"
            :title="t('eshop.languages.empty')" />

          <div v-for="row in i18nRows" :key="row.locale" class="border border-neutral-200 rounded-md p-4 space-y-3">
            <div class="flex items-center justify-between">
              <span class="text-sm font-semibold">{{ localeLabel(row.locale) }} <span class="text-neutral-400 font-mono font-normal uppercase">{{ row.locale }}</span></span>
              <button type="button" @click="removeLocale(row.locale)" :title="t('eshop.languages.remove_locale')" class="cursor-pointer text-neutral-400 hover:text-danger-500 px-1">
                <svg class="w-4 h-4 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
              </button>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('eshop.languages.field_name') }}</label>
                <input v-model="row.name" type="text" maxlength="255" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
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

      <!-- ═══════════ TAB: KATEGORIE & ŠTÍTKY ═══════════ -->
      <div v-if="isEdit" v-show="tab === 'categories'" class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
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
      <div v-if="isEdit" v-show="tab === 'parameters'" class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
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
      <div v-if="isEdit" v-show="tab === 'prices'" class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
        <div class="p-5 space-y-4">
          <div class="flex flex-wrap items-center gap-2">
            <button type="button" @click="addPriceRow" :class="btnOutline('primary')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
              {{ t('eshop.prices.add_currency') }}
            </button>
            <button type="button" @click="recomputePrices" :disabled="recomputing || prices.length === 0" :class="btnOutline('neutral')">
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
                <tr v-for="(p, idx) in prices" :key="p.id ?? `new-${idx}`" class="border-b border-neutral-100 align-top">
                  <td class="py-2 pr-3">
                    <select v-model="p.currency_code" required
                      class="w-28 h-9 px-2 border border-neutral-300 rounded-md text-sm font-mono bg-surface">
                      <option value="">{{ t('eshop.prices.select_currency') }}</option>
                      <option v-for="c in currencyOptions(p.currency_code)" :key="c.code" :value="c.code" :disabled="currencyTaken(c.code, idx)">{{ c.code }}{{ c.known ? '' : ' ⚠' }}</option>
                    </select>
                  </td>
                  <td class="py-2 pr-3">
                    <select v-model="p.price_mode" class="h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
                      <option value="markup">{{ t('eshop.prices.mode_markup') }}</option>
                      <option value="fixed">{{ t('eshop.prices.mode_fixed') }}</option>
                    </select>
                  </td>
                  <td class="py-2 pr-3">
                    <input v-if="p.price_mode === 'markup'" v-model="p.markup_pct" type="number" step="0.01" min="0"
                      :placeholder="t('eshop.prices.markup_ph')"
                      class="w-28 h-9 px-2 border border-neutral-300 rounded-md text-sm font-mono text-right" />
                    <input v-else v-model="p.fixed_price" type="text" inputmode="decimal"
                      :placeholder="t('eshop.prices.fixed_ph')"
                      class="w-28 h-9 px-2 border border-neutral-300 rounded-md text-sm font-mono text-right" />
                  </td>
                  <td class="py-2 pr-3">
                    <select v-model="p.rounding" class="h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
                      <option v-for="rm in ROUNDING_MODES" :key="rm" :value="rm">{{ t('eshop.prices.rounding_' + (rm === '9_ending' ? '9_ending' : rm.replace('.', '_'))) }}</option>
                    </select>
                  </td>
                  <td class="py-2 pr-3 text-center">
                    <input v-model="p.is_manual_override" type="checkbox" class="rounded border-neutral-300 text-primary-600 mt-2" />
                  </td>
                  <td class="py-2 pr-3 text-right font-mono text-neutral-700">{{ p.computed_price ?? '—' }}</td>
                  <td class="py-2 pr-1 text-right font-mono text-neutral-500 text-xs">{{ p.computed_rate ?? '—' }}</td>
                  <td class="py-2 text-right">
                    <button type="button" @click="removePriceRow(idx)" :title="t('common.delete')" class="cursor-pointer text-neutral-400 hover:text-danger-500 px-1">
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
        </div>
      </div>

      <!-- ═══════════ TAB: DODAVATELÉ ═══════════ -->
      <div v-if="isEdit" v-show="tab === 'vendors'" class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
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
      <div v-if="isEdit" v-show="tab === 'attachments'" class="bg-surface border border-neutral-200 rounded-lg shadow-sm">
        <div class="p-5 space-y-4">
          <div class="flex items-center gap-3">
            <input ref="fileInput" type="file" multiple class="hidden" @change="onFilesPicked" />
            <button type="button" @click="triggerUpload" :disabled="uploading" :class="btnFilled('primary')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.upload" /></svg>
              {{ uploading ? t('eshop.attachments.uploading') : t('eshop.attachments.upload') }}
            </button>
          </div>

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
                <div class="flex items-center justify-between gap-1">
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
                <div class="flex items-center justify-between gap-1 pt-1 border-t border-neutral-100">
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

      <div class="mt-4 flex justify-end gap-3">
        <RouterLink to="/stock/items" :class="btnOutline('neutral')">{{ t('common.cancel') }}</RouterLink>
        <button v-if="tab !== 'attachments'" type="submit" :disabled="submitting" :class="btnFilled('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
          {{ submitting ? t('common.saving') : (isEdit ? t('common.save') : t('common.create')) }}
        </button>
      </div>
    </form>
  </div>
</template>
