import { requestSupplierId } from '@/workspace/supplierContext'
import { api } from './client'
import type { CatalogJob } from './catalogJobs'
import type { ProductSetDefinition } from './eshop'
import { downloadApiFilePost } from '@/utils/downloadFile'

/**
 * Skladová evidence (Epic SKLAD). Money/qty pole jsou DECIMAL uložené na backendu
 * jako string (money-safe vzor, žádné floatování) — v TS proto vždy `string`, ne `number`.
 * Vše pod `X-Supplier-Id` hlavičkou (auto přes api/client.ts interceptor).
 */

export type StockItemType = 'material' | 'goods' | 'product' | 'service'
/** Druh položky pro EET: běžné zboží, dárkový poukaz na konkrétní plnění, hodnotový poukaz / kredit. */
export type StockItemEetKind = 'standard' | 'specific_voucher' | 'stored_value'
export type StockItemLifecycleStatus = 'draft' | 'ready' | 'retired'
export type StockTrackingMode = 'none' | 'lot' | 'serial'
export type StockDocType = 'receipt' | 'issue' | 'transfer'
export type StockDocOrigin = 'manual' | 'invoice' | 'credit_note' | 'purchase_invoice' | 'inventory' | 'purchase_order'
export type StockDocStatus = 'draft' | 'posted' | 'reversed'
export type StockTakeStatus = 'draft' | 'preparing' | 'counting' | 'closed'
export type LandedCostAllocation = 'by_value' | 'by_qty'

/**
 * Platná cena karty doplněná backendem (EffectivePriceResolver, migrace 1328).
 * `effective_price` je JEDINÁ cena, kterou smí UI nabízet do dokladu —
 * `sale_price_without_vat` je jen standardní hladina bez akcí.
 */
export interface StockItemEffectivePrice {
  effective_price?: string | null
  promo_price?: string | null
  promo_label?: string | null
  promo_qty_available?: string | null
}

/** Kde karta vyhověla hledání, když ne v kódu, názvu ani EAN (od 3 znaků). */
export interface StockSearchMatch {
  kind: 'serial' | 'lot' | 'attribute'
  value: string
  /** Název parametru u `kind = attribute`. */
  attribute: string | null
}

export interface StockItem extends StockItemEffectivePrice {
  id: number
  supplier_id: number
  sku: string
  name: string
  item_type: StockItemType
  eet_item_kind?: StockItemEetKind
  manufacturer_id: number | null
  unit: string
  tracking_mode?: StockTrackingMode
  ean: string | null
  vat_rate_id: number | null
  sale_price_without_vat: string | null
  min_qty: string | null
  intrastat_cn8_code?: string | null
  intrastat_country_of_origin?: string | null
  intrastat_net_mass_kg?: string | null
  intrastat_supplementary_unit?: string | null
  intrastat_supplementary_unit_coefficient?: string | null
  qty?: string
  value_total?: string
  avg_unit_cost?: string
  is_active: boolean
  is_stocked: boolean
  /** Výchozí prodejní jednotka (kód balení); null = základní jednotka. */
  default_sale_unit?: string | null
  /** Karta má individuální ceny zákazníků. */
  has_customer_prices?: boolean
  lifecycle_status?: StockItemLifecycleStatus
  retired_at?: string | null
  row_version: number
  note: string | null
  created_at: string
  updated_at: string
  /** Jen ve výsledku hledání (`q`). */
  search_match?: StockSearchMatch | null
  /** Jen se `display_attribute_id`: hodnota zvoleného parametru jako text (bool = '1'/'0'). */
  display_attribute_value?: string | null
}

export interface StockItemPayload {
  /** Aktuální verze karty pro klientský zápis. */
  row_version?: number
  sku?: string
  name: string
  item_type: StockItemType
  eet_item_kind?: StockItemEetKind
  unit: string
  tracking_mode?: StockTrackingMode
  ean?: string | null
  vat_rate_id?: number | null
  sale_price_without_vat?: string | number | null
  min_qty?: string | number | null
  intrastat_cn8_code?: string | null
  intrastat_country_of_origin?: string | null
  intrastat_net_mass_kg?: string | number | null
  intrastat_supplementary_unit?: string | null
  intrastat_supplementary_unit_coefficient?: string | number | null
  is_active?: boolean
  note?: string | null
}

export type IntrastatDirection = 'dispatch' | 'arrival'
export type IntrastatIssueSeverity = 'error' | 'warning'

export interface IntrastatExportRequest {
  period: string
  direction: IntrastatDirection
  transaction_code: string
  transport_mode: string
  delivery_terms: string
  record_type: string
  statistical_code: string
}

export interface IntrastatPreviewIssue {
  severity: IntrastatIssueSeverity
  code: string
  message: string
  row_number?: number | null
}

export interface IntrastatPreviewRow {
  row_number: number
  source_document: {
    id: number
    number: string | null
    date: string
    line_id: number
    type: StockDocType
  }
  partner_name: string | null
  partner_country: string
  partner_vat_id: string
  cn8_code: string
  country_of_origin: string
  description: string
  quantity: string | null
  net_mass_kg: string | null
  supplementary_unit: string | null
  supplementary_quantity: string | null
  invoiced_value: number | null
  issues: IntrastatPreviewIssue[]
}

export interface IntrastatPreview {
  summary: {
    row_count: number
    error_count: number
    warning_count: number
    total_net_mass_kg: string
    total_invoiced_value: number
  }
  rows: IntrastatPreviewRow[]
  issues: IntrastatPreviewIssue[]
}

export interface StockItemDuplicateRequest {
  sku: string
  name: string
  row_version: number
  sections: Array<'core' | 'product' | 'i18n' | 'categories' | 'tags' | 'attributes' | 'fees' | 'prices' | 'vendors'>
}

export type StockItemCopySection = StockItemDuplicateRequest['sections'][number]

export interface StockItemTemplate {
  id: number
  name: string
  sections: StockItemCopySection[]
  row_version: number
  created_at: string
  updated_at: string
}

export interface ProductAssembly {
  id: number
  supplier_id: number
  stock_item_id: number
  warehouse_id: number
  quantity: string
  status: 'posted' | 'reversed'
  value_total: string
  issue_document_id: number
  receipt_document_id: number
  definition: ProductSetDefinition
  components: Array<Record<string, unknown>>
  created_at: string
}

