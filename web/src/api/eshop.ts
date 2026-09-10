import { api } from './client'
import type { StockItemPayload, StockTrackingMode } from './stock'

/**
 * E-shop číselníky (Epic ESHOP).
 */

export interface Manufacturer {
  id: number
  code: string
  name: string
  website: string | null
  display_order: number
  export_eshop: boolean
  archived: boolean
}

export interface ManufacturerPayload {
  code: string
  name: string
  website?: string | null
  display_order: number
  export_eshop: boolean
  archived: boolean
}

export interface EshopLocale {
  id: number
  code: string
  name: string
  display_order: number
  is_default: boolean
  archived: boolean
}

export interface EshopLocalePayload {
  code: string
  name: string
  display_order?: number
  is_default?: boolean
  archived: boolean
}

/** Prodejní měna e-shopu — číselník cen, nezávislý na měnových účtech dodavatele. */
export interface EshopCurrency {
  id: number
  code: string
  name: string
  symbol: string | null
  display_order: number
  is_default: boolean
  archived: boolean
}

export interface EshopCurrencyPayload {
  code: string
  name: string
  symbol?: string | null
  display_order?: number
  is_default?: boolean
  archived: boolean
}

export interface Tag {
  id: number
  code: string
  name: string
  color: string | null
  archived: boolean
}

export interface TagPayload {
  code: string
  name: string
  color?: string | null
  archived: boolean
}

export interface FeeType {
  id: number
  code: string
  name: string
  vat_rate_id: number | null
  archived: boolean
}

export interface FeeTypePayload {
  code: string
  name: string
  vat_rate_id?: number | null
  archived: boolean
}

export interface Attribute {
  id: number
  code: string
  name: string
  data_type: 'text' | 'number' | 'bool' | 'enum'
  unit: string | null
  is_filterable: boolean
  is_multivalue: boolean
  display_order: number
  archived: boolean
}

export interface AttributePayload {
  code: string
  name: string
  data_type: 'text' | 'number' | 'bool' | 'enum'
  unit?: string | null
  is_filterable: boolean
  is_multivalue: boolean
  display_order: number
  archived: boolean
}

export interface AttributeOption {
  id: number
  attribute_id: number
  code: string
  label: string
  display_order: number
}

export interface AttributeOptionPayload {
  code: string
  label: string
  display_order: number
}

export interface Category {
  id: number
  parent_id: number | null
  code: string
  name: string
  path: string
  depth: number
  display_order: number
  export_eshop: boolean
  archived: boolean
}

export interface CategoryPayload {
  parent_id?: number | null
  code: string
  name: string
  display_order: number
  export_eshop: boolean
  archived: boolean
}

export interface CategoryI18nRow {
  locale: string
  name: string | null
  description?: string | null
  seo_title?: string | null
  seo_description?: string | null
  seo_slug?: string | null
}

// ── Karta zboží (agregát nad skladovou kartou) ──────────────────────────────
export type ProductPricingBase = 'weighted_avg' | 'last_purchase' | 'manual'

export interface ProductI18nRow {
  locale: string
  name: string | null
  short_desc: string | null
  description: string | null
  seo_title: string | null
  seo_description: string | null
  seo_slug: string | null
}

export interface ProductCategoryRow {
  category_id: number
  is_primary: boolean
  display_order: number
}

export interface ProductAttributeRow {
  attribute_id: number
  option_id?: number | null
  value_text?: string | null
  value_num?: string | null
  value_bool?: boolean | null
}

export interface ProductFeeRow {
  fee_type_id: number
  amount: string
  currency_code: string
  vat_included: boolean
}

export interface ProductMedia {
  id: number
  stock_item_id: number
  media_type: 'image' | 'document'
  storage_key: string
  original_name: string
  mime_type: string
  size_bytes: number
  title: string | null
  alt_text: string | null
  display_order: number
  is_primary: boolean
  export_eshop: boolean
}

export interface ProductMediaPayload {
  title?: string | null
  alt_text?: string | null
  is_primary?: boolean
  export_eshop?: boolean
}

