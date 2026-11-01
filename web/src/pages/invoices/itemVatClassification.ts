/**
 * Členění DPH položky vydané faktury v editoru.
 *
 * Sloupec `invoice_items.vat_classification_code` nerozlišuje ruční volbu od kódu, který
 * doplnil server, přitom odvozený kód musí po změně sazby, odběratele nebo data zaniknout.
 * Editor proto uložený kód drží, jen dokud se nezměnilo nic, z čeho se odvozuje. Tím ruční
 * volba přežije další uložení a odvozený kód nezůstane viset na špatném řádku přiznání.
 */
export interface ItemVatInputs {
  vat_rate_id: number
  unit: string
  asset_id: number | null
  small_asset_id: number | null
}

export interface DocumentVatInputs {
  reverse_charge: boolean
  client_id: number | null
  tax_date: string
}

export interface LoadedItemVat {
  code: string | null
  item: ItemVatInputs
  document: DocumentVatInputs
}

type ItemLike = {
  vat_rate_id?: number | null
  unit?: string | null
  asset_id?: number | null
  small_asset_id?: number | null
}

type DocumentLike = {
  reverse_charge?: boolean | null
  client_id?: number | null
  tax_date?: string | null
  issue_date?: string | null
}

export function itemVatInputs(item: ItemLike): ItemVatInputs {
  return {
    vat_rate_id: Number(item.vat_rate_id ?? 0),
    unit: item.unit ?? '',
    asset_id: item.asset_id ?? null,
    small_asset_id: item.small_asset_id ?? null,
  }
}

export function documentVatInputs(document: DocumentLike): DocumentVatInputs {
  return {
    reverse_charge: !!document.reverse_charge,
    client_id: document.client_id ?? null,
    tax_date: document.tax_date || document.issue_date || '',
  }
}

function sameInputs<T extends object>(a: T, b: T): boolean {
  return (Object.keys(a) as (keyof T)[]).every(key => a[key] === b[key])
}

/** Uložený kód, dokud platí vstupy, ze kterých vznikl. Jinak `null` = odvodí server. */
export function loadedItemVatCode(loaded: LoadedItemVat | undefined, item: ItemLike, document: DocumentLike): string | null {
  if (!loaded) return null
  return sameInputs(loaded.item, itemVatInputs(item)) && sameInputs(loaded.document, documentVatInputs(document))
    ? loaded.code
    : null
}
