<script setup lang="ts">
import { ref, computed, onMounted, watch, nextTick, useId } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { recurringApi, type RecurringTemplate, type RecurringTemplatePayload, type Frequency, type OssRateType, type OssSupplyType } from '@/api/recurring'
import type { PaymentMethod } from '@/api/invoices'
import { apiErrorMessage } from '@/api/errors'
import { clientsApi, type Client, type ViesLookupResult } from '@/api/clients'
import { projectsApi, type Project } from '@/api/projects'
import { codebooksApi, type VatRate, type Currency, type Unit } from '@/api/codebooks'
import { revenueCategoriesApi, type RevenueCategory } from '@/api/revenueCategories'
import { priceListApi, type CatalogDescriptionSource, type CatalogPolicy, type PriceListItem, type ResolvedPriceListItem } from '@/api/priceList'
import { settingsApi, type BrandingProfile } from '@/api/settings'
import { useToast } from '@/composables/useToast'
import { useSupplierStore } from '@/stores/supplier'
import { useAuthStore } from '@/stores/auth'
import { formatMoney } from '@/composables/useFormat'
import { focusLastRow } from '@/composables/useRowFocus'
import { usePaneDom } from '@/composables/usePaneDom'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import ClientFormModal from '@/components/modals/ClientFormModal.vue'
import ProjectFormModal from '@/components/modals/ProjectFormModal.vue'
import { appIsoDate } from '@/utils/date'

const { t, tm, rt } = useI18n()
const toast = useToast()
const route = useRoute()
const router = useRouter()
const supplierStore = useSupplierStore()
const auth = useAuthStore()
const paneDom = usePaneDom()
const pageId = useId()

// Aktivní dodavatel — neplátce DPH fakturuje bez DPH (žádné DPH UI ani sazba), stejně
// jako u ruční faktury (InvoiceEditor). Backend (RecurringInvoiceGenerator) to navíc
// vynucuje při generování, takže to platí i pro šablony uložené dřív.
const supplierIsVatPayer = computed(() => supplierStore.currentSupplier?.is_vat_payer ?? true)
// Identifikovaná osoba (§ 6g–6l ZDPH, #94) — neplátce s RC u služeb do EU.
const supplierIsIdentified = computed(() => supplierStore.currentSupplier?.is_identified ?? false)
const priceListEnabled = computed(() => !auth.hasCommercialFeatures || supplierStore.currentSupplier?.stock_enabled !== true)
// RC checkbox: plátce vždy, IO taky (její hlavní use-case); čistý neplátce ne.
const showReverseChargeUI = computed(() => supplierIsVatPayer.value || supplierIsIdentified.value)

const isEdit = computed(() => route.params.id !== undefined && route.params.id !== 'new')
const tplId = computed(() => (isEdit.value ? Number(route.params.id) : null))

const loading = ref(false)
const submitting = ref(false)
const error = ref('')

// Inline nápověda placeholderů ({YYYY}, {DATE}…) v popisech položek — defaultně zabalená.
const placeholdersHelpOpen = ref(false)
const placeholderRows = computed(() =>
  (tm('recurring.placeholders_rows') as { c: string; d: string }[]).map(r => ({ c: rt(r.c), d: rt(r.d) }))
)

const clients = ref<Client[]>([])  // akumulovaná cache (výsledky hledání + vybraný)
// Server-side našeptávač klientů (zákazníků) — SearchableSelect remote.
const clientOptions = ref<{ value: number; label: string; secondary?: string }[]>([])
const clientsLoading = ref(false)
const selectedClientOption = ref<{ value: number; label: string; secondary?: string } | null>(null)
function clientToOption(c: Client) {
  return { value: c.id, label: c.company_name, secondary: c.ic ?? undefined }
}
function mergeClients(list: Client[]) {
  const byId = new Map(clients.value.map(c => [c.id, c]))
  for (const c of list) byId.set(c.id, c)
  clients.value = Array.from(byId.values())
}
async function onClientSearch(q: string) {
  clientsLoading.value = true
  try {
    const res = await clientsApi.list({ q: q || undefined, role: 'customers', archived: false, per_page: 50 })
    mergeClients(res.data)
    clientOptions.value = res.data.map(clientToOption)
  } catch { /* ignore */ } finally {
    clientsLoading.value = false
  }
}
async function ensureClientLoaded(id: number, fallbackName?: string | null) {
  const existing = clients.value.find(c => c.id === id)
  if (existing) { selectedClientOption.value = clientToOption(existing); return }
  try {
    const full = await clientsApi.get(id)
    mergeClients([full])
    selectedClientOption.value = clientToOption(full)
  } catch {
    selectedClientOption.value = { value: id, label: fallbackName ?? `#${id}` }
  }
}
const projects = ref<Project[]>([])
const currencies = ref<Currency[]>([])
const vatRates = ref<VatRate[]>([])
const units = ref<Unit[]>([])
const revenueCategories = ref<RevenueCategory[]>([])
const brandingProfiles = ref<BrandingProfile[]>([])
const priceListItems = ref<PriceListItem[]>([])
const catalogResolving = ref<number | null>(null)
const hasPriceList = computed(() => priceListEnabled.value && priceListItems.value.length > 0)

async function loadPriceListItems() {
  if (!priceListEnabled.value) {
    priceListItems.value = []
    return
  }
  const currency = currencies.value.find(item => item.id === form.value.currency_id)?.code
  if (!currency) return
  try {
    const result = await priceListApi.list({
      currency,
      client_id: form.value.client_id ?? undefined,
      prices_include_vat: form.value.prices_include_vat,
      per_page: 200,
    })
    priceListItems.value = result.data
  } catch {
    priceListItems.value = []
  }
}
// Label kategorie načtené šablony — pro případ, že je mezitím archivovaná
// (list(false) vrací jen aktivní) a v selectu by jinak „zmizela".
const loadedCategoryLabel = ref<string | null>(null)

function defaultItemUnit(): string {
  return units.value.find(u => u.is_default)?.code || units.value[0]?.code || 'ks'
}

type FormItem = {
  description: string
  quantity: number
  unit: string
  unit_price_without_vat: number
  vat_rate_id: number
  order_index: number
  price_list_item_id: number | null
  catalog_policy: CatalogPolicy
  description_source: CatalogDescriptionSource
  catalog_price_source: string | null
  catalog_source_currency_code: string | null
  catalog_source_unit_price: number | null
  catalog_exchange_rate: number | null
  catalog_exchange_rate_date: string | null
  accept_catalog_changes: boolean
  catalog_current: ResolvedPriceListItem | null
  // OSS na položce šablony (migrace 1297) — uložené rozhodnutí, ne snímek výpočtu.
  // Kurz, přepočtené částky ani „oprava období" tu záměrně nejsou: to jsou vlastnosti
  // konkrétního dokladu k jeho datu plnění, které dopočítá až generátor.
  oss_applicable: boolean
  oss_consumer_country: string | null
  oss_rate_type: OssRateType | null
  oss_supply_type: OssSupplyType | null
}

const form = ref<{
  client_id: number | null
  project_id: number | null
  branding_profile_id: number | null
  name: string
  frequency: Frequency
  day_of_month: number | null
  end_of_month: boolean
  anchor_date: string
  end_date: string | null
  invoice_type: 'invoice' | 'proforma'
  currency_id: number
  language: 'cs' | 'en'
  payment_method: PaymentMethod
  reverse_charge: boolean
  prices_include_vat: boolean
  discount_percent: number
  revenue_category_id: number | null
  payment_due_days: number
  payment_due_unit: 'days' | 'month' | null
  tax_date_mode: 'same_as_issue' | 'previous_month_last_day'
  draft_open_mode: 'at_issue' | 'period_start'
  reminder_days_before: number
  note_above_items: string
  note_below_items: string
  increment_month_in_descriptions: boolean
  auto_issue: boolean
  auto_send_email: boolean
  items: FormItem[]
}>({
  client_id: null,
  project_id: null,
  branding_profile_id: null,
  name: '',
  frequency: 'monthly',
  day_of_month: null,
  end_of_month: false,
  anchor_date: today(),
  end_date: null,
  invoice_type: 'invoice',
  currency_id: 0,
  language: 'cs',
  payment_method: 'bank_transfer',
  reverse_charge: false,
  prices_include_vat: false,
  discount_percent: 0,
  revenue_category_id: null,
  payment_due_days: 14,
  payment_due_unit: null,
  tax_date_mode: 'same_as_issue',
  draft_open_mode: 'at_issue',
  reminder_days_before: 1,
  note_above_items: '',
  note_below_items: '',
  increment_month_in_descriptions: true,
  auto_issue: true,
  auto_send_email: true,
  items: [],
})

function today(): string {
  return appIsoDate()
}

function dayFromDate(date: string): number | null {
  const day = parseInt(date.slice(8, 10), 10)
  if (!Number.isFinite(day) || day < 1) return null
  return Math.min(28, day)
}

const formLoaded = ref(false)