/**
 * Agregát karty (GET /eshop/products/{id}): základní skladová karta (StockItem)
 * + e-shopová pole + navázané kolekce. Money/decimal pole jsou string.
 */
export interface Product {
  id: number
  row_version: number
  supplier_id: number
  sku: string
  name: string
  item_type: 'material' | 'goods' | 'product'
  unit: string
  tracking_mode: StockTrackingMode
  ean: string | null
  vat_rate_id: number | null
  sale_price_without_vat: string | null
  min_qty: string | null
  is_active: boolean
  note: string | null
  // e-shopová pole
  manufacturer_id: number | null
  warranty_months: number | null
  delivery_days: number | null
  export_eshop: boolean
  is_stocked: boolean
  weight_g: number | null
  pricing_base: ProductPricingBase
  // navázané kolekce
  i18n: ProductI18nRow[]
  categories: ProductCategoryRow[]
  tag_ids: number[]
  attributes: ProductAttributeRow[]
  fees: ProductFeeRow[]
  media: ProductMedia[]
}

// ── Ceny karty (Prices) ──────────────────────────────────────────────────
export type PriceMode = 'markup' | 'target_margin' | 'fixed'
export type PriceRounding = 'none' | '0.01' | '0.10' | '0.50' | '1' | '9_ending'

/** Řádek ceny per měna. Money/decimal pole jsou string. */
export interface ProductPrice {
  id: number
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
  use_pricing_rules?: boolean
}

/** PUT /eshop/products/{id}/prices — vstupní řádek ceny. */
export interface ProductPricePayload {
  currency_code: string
  price_mode: PriceMode
  markup_pct: string | null
  fixed_price: string | null
  rounding: PriceRounding
  is_manual_override: boolean
  use_pricing_rules?: boolean
}

export interface ProductPriceWriteResult {
  prices: ProductPrice[]
  row_version: number
}

// ── Akční (promoční) ceny karty (Promo prices, migrace 1328) ─────────────
/**
 * Množstevní strop akce:
 *  - `stock`     … do vyprodání zásob (strop = živý stav skladu; doskladnění akci obnoví),
 *  - `limited`   … pevný rozpočet kusů odečítaný prodejem (doskladnění ho NEobnoví),
 *  - `unlimited` … bez množstevního stropu.
 */
export type PromoQtyMode = 'stock' | 'limited' | 'unlimited'
export type PromoState = 'active' | 'scheduled' | 'expired' | 'disabled' | 'exhausted'

/** Řádek akční ceny. Money/qty pole jsou string (money-safe). */
export interface ProductPromoPrice {
  id: number
  stock_item_id: number
  currency_code: string
  promo_price: string
  label: string | null
  valid_from: string | null
  valid_to: string | null
  qty_mode: PromoQtyMode
  qty_limit: string | null
  is_active: boolean
  note: string | null
  /** Dopočet backendem — kolik kusů akce ještě pokryje; null = bez stropu. */
  qty_remaining: string | null
  state: PromoState
}

/** PUT /eshop/products/{id}/promo-prices — vstupní řádek (id = úprava, bez id = nová). */
export interface ProductPromoPricePayload {
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
}

/** GET /eshop/products/{id}/effective-price — platná cena pro dané množství. */
export interface EffectivePrice {
  stock_item_id: number
  currency_code: string
  base_price: string | null
  unit_price: string | null
  promo_applied: boolean
  promo_reason: 'applied' | 'none' | 'qty_exceeds_remaining' | 'exhausted' | 'not_cheaper'
  promo_qty_available: string | null
  promo: {
    id: number
    label: string | null
    promo_price: string
    valid_from: string | null
    valid_to: string | null
    qty_mode: PromoQtyMode
    qty_limit: string | null
    qty_remaining: string | null
  } | null
}

