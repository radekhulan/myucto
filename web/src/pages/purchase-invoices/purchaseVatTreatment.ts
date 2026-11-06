import type { PurchaseVatAllocation, PurchaseVatItemState, VatDeduction } from '@/api/purchaseInvoices'
import type { VatClassification } from '@/api/vatClassifications'

// Zrcadlo serverového PurchaseVatTreatment pro rozpracovaný formulář editoru. Po uložení
// platí `vat_treatment` ze serveru; tady se jen ukazuje, jak doklad do evidence DPH půjde.

type ClassificationLike = Pick<VatClassification, 'code' | 'dphdp3_line' | 'kh_section' | 'is_reverse_charge'>
  & { dphdp3_line_secondary?: string | null }

function find(code: string | null | undefined, list: ClassificationLike[]): ClassificationLike | undefined {
  const c = (code ?? '').trim()
  return c === '' ? undefined : list.find(vc => vc.code === c)
}

/** Kód bez řádku přiznání a oddílu KH, který není samovyměřením (42, „mimo"). */
export function isNoClaimCode(code: string | null | undefined, list: ClassificationLike[]): boolean {
  const vc = find(code, list)
  return !!vc && !vc.is_reverse_charge && !(vc.dphdp3_line ?? '').trim()
    && !(vc.dphdp3_line_secondary ?? '').trim() && !(vc.kh_section ?? '').trim()
}

/** Kód odpočtu tuzemské daně (40, 41 …). */
export function isClaimCode(code: string | null | undefined, list: ClassificationLike[]): boolean {
  const vc = find(code, list)
  return !!vc && !vc.is_reverse_charge && !isNoClaimCode(code, list)
}

export function deductionKey(mode: VatDeduction | string | null | undefined, percent: number | null | undefined): string {
  const m = ['full', 'none', 'proportional', 'reduced'].includes(String(mode)) ? String(mode) : 'full'
  if (m !== 'proportional') return m
  const p = Math.max(0, Math.min(100, Number.isFinite(Number(percent)) ? Number(percent) : 100))
  return `proportional:${p.toFixed(2)}`
}

export interface HeaderVat {
  vat_deduction: VatDeduction
  vat_deduction_percent: number
  vat_classification_code: string | null
}

/** Liší se nárok hlavičky od rozpisu DPH? (evidence bere nárok z rozpisu) */
export function headerDiffersFromAllocations(header: HeaderVat, allocations: Pick<PurchaseVatAllocation, 'vat_deduction' | 'vat_deduction_percent'>[]): boolean {
  if (allocations.length === 0) return false
  const headerKey = deductionKey(header.vat_deduction, header.vat_deduction_percent)
  return allocations.some(a => deductionKey(a.vat_deduction, a.vat_deduction_percent) !== headerKey)
}

/**
 * Mají alokace rozpisu různý nárok na odpočet (smíšený nárok)? Pak hlavička nárok neurčuje:
 * změnu hlavičky systém do rozpisu nepromítne a do evidence jde nárok z alokací.
 */
export function allocationsHaveMixedDeduction(allocations: Pick<PurchaseVatAllocation, 'vat_deduction' | 'vat_deduction_percent'>[]): boolean {
  return new Set(allocations.map(a => deductionKey(a.vat_deduction, a.vat_deduction_percent))).size > 1
}

/**
 * Stav položky v evidenci DPH: `effectiveCode` je kód, se kterým položka do evidence jde
 * (vlastní, jinak kód hlavičky, stejně jako evidence DPH).
 */
export function itemVatState(
  itemCode: string | null | undefined,
  header: HeaderVat,
  hasAllocations: boolean,
  list: ClassificationLike[],
): { state: PurchaseVatItemState; effectiveCode: string | null } {
  const own = (itemCode ?? '').trim() || null
  const headerCode = (header.vat_classification_code ?? '').trim() || null
  const effectiveCode = own ?? headerCode
  if (hasAllocations) return { state: 'allocations', effectiveCode }
  if (header.vat_deduction === 'none') return { state: 'no_claim_header', effectiveCode }
  if (effectiveCode === null) return { state: 'unclassified', effectiveCode }
  if (isNoClaimCode(effectiveCode, list)) return { state: 'no_claim_code', effectiveCode }
  return { state: 'claim', effectiveCode }
}

/**
 * Rozpor hlavička × položka, který mění výsledek v DPH: hlavička „bez nároku" (nárokem
 * nebo kódem) a položka s kódem odpočtu. Platí i pro nové položky při zakládání.
 */
export function itemConflictsWithHeader(itemCode: string | null | undefined, header: HeaderVat, hasAllocations: boolean, list: ClassificationLike[]): boolean {
  if (hasAllocations) return false
  const own = (itemCode ?? '').trim() || null
  if (header.vat_deduction === 'none') return isClaimCode(own ?? header.vat_classification_code, list)
  return own !== null && isNoClaimCode(header.vat_classification_code, list) && isClaimCode(own, list)
}