function defaultVatRateId(): number {
  // Neplátce DPH → vždy 0% Osvobozeno (rate_percent=0, !is_reverse_charge), jako InvoiceEditor.
  // U dodavatele v OSS je v číselníku i cizí 0 %, proto se nejdřív hledá tuzemská.
  if (!supplierIsVatPayer.value) {
    const zero = vatRates.value.find(v => v.country === 'CZ' && Number(v.rate_percent) === 0 && !v.is_reverse_charge)
      || vatRates.value.find(v => Number(v.rate_percent) === 0 && !v.is_reverse_charge)
    if (zero) return zero.id
  }
  const def = vatRates.value.find(v => v.country === 'CZ' && v.is_default)
    || vatRates.value.find(v => v.is_default)
  return def?.id ?? vatRates.value[0]?.id ?? 0
}

// ── OSS na položce šablony (migrace 1297) ────────────────────────────────────────────
// Vzor i chování zrcadlí řádek faktury (InvoiceEditor): zaškrtávátko u řádku a pod ním
// pod-řádek se zemí spotřeby, typem sazby a typem plnění. Rozdíl je jediný — šablona
// nezná „opravu období", protože to je vlastnost konkrétního dokladu, ne předpisu.
const ossAvailable = computed(() => supplierStore.currentSupplier?.oss_enabled === true)
const hasOssItem = computed(() => form.value.items.some(it => it.oss_applicable))

// Sazbu státu spotřeby nabízíme jen OSS řádku; tuzemský řádek zůstává u českých sazeb,
// jinak by se cizí sazba dostala na tuzemské plnění (a s ní cizí daň na ř. 1 přiznání).
const selectableVatRates = computed(() => vatRates.value.filter(r => !r.is_reverse_charge))
const domesticVatRates = computed(() => selectableVatRates.value.filter(r => r.country === 'CZ'))
function vatRatesForItem(item: FormItem): VatRate[] {
  // Dodavatel mimo OSS má v číselníku stejně jen tuzemské sazby — nabídka zůstává,
  // jaká byla (včetně RC řádku), ať se filtrem nezmění chování šablon bez OSS.
  if (!ossAvailable.value) return vatRates.value
  return item.oss_applicable ? selectableVatRates.value : domesticVatRates.value
}
function vatRateLabel(r: VatRate): string {
  const prefix = r.country !== 'CZ' ? `${r.country} ` : ''
  if (Number(r.rate_percent) > 0) return `${prefix}${r.rate_percent} %`
  if (r.is_reverse_charge) return `${prefix}RC`
  return `${prefix}0 %`
}

function onOssApplicableChange(item: FormItem) {
  if (item.oss_applicable) {
    // Typ plnění je u OSS řádku povinný (bez něj by ho generátor stejně doplnil),
    // ať uživatel vidí, s čím se počítá, místo prázdného selectu.
    if (!item.oss_supply_type) item.oss_supply_type = 'goods'
    return
  }
  // Vypnuté OSS = tuzemský řádek. Cizí sazba by na něm zůstala viset neviditelně,
  // protože select ji po přepnutí přestane nabízet.
  item.oss_consumer_country = null
  item.oss_rate_type = null
  item.oss_supply_type = null
  const current = vatRates.value.find(v => v.id === item.vat_rate_id)
  if (current && current.country !== 'CZ') item.vat_rate_id = defaultVatRateId()
}

function blankItem(): FormItem {
  return {
    description: '',
    quantity: 1,
    unit: defaultItemUnit(),
    unit_price_without_vat: 0,
    vat_rate_id: defaultVatRateId(),
    order_index: form.value.items.length,
    price_list_item_id: null,
    catalog_policy: 'fixed',
    description_source: 'template',
    catalog_price_source: null,
    catalog_source_currency_code: null,
    catalog_source_unit_price: null,
    catalog_exchange_rate: null,
    catalog_exchange_rate_date: null,
    accept_catalog_changes: false,
    catalog_current: null,
    oss_applicable: false,
    oss_consumer_country: null,
    oss_rate_type: null,
    oss_supply_type: null,
  }
}

async function applyCatalogItem(item: FormItem, index: number, preservePolicy = false) {
  if (!item.price_list_item_id) {
    Object.assign(item, {
      price_list_item_id: null,
      catalog_policy: 'fixed',
      description_source: 'template',
      catalog_price_source: null,
      catalog_source_currency_code: null,
      catalog_source_unit_price: null,
      catalog_exchange_rate: null,
      catalog_exchange_rate_date: null,
      accept_catalog_changes: false,
      catalog_current: null,
    })
    return
  }
  if (!form.value.client_id || !form.value.currency_id) {
    error.value = t('recurring.catalog_requires_context')
    return
  }
  catalogResolving.value = index
  const policy = item.catalog_policy
  try {
    const resolved = await priceListApi.resolve(item.price_list_item_id, {
      client_id: form.value.client_id,
      currency_id: form.value.currency_id,
      rate_date: form.value.anchor_date || today(),
      prices_include_vat: form.value.prices_include_vat,
    })
    Object.assign(item, {
      description: resolved.description,
      unit: resolved.unit,
      unit_price_without_vat: resolved.unit_price_without_vat,
      vat_rate_id: resolved.vat_rate_id,
      catalog_policy: preservePolicy ? policy : 'fixed',
      description_source: 'catalog',
      catalog_price_source: resolved.catalog_price_source,
      catalog_source_currency_code: resolved.catalog_source_currency_code,
      catalog_source_unit_price: resolved.catalog_source_unit_price,
      catalog_exchange_rate: resolved.catalog_exchange_rate,
      catalog_exchange_rate_date: resolved.catalog_exchange_rate_date,
      accept_catalog_changes: true,
      catalog_current: null,
    })
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    catalogResolving.value = null
  }
}

function catalogSnapshotChanged(item: FormItem): boolean {
  const current = item.catalog_current
  if (!current) return false
  return item.catalog_price_source !== current.catalog_price_source
    || item.catalog_source_currency_code !== current.catalog_source_currency_code
    || Math.abs(Number(item.catalog_source_unit_price ?? 0) - current.catalog_source_unit_price) >= 0.005
    || item.unit !== current.unit
    || item.vat_rate_id !== current.vat_rate_id
    || (item.description_source === 'catalog' && item.description !== current.description)
}

function catalogCurrentLabel(item: FormItem): string {
  const current = item.catalog_current
  if (!current) return ''
  return t('recurring.catalog_current_values', {
    price: current.catalog_source_unit_price,
    currency: current.catalog_source_currency_code,
    unit: current.unit,
    vat: current.vat_rate_percent,
  })
}

function addItem() {
  form.value.items.push(blankItem())
  focusLastRow('[data-row-input="rec-item"]', paneDom.root())
}
function removeItem(idx: number) {
  form.value.items.splice(idx, 1)
}

function round2(n: number): number {
  return Math.round(n * 100) / 100
}

// Naplní vat buckety per sazba; v režimu „ceny s DPH" (prices_include_vat) je
// cena položky brutto a DPH se počítá shora koeficientem (jako InvoiceMath shora).
function vatBuckets(): Map<number, { rate: number; base: number; vat: number }> {
  const pricesIncl = form.value.prices_include_vat && supplierIsVatPayer.value
  const buckets = new Map<number, { rate: number; base: number; vat: number }>()
  for (const item of form.value.items) {
    const vatRate = (form.value.reverse_charge || !supplierIsVatPayer.value)
      ? 0
      : vatRates.value.find(v => v.id === item.vat_rate_id)?.rate_percent ?? 0
    const amount = round2((Number(item.quantity) || 0) * (Number(item.unit_price_without_vat) || 0))
    let base: number
    let vat: number
    if (pricesIncl) {
      vat = round2(amount * vatRate / (100 + vatRate))
      base = round2(amount - vat)
    } else {
      base = amount
      // Dělit AŽ NAKONEC (issue #82): `base * (rate/100)` spočítá koeficient ve floatu
      // a při 21 % dá jiný haléř u 676 z 500 000 částek (např. 29,50 → 6,19 místo 6,20).
      // Náhled šablony by se pak rozešel s fakturou, kterou vygeneruje InvoiceMath.
      vat = round2(base * vatRate / 100)
    }
    if (!buckets.has(vatRate)) buckets.set(vatRate, { rate: vatRate, base: 0, vat: 0 })
    const b = buckets.get(vatRate)!
    b.base += base
    b.vat += vat
  }
  return buckets
}

const computedAmountToPay = computed(() => {
  const pricesIncl = form.value.prices_include_vat
  const buckets = vatBuckets()
  // Sleva na úrovni dokladu — odečte se na každé sazbě (zrcadlí materializaci
  // záporné položky „Sleva X %" v generátoru).
  const pct = Math.min(100, Math.max(0, Number(form.value.discount_percent) || 0))
  let totalBase = 0
  let totalVat = 0
  for (const b of buckets.values()) {
    let base = b.base
    let vat = b.vat
    if (pct > 0) {
      if (pricesIncl) {
        // Sleva z hrubé částky; daň dopočtena shora z hrubé částky po slevě.
        const gross = round2(base + vat)
        const newGross = round2(gross - round2(gross * pct / 100))
        vat = round2(newGross * b.rate / (100 + b.rate))
        base = round2(newGross - vat)
      } else {
        const disc = round2(base * pct / 100)
        base = round2(base - disc)
        vat = round2(vat - round2(disc * b.rate / 100))
      }
    }
    totalBase = round2(totalBase + base)
    totalVat = round2(totalVat + vat)
  }
  return round2(totalBase + totalVat)
})