export interface ProductAssemblyCreatePayload {
  operation_key: string
  stock_item_id: number
  warehouse_id: number
  doc_date: string
  quantity: string
  definition: ProductSetDefinition
  selections?: Record<string, Record<string, string[]>>
  component_tracking_allocations?: Record<string, TrackingAllocationInput[]>
  product_tracking_allocations?: TrackingAllocationInput[]
}

export interface ProductAssemblyListResponse {
  items: ProductAssembly[]
  pagination: { page: number; limit: number; total: number; pages: number }
}

export interface StockItemSearchResult extends StockItemEffectivePrice {
  item_type?: StockItemType
  id: number
  sku: string
  name: string
  unit: string
  tracking_mode?: StockTrackingMode
  vat_rate_id: number | null
  sale_price_without_vat: string | null
  /** Balení karty (nadřazené jednotky) s poměrem k základní jednotce `unit`. */
  units?: StockItemPackagingUnit[]
  /** Výchozí prodejní jednotka; null = základní jednotka. */
  default_sale_unit?: string | null
  /** Kód balení, když dotaz přesně odpovídá EAN balení; jinak null. */
  matched_unit?: string | null
  /** Karta má individuální ceny zákazníků (zapíná nacenění v editoru faktury). */
  has_customer_prices?: boolean
  search_match?: StockSearchMatch | null
  /** Karta hlídá stav skladu (false = neskladová položka, `qty` null). */
  is_stocked?: boolean
  /** Stav v základní jednotce jako GET /stock/availability (s `warehouse_id` jen ten sklad). */
  qty?: string | null
}

/**
 * Balení skladové karty (issue #17). `numerator/denominator` je přesný zlomek:
 * základní množství = qty × numerator / denominator. `factor` je týž poměr jako
 * desetinný string (max 3 desetinná místa) pro formulář a výstup.
 */
export interface StockItemPackagingUnit {
  unit_code: string
  name: string | null
  factor: string
  numerator: number
  denominator: number
  ean: string | null
  /** Kód používají vystavené doklady karty, backend proto odmítne jeho odebrání i změnu poměru. */
  in_use?: boolean
}

/** Účet a dimenze, které položka dokladu dostane z produktu (produkt > kategorie). */
export interface StockItemPostingDefaults {
  own_revenue_account_code: string | null
  own_expense_account_code: string | null
  revenue_account_code: string | null
  revenue_account_source: 'product' | 'product_category' | null
  expense_account_code: string | null
  expense_account_source: 'product' | 'product_category' | null
  dimensions: Record<number, number>
  dimension_sources: Record<number, 'product' | 'product_category'>
}

export interface StockItemPackaging {
  base_unit: string
  default_sale_unit: string | null
  units: StockItemPackagingUnit[]
}

export interface StockItemPackagingPayload {
  default_sale_unit: string | null
  units: Array<{ unit_code: string; factor: string; ean: string | null }>
}

export type StockCustomerPriceType = 'fixed' | 'discount_pct'

/** Individuální cena zákazníka, VŽDY za základní jednotku a bez DPH. */
export interface StockItemCustomerPrice {
  id: number
  client_id: number
  client_name: string | null
  currency_code: string
  price_type: StockCustomerPriceType
  fixed_price: string | null
  discount_pct: string | null
  valid_from: string | null
  valid_to: string | null
  note: string | null
  /** Dnešní výsledná cena za základní jednotku (dopočet backendem). */
  resulting_price: string | null
}

export interface StockItemCustomerPricePayload {
  client_id: number
  currency_code: string
  price_type: StockCustomerPriceType
  fixed_price?: string | null
  discount_pct?: string | null
  valid_from?: string | null
  valid_to?: string | null
  note?: string | null
}

/** Cena karty pro jednu cenovou hladinu v jedné měně (náhled „dnes, množství 1, bez akce"). */
export interface StockItemPriceLevelRow {
  price_level_id: number
  code: string
  name: string
  is_active: boolean
  currency_code: string
  standard_price: string | null
  resulting_price: string | null
  /** Odkud výsledná cena pochází: výjimka karty, kategorie, výrobce, výchozí sleva hladiny, nic. */
  source: 'product' | 'category' | 'manufacturer' | 'default' | 'none'
  /** Název kategorie / výrobce, pokud ho backend pošle. */
  source_label?: string | null
  rule: StockPriceLevelRuleSummary | null
  /** Výchozí sleva hladiny v %. */
  default_discount_pct?: string
  /** Výjimka této karty (pravidlo match_type='product') platná pro danou měnu. */
  product_rule?: StockPriceLevelRuleSummary | null
  /** Cena a zdroj, které by platily bez výjimky karty. */
  inherited_price?: string | null
  inherited_source?: 'product' | 'category' | 'manufacturer' | 'default' | 'none'
}

export interface StockPriceLevelRuleSummary {
  rule_type: 'discount_pct' | 'fixed'
  discount_pct: string | null
  fixed_price: string | null
  currency_code: string | null
  /** Na co pravidlo míří (kategorie / výrobce / produkt), pokud ho backend pošle. */
  match_type?: 'product' | 'category' | 'manufacturer'
  match_id?: number
  match_label?: string | null
}

/**
 * Výjimka karty pro hladinu (pravidlo match_type='product'). Položky se aplikují v pořadí:
 * upsert nahradí pravidlo své měny (bez měny = sleva ve všech měnách), `remove` bez
 * `currency_code` odebere všechna pravidla karty v hladině, s ním jen pravidlo té měny.
 */
export type StockItemPriceLevelPayload =
  | { price_level_id: number; rule_type: 'discount_pct' | 'fixed'; discount_pct?: string | null; fixed_price?: string | null; currency_code?: string | null }
  | { price_level_id: number; remove: true; currency_code?: string | null }

export interface StockQuotePriceLevel {
  id: number
  code: string
  name: string
}

export type StockQuotePriceSource = 'standard' | 'customer_fixed' | 'customer_discount' | 'promo' | 'price_level_fixed' | 'price_level_discount'

export interface StockQuoteRequestLine {
  key: string
  stock_item_id: number
  unit: string | null
  quantity: string
}

export interface StockQuoteRequest {
  client_id: number | null
  /** Cenová hladina zvolená na dokladu; null = hladina odběratele. */
  price_level_id?: number | null
  currency: string
  date: string | null
  lines: StockQuoteRequestLine[]
}