// ── Dodavatelé karty (Vendors) ───────────────────────────────────────────
/** Řádek dodavatele karty. Money/decimal pole jsou string. */
export interface ProductVendor {
  id: number
  client_id: number
  client_name: string
  vendor_sku: string | null
  purchase_price: string | null
  currency_code: string
  delivery_days: number | null
  stock_qty: string | null
  is_preferred: boolean
  note: string | null
}

/** PUT /eshop/products/{id}/vendors — vstupní řádek dodavatele. */
export interface ProductVendorPayload {
  client_id: number
  vendor_sku: string | null
  purchase_price: string | null
  currency_code: string
  delivery_days: number | null
  stock_qty: string | null
  is_preferred: boolean
  note: string | null
}

// ── Import zboží (Products import — Epic ESHOP F3) ───────────────────────
export interface ProductImportRow {
  line: number
  key: string
  status: 'create' | 'update' | 'skip' | 'error'
  changes?: Record<string, { from: unknown; to: unknown }>
  message?: string
}

export interface ProductImportReport {
  ok: boolean
  dry_run: boolean
  created: number
  updated: number
  skipped: number
  failed: number
  rows: ProductImportRow[]
}

/** PUT /eshop/products/{id} — všechny sekce volitelné (částečný update agregátu). */
export interface ProductUpdatePayload {
  row_version: number
  manufacturer_id?: number | null
  warranty_months?: number | null
  delivery_days?: number | null
  export_eshop?: boolean
  is_stocked?: boolean
  weight_g?: number | null
  pricing_base?: ProductPricingBase
  i18n?: ProductI18nRow[]
  categories?: ProductCategoryRow[]
  tag_ids?: number[]
  attributes?: ProductAttributeRow[]
  fees?: ProductFeeRow[]
}

export interface ProductEditorPayload {
  row_version: number
  item: StockItemPayload
  product: Omit<ProductUpdatePayload, 'row_version'>
  prices: ProductPricePayload[]
  promo_prices: ProductPromoPricePayload[]
  vendors: ProductVendorPayload[]
}

// ── Virtuální sety (SK-09, SK-16) ─────────────────────────────────────────
export interface ProductSetComponent {
  item_id: number
  quantity: string
}

export interface ProductSetOption extends ProductSetComponent {
  code: string
  name: string
  surcharges: Record<string, string>
}

export interface ProductSetGroup {
  code: string
  name: string
  min: number
  max: number
  options: ProductSetOption[]
}

export interface ProductSetPrice {
  mode: 'sum' | 'discount' | 'fixed'
  discount_pct?: string | null
  fixed_price?: string | null
}

export interface ProductSetDefinition {
  components: ProductSetComponent[]
  groups: ProductSetGroup[]
  prices: Record<string, ProductSetPrice>
}

export interface ProductSet {
  stock_item_id: number
  supplier_id: number
  row_version: number
  definition: ProductSetDefinition
}

export interface ProductSetCard {
  id: number
  sku: string
  name: string
  unit: string
  vat_rate_id: number | null
  is_active: boolean
  is_stocked: boolean
}

export interface ProductSetReadResponse {
  set: ProductSet | null
  definitions: Record<string, ProductSetDefinition>
  cards: ProductSetCard[]
}

export interface ProductSetQuoteComponent {
  item_id: number
  quantity: string
  unit_price: string
  amount: string
}

export interface ProductSetQuoteResponse {
  stock_item_id: number
  row_version: number
  currency_code: string
  quantity: string
  prices_include_vat: boolean
  vat_rate_id: number
  selections: Record<string, Record<string, string[]>>
  definition_snapshot: Record<string, ProductSetDefinition>
  component_snapshot: ProductSetCard[]
  quote: { amount: string; components: ProductSetQuoteComponent[] }
}

function toParams<T extends object>(f: T = {} as T): Record<string, string | number> {
  const out: Record<string, string | number> = {}
  for (const [k, v] of Object.entries(f)) {
    if (v === undefined || v === null || v === '') continue
    out[k] = typeof v === 'boolean' ? (v ? 1 : 0) : (v as string | number)
  }
  return out
}