const currencyCode = computed(() =>
  currencies.value.find(c => c.id === form.value.currency_id)?.code ?? 'CZK'
)

// Záhlaví sloupce jednotkové ceny — v režimu „ceny s DPH" je to cena včetně DPH.
const unitPriceHeaderLabel = computed(() => form.value.prices_include_vat
  ? t('invoice.items_table.unit_price_gross')
  : t('invoice.items_table.unit_price'))

const computedDiscountAmount = computed(() => {
  const pct = Math.min(100, Math.max(0, Number(form.value.discount_percent) || 0))
  if (pct <= 0) return 0
  // discountAmount = úbytek základu (bez DPH) v obou režimech.
  let disc = 0
  for (const b of vatBuckets().values()) {
    if (form.value.prices_include_vat) {
      const gross = round2(b.base + b.vat)
      const newGross = round2(gross - round2(gross * pct / 100))
      const newVat = round2(newGross * b.rate / (100 + b.rate))
      disc = round2(disc + round2(b.base - round2(newGross - newVat)))
    } else {
      disc = round2(disc + round2(b.base * pct / 100))
    }
  }
  return disc
})

// Šablony nemají advance ani parent_invoice_id (vždy generují fresh invoice/proforma),
// takže stačí kontrola na total amount. Pokud se to v budoucnu změní, sjednotit s
// InvoiceEditor.requiresPositiveAmountToPay.
const hasNonPositiveAmountToPay = computed(() =>
  form.value.items.length > 0 && computedAmountToPay.value <= 0
)

function itemHasBothNegative(item: { quantity: number, unit_price_without_vat: number }): boolean {
  return Number(item.quantity) < 0 && Number(item.unit_price_without_vat) < 0
}

async function loadProjectsForClient(clientId: number) {
  // Role client (F6): GET /clients/{id}/projects je pro klienta zakázaný (403) — zakázky nenačítat.
  if (!clientId || auth.isClientRole) {
    projects.value = []
    return
  }
  try {
    projects.value = await projectsApi.listForClient(clientId)
  } catch {
    projects.value = []
  }
}

// VIES ověření DIČ vybraného klienta (jen pokud má DIČ) — zrcadlí InvoiceEditor.
const viesResult = ref<{ status: 'checking' | 'valid' | 'invalid' | 'no_dic' | 'error'; dic?: string; name?: string; message?: string } | null>(null)

async function verifyClientVies(clientId: number) {
  const c = clients.value.find(cc => cc.id === clientId)
  if (!c) { viesResult.value = null; return }
  const dic = (c.dic || '').trim()
  if (!dic) { viesResult.value = { status: 'no_dic' }; return }
  viesResult.value = { status: 'checking', dic }
  try {
    const r: ViesLookupResult = await clientsApi.lookupVies(dic)
    if (r.valid) {
      viesResult.value = { status: 'valid', dic, name: r.name }
    } else {
      viesResult.value = { status: 'invalid', dic, message: r.source === 'error' ? t('invoice.vies.service_unavailable') : t('invoice.vies.not_valid') }
    }
  } catch (e: any) {
    viesResult.value = { status: 'error', dic, message: e?.response?.data?.error?.message || t('invoice.vies.verify_error') }
  }
}

watch(() => form.value.client_id, async (newId) => {
  if (newId) {
    // Pokrývá všechny cesty (edit / ?client_id / from_invoice / výběr / inline-create):
    // dotáhne klienta do cache + nastaví label, aby ho našel i find() níže a VIES.
    await ensureClientLoaded(newId)
    await loadProjectsForClient(newId)
    const c = clients.value.find(x => x.id === newId)
    if (c) {
      if (!form.value.name) form.value.name = c.company_name
      // Po výběru klienta převzít jeho default splatnosti. Až po prvotním načtení
      // (formLoaded), aby se v edit módu nepřepsala uložená hodnota šablony.
      // Pokud má klient default a zvolí se i zakázka, projektová splatnost níže přebije.
      if (formLoaded.value && typeof c.payment_due_default === 'number') {
        form.value.payment_due_days = c.payment_due_default
        // Bez jednotky by se z klientova „1× měsíc" stala splatnost 1 DEN.
        // NULL u klienta znamená „dědí dodavatele" — necháme NULL i tady, dědění
        // dořeší PaymentDueResolver při generování faktury.
        form.value.payment_due_unit = c.payment_due_unit ?? null
      }
      // RC default dle klienta — zrcadlí InvoiceEditor.applyClientDefaults:
      // plátce přebírá klientský flag; identifikovaná osoba (#94) RC zapne
      // automaticky u EU klienta s DIČ (čl. 196, klasifikace řeší generátor);
      // čistý neplátce nikdy. Jen po prvotním načtení (edit mód nepřepisovat).
      if (formLoaded.value) {
        if (supplierIsVatPayer.value) {
          form.value.reverse_charge = c.reverse_charge
        } else {
          form.value.reverse_charge = supplierIsIdentified.value
            && !!c.country_is_eu && c.country_iso2 !== 'CZ' && (c.dic || '').trim() !== ''
        }
      }
    }
    await verifyClientVies(newId)
  } else {
    selectedClientOption.value = null
    projects.value = []
    viesResult.value = null
  }
})

// Po výběru zakázky převzít její splatnost. Až po prvotním načtení formuláře,
// aby se v edit módu nepřepsala uložená hodnota při hydrataci.
watch(() => form.value.project_id, (newId) => {
  if (!formLoaded.value) return
  if (!newId) return
  const p = projects.value.find(x => x.id === newId)
  if (p && typeof p.payment_due_days === 'number') {
    form.value.payment_due_days = p.payment_due_days
    form.value.payment_due_unit = p.payment_due_unit ?? null
  }
})

// Inline create modaly — žádné opouštění editoru pravidelné fakturace.
const clientModalOpen = ref(false)
const projectModalOpen = ref(false)

async function onClientCreatedInModal(client: Client) {
  mergeClients([client])
  selectedClientOption.value = clientToOption(client)
  form.value.client_id = client.id
  clientModalOpen.value = false
  // watch na client_id zavolá ensureClientLoaded (najde v cache) + loadProjects + name
}

async function onProjectCreatedInModal(project: Project) {
  projects.value = [project, ...projects.value.filter(p => p.id !== project.id)]
  form.value.project_id = project.id
  if (typeof project.payment_due_days === 'number') {
    form.value.payment_due_days = project.payment_due_days
    form.value.payment_due_unit = project.payment_due_unit ?? null
  }
  projectModalOpen.value = false
}

watch(() => form.value.end_of_month, (eom) => {
  if (eom) form.value.day_of_month = null
})

watch(() => form.value.anchor_date, (newDate) => {
  if (!formLoaded.value) return
  if (!newDate) return
  if (form.value.end_of_month) return
  if (form.value.day_of_month != null) return
  form.value.day_of_month = dayFromDate(newDate)
})

watch(() => form.value.auto_issue, (ai) => {
  if (!ai) form.value.auto_send_email = false
})

// „Otevřený koncept" (period_start) zatím podporujeme jen pro měsíční periodicitu
// a vyžaduje auto-vystavení (koncept se na konci období uzavře sám).
watch(() => form.value.frequency, (f) => {
  if (f !== 'monthly' && form.value.draft_open_mode === 'period_start') {
    form.value.draft_open_mode = 'at_issue'
  }
})
watch(() => form.value.draft_open_mode, (m) => {
  if (m === 'period_start') form.value.auto_issue = true
})

watch(
  () => [form.value.client_id, form.value.currency_id, form.value.prices_include_vat] as const,
  () => { if (formLoaded.value) void loadPriceListItems() },
)