/** Nacenění řádku (POST /stock/items/quote), jediný zdroj ceny skladového řádku faktury. */
export interface StockQuoteLine {
  key: string
  stock_item_id: number
  /** Použitá jednotka (neznámá → základní). */
  unit: string | null
  base_unit: string
  numerator: number
  denominator: number
  base_quantity: string
  /** Cena bez DPH za ZVOLENOU jednotku; null = pro danou měnu karta cenu nemá. */
  unit_price: string | null
  base_unit_price: string
  /** Ceníková cena bez DPH za zvolenou jednotku (standardní cena před slevou, hladinou i akcí); null = neznámá. */
  list_unit_price?: string | null
  price_source: StockQuotePriceSource
  customer_price_id: number | null
  /** Sleva zákazníka nebo hladiny v % (price_source = customer_discount / price_level_discount). */
  discount_pct?: string | null
  /** Hladina odběratele, pokud cenu určila ona. */
  price_level?: StockQuotePriceLevel | null
  promo: { label: string | null; promo_price: string } | null
  promo_reason?: string | null
  promo_qty_available?: string | null
}

export interface StockQuoteResponse {
  lines: StockQuoteLine[]
}

export interface StockItemListFilters {
  type?: StockItemType
  active?: boolean
  q?: string
  only_below_min?: boolean
  warehouse_id?: number
  manufacturer_id?: number
  category_id?: number
  vendor_id?: number
  tag_ids?: number[]
  attribute_filters?: StockItemAttributeFilter[]
  missing?: Array<'manufacturer' | 'category' | 'image' | 'price' | 'ean'>
  availability?: 'in_stock' | 'out_of_stock' | 'below_min'
  qty_min?: string | number
  qty_max?: string | number
  sort?: 'sku' | 'name' | 'type' | 'qty' | 'value'
  direction?: 'asc' | 'desc'
  /** Volitelný sloupec seznamu s hodnotou jednoho parametru karty (nefiltruje). */
  display_attribute_id?: number
  page?: number
  per_page?: number
}

export interface StockItemNeighbors {
  previous_id: number | null
  next_id: number | null
  position: number | null
  total: number
}

export interface StockItemNeighborsOptions extends Omit<StockItemListFilters, 'page' | 'per_page'> {
  ids?: number[]
  excluded_ids?: number[]
}

export interface StockItemAttributeFilter {
  attribute_id: number
  option_id?: number
  value_text?: string
  value_bool?: boolean
  value_num_min?: string | number
  value_num_max?: string | number
}

/** Stránkovací meta blok (jednotný kontrakt, vzor invoicesApi.listGrouped). */
export interface StockPageMeta {
  total: number
  page?: number
  per_page?: number
  pages?: number
}

export interface Warehouse {
  id: number
  supplier_id: number
  code: string
  name: string
  is_default: boolean
  is_active: boolean
  is_sellable: boolean
  note: string | null
  created_at: string
  updated_at: string
  /** Hodnota skladu — Σ stock_levels.value_total (dopočtená BE). */
  value: string
}

export interface WarehousePayload {
  code: string
  name: string
  is_default?: boolean
  is_active?: boolean
  is_sellable?: boolean
  note?: string | null
}

export interface StockTrackingAllocation {
  stock_tracking_unit_id?: number
  quantity: string
  serial_number?: string
  lot_code?: string
  expires_on?: string
  location_id?: number
}

export type FulfillmentTaskStatus = 'picking' | 'packing' | 'partially_shipped' | 'shipped' | 'cancelled'
export type FulfillmentDisposition = 'sellable' | 'quarantine' | 'scrap'

export interface FulfillmentTaskLine {
  id: number
  task_id: number
  source_line_id: string
  stock_item_id: number
  warehouse_id: number
  component_snapshot: { sku: string; ean: string | null; name: string; unit: string; tracking_allocations: StockTrackingAllocation[] }
  expected_qty: string
  picked_qty: string
  shipped_qty: string
  returned_qty: string
}

export interface FulfillmentShipmentItem {
  id: number
  task_line_id: number
  qty: string
  returned_qty: string
  tracking_snapshot: StockTrackingAllocation[]
  component_snapshot: FulfillmentTaskLine['component_snapshot']
}

export interface FulfillmentReturn {
  id: number
  disposition: FulfillmentDisposition
  warehouse_id: number | null
  receipt_document_id: number | null
  note: string | null
  received_at: string
  items: Array<{ id: number; shipment_item_id: number; qty: string; component_snapshot: Record<string, unknown> }>
}

export interface FulfillmentShipment {
  id: number
  task_id: number
  carrier: string | null
  tracking_number: string | null
  status: 'packing' | 'shipped' | 'cancelled'
  issue_document_id: number | null
  shipped_at: string | null
  items: FulfillmentShipmentItem[]
  returns: FulfillmentReturn[]
}

export interface FulfillmentTask {
  id: number
  source_type: 'stock_issue_draft' | 'sales_order'
  source_id: string
  source_snapshot: Record<string, unknown>
  status: FulfillmentTaskStatus
  line_count?: number
  expected_qty?: string
  picked_qty?: string
  shipped_qty?: string
  lines?: FulfillmentTaskLine[]
  shipments?: FulfillmentShipment[]
  created_at: string
  updated_at: string
}

export interface WarehouseLocation {
  id: number
  supplier_id: number
  warehouse_id: number
  code: string
  name: string
  is_active: boolean
}

export interface TrackingAllocationInput {
  stock_tracking_unit_id?: number | null
  quantity: string | number
  unit_code?: string
  serial_number?: string | null
  lot_code?: string | null
  expires_on?: string | null
  location_id?: number | null
  location_to_id?: number | null
}

export interface StockTrackingChoices {
  tracking_mode: StockTrackingMode
  base_unit: string
  in_stock: Array<{ serial_number: string | null; lot_code: string | null; expires_on: string | null; quantity: string }>
  /** Jen u dobropisu k faktuře: kusy vydané původní fakturou a dosud nevrácené; jinak null. */
  returnable: Array<{ serial_number: string | null; lot_code: string | null; expires_on: string | null; quantity: string }> | null
}

export interface StockTrackingOverview {
  tracking_mode: StockTrackingMode
  base_unit: string
  units: Array<{ id: number; unit_code: string; numerator: number; denominator: number }>
  inventory: Array<{ stock_tracking_unit_id: number; tracking_type: 'lot' | 'serial'; lot_code: string | null; serial_number: string | null; expires_on: string | null; warehouse_id: number; location_id: number | null; warehouse_code: string; location_code: string | null; quantity: string }>
  history: Array<Record<string, unknown>>
}

