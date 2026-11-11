/**
 * Drobný zákazník bez adresy (#310, `clients.simplified_only`) — živá nápověda editoru.
 *
 * Zrcadlí serverovou bránu `InvoiceIssueTaxGate::simplifiedOnlyClientRejection()`, která je
 * závazná: plátce smí takovému odběrateli vystavit jen fakturu s příznakem zjednodušeného
 * daňového dokladu (§ 30 ZDPH) a jen do limitu včetně daně. Dobropis jen jako zjednodušený
 * opravný daňový doklad k původnímu zjednodušenému dokladu (§ 45 odst. 2 ZDPH); zda je původní
 * doklad opravdu zjednodušený a kolik z něj zbývá opravit, ověří až server. Neplátce zjednodušené
 * doklady nevystavuje, jeho faktuře jako označení odběratele stačí jméno.
 */
export const SIMPLIFIED_LIMIT_WITH_VAT = 10000

export type SimplifiedOnlyClientProblem = 'requires_simplified' | 'credit_note_requires_original' | 'over_limit' | null

export function simplifiedOnlyClientProblem(input: {
  simplifiedOnly: boolean
  supplierIsVatPayer: boolean
  invoiceType: string
  isSimplified: boolean
  totalWithVat: number
  hasOriginal?: boolean
  limit?: number
}): SimplifiedOnlyClientProblem {
  if (!input.simplifiedOnly || !input.supplierIsVatPayer) return null
  if (input.invoiceType === 'credit_note' && !input.hasOriginal) return 'credit_note_requires_original'
  if (!['invoice', 'credit_note'].includes(input.invoiceType) || !input.isSimplified) return 'requires_simplified'
  return Math.abs(input.totalWithVat) > (input.limit ?? SIMPLIFIED_LIMIT_WITH_VAT) ? 'over_limit' : null
}