onMounted(async () => {
  loading.value = true
  try {
    // Klienti se hledají server-side (onClientSearch); cache se plní výsledky + vybraným.
    const [cur, vat, un, rcat, profiles] = await Promise.all([
      codebooksApi.currencies(),
      // Dodavatel v OSS potřebuje i sazby států spotřeby — bez nich by OSS řádek
      // šablony musel nést tuzemskou sazbu a generoval by měsíčně špatnou daň.
      codebooksApi.vatRates(supplierStore.currentSupplier?.oss_enabled ? 'ALL' : 'CZ'),
      codebooksApi.units(),
      revenueCategoriesApi.list(false).catch(() => [] as RevenueCategory[]),  // jen aktivní
      settingsApi.listAvailableBrandingProfiles().catch(() => [] as BrandingProfile[]),
    ])
    currencies.value = cur
    vatRates.value = vat
    units.value = un
    revenueCategories.value = rcat
    brandingProfiles.value = profiles
    await loadPriceListItems()

    if (form.value.currency_id === 0) {
      const def = cur.find(c => c.is_default && c.code === 'CZK') || cur[0]
      if (def) form.value.currency_id = def.id
    }

    // Initialize first item only when creating (not editing)
    if (!isEdit.value && form.value.items.length === 0) {
      form.value.items.push(blankItem())
    }

    // Pre-fill day_of_month from anchor_date for new templates so the user sees the
    // effective day instead of an empty field (server-side null fallback uses anchor's day too).
    if (!isEdit.value && form.value.day_of_month == null && !form.value.end_of_month) {
      form.value.day_of_month = dayFromDate(form.value.anchor_date)
    }

    // Pre-fill client_id from ?client_id=N (např. z ClientDetail "+ Nová šablona")
    const queryClientId = Number(route.query.client_id)
    if (!isEdit.value && queryClientId > 0) {
      form.value.client_id = queryClientId
      const c = clients.value.find(x => x.id === queryClientId)
      if (c) form.value.name = c.company_name
      await loadProjectsForClient(queryClientId)
    }

    // Pre-fill from existing invoice (?from_invoice=ID)
    const fromInvoice = route.query.from_invoice
    if (!isEdit.value && fromInvoice) {
      try {
        const mod = await import('@/api/invoices')
        const inv = await mod.invoicesApi.get(Number(fromInvoice))
        form.value.client_id = inv.client_id
        form.value.project_id = inv.project_id
        form.value.branding_profile_id = brandingProfiles.value.some(profile => profile.id === inv.branding_profile_id)
          ? inv.branding_profile_id : null
        form.value.name = inv.client_company_name ?? ''
        form.value.invoice_type = inv.invoice_type === 'proforma' ? 'proforma' : 'invoice'
        form.value.currency_id = inv.currency_id
        form.value.language = inv.language
        form.value.payment_method = inv.payment_method ?? 'bank_transfer'
        form.value.reverse_charge = inv.reverse_charge
        form.value.prices_include_vat = (inv as { prices_include_vat?: boolean }).prices_include_vat ?? false
        form.value.discount_percent = inv.discount_percent ?? 0
        form.value.note_above_items = inv.note_above_items ?? ''
        form.value.note_below_items = inv.note_below_items ?? ''
        // Slevová položka se do šablony nepřenáší — drží se jako discount_percent.
        form.value.items = inv.items.filter(it => it.item_kind !== 'discount').map((it, i) => ({
          description: it.description,
          quantity: it.quantity,
          unit: it.unit,
          unit_price_without_vat: it.unit_price_without_vat,
          vat_rate_id: it.vat_rate_id,
          order_index: i,
          price_list_item_id: null,
          catalog_policy: 'fixed',
          description_source: 'template',
          catalog_price_source: null,
          catalog_source_currency_code: null,
          catalog_source_unit_price: null,
          catalog_exchange_rate: null,
          catalog_exchange_rate_date: null,
          accept_catalog_changes: false,
          catalog_current: null,
          // OSS z předlohy se přenáší: jednorázová faktura polskému spotřebiteli
          // se dělá šablonou právě proto, aby se totéž rozhodnutí nedělalo znovu.
          oss_applicable: it.oss_applicable ?? false,
          oss_consumer_country: it.oss_applicable ? (it.oss_consumer_country || null) : null,
          oss_rate_type: it.oss_applicable ? ((it.oss_rate_type as OssRateType | null) || null) : null,
          oss_supply_type: it.oss_applicable ? (it.oss_supply_type ?? null) : null,
        }))
        if (inv.client_id) await loadProjectsForClient(inv.client_id)
      } catch {
        // ignore — proceed with empty form
      }
    }

    if (isEdit.value && tplId.value) {
      const tpl: RecurringTemplate = await recurringApi.get(tplId.value)
      Object.assign(form.value, {
        client_id: tpl.client_id,
        project_id: tpl.project_id,
        branding_profile_id: brandingProfiles.value.some(profile => profile.id === tpl.branding_profile_id)
          ? tpl.branding_profile_id : null,
        name: tpl.name,
        frequency: tpl.frequency,
        day_of_month: tpl.day_of_month,
        end_of_month: tpl.end_of_month,
        anchor_date: tpl.anchor_date.slice(0, 10),
        end_date: tpl.end_date ? tpl.end_date.slice(0, 10) : null,
        invoice_type: tpl.invoice_type,
        currency_id: tpl.currency_id,
        language: tpl.language,
        payment_method: tpl.payment_method,
        reverse_charge: tpl.reverse_charge,
        prices_include_vat: (tpl as { prices_include_vat?: boolean }).prices_include_vat ?? false,
        discount_percent: tpl.discount_percent ?? 0,
        revenue_category_id: tpl.revenue_category_id ?? null,
        payment_due_days: tpl.payment_due_days,
        payment_due_unit: tpl.payment_due_unit ?? null,
        tax_date_mode: tpl.tax_date_mode ?? 'same_as_issue',
        draft_open_mode: tpl.draft_open_mode ?? 'at_issue',
        reminder_days_before: tpl.reminder_days_before ?? 1,
        note_above_items: tpl.note_above_items ?? '',
        note_below_items: tpl.note_below_items ?? '',
        increment_month_in_descriptions: tpl.increment_month_in_descriptions,
        auto_issue: tpl.auto_issue,
        auto_send_email: tpl.auto_send_email,
        items: (tpl.items ?? []).map(it => ({
          description: it.description,
          quantity: it.quantity,
          unit: it.unit,
          unit_price_without_vat: it.unit_price_without_vat,
          vat_rate_id: it.vat_rate_id,
          order_index: it.order_index,
          price_list_item_id: it.price_list_item_id ?? null,
          catalog_policy: it.catalog_policy ?? 'fixed',
          description_source: it.description_source ?? 'template',
          catalog_price_source: it.catalog_price_source ?? null,
          catalog_source_currency_code: it.catalog_source_currency_code ?? null,
          catalog_source_unit_price: it.catalog_source_unit_price ?? null,
          catalog_exchange_rate: it.catalog_exchange_rate ?? null,
          catalog_exchange_rate_date: it.catalog_exchange_rate_date ?? null,
          accept_catalog_changes: false,
          catalog_current: null,
          oss_applicable: it.oss_applicable ?? false,
          oss_consumer_country: it.oss_consumer_country ?? null,
          oss_rate_type: (it.oss_rate_type as OssRateType | null) ?? null,
          oss_supply_type: it.oss_supply_type ?? null,
        })),
      })
      loadedCategoryLabel.value = tpl.revenue_category_label ?? null
      if (tpl.client_id) await loadProjectsForClient(tpl.client_id)
    }
  } finally {
    loading.value = false
    await loadPriceListItems()
    await Promise.all(form.value.items.map(async item => {
      if (!priceListEnabled.value || !item.price_list_item_id || item.catalog_policy !== 'review_required' || !form.value.client_id || !form.value.currency_id) return
      try {
        item.catalog_current = await priceListApi.resolve(item.price_list_item_id, {
          client_id: form.value.client_id,
          currency_id: form.value.currency_id,
          rate_date: form.value.anchor_date || today(),
          prices_include_vat: form.value.prices_include_vat,
        })
      } catch {
        item.catalog_current = null
      }
    }))
    formLoaded.value = true
  }
})