export interface StockLevelRow {
  warehouse_id: number
  stock_item_id: number
  qty: string
  value_total: string
  avg_unit_cost: string
  sku: string
  name: string
  unit: string
  item_type: StockItemType
  min_qty: string | null
  is_active: boolean
  warehouse_code: string
  warehouse_name: string
}

export interface StockLevelFilters {
  warehouse_id?: number
  item_type?: StockItemType
  below_min?: boolean
  active?: boolean
  q?: string
  /** Omezí na konkrétní karty (merge se stránkou items) — CSV na drátě. */
  item_ids?: number[]
  page?: number
  per_page?: number
}

export interface StockLedgerRow {
  line_id: number
  document_id: number
  doc_number: string | null
  doc_type: StockDocType
  origin: StockDocOrigin
  status: StockDocStatus
  doc_date: string
  line_no: number
  warehouse_id: number
  warehouse_code: string
  qty_signed: string
  qty: string
  unit_cost: string
  value_total: string
  note: string | null
  /** Pohyb z převzaté historie skladu (import historických dokladů). */
  imported?: boolean
  /** Faktura/dobropis, který výdej či vratku vyvolal (null bez práva k fakturám). */
  invoice_id: number | null
  invoice_number: string | null
  invoice_type: string | null
  purchase_invoice_id: number | null
  purchase_invoice_number: string | null
  /** Odběratel (FV), dodavatel (PF) nebo volný text ručního dokladu. */
  partner: StockLedgerPartner | null
  /** Prodejní cena řádku faktury bez DPH za `sale_unit` v měně `sale_currency`. */
  sale_unit_price: string | null
  sale_unit: string | null
  sale_currency: string | null
  /** Doplněno FE (running balance dopočtená BE stránku po stránce). */
  balance_after?: string
}

export interface StockLedgerPartner {
  kind: 'client' | 'vendor' | 'text'
  id: number | null
  name: string
}

export interface StockItemMovementsResponse {
  items: StockLedgerRow[]
  opening_balance: string
  limit: number
  offset: number
}

export interface StockDocumentLine {
  id?: number
  document_id?: number
  supplier_id?: number
  stock_item_id: number
  doc_date?: string | null
  qty: string
  unit_cost?: string
  value_total?: string
  extra_cost?: string
  invoice_item_id?: number | null
  purchase_invoice_item_id?: number | null
  purchase_order_line_id?: number | null
  source_description?: string | null
  source_qty?: string | null
  line_no?: number
  note?: string | null
  tracking_mode?: StockTrackingMode
  tracking_allocations?: TrackingAllocationInput[]
  // joined (read-only, jen v odpovědi)
  sku?: string
  name?: string
  unit?: string
}

export interface StockDocument {
  id: number
  supplier_id: number
  doc_type: StockDocType
  origin: StockDocOrigin
  warehouse_id: number
  warehouse_to_id: number | null
  doc_number: string | null
  doc_date: string
  description: string
  partner_name: string | null
  invoice_id: number | null
  purchase_invoice_id: number | null
  purchase_order_id: number | null
  stock_take_id: number | null
  /** Úloha importu historie skladu, která doklad převzala (převzatý doklad nejde stornovat). */
  history_import_job_id?: number | null
  journal_entry_id: number | null
  reversal_document_id: number | null
  status: StockDocStatus
  booked_at: string | null
  booked_by: number | null
  created_by: number | null
  created_at: string
  updated_at: string
  allow_over_delivery: boolean
  warehouse_code?: string | null
  warehouse_name?: string | null
  warehouse_to_code?: string | null
  warehouse_to_name?: string | null
  lines?: StockDocumentLine[]
  warnings?: string[]
}

export interface StockDocumentPayload {
  doc_type: StockDocType
  origin?: StockDocOrigin
  doc_date: string
  description: string
  warehouse_id: number
  warehouse_to_id?: number | null
  partner_name?: string | null
  invoice_id?: number
  purchase_invoice_id?: number
  stock_take_id?: number
  lines: Array<{
    stock_item_id: number
    qty: string | number
    unit_cost?: string | number
    extra_cost?: string | number
    invoice_item_id?: number
    purchase_invoice_item_id?: number
    source_description?: string
    source_qty?: string | number
    note?: string | null
    tracking_allocations?: TrackingAllocationInput[]
  }>
}

export interface StockCycleCount {
  id: number
  warehouse_id: number
  location_id: number | null
  /** Rozsah: kategorie včetně podkategorií (null = bez omezení). */
  category_id?: number | null
  category_name?: string | null
  status: 'draft' | 'queued' | 'running' | 'counting' | 'closed' | 'cancelled' | 'failed'
  preparation_job_id: number | null
  preparation_job?: CatalogJob | null
  take_date: string
  note: string | null
  lines: Array<{ id: number; stock_item_id: number; stock_tracking_unit_id: number | null; sku: string; name: string; unit: string; tracking_mode: StockTrackingMode; lot_code: string | null; serial_number: string | null; expires_on: string | null; expected_qty: string; counted_qty: string | null; surplus_unit_cost: string | null }>
}

export interface StockDocumentListFilters {
  doc_type?: StockDocType
  status?: StockDocStatus
  origin?: StockDocOrigin
  q?: string
  from?: string
  to?: string
  warehouse_id?: number
  limit?: number
  offset?: number
}

export interface StockDocumentListResponse {
  items: StockDocument[]
  total: number
  limit: number
  offset: number
}

export interface StockTakeLine {
  id: number
  stock_take_id: number
  supplier_id: number
  stock_item_id: number
  expected_qty: string
  expected_value: string
  counted_qty: string | null
  surplus_unit_cost: string | null
  item_sku: string
  item_name: string
  item_unit: string
  diff_qty: string | null
}

export interface StockTake {
  id: number
  supplier_id: number
  warehouse_id: number
  take_date: string
  status: StockTakeStatus
  note: string | null
  counting_method: 'physical_count' | 'measurement' | 'weighing' | 'other'
  responsible_count_name: string
  responsible_inventory_name: string
  started_at: string | null
  receipt_document_id: number | null
  issue_document_id: number | null
  created_by: number | null
  closed_by: number | null
  closed_at: string | null
  created_at: string
  updated_at: string
  preparation_job?: CatalogJob | null
  lines?: StockTakeLine[]
  receipt_document?: StockDocument | null
  issue_document?: StockDocument | null
}

/** Volitelný sloupec sestavy Stav zásob (parametr karty se vybírá přes attribute_id). */
export type StockStatusColumn = 'avg_unit_cost' | 'value_total' | 'sale_price' | 'note'