export const eshopApi = {
  // ── Výrobci (Manufacturers) ──────────────────────────────────────────────
  listManufacturers: (filters?: any) =>
    api.get<Manufacturer[]>('/eshop/manufacturers', { params: toParams(filters) }).then(r => r.data),
  getManufacturer: (id: number) => api.get<Manufacturer>(`/eshop/manufacturers/${id}`).then(r => r.data),
  createManufacturer: (payload: ManufacturerPayload) => api.post<Manufacturer>('/eshop/manufacturers', payload).then(r => r.data),
  updateManufacturer: (id: number, payload: ManufacturerPayload) => api.put<Manufacturer>(`/eshop/manufacturers/${id}`, payload).then(r => r.data),
  deleteManufacturer: (id: number) => api.delete<{ deleted: true }>(`/eshop/manufacturers/${id}`).then(r => r.data),

  // ── Jazyky (Locales) ─────────────────────────────────────────────────────
  listLocales: (filters?: any) =>
    api.get<EshopLocale[]>('/eshop/locales', { params: toParams(filters) }).then(r => r.data),
  getLocale: (id: number) => api.get<EshopLocale>(`/eshop/locales/${id}`).then(r => r.data),
  createLocale: (payload: EshopLocalePayload) => api.post<EshopLocale>('/eshop/locales', payload).then(r => r.data),
  updateLocale: (id: number, payload: EshopLocalePayload) => api.put<EshopLocale>(`/eshop/locales/${id}`, payload).then(r => r.data),
  deleteLocale: (id: number) => api.delete<{ deleted: true }>(`/eshop/locales/${id}`).then(r => r.data),

  // ── Prodejní měny (Currencies) ───────────────────────────────────────────
  listCurrencies: (filters?: any) =>
    api.get<EshopCurrency[]>('/eshop/currencies', { params: toParams(filters) }).then(r => r.data),
  getCurrency: (id: number) => api.get<EshopCurrency>(`/eshop/currencies/${id}`).then(r => r.data),
  createCurrency: (payload: EshopCurrencyPayload) => api.post<EshopCurrency>('/eshop/currencies', payload).then(r => r.data),
  updateCurrency: (id: number, payload: EshopCurrencyPayload) => api.put<EshopCurrency>(`/eshop/currencies/${id}`, payload).then(r => r.data),
  deleteCurrency: (id: number) => api.delete<{ deleted: true }>(`/eshop/currencies/${id}`).then(r => r.data),

  // ── Tagy (Tags) ─────────────────────────────────────────────────────────
  listTags: (filters?: any) =>
    api.get<Tag[]>('/eshop/tags', { params: toParams(filters) }).then(r => r.data),
  getTag: (id: number) => api.get<Tag>(`/eshop/tags/${id}`).then(r => r.data),
  createTag: (payload: TagPayload) => api.post<Tag>('/eshop/tags', payload).then(r => r.data),
  updateTag: (id: number, payload: TagPayload) => api.put<Tag>(`/eshop/tags/${id}`, payload).then(r => r.data),
  deleteTag: (id: number) => api.delete<{ deleted: true }>(`/eshop/tags/${id}`).then(r => r.data),

  // ── Poplatky (Fee Types) ─────────────────────────────────────────────────
  listFeeTypes: (filters?: any) =>
    api.get<FeeType[]>('/eshop/fee-types', { params: toParams(filters) }).then(r => r.data),
  getFeeType: (id: number) => api.get<FeeType>(`/eshop/fee-types/${id}`).then(r => r.data),
  createFeeType: (payload: FeeTypePayload) => api.post<FeeType>('/eshop/fee-types', payload).then(r => r.data),
  updateFeeType: (id: number, payload: FeeTypePayload) => api.put<FeeType>(`/eshop/fee-types/${id}`, payload).then(r => r.data),
  deleteFeeType: (id: number) => api.delete<{ deleted: true }>(`/eshop/fee-types/${id}`).then(r => r.data),

  // ── Atributy (Attributes) ────────────────────────────────────────────────
  listAttributes: (filters?: any) =>
    api.get<Attribute[]>('/eshop/attributes', { params: toParams(filters) }).then(r => r.data),
  getAttribute: (id: number) => api.get<Attribute>(`/eshop/attributes/${id}`).then(r => r.data),
  createAttribute: (payload: AttributePayload) => api.post<Attribute>('/eshop/attributes', payload).then(r => r.data),
  updateAttribute: (id: number, payload: AttributePayload) => api.put<Attribute>(`/eshop/attributes/${id}`, payload).then(r => r.data),
  deleteAttribute: (id: number) => api.delete<{ deleted: true }>(`/eshop/attributes/${id}`).then(r => r.data),

  // ── Volby atributu (Attribute Options) ──────────────────────────────────
  listAttributeOptions: (attributeId: number) =>
    api.get<AttributeOption[]>(`/eshop/attributes/${attributeId}/options`).then(r => r.data),
  createAttributeOption: (attributeId: number, payload: AttributeOptionPayload) =>
    api.post<AttributeOption>(`/eshop/attributes/${attributeId}/options`, payload).then(r => r.data),
  updateAttributeOption: (id: number, payload: AttributeOptionPayload) =>
    api.put<AttributeOption>(`/eshop/attribute-options/${id}`, payload).then(r => r.data),
  deleteAttributeOption: (id: number) =>
    api.delete<{ deleted: true }>(`/eshop/attribute-options/${id}`).then(r => r.data),

  // ── Kategorie (Categories) ───────────────────────────────────────────────
  listCategories: (filters?: any) =>
    api.get<Category[]>('/eshop/categories', { params: toParams(filters) }).then(r => r.data),
  getCategory: (id: number) => api.get<Category>(`/eshop/categories/${id}`).then(r => r.data),
  createCategory: (payload: CategoryPayload) => api.post<Category>('/eshop/categories', payload).then(r => r.data),
  updateCategory: (id: number, payload: CategoryPayload) => api.put<Category>(`/eshop/categories/${id}`, payload).then(r => r.data),
  deleteCategory: (id: number) => api.delete<{ deleted: true }>(`/eshop/categories/${id}`).then(r => r.data),
  moveCategory: (id: number, parentId: number | null) =>
    api.post<Category>(`/eshop/categories/${id}/move`, { parent_id: parentId }).then(r => r.data),
  getCategoryI18n: (id: number) => api.get<CategoryI18nRow[]>(`/eshop/categories/${id}/i18n`).then(r => r.data),
  updateCategoryI18n: (id: number, rows: CategoryI18nRow[]) =>
    api.put<CategoryI18nRow[]>(`/eshop/categories/${id}/i18n`, { i18n: rows }).then(r => r.data),

  // ── Karta zboží — agregát (Products) ─────────────────────────────────────
  getProduct: (id: number) => api.get<Product>(`/eshop/products/${id}`).then(r => r.data),
  updateProduct: (id: number, payload: ProductUpdatePayload) =>
    api.put<Product>(`/eshop/products/${id}`, payload).then(r => r.data),
  saveProductEditor: (id: number, payload: ProductEditorPayload) =>
    api.put<Product>(`/eshop/products/${id}/editor`, payload).then(r => r.data),
  getProductI18n: (id: number) => api.get<ProductI18nRow[]>(`/eshop/products/${id}/i18n`).then(r => r.data),

  // ── Virtuální sety ───────────────────────────────────────────────────────
  getProductSet: (id: number) => api.get<ProductSetReadResponse>(`/eshop/sets/${id}`).then(r => r.data),
  saveProductSet: (id: number, rowVersion: number, definition: ProductSetDefinition) =>
    api.put<ProductSet>(`/eshop/sets/${id}`, { row_version: rowVersion, definition }).then(r => r.data),
  quoteProductSet: (id: number, payload: {
    currency_code: string
    quantity: string
    selections: Record<string, Record<string, string[]>>
  }) => api.post<ProductSetQuoteResponse>(`/eshop/sets/${id}/quote`, payload).then(r => r.data),

  // ── Import zboží (Epic ESHOP F3) ─────────────────────────────────────────
  importProducts: (file: File, dryRun: boolean) => {
    const fd = new FormData()
    fd.append('file', file)
    fd.append('dry_run', dryRun ? '1' : '0')
    return api.post<ProductImportReport>('/eshop/products/import', fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then(r => r.data)
  },

  // ── Ceny karty (Prices) ──────────────────────────────────────────────────
  getPrices: (productId: number) =>
    api.get<ProductPrice[]>(`/eshop/products/${productId}/prices`).then(r => r.data),
  updatePrices: (productId: number, prices: ProductPricePayload[]) =>
    api.put<ProductPrice[]>(`/eshop/products/${productId}/prices`, { prices }).then(r => r.data),
  updatePricesVersioned: (productId: number, rowVersion: number, prices: ProductPricePayload[]) =>
    api.put<ProductPriceWriteResult>(`/eshop/products/${productId}/prices`, {
      row_version: rowVersion,
      prices,
    }).then(r => r.data),
  recomputePrices: (productId: number) =>
    api.post<ProductPrice[]>(`/eshop/products/${productId}/prices/recompute`).then(r => r.data),

  // ── Akční ceny karty (Promo prices) ──────────────────────────────────────
  getPromoPrices: (productId: number) =>
    api.get<ProductPromoPrice[]>(`/eshop/products/${productId}/promo-prices`).then(r => r.data),
  updatePromoPrices: (productId: number, promoPrices: ProductPromoPricePayload[]) =>
    api.put<ProductPromoPrice[]>(`/eshop/products/${productId}/promo-prices`, { promo_prices: promoPrices }).then(r => r.data),
  getEffectivePrice: (productId: number, params?: { currency?: string; qty?: string | number; on_date?: string }) =>
    api.get<EffectivePrice>(`/eshop/products/${productId}/effective-price`, { params: toParams(params ?? {}) }).then(r => r.data),

  // ── Dodavatelé karty (Vendors) ───────────────────────────────────────────
  getVendors: (productId: number) =>
    api.get<ProductVendor[]>(`/eshop/products/${productId}/vendors`).then(r => r.data),
  updateVendors: (productId: number, vendors: ProductVendorPayload[]) =>
    api.put<ProductVendor[]>(`/eshop/products/${productId}/vendors`, { vendors }).then(r => r.data),

  // ── Média karty (Media) ──────────────────────────────────────────────────
  listMedia: (productId: number) =>
    api.get<ProductMedia[]>(`/eshop/products/${productId}/media`).then(r => r.data),
  uploadMedia: (productId: number, files: File[], onProgress?: (pct: number) => void) => {
    const fd = new FormData()
    if (files.length === 1) fd.append('file', files[0], files[0].name)
    else for (const f of files) fd.append('file[]', f, f.name)
    return api.post<ProductMedia[]>(`/eshop/products/${productId}/media`, fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
      onUploadProgress: (e) => { if (onProgress && e.total) onProgress(Math.round((e.loaded / e.total) * 100)) },
    }).then(r => r.data)
  },
  reorderMedia: (productId: number, order: number[]) =>
    api.put<{ ok: true }>(`/eshop/products/${productId}/media/reorder`, { order }).then(r => r.data),
  updateMedia: (mediaId: number, payload: ProductMediaPayload) =>
    api.put<ProductMedia>(`/eshop/media/${mediaId}`, payload).then(r => r.data),
  deleteMedia: (mediaId: number) =>
    api.delete<{ deleted: true }>(`/eshop/media/${mediaId}`).then(r => r.data),
  /** URL binárky média pro <img>/odkaz (supplier_id v query pro auth kontext). */
  mediaFileUrl: (mediaId: number) => {
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const qs = sid && /^\d+$/.test(sid) ? `?supplier_id=${sid}` : ''
    return `/api/eshop/media/${mediaId}/file${qs}`
  },
}