async function submit() {
  error.value = ''
  if (!form.value.client_id) { error.value = 'Klient je povinný'; return }
  if (!form.value.name.trim()) { error.value = t('recurring.name_required'); return }
  if (form.value.items.length === 0) { error.value = t('recurring.items_required'); return }
  if (form.value.auto_send_email && !form.value.auto_issue) {
    error.value = t('recurring.auto_send_requires_issue')
    return
  }
  if (form.value.draft_open_mode === 'period_start') {
    if (form.value.frequency !== 'monthly') {
      error.value = t('recurring.period_start_requires_monthly')
      return
    }
    if (!form.value.auto_issue) {
      error.value = t('recurring.period_start_requires_auto_issue')
      return
    }
  }
  if (hasNonPositiveAmountToPay.value) {
    error.value = t('invoice.amount_positive_required')
    return
  }
  // OSS bez země spotřeby server ukládá jako NE-OSS (položka by při každém generování
  // vznikla neplatná). Bez téhle hlášky by uživatel zaškrtl OSS, uložil a rozhodnutí by
  // tiše zmizelo — o to hůř, že šablona generuje bez dozoru.
  if (form.value.items.some(it => it.oss_applicable && !(it.oss_consumer_country || '').trim())) {
    error.value = t('recurring.oss_country_required')
    return
  }

  submitting.value = true
  try {
    const payload: RecurringTemplatePayload = {
      client_id: form.value.client_id!,
      project_id: form.value.project_id,
      branding_profile_id: form.value.branding_profile_id,
      name: form.value.name.trim(),
      frequency: form.value.frequency,
      day_of_month: form.value.end_of_month ? null : form.value.day_of_month,
      end_of_month: form.value.end_of_month,
      anchor_date: form.value.anchor_date,
      end_date: form.value.end_date || null,
      invoice_type: form.value.invoice_type,
      currency_id: form.value.currency_id,
      language: form.value.language,
      payment_method: form.value.payment_method,
      reverse_charge: form.value.reverse_charge,
      prices_include_vat: form.value.prices_include_vat,
      discount_percent: form.value.discount_percent || 0,
      revenue_category_id: form.value.revenue_category_id,
      payment_due_days: form.value.payment_due_days,
      payment_due_unit: form.value.payment_due_unit,
      tax_date_mode: form.value.tax_date_mode,
      draft_open_mode: form.value.draft_open_mode,
      reminder_days_before: form.value.reminder_days_before,
      note_above_items: form.value.note_above_items || null,
      note_below_items: form.value.note_below_items || null,
      increment_month_in_descriptions: form.value.increment_month_in_descriptions,
      auto_issue: form.value.auto_issue,
      auto_send_email: form.value.auto_send_email,
      items: form.value.items.map((it, i) => ({
        description: it.description,
        quantity: it.quantity,
        unit: it.unit,
        unit_price_without_vat: it.unit_price_without_vat,
        vat_rate_id: it.vat_rate_id,
        order_index: i,
        price_list_item_id: it.price_list_item_id,
        catalog_policy: it.catalog_policy,
        description_source: it.description_source,
        catalog_price_source: it.catalog_price_source,
        catalog_source_currency_code: it.catalog_source_currency_code,
        catalog_source_unit_price: it.catalog_source_unit_price,
        catalog_exchange_rate: it.catalog_exchange_rate,
        catalog_exchange_rate_date: it.catalog_exchange_rate_date,
        accept_catalog_changes: it.accept_catalog_changes,
        oss_applicable: it.oss_applicable,
        oss_consumer_country: it.oss_applicable ? (it.oss_consumer_country || '').trim().toUpperCase() || null : null,
        oss_rate_type: it.oss_applicable ? it.oss_rate_type : null,
        oss_supply_type: it.oss_applicable ? it.oss_supply_type : null,
      })),
    }
    if (isEdit.value && tplId.value) {
      await recurringApi.update(tplId.value, payload)
    } else {
      await recurringApi.create(payload)
    }
    toast.success(t('recurring.saved'))
    router.push({ name: 'recurring' })
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message ?? 'Error'
    // Toast + scroll k bannéru — uživatel může být odscrollovaný dole u tlačítka Uložit.
    toast.error(error.value)
    await nextTick()
    paneDom.querySelector('[data-error-banner]')?.scrollIntoView({ behavior: 'smooth', block: 'center' })
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="max-w-5xl">
    <h1 class="text-2xl font-bold text-neutral-900 mb-5">
      {{ isEdit ? t('recurring.form_title_edit') : t('recurring.form_title_new') }}
    </h1>

    <div v-if="loading" class="text-center py-12 text-neutral-400">…</div>
    <form v-else @submit.prevent="submit" class="space-y-5">
      <!-- Basics -->
      <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm space-y-4">
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('recurring.name') }} *</label>
          <input v-model="form.name" type="text" maxlength="200"
            :placeholder="t('recurring.name_placeholder')"
            class="w-full h-10 px-3 border border-neutral-300 rounded-md" />
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('recurring.client') }} *</label>
            <div class="flex gap-2">
              <div class="flex-1 min-w-0">
                <SearchableSelect
                  :model-value="form.client_id"
                  @update:model-value="(v) => { form.client_id = v }"
                  remote
                  :loading="clientsLoading"
                  :options="clientOptions"
                  :selected-option="selectedClientOption"
                  @search="onClientSearch"
                  :placeholder="t('recurring.client')"
                />
              </div>
              <button type="button" @click="clientModalOpen = true"
                class="cursor-pointer shrink-0 h-9 px-3 inline-flex items-center gap-1.5 border border-primary-500/40 text-primary-700 hover:bg-primary-50 rounded-md text-sm font-medium"
                :title="t('client.new_title')">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                </svg>
                <span class="hidden sm:inline">{{ t('client.new_title') }}</span>
              </button>
            </div>
            <!-- VIES výsledek — zrcadlí InvoiceEditor -->
            <div v-if="viesResult" class="mt-1 text-xs flex items-start gap-1.5">
              <template v-if="viesResult.status === 'checking'">
                <span class="text-neutral-500">{{ t('invoice.vies.checking', { dic: viesResult.dic }) }}</span>
              </template>
              <template v-else-if="viesResult.status === 'valid'">
                <svg class="w-4 h-4 text-success-600 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                <span class="text-success-600">{{ t('invoice.vies.valid', { dic: viesResult.dic }) }}<span v-if="viesResult.name" class="text-neutral-500"> — {{ viesResult.name }}</span></span>
              </template>
              <template v-else-if="viesResult.status === 'invalid'">
                <svg class="w-4 h-4 text-danger-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                <span class="text-danger-500">{{ t('common.dic') }} <span class="font-mono">{{ viesResult.dic }}</span>: {{ viesResult.message }}</span>
              </template>
              <template v-else-if="viesResult.status === 'error'">
                <span class="text-warning-600">⚠ {{ viesResult.message }}</span>
              </template>
              <template v-else-if="viesResult.status === 'no_dic'">
                <span class="text-neutral-400">{{ t('invoice.vies.no_dic') }}</span>
              </template>
            </div>
          </div>
          <div v-if="!auth.isClientRole">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('recurring.project') }}</label>
            <div class="flex gap-2">
              <div class="flex-1 min-w-0">
                <SearchableSelect
                  :model-value="form.project_id"
                  @update:model-value="(v) => { form.project_id = v }"
                  :options="projects.map(p => ({ value: p.id, label: p.name }))"
                  :placeholder="t('invoice.no_project')"
                  :disabled="!form.client_id"
                />
              </div>
              <button type="button" @click="projectModalOpen = true" :disabled="!form.client_id"
                class="cursor-pointer shrink-0 h-9 px-3 inline-flex items-center gap-1.5 border border-primary-500/40 text-primary-700 hover:bg-primary-50 disabled:opacity-50 disabled:cursor-not-allowed rounded-md text-sm font-medium"
                :title="t('project.new_title')">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                </svg>
                <span class="hidden sm:inline">{{ t('invoice.new_project_short') }}</span>
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Periodicity -->
      <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm space-y-4">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('recurring.section_periodicity') }}</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('recurring.frequency') }} *</label>
            <select v-model="form.frequency" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface">
              <option value="monthly">{{ t('recurring.frequency_monthly') }}</option>
              <option value="quarterly">{{ t('recurring.frequency_quarterly') }}</option>
              <option value="semi_annually">{{ t('recurring.frequency_semi_annually') }}</option>
              <option value="annually">{{ t('recurring.frequency_annually') }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('recurring.day_of_month') }}</label>
            <input v-model.number="form.day_of_month" type="number" min="1" max="28"
              :disabled="form.end_of_month"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md disabled:bg-neutral-50 disabled:text-neutral-400" />
          </div>
        </div>
        <label class="flex items-start gap-2 text-sm text-neutral-700">
          <input v-model="form.end_of_month" type="checkbox" class="mt-1 rounded border-neutral-300 text-primary-600" />
          <span>
            <span class="font-medium">{{ t('recurring.end_of_month') }}</span>
            <span class="block text-xs text-neutral-500">{{ t('recurring.day_of_month_hint') }}</span>
          </span>
        </label>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('recurring.anchor_date') }} *</label>
            <input v-model="form.anchor_date" type="date"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('recurring.end_date') }}</label>
            <input v-model="form.end_date" type="date"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md" />
          </div>
        </div>
        <p class="text-xs text-neutral-500 -mt-2">{{ t('recurring.end_date_hint') }}</p>
      </div>

      <!-- Invoice metadata -->
      <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm space-y-4">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('recurring.section_invoice_meta') }}</h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('recurring.invoice_type') }}</label>
            <select v-model="form.invoice_type" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface">
              <option value="invoice">{{ t('type.invoice') }}</option>
              <option value="proforma">{{ t('type.proforma') }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('recurring.currency') }}</label>
            <select v-model.number="form.currency_id" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface">
              <option v-for="c in currencies" :key="c.id" :value="c.id">{{ c.label }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('recurring.language') }}</label>
            <select v-model="form.language" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface">
              <option value="cs">CZ</option>
              <option value="en">EN</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('payment_method.label') }}</label>
            <select v-model="form.payment_method" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface">
              <option value="bank_transfer">{{ t('payment_method.bank_transfer') }}</option>
              <option value="card">{{ t('payment_method.card') }}</option>
              <option value="cash">{{ t('payment_method.cash') }}</option>
              <option value="other">{{ t('payment_method.other') }}</option>
            </select>
          </div>
          <div v-if="brandingProfiles.length > 0">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('recurring.branding_profile') }}</label>
            <select v-model="form.branding_profile_id" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface">
              <option :value="null">{{ t('recurring.branding_profile_default') }}</option>
              <option v-for="profile in brandingProfiles" :key="profile.id" :value="profile.id">{{ profile.name }}</option>
            </select>
            <p class="mt-1 text-xs text-neutral-500">{{ t('recurring.branding_profile_hint') }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('recurring.payment_due_label') }}</label>
            <div class="flex gap-2 items-center">
              <input v-model.number="form.payment_due_days" type="number" min="0"
                class="w-20 shrink-0 h-10 px-3 border border-neutral-300 rounded-md text-right font-mono" />
              <select v-model="form.payment_due_unit"
                class="flex-1 min-w-0 h-10 px-2 border border-neutral-300 rounded-md text-sm bg-surface focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none">
                <option :value="null">{{ t('recurring.payment_due_unit_inherit') }}</option>
                <option value="days">{{ t('recurring.payment_due_unit_days') }}</option>
                <option value="month">{{ t('recurring.payment_due_unit_month') }}</option>
              </select>
            </div>
            <p class="mt-1 text-xs text-neutral-500">{{ t('recurring.payment_due_unit_hint') }}</p>
          </div>
          <div>
            <label :for="`${pageId}-rec-discount-percent`" class="block text-sm font-medium text-neutral-700 mb-1">{{ t('invoice.discount.label') }}</label>
            <div class="relative">
              <input :id="`${pageId}-rec-discount-percent`" v-model.number="form.discount_percent" type="number" min="0" max="100" step="0.01"
                class="w-full h-10 pl-3 pr-8 border border-neutral-300 rounded-md text-right font-mono" />
              <span class="absolute right-3 top-1/2 -translate-y-1/2 text-neutral-400 pointer-events-none">%</span>
            </div>
          </div>
          <!-- Pevná kategorie tržby (#119) — přebíjí fallback zakázka → zákazník -->
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('recurring.revenue_category') }}</label>
            <select v-model="form.revenue_category_id"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface">
              <option :value="null">— {{ t('recurring.revenue_category_fallback') }} —</option>
              <option v-for="c in revenueCategories" :key="c.id" :value="c.id">
                {{ c.label }} ({{ c.code }})
              </option>
              <option v-if="form.revenue_category_id && !revenueCategories.some(c => c.id === form.revenue_category_id)"
                :value="form.revenue_category_id">
                {{ loadedCategoryLabel ?? `#${form.revenue_category_id}` }}
              </option>
            </select>
            <p class="mt-1 text-xs text-neutral-500">{{ t('recurring.revenue_category_hint') }}</p>
          </div>
          <div class="md:col-span-2">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('recurring.tax_date_mode') }}</label>
            <select v-model="form.tax_date_mode"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface">
              <option value="same_as_issue">{{ t('recurring.tax_date_mode_same_as_issue') }}</option>
              <option value="previous_month_last_day">{{ t('recurring.tax_date_mode_previous_month_last_day') }}</option>
            </select>
            <p class="mt-1 text-xs text-neutral-500">{{ t('recurring.tax_date_mode_hint') }}</p>
          </div>
        </div>
      </div>

      <!-- Items -->
      <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm">
        <div class="flex items-center justify-between mb-3">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('recurring.items') }}</h3>
          <button type="button" @click="addItem"
            class="cursor-pointer px-3 h-8 text-sm bg-primary-600 hover:bg-primary-700 text-white rounded-md font-medium">
            {{ t('invoice.add_item') }}
          </button>
        </div>
        <p class="mb-1 text-xs text-neutral-500">{{ t('invoice.negative_item_hint') }}</p>

        <!-- Placeholdery období (#108) — defaultně zabalená nápověda -->
        <div class="mb-3">
          <button type="button" @click="placeholdersHelpOpen = !placeholdersHelpOpen"
            class="cursor-pointer inline-flex items-center gap-1 text-xs text-primary-700 hover:text-primary-800">
            <svg :class="['w-3 h-3 transition-transform', placeholdersHelpOpen ? 'rotate-90' : '']" viewBox="0 0 20 20" fill="currentColor">
              <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
            </svg>
            {{ t('recurring.placeholders_toggle') }}
          </button>
          <div v-if="placeholdersHelpOpen" class="mt-2 rounded-md border border-neutral-200 bg-neutral-50 px-3 py-2 text-xs text-neutral-600 space-y-2">
            <p>{{ t('recurring.placeholders_intro') }}</p>
            <dl class="space-y-1">
              <div v-for="(row, i) in placeholderRows" :key="i" class="flex gap-2">
                <dt class="font-mono shrink-0 text-neutral-800 whitespace-nowrap">{{ row.c }}</dt>
                <dd>{{ row.d }}</dd>
              </div>
            </dl>
            <p class="text-neutral-500">{{ t('recurring.placeholders_example') }}</p>
          </div>
        </div>
        <!-- RC checkbox dřív v šabloně chyběl (flag se přenášel jen „z faktury") —
             doplněno s IO podporou (#94); generátor RC přenáší do vystavených faktur
             a auto-klasifikace položek dá EU službám kód 22 (souhrnné hlášení). -->
        <div v-if="showReverseChargeUI" class="mb-3">
          <label class="flex items-center gap-2 text-sm text-neutral-700 mb-1">
            <input v-model="form.reverse_charge" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            <span>{{ t('invoice.reverse_charge') }} ({{ t('invoice.totals.vat') }} 0 %)</span>
          </label>
          <p v-if="!supplierIsVatPayer && form.reverse_charge" class="text-xs text-neutral-500 ml-6">
            {{ t('invoice.reverse_charge_io_hint') }}
          </p>
        </div>
        <template v-if="supplierIsVatPayer">
          <label class="flex items-center gap-2 text-sm text-neutral-700 mb-1">
            <input v-model="form.prices_include_vat" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            <span>{{ t('invoice.prices_include_vat') }}</span>
          </label>
          <p class="mb-3 text-xs text-neutral-500 ml-6">{{ t('invoice.prices_include_vat_hint') }}</p>
        </template>
        <!-- Desktop: tabulka -->
        <div class="hidden md:block overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="text-xs text-neutral-500">
            <tr>
              <th class="text-left">{{ t('invoice.items_table.description') }}</th>
              <th class="text-right" style="width:8%">{{ t('invoice.items_table.qty') }}</th>
              <th class="text-left" style="width:8%">{{ t('invoice.items_table.unit') }}</th>
              <th class="text-right" style="width:15%">{{ unitPriceHeaderLabel }}</th>
              <th v-if="supplierIsVatPayer" class="text-left" style="width:14%">{{ t('invoice.items_table.vat') ?? 'DPH' }}</th>
              <th :style="ossAvailable || hasOssItem ? 'width:96px' : 'width:30px'"></th>
            </tr>
          </thead>
          <tbody>
            <template v-for="(it, idx) in form.items" :key="idx">
            <tr :class="['border-t border-neutral-200', itemHasBothNegative(it) ? 'bg-danger-50' : '']">
              <td class="py-1.5 pr-2">
                <div v-if="priceListEnabled && (hasPriceList || it.price_list_item_id)" class="mb-1 grid gap-1 xl:grid-cols-3">
                  <select v-model.number="it.price_list_item_id" class="h-8 min-w-0 px-2 border border-neutral-300 rounded bg-surface text-xs" @change="applyCatalogItem(it, idx)">
                    <option :value="null">{{ t('recurring.catalog_manual') }}</option>
                    <option v-for="catalogItem in priceListItems" :key="catalogItem.id" :value="catalogItem.id">{{ catalogItem.code }} · {{ catalogItem.name }}</option>
                  </select>
                  <select v-if="it.price_list_item_id" v-model="it.catalog_policy" class="h-8 min-w-0 px-2 border border-neutral-300 rounded bg-surface text-xs">
                    <option value="fixed">{{ t('recurring.catalog_policy_fixed') }}</option>
                    <option value="current">{{ t('recurring.catalog_policy_current') }}</option>
                    <option value="review_required">{{ t('recurring.catalog_policy_review_required') }}</option>
                  </select>
                  <select v-if="it.price_list_item_id" v-model="it.description_source" class="h-8 min-w-0 px-2 border border-neutral-300 rounded bg-surface text-xs">
                    <option value="catalog">{{ t('recurring.catalog_description_catalog') }}</option>
                    <option value="template">{{ t('recurring.catalog_description_template') }}</option>
                  </select>
                </div>
                <input v-model="it.description" type="text" data-row-input="rec-item" :disabled="priceListEnabled && !!it.price_list_item_id && it.description_source === 'catalog'" class="w-full h-8 px-2 border border-neutral-300 rounded bg-surface disabled:bg-neutral-100" />
                <p v-if="priceListEnabled && it.price_list_item_id" class="mt-1 text-xs text-neutral-500">
                  {{ catalogResolving === idx ? t('common.loading') : t('recurring.catalog_snapshot', { currency: it.catalog_source_currency_code ?? '—', price: it.catalog_source_unit_price ?? '—' }) }}
                  <button v-if="it.catalog_policy === 'review_required'" type="button" class="ml-2 text-primary-700 hover:underline" @click="applyCatalogItem(it, idx, true)">
                    {{ t('recurring.catalog_accept_changes') }}
                  </button>
                </p>
                <p v-if="priceListEnabled && it.catalog_policy === 'review_required' && catalogSnapshotChanged(it)" class="mt-1 text-xs text-warning-700">
                  {{ catalogCurrentLabel(it) }}
                </p>
              </td>
              <td class="py-1.5 pr-2"><input v-model="it.quantity" v-math type="text" inputmode="decimal" :class="['w-full h-8 px-2 border rounded bg-surface text-right font-mono', itemHasBothNegative(it) ? 'border-danger-400' : 'border-neutral-300']" /></td>
              <td class="py-1.5 pr-2">
                <select v-model="it.unit" :disabled="priceListEnabled && !!it.price_list_item_id" class="w-full h-8 px-1 border border-neutral-300 rounded bg-surface text-sm disabled:bg-neutral-100">
                  <option v-for="u in units" :key="u.id" :value="u.code">{{ u.code }}</option>
                  <option v-if="it.unit && !units.some(u => u.code === it.unit)" :value="it.unit">{{ it.unit }}</option>
                </select>
              </td>
              <td class="py-1.5 pr-2"><input v-model="it.unit_price_without_vat" v-math type="text" inputmode="decimal" :disabled="priceListEnabled && !!it.price_list_item_id" :class="['w-full h-8 px-2 border rounded bg-surface text-right font-mono disabled:bg-neutral-100', itemHasBothNegative(it) ? 'border-danger-400' : 'border-neutral-300']" /></td>
              <td v-if="supplierIsVatPayer" class="py-1.5 pr-2">
                <select v-model.number="it.vat_rate_id" :disabled="priceListEnabled && !!it.price_list_item_id" class="w-full h-8 px-2 border border-neutral-300 rounded bg-surface disabled:bg-neutral-100">
                  <option v-for="r in vatRatesForItem(it)" :key="r.id" :value="r.id">{{ vatRateLabel(r) }}</option>
                  <option v-if="!vatRatesForItem(it).some(r => r.id === it.vat_rate_id)" :value="it.vat_rate_id">
                    {{ vatRates.find(r => r.id === it.vat_rate_id) ? vatRateLabel(vatRates.find(r => r.id === it.vat_rate_id)!) : '—' }}
                  </option>
                </select>
              </td>
              <td class="py-1.5 text-right">
                <div class="flex items-center justify-end gap-2 whitespace-nowrap">
                  <label v-if="ossAvailable || it.oss_applicable"
                    class="inline-flex shrink-0 items-center gap-1 text-xs text-neutral-600" :title="t('invoice.oss.enabled')">
                    <input v-model="it.oss_applicable" type="checkbox" class="rounded border-neutral-300 text-primary-600"
                      @change="onOssApplicableChange(it)" />
                    <span>{{ t('invoice.oss.enabled') }}</span>
                  </label>
                  <button type="button" @click="removeItem(idx)" class="cursor-pointer text-danger-500 hover:text-danger-700 text-lg">×</button>
                </div>
              </td>
            </tr>
            <!-- OSS pod-řádek — stejná pole jako na řádku faktury; „oprava období" tu není,
                 protože to je vlastnost konkrétního dokladu, ne předpisu. -->
            <tr v-if="it.oss_applicable" :class="['border-t-0!', itemHasBothNegative(it) ? 'bg-danger-50' : '']">
              <td :colspan="supplierIsVatPayer ? 6 : 5" class="pb-2">
                <div class="flex flex-wrap items-center gap-1.5 text-xs">
                  <input v-model="it.oss_consumer_country" type="text" maxlength="2"
                    :placeholder="t('invoice.oss.country')" :title="t('invoice.oss.country')"
                    :class="['w-11 h-7 shrink-0 px-1 border rounded text-xs text-center font-mono uppercase',
                             (it.oss_consumer_country || '').trim() ? 'border-neutral-300' : 'border-warning-500 text-warning-700']" />
                  <select v-model="it.oss_rate_type"
                    :title="it.oss_rate_type ? t('invoice.oss.rate_type') : t('invoice.oss.rate_type_missing_hint')"
                    :class="['h-7 shrink-0 px-1 border rounded text-xs bg-surface',
                             it.oss_rate_type ? 'border-neutral-300' : 'border-warning-500 text-warning-700']">
                    <option :value="null">{{ t('invoice.oss.rate_unknown') }}</option>
                    <option value="standard">{{ t('invoice.oss.rate_standard') }}</option>
                    <option value="reduced">{{ t('invoice.oss.rate_reduced') }}</option>
                    <option value="second_reduced">{{ t('invoice.oss.rate_second_reduced') }}</option>
                    <option value="parking">{{ t('invoice.oss.rate_parking') }}</option>
                  </select>
                  <!-- Prázdný typ sazby není kosmetika: na šabloně se dá dopočítat odvozením
                       až při generování, ale jen když najde tutéž zemi spotřeby. -->
                  <span v-if="!it.oss_rate_type" :title="t('recurring.oss_rate_type_missing_hint')"
                    class="shrink-0 px-1.5 py-0.5 rounded border bg-warning-50 text-warning-700 border-warning-500/40 whitespace-nowrap">
                    {{ t('invoice.oss.rate_type_missing') }}
                  </span>
                  <select v-model="it.oss_supply_type" :title="t('invoice.oss.supply_type')"
                    class="h-7 shrink-0 px-1 border border-neutral-300 rounded text-xs bg-surface">
                    <option value="goods">{{ t('invoice.oss.goods') }}</option>
                    <option value="services">{{ t('invoice.oss.services') }}</option>
                  </select>
                </div>
              </td>
            </tr>
            </template>
          </tbody>
        </table>
        </div>

        <!-- Mobile: stack karet (každé pole na vlastním řádku, čitelné inputy) -->
        <div v-if="form.items.length > 0" class="md:hidden divide-y divide-neutral-200 border-t border-neutral-200">
          <div v-for="(it, idx) in form.items" :key="`m-${idx}`" :class="['py-3 space-y-2', itemHasBothNegative(it) ? 'bg-danger-50' : '']">
            <div class="flex items-center justify-between text-xs text-neutral-500">
              <span class="font-mono">#{{ idx + 1 }}</span>
              <button type="button" @click="removeItem(idx)" class="cursor-pointer w-8 h-8 inline-flex items-center justify-center border border-danger-500/40 text-danger-500 hover:bg-danger-50 rounded text-lg leading-none">×</button>
            </div>
            <div v-if="priceListEnabled && (hasPriceList || it.price_list_item_id)" class="space-y-2">
              <label class="block text-xs font-medium text-neutral-600">{{ t('recurring.catalog_item') }}</label>
              <select v-model.number="it.price_list_item_id" class="w-full h-10 px-3 border border-neutral-300 rounded bg-surface text-sm" @change="applyCatalogItem(it, idx)">
                <option :value="null">{{ t('recurring.catalog_manual') }}</option>
                <option v-for="catalogItem in priceListItems" :key="catalogItem.id" :value="catalogItem.id">{{ catalogItem.code }} · {{ catalogItem.name }}</option>
              </select>
              <div v-if="it.price_list_item_id" class="grid grid-cols-2 gap-2">
                <select v-model="it.catalog_policy" class="h-10 px-2 border border-neutral-300 rounded bg-surface text-xs">
                  <option value="fixed">{{ t('recurring.catalog_policy_fixed') }}</option>
                  <option value="current">{{ t('recurring.catalog_policy_current') }}</option>
                  <option value="review_required">{{ t('recurring.catalog_policy_review_required') }}</option>
                </select>
                <select v-model="it.description_source" class="h-10 px-2 border border-neutral-300 rounded bg-surface text-xs">
                  <option value="catalog">{{ t('recurring.catalog_description_catalog') }}</option>
                  <option value="template">{{ t('recurring.catalog_description_template') }}</option>
                </select>
              </div>
              <button v-if="it.price_list_item_id && it.catalog_policy === 'review_required'" type="button" class="text-left text-xs text-primary-700 hover:underline" @click="applyCatalogItem(it, idx, true)">
                {{ t('recurring.catalog_accept_changes') }}
              </button>
              <p v-if="it.price_list_item_id" class="text-xs text-neutral-500">
                {{ t('recurring.catalog_snapshot', { currency: it.catalog_source_currency_code ?? '—', price: it.catalog_source_unit_price ?? '—' }) }}
              </p>
              <p v-if="it.catalog_policy === 'review_required' && catalogSnapshotChanged(it)" class="text-xs text-warning-700">
                {{ catalogCurrentLabel(it) }}
              </p>
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('invoice.items_table.description') }}</label>
              <input v-model="it.description" type="text" data-row-input="rec-item" :disabled="priceListEnabled && !!it.price_list_item_id && it.description_source === 'catalog'" class="w-full h-10 px-3 border border-neutral-300 rounded bg-surface text-sm disabled:bg-neutral-100" />
            </div>
            <div class="grid grid-cols-2 gap-2">
              <div>
                <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('invoice.items_table.qty') }}</label>
                <input v-model="it.quantity" v-math type="text" inputmode="decimal" :class="['w-full h-10 px-3 border rounded bg-surface text-right font-mono text-sm', itemHasBothNegative(it) ? 'border-danger-400' : 'border-neutral-300']" />
              </div>
              <div>
                <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('invoice.items_table.unit') }}</label>
                <select v-model="it.unit" :disabled="priceListEnabled && !!it.price_list_item_id" class="w-full h-10 px-2 border border-neutral-300 rounded bg-surface text-sm disabled:bg-neutral-100">
                  <option v-for="u in units" :key="u.id" :value="u.code">{{ u.code }}</option>
                  <option v-if="it.unit && !units.some(u => u.code === it.unit)" :value="it.unit">{{ it.unit }}</option>
                </select>
              </div>
            </div>
            <div :class="supplierIsVatPayer ? 'grid grid-cols-2 gap-2' : ''">
              <div>
                <label class="block text-xs font-medium text-neutral-600 mb-1">{{ unitPriceHeaderLabel }}</label>
                <input v-model="it.unit_price_without_vat" v-math type="text" inputmode="decimal" :disabled="priceListEnabled && !!it.price_list_item_id" :class="['w-full h-10 px-3 border rounded bg-surface text-right font-mono text-sm disabled:bg-neutral-100', itemHasBothNegative(it) ? 'border-danger-400' : 'border-neutral-300']" />
              </div>
              <div v-if="supplierIsVatPayer">
                <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('invoice.items_table.vat') ?? 'DPH' }}</label>
                <select v-model.number="it.vat_rate_id" :disabled="priceListEnabled && !!it.price_list_item_id" class="w-full h-10 px-2 border border-neutral-300 rounded bg-surface text-sm disabled:bg-neutral-100">
                  <option v-for="r in vatRatesForItem(it)" :key="r.id" :value="r.id">{{ vatRateLabel(r) }}</option>
                  <option v-if="!vatRatesForItem(it).some(r => r.id === it.vat_rate_id)" :value="it.vat_rate_id">
                    {{ vatRates.find(r => r.id === it.vat_rate_id) ? vatRateLabel(vatRates.find(r => r.id === it.vat_rate_id)!) : '—' }}
                  </option>
                </select>
              </div>
            </div>
            <div v-if="ossAvailable || it.oss_applicable" class="border border-neutral-200 rounded-md p-2">
              <label class="inline-flex items-center gap-2 text-sm">
                <input v-model="it.oss_applicable" type="checkbox" class="rounded border-neutral-300 text-primary-600"
                  @change="onOssApplicableChange(it)" />
                <span>{{ t('invoice.oss.enabled') }}</span>
              </label>
              <div v-if="it.oss_applicable" class="grid grid-cols-2 gap-2 mt-2">
                <div>
                  <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('invoice.oss.country') }}</label>
                  <input v-model="it.oss_consumer_country" type="text" maxlength="2"
                    :class="['w-full h-10 px-3 border rounded text-sm font-mono uppercase',
                             (it.oss_consumer_country || '').trim() ? 'border-neutral-300' : 'border-warning-500 text-warning-700']" />
                </div>
                <div>
                  <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('invoice.oss.supply_type') }}</label>
                  <select v-model="it.oss_supply_type" class="w-full h-10 px-2 border border-neutral-300 rounded text-sm bg-surface">
                    <option value="goods">{{ t('invoice.oss.goods') }}</option>
                    <option value="services">{{ t('invoice.oss.services') }}</option>
                  </select>
                </div>
                <div class="col-span-2">
                  <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('invoice.oss.rate_type') }}</label>
                  <select v-model="it.oss_rate_type"
                    :class="['w-full h-10 px-2 border rounded text-sm bg-surface',
                             it.oss_rate_type ? 'border-neutral-300' : 'border-warning-500 text-warning-700']">
                    <option :value="null">{{ t('invoice.oss.rate_unknown') }}</option>
                    <option value="standard">{{ t('invoice.oss.rate_standard') }}</option>
                    <option value="reduced">{{ t('invoice.oss.rate_reduced') }}</option>
                    <option value="second_reduced">{{ t('invoice.oss.rate_second_reduced') }}</option>
                    <option value="parking">{{ t('invoice.oss.rate_parking') }}</option>
                  </select>
                  <p v-if="!it.oss_rate_type" class="mt-1 text-xs text-warning-700">
                    {{ t('recurring.oss_rate_type_missing_hint') }}
                  </p>
                </div>
              </div>
            </div>
          </div>
        </div>
        <!-- Šablona se zakládá jednou a generuje roky, registrace do OSS má konec platnosti.
             Bez téhle věty uživatel čeká, že mu OSS pojede i po jejím skončení. -->
        <div v-if="hasOssItem" class="mt-3 rounded-md border border-neutral-200 bg-neutral-50 px-3 py-2 text-xs text-neutral-600">
          {{ t('recurring.oss_registration_hint') }}
        </div>
        <dl v-if="form.items.length > 0" class="mt-4 ml-auto max-w-xs space-y-1 text-sm">
          <div v-if="computedDiscountAmount > 0" class="flex justify-between text-warning-700">
            <dt>{{ t('invoice.discount.applied') }} {{ form.discount_percent }} %</dt>
            <dd class="font-mono">−{{ formatMoney(computedDiscountAmount, currencyCode) }}</dd>
          </div>
          <div class="flex justify-between font-semibold border-t border-neutral-200 pt-1">
            <dt>{{ t('invoice.totals.total') }}</dt>
            <dd class="font-mono">{{ formatMoney(computedAmountToPay, currencyCode) }}</dd>
          </div>
        </dl>
        <div v-if="hasNonPositiveAmountToPay" class="mt-3 rounded-md border border-warning-200 bg-warning-50 px-3 py-2 text-xs text-warning-700">
          {{ t('invoice.amount_positive_required') }}
        </div>
      </div>

      <!-- Poznámky nad/pod položkami — stejná editace jako u vydané faktury;
           generátor je přenáší na fakturu a vyhodnocuje v nich placeholdery období. -->
      <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm space-y-4">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('recurring.section_notes') }}</h3>
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('invoice.note_above') }}</label>
          <textarea v-model="form.note_above_items" rows="2" class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm"></textarea>
        </div>
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('invoice.note_below') }}</label>
          <textarea v-model="form.note_below_items" rows="2" class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm"></textarea>
        </div>
        <p class="text-xs text-neutral-500">{{ t('recurring.notes_placeholders_hint') }}</p>
      </div>

      <!-- Automation -->
      <div class="bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm space-y-3">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('recurring.section_automation') }}</h3>

        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('recurring.draft_open_mode') }}</label>
          <select v-model="form.draft_open_mode"
            class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface">
            <option value="at_issue">{{ t('recurring.draft_open_mode_at_issue') }}</option>
            <option value="period_start" :disabled="form.frequency !== 'monthly'">
              {{ t('recurring.draft_open_mode_period_start') }}
            </option>
          </select>
          <p class="mt-1 text-xs text-neutral-500">
            {{ form.frequency === 'monthly' ? t('recurring.draft_open_mode_hint') : t('recurring.draft_open_mode_monthly_only') }}
          </p>
        </div>

        <div v-if="form.draft_open_mode === 'period_start'">
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('recurring.reminder_days_before') }}</label>
          <input v-model.number="form.reminder_days_before" type="number" min="0" max="14"
            class="w-full md:w-40 h-10 px-3 border border-neutral-300 rounded-md" />
          <p class="mt-1 text-xs text-neutral-500">{{ t('recurring.reminder_days_before_hint') }}</p>
        </div>

        <label class="flex items-start gap-2 text-sm text-neutral-700">
          <input v-model="form.increment_month_in_descriptions" type="checkbox" class="mt-1 rounded border-neutral-300 text-primary-600" />
          <span>{{ t('recurring.increment_month') }}</span>
        </label>
        <label class="flex items-start gap-2 text-sm text-neutral-700">
          <input v-model="form.auto_issue" type="checkbox" class="mt-1 rounded border-neutral-300 text-primary-600" />
          <span>{{ t('recurring.auto_issue') }}</span>
        </label>
        <label class="flex items-start gap-2 text-sm text-neutral-700">
          <input v-model="form.auto_send_email" type="checkbox" :disabled="!form.auto_issue"
            class="mt-1 rounded border-neutral-300 text-primary-600 disabled:opacity-50" />
          <span :class="!form.auto_issue ? 'text-neutral-400' : ''">{{ t('recurring.auto_send_email') }}</span>
        </label>
      </div>

      <div v-if="error" data-error-banner class="p-3 bg-danger-50 border border-danger-200 text-danger-700 rounded text-sm">{{ error }}</div>

      <div class="flex justify-end gap-3">
        <button type="button" @click="router.push({ name: 'recurring' })"
          class="cursor-pointer px-4 h-10 text-sm border border-neutral-300 rounded-md text-neutral-700 hover:bg-neutral-50">
          {{ t('common.cancel') }}
        </button>
        <button type="submit" :disabled="submitting"
          class="cursor-pointer px-4 h-10 text-sm bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md">
          {{ submitting ? '…' : t('common.save') }}
        </button>
      </div>
    </form>

    <!-- Inline create modaly — žádné opouštění editoru -->
    <ClientFormModal v-if="clientModalOpen"
      @created="onClientCreatedInModal"
      @close="clientModalOpen = false" />
    <ProjectFormModal v-if="projectModalOpen && form.client_id"
      :client-id="form.client_id"
      @created="onProjectCreatedInModal"
      @close="projectModalOpen = false" />
  </div>
</template>