export interface StockStatusFilters extends StockLevelFilters {
  /** Kategorie karty včetně podkategorií. */
  category_id?: number
  /** Volitelné sloupce oddělené čárkou, 'none' = žádný; bez parametru průměrná cena a hodnota. */
  columns?: string
  attribute_id?: number
}

export interface StockStatusRow extends StockLevelRow {
  /** Jen když byl sloupec vyžádán. */
  sale_price?: string | null
  note?: string | null
  /** Hodnoty parametru spojené čárkou; u typu ano/ne 'true'/'false' (překládá UI). */
  attribute_value?: string | null
}

export interface StockStatusReport {
  items: StockStatusRow[]
  totals: { value_total: string; count: number }
  /** Jen při výběru sloupců: zobrazené volitelné sloupce, parametr jako 'attribute'. */
  columns?: Array<StockStatusColumn | 'attribute'>
  attribute?: { id: number; code: string; name: string; unit: string | null; data_type: string } | null
}

export interface StockSalesFilters {
  date_from?: string
  date_to?: string
  client_id?: number
  category_id?: number
  warehouse_id?: number
  stock_item_id?: number
  q?: string
  group_by?: 'none' | 'client' | 'item'
  page?: number
  per_page?: number
}

export interface StockSalesAmount { currency: string; total_without_vat: string }

export interface StockSalesRow {
  invoice_item_id: number
  invoice_id: number
  invoice_number: string | null
  invoice_type: 'invoice' | 'credit_note'
  issue_date: string
  tax_date: string
  client_id: number | null
  client_name: string
  stock_item_id: number
  sku: string
  name: string
  description: string
  warehouse_id: number | null
  qty: string
  unit: string
  unit_price: string
  total_without_vat: string
  currency: string
  /** Sériová čísla a šarže vydané k řádku faktury. */
  identifiers: string[]
}

export interface StockSalesGroup {
  /** client_id nebo stock_item_id; null = doklady bez odběratele. */
  id: number | null
  label: string
  /** SKU u seskupení podle karet. */
  code: string
  lines: number
  documents: number
  qty: string
  amounts: StockSalesAmount[]
}

export interface StockSalesReport {
  filters: Required<Pick<StockSalesFilters, 'date_from' | 'date_to' | 'group_by'>> & StockSalesFilters
  items: StockSalesRow[]
  groups: StockSalesGroup[]
  totals: { lines: number; qty: string; units: string[]; amounts: StockSalesAmount[] }
  pagination: { page: number; per_page: number; total: number; pages: number }
}

export interface StockValuationRow {
  warehouse_id: number
  warehouse_code: string
  warehouse_name: string
  stock_item_id: number
  sku: string
  name: string
  unit: string
  qty: string
  value_total: string
}

export interface StockValuationReport {
  date: string
  items: StockValuationRow[]
  totals: { value_total: string; count: number }
}

interface StockReceiptProposalBase {
  lines: Array<{
    purchase_invoice_item_id: number
    purchase_order_line_id: number | null
    stock_item_id: number | null
    description: string
    quantity: string
    already_received: string
    remaining_qty: string
    unit_cost: string
  }>
  cost_candidates: Array<{
    purchase_invoice_item_id: number
    description: string
    amount: string
  }>
}

export interface StockReceiptReadyProposal extends StockReceiptProposalBase {
  purchase_invoice: {
    id: number
    varsymbol: string | null
    vendor_invoice_number: string
    vendor_name: string | null
    currency_code: string
    exchange_rate: string | null
  }
  pf_changed_after_receipt: boolean
  purchase_invoice_id?: never
  not_receivable_kind?: never
}

export interface StockReceiptUnavailableProposal extends StockReceiptProposalBase {
  purchase_invoice_id: number
  not_receivable_kind: 'advance' | 'tax_document' | 'credit_note'
  purchase_invoice?: never
  pf_changed_after_receipt?: false
}

export type StockReceiptProposal = StockReceiptReadyProposal | StockReceiptUnavailableProposal

export interface StockReceiptLandedCost {
  purchase_invoice_id?: number
  purchase_invoice_item_id?: number
  description?: string
  amount: number
  allocation?: LandedCostAllocation
}

export interface StockReceiptPayload {
  warehouse_id: number
  doc_date: string
  description?: string
  lines: Array<{
    purchase_invoice_item_id: number
    stock_item_id?: number
    quantity: string | number
    unit_cost?: string | number
  }>
  landed_costs?: StockReceiptLandedCost[]
}

// ── Nabídky dodavatelů („u dodavatele", fáze 3) ──────────────────────────────

export type VendorAvailabilityState = 'in_stock' | 'on_order' | 'unavailable' | 'unknown'
export type VendorOfferDataSource = 'manual' | 'import' | 'feed'

/**
 * Zboží × dodavatel nad `stock_item_vendors`. `on_hand` je vlastní stav skladu
 * napříč sklady — karta bez jediného pohybu vrací „0", ne prázdno, aby šla
 * evidovat nabídka i u zboží, které se nikdy nekupovalo.
 */
export interface VendorOffer {
  id: number
  supplier_id: number
  stock_item_id: number
  client_id: number
  client_name: string
  sku: string
  item_name: string
  unit: string
  vendor_sku: string | null
  purchase_price: string | null
  currency_code: string
  delivery_days: number | null
  stock_qty: string | null
  /** Informativní razítko — hlášená skladovost platí, dokud ji dodavatel nezmění. */
  stock_qty_updated_at: string | null
  availability_state: VendorAvailabilityState
  min_order_qty: string | null
  package_qty: string | null
  price_valid_to: string | null
  data_source: VendorOfferDataSource
  is_active: boolean
  is_preferred: boolean
  note: string | null
  on_hand: string
  updated_at: string
}

/** Částečný update — pošli jen pole, která se mají změnit. */
export interface VendorOfferPatch {
  vendor_sku?: string | null
  purchase_price?: string | number | null
  currency_code?: string
  delivery_days?: number | null
  stock_qty?: string | number | null
  stock_qty_updated_at?: string | null
  availability_state?: VendorAvailabilityState
  min_order_qty?: string | number | null
  package_qty?: string | number | null
  price_valid_to?: string | null
  data_source?: VendorOfferDataSource
  is_active?: boolean
  is_preferred?: boolean
  note?: string | null
}

export interface VendorOfferPayload extends VendorOfferPatch {
  stock_item_id: number
  client_id: number
}

export interface VendorOfferFilters {
  stock_item_id?: number
  client_id?: number
  availability_state?: VendorAvailabilityState | ''
  active?: boolean
  preferred?: boolean
  q?: string
  limit?: number
  offset?: number
}

export interface VendorOfferListResponse {
  items: VendorOffer[]
  total: number
  limit: number
  offset: number
}

export interface VendorOfferImportRow {
  line: number
  key: string
  status: 'create' | 'update' | 'skip' | 'error'
  changes?: Record<string, { from: unknown; to: unknown }>
  message?: string
}

export interface VendorOfferImportReport {
  ok: boolean
  dry_run: boolean
  created: number
  updated: number
  skipped: number
  failed: number
  rows: VendorOfferImportRow[]
}

function toParams<T extends object>(f: T = {} as T): Record<string, string | number> {
  const out: Record<string, string | number> = {}
  for (const [k, v] of Object.entries(f)) {
    if (v === undefined || v === null || v === '') continue
    out[k] = typeof v === 'boolean' ? (v ? 1 : 0) : (v as string | number)
  }
  return out
}

function downloadUrl(path: string): string {
  const sid = requestSupplierId()
  const params = new URLSearchParams()
  if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
  const qs = params.toString()
  return `/api${path}${qs ? (path.includes('?') ? '&' : '?') + qs : ''}`
}

export const stockApi = {
  // ── Sklady ──────────────────────────────────────────────────────────────
  listWarehouses: (activeOnly = false) =>
    api.get<Warehouse[]>('/stock/warehouses', { params: activeOnly ? { active: 1 } : undefined }).then(r => r.data),
  getWarehouse: (id: number) => api.get<Warehouse>(`/stock/warehouses/${id}`).then(r => r.data),
  createWarehouse: (payload: WarehousePayload) => api.post<Warehouse>('/stock/warehouses', payload).then(r => r.data),
  updateWarehouse: (id: number, payload: WarehousePayload) => api.put<Warehouse>(`/stock/warehouses/${id}`, payload).then(r => r.data),
  deleteWarehouse: (id: number) => api.delete<{ deleted: true }>(`/stock/warehouses/${id}`).then(r => r.data),
  listLocations: (warehouseId?: number) => api.get<WarehouseLocation[]>('/stock/locations', { params: warehouseId ? { warehouse_id: warehouseId } : undefined }).then(r => r.data),
  createLocation: (payload: { warehouse_id: number; code: string; name: string; is_active?: boolean }) => api.post<WarehouseLocation>('/stock/locations', payload).then(r => r.data),
  updateLocation: (id: number, payload: { warehouse_id: number; code: string; name: string; is_active?: boolean }) => api.put<WarehouseLocation>(`/stock/locations/${id}`, payload).then(r => r.data),

  listFulfillmentTasks: () => api.get<{ items: FulfillmentTask[] }>('/stock/fulfillment/tasks').then(r => r.data.items),
  getFulfillmentTask: (id: number) => api.get<FulfillmentTask>(`/stock/fulfillment/tasks/${id}`).then(r => r.data),
  createFulfillmentTask: (sourceId: string | number, sourceType: FulfillmentTask['source_type'] = 'stock_issue_draft') => api.post<FulfillmentTask>('/stock/fulfillment/tasks', {
    source_type: sourceType, source_id: String(sourceId),
  }).then(r => r.data),
  scanFulfillmentItem: (taskId: number, payload: { client_operation_id: string; code: string; quantity?: string }) =>
    api.post<{ task_line_id: number; picked_qty: string; expected_qty: string; status: FulfillmentTaskStatus; replayed: boolean }>(`/stock/fulfillment/tasks/${taskId}/scan`, payload).then(r => r.data),
  createFulfillmentShipment: (taskId: number, payload: { carrier: string; tracking_number: string; items: Array<{ task_line_id: number; quantity: string; tracking_allocations?: StockTrackingAllocation[] }> }) =>
    api.post<FulfillmentShipment>(`/stock/fulfillment/tasks/${taskId}/shipments`, payload).then(r => r.data),
  dispatchFulfillmentShipment: (shipmentId: number) =>
    api.post<FulfillmentTask>(`/stock/fulfillment/shipments/${shipmentId}/dispatch`).then(r => r.data),
  receiveFulfillmentReturn: (shipmentId: number, payload: { disposition: FulfillmentDisposition; warehouse_id?: number; note?: string; items: Array<{ shipment_item_id: number; quantity: string; tracking_allocations?: StockTrackingAllocation[] }> }) =>
    api.post<FulfillmentReturn>(`/stock/fulfillment/shipments/${shipmentId}/returns`, payload).then(r => r.data),

  // ── Skladové karty ──────────────────────────────────────────────────────
  listItems: (filters: StockItemListFilters = {}, options: { signal?: AbortSignal } = {}) => {
    const { tag_ids, attribute_filters, missing, ...rest } = filters
    return api.get<{ data: StockItem[]; meta: StockPageMeta }>('/stock/items', {
      params: toParams({
        ...rest,
        tag_ids: tag_ids?.length ? tag_ids.join(',') : undefined,
        attribute_filters: attribute_filters?.length ? JSON.stringify(attribute_filters) : undefined,
        missing: missing?.length ? missing.join(',') : undefined,
      }),
      signal: options.signal,
    }).then(r => r.data)
  },
  itemNeighbors: (id: number, options: StockItemNeighborsOptions = {}, request: { signal?: AbortSignal } = {}) => {
    const { ids, excluded_ids, ...filters } = options
    return api.post<StockItemNeighbors>(`/stock/items/${id}/neighbors`, { filters, ids, excluded_ids }, { signal: request.signal }).then(r => r.data)
  },
  /** `warehouseId` = sklad, pro který server spočítá `qty` (sklad řádku dokladu). */
  searchItems: (q: string, limit = 50, warehouseId?: number | null) =>
    api.get<StockItemSearchResult[]>('/stock/items/search', {
      params: warehouseId ? { q, limit, warehouse_id: warehouseId } : { q, limit },
    }).then(r => r.data),
  getItem: (id: number, options: { signal?: AbortSignal } = {}) => api.get<StockItem>(`/stock/items/${id}`, { signal: options.signal }).then(r => r.data),
  createItem: (payload: StockItemPayload) => api.post<StockItem>('/stock/items', payload).then(r => r.data),
  updateItem: (id: number, payload: StockItemPayload) => api.put<StockItem>(`/stock/items/${id}`, payload).then(r => r.data),
  updateLifecycle: (id: number, status: StockItemLifecycleStatus, rowVersion: number) =>
    api.post<StockItem>(`/stock/items/${id}/lifecycle`, { status, row_version: rowVersion }).then(r => r.data),
  duplicateItem: (id: number, payload: StockItemDuplicateRequest) =>
    api.post<StockItem>(`/stock/items/${id}/duplicate`, payload).then(r => r.data),
  listItemTemplates: () => api.get<StockItemTemplate[]>('/stock/item-templates').then(r => r.data),
  saveItemTemplate: (id: number, payload: { name: string; row_version: number; sections: StockItemCopySection[] }) =>
    api.post<StockItemTemplate>(`/stock/items/${id}/templates`, payload).then(r => r.data),
  applyItemTemplate: (templateId: number, payload: { sku: string; name: string; row_version: number }) =>
    api.post<StockItem>(`/stock/item-templates/${templateId}/apply`, payload).then(r => r.data),
  deleteItemTemplate: (templateId: number, rowVersion: number) =>
    api.delete<{ deleted: true }>(`/stock/item-templates/${templateId}`, { params: { row_version: rowVersion } }).then(r => r.data),
  deleteItem: (id: number) => api.delete<{ deleted: true }>(`/stock/items/${id}`).then(r => r.data),
  itemMovements: (id: number, opts: { warehouse_id?: number; from?: string; to?: string; limit?: number; offset?: number } = {}) =>
    api.get<StockItemMovementsResponse>(`/stock/items/${id}/movements`, { params: toParams(opts) }).then(r => r.data),
  itemMovementsExportUrl: (id: number, format: 'pdf' | 'xlsx', opts: { warehouse_id?: number; from?: string; to?: string } = {}) => {
    const params = new URLSearchParams(toParams({ ...opts, format }) as Record<string, string>)
    return downloadUrl(`/stock/items/${id}/movements/export?${params.toString()}`)
  },
  itemTracking: (id: number) => api.get<StockTrackingOverview>(`/stock/items/${id}/tracking`).then(r => r.data),
  /** Nabídka kusů pro řádek faktury: skladem ve skladu, u dobropisu i kusy k vrácení (returnable). */
  trackingChoices: (id: number, params: { warehouse_id?: number | null; invoice_id?: number | null }) =>
    api.get<StockTrackingChoices>(`/stock/items/${id}/tracking-choices`, {
      params: { warehouse_id: params.warehouse_id ?? undefined, invoice_id: params.invoice_id ?? undefined },
    }).then(r => r.data),
  replaceItemUnits: (id: number, units: Array<{ unit_code: string; numerator: number; denominator: number }>) => api.put(`/stock/items/${id}/units`, { units }).then(r => r.data),

  // ── Výchozí účet a dimenze produktu (Účtování podle dimenzí, F1) ─────────
  getPostingDefaults: (id: number) =>
    api.get<StockItemPostingDefaults>(`/stock/items/${id}/posting-defaults`).then(r => r.data),
  savePostingAccounts: (id: number, payload: { revenue_account_code?: string | null; expense_account_code?: string | null }) =>
    api.put<{ revenue_account_code: string | null; expense_account_code: string | null }>(`/stock/items/${id}/posting-defaults`, payload).then(r => r.data),

  // ── Balení a individuální ceny zákazníků (issue #17) ────────────────────
  getItemPackaging: (id: number) =>
    api.get<StockItemPackaging>(`/stock/items/${id}/packaging`).then(r => r.data),
  replaceItemPackaging: (id: number, payload: StockItemPackagingPayload) =>
    api.put<StockItemPackaging>(`/stock/items/${id}/packaging`, payload).then(r => r.data),
  getCustomerPrices: (id: number) =>
    api.get<StockItemCustomerPrice[]>(`/stock/items/${id}/customer-prices`).then(r => r.data),
  /** Nahradí celou sadu individuálních cen karty (tělo je přímo pole řádků). */
  replaceCustomerPrices: (id: number, rows: StockItemCustomerPricePayload[]) =>
    api.put<StockItemCustomerPrice[]>(`/stock/items/${id}/customer-prices`, rows).then(r => r.data),
  getItemPriceLevels: (id: number) =>
    api.get<StockItemPriceLevelRow[]>(`/stock/items/${id}/price-levels`).then(r => r.data),
  /** Spravuje jen výjimky karty (pravidla match_type='product'); ostatní pravidla hladin nechá být. */
  replaceItemPriceLevels: (id: number, rows: StockItemPriceLevelPayload[]) =>
    api.put<StockItemPriceLevelRow[]>(`/stock/items/${id}/price-levels`, rows).then(r => r.data),
  quoteItems: (payload: StockQuoteRequest) =>
    api.post<StockQuoteResponse>('/stock/items/quote', payload).then(r => r.data),

  previewIntrastat: (payload: IntrastatExportRequest) =>
    api.post<IntrastatPreview>('/stock/intrastat/preview', payload).then(r => r.data),
  exportIntrastat: (payload: IntrastatExportRequest) =>
    downloadApiFilePost('/stock/intrastat/export', payload, `intrastat-${payload.period}-${payload.direction}.csv`),

  // ── Stavy zásob ─────────────────────────────────────────────────────────
  levels: (filters: StockLevelFilters = {}) => {
    const { item_ids, ...rest } = filters
    return api.get<{ data: StockLevelRow[]; meta: StockPageMeta }>('/stock/levels', {
      params: toParams({ ...rest, item_ids: item_ids?.length ? item_ids.join(',') : undefined }),
    }).then(r => r.data)
  },
  /** stock_item_id (jako string klíč) => dostupné množství (DECIMAL string). Chybějící = žádný stav. */
  availability: (itemIds: number[], warehouseId?: number) =>
    api.get<Record<string, string>>('/stock/availability', {
      params: toParams({ item_ids: itemIds.join(','), warehouse_id: warehouseId }),
    }).then(r => r.data),

  // ── Kompletace výrobku ──────────────────────────────────────────────────
  listAssemblies: (page = 1, limit = 25) =>
    api.get<ProductAssemblyListResponse>('/stock/assemblies', { params: { page, limit } }).then(r => r.data),
  getAssembly: (id: number) => api.get<ProductAssembly>(`/stock/assemblies/${id}`).then(r => r.data),
  createAssembly: (payload: ProductAssemblyCreatePayload) =>
    api.post<ProductAssembly>('/stock/assemblies', payload).then(r => r.data),
  reverseAssembly: (id: number) => api.post<ProductAssembly>(`/stock/assemblies/${id}/reverse`).then(r => r.data),

  // ── Skladové doklady ────────────────────────────────────────────────────
  listDocuments: (filters: StockDocumentListFilters = {}) =>
    api.get<StockDocumentListResponse>('/stock/documents', { params: toParams(filters) }).then(r => r.data),
  getDocument: (id: number) => api.get<StockDocument>(`/stock/documents/${id}`).then(r => r.data),
  createDocument: (payload: StockDocumentPayload) => api.post<StockDocument>('/stock/documents', payload).then(r => r.data),
  updateDocument: (id: number, payload: StockDocumentPayload) => api.put<StockDocument>(`/stock/documents/${id}`, payload).then(r => r.data),
  deleteDocument: (id: number) => api.delete<{ deleted: true }>(`/stock/documents/${id}`).then(r => r.data),
  postDocument: (id: number) => api.post<StockDocument>(`/stock/documents/${id}/post`).then(r => r.data),
  reverseDocument: (id: number, reason?: string) =>
    api.post<{ original: StockDocument; reversal: StockDocument }>(`/stock/documents/${id}/reverse`, { reason }).then(r => r.data),
  documentPdfUrl: (id: number) => downloadUrl(`/stock/documents/${id}/pdf`),
  documentsForInvoice: (invoiceId: number) =>
    api.get<StockDocument[]>(`/invoices/${invoiceId}/stock-documents`).then(r => r.data),

  // ── Inventury ───────────────────────────────────────────────────────────
  listTakes: (filters: { warehouse_id?: number; status?: StockTakeStatus } = {}) =>
    api.get<StockTake[]>('/stock/takes', { params: toParams(filters) }).then(r => r.data),
  getTake: (id: number) => api.get<StockTake>(`/stock/takes/${id}`).then(r => r.data),
  createTake: (payload: { warehouse_id: number; take_date: string; note?: string | null; counting_method: string; responsible_count_name: string; responsible_inventory_name: string }) =>
    api.post<StockTake>('/stock/takes', payload).then(r => r.data),
  updateTake: (id: number, lines: Array<{ id: number; counted_qty: string | number | null; surplus_unit_cost?: string | number | null }>, addItemIds: number[] = []) =>
    api.put<StockTake>(`/stock/takes/${id}`, addItemIds.length > 0 ? { lines, add_item_ids: addItemIds } : { lines }).then(r => r.data),
  startTake: (id: number) => api.post<StockTake>(`/stock/takes/${id}/start`).then(r => r.data),
  closeTake: (id: number) =>
    api.post<StockTake & { receipt_document: StockDocument | null; issue_document: StockDocument | null }>(`/stock/takes/${id}/close`).then(r => r.data),
  takePdfUrl: (id: number) => downloadUrl(`/stock/takes/${id}/pdf`),
  listCycleCounts: () => api.get<StockCycleCount[]>('/stock/cycle-counts').then(r => r.data),
  getCycleCount: (id: number) => api.get<StockCycleCount>(`/stock/cycle-counts/${id}`).then(r => r.data),
  createCycleCount: (payload: { warehouse_id: number; location_id?: number | null; category_id?: number | null; take_date: string; item_ids?: number[]; note?: string | null }) => api.post<StockCycleCount>('/stock/cycle-counts', payload).then(r => r.data),
  updateCycleCount: (id: number, lines: Array<{ id: number; counted_qty: string | number | null; surplus_unit_cost?: string | number | null }>) => api.put<StockCycleCount>(`/stock/cycle-counts/${id}`, { lines }).then(r => r.data),
  closeCycleCount: (id: number) => api.post<StockCycleCount>(`/stock/cycle-counts/${id}/close`).then(r => r.data),

  // ── Sestavy ─────────────────────────────────────────────────────────────
  reportStatus: (filters: StockStatusFilters = {}) =>
    api.get<StockStatusReport>('/stock/reports/status', { params: toParams(filters) }).then(r => r.data),
  reportValuation: (filters: { date?: string; warehouse_id?: number } = {}) =>
    api.get<StockValuationReport>('/stock/reports/valuation', { params: toParams(filters) }).then(r => r.data),
  reportSales: (filters: StockSalesFilters = {}) =>
    api.get<StockSalesReport>('/stock/reports/sales', { params: toParams(filters) }).then(r => r.data),
  reportExportUrl: (name: 'status' | 'valuation' | 'sales', format: 'pdf' | 'xlsx', filters: Record<string, unknown> = {}) => {
    const params = new URLSearchParams(toParams({ ...filters, format }) as Record<string, string>)
    return downloadUrl(`/stock/reports/${name}/export?${params.toString()}`)
  },

  // ── Příjem na sklad z přijaté faktury ───────────────────────────────────
  receiptPropose: (purchaseInvoiceId: number) =>
    api.get<StockReceiptProposal>(`/purchase-invoices/${purchaseInvoiceId}/stock-receipt`).then(r => r.data),
  receiptCreate: (purchaseInvoiceId: number, payload: StockReceiptPayload) =>
    api.post<StockDocument>(`/purchase-invoices/${purchaseInvoiceId}/stock-receipt`, payload).then(r => r.data),
  receiptList: (purchaseInvoiceId: number) =>
    api.get<StockDocument[]>(`/purchase-invoices/${purchaseInvoiceId}/stock-receipts`).then(r => r.data),

  // ── Nabídky dodavatelů („u dodavatele") ─────────────────────────────────
  listVendorOffers: (filters: VendorOfferFilters = {}) =>
    api.get<VendorOfferListResponse>('/stock/vendor-offers', { params: toParams(filters) }).then(r => r.data),
  getVendorOffer: (id: number) => api.get<VendorOffer>(`/stock/vendor-offers/${id}`).then(r => r.data),
  createVendorOffer: (payload: VendorOfferPayload) =>
    api.post<VendorOffer>('/stock/vendor-offers', payload).then(r => r.data),
  /** Partial update — klíč, který v payloadu není, se nemění. */
  patchVendorOffer: (id: number, payload: VendorOfferPatch) =>
    api.patch<VendorOffer>(`/stock/vendor-offers/${id}`, payload).then(r => r.data),
  deleteVendorOffer: (id: number) =>
    api.delete<{ deleted: true }>(`/stock/vendor-offers/${id}`).then(r => r.data),
  importVendorOffers: (file: File, dryRun: boolean) => {
    const fd = new FormData()
    fd.append('file', file)
    fd.append('dry_run', dryRun ? '1' : '0')
    return api.post<VendorOfferImportReport>('/stock/vendor-offers/import', fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then(r => r.data)
  },
}
