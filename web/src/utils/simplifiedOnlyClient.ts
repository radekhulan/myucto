/**
 * Drobný zákazník bez adresy (#310, `clients.simplified_only`) — živá nápověda editoru.
 *
 * Zrcadlí serverovou bránu `InvoiceIssueTaxGate::simplifiedOnlyClientRejection()`, která je
 * závazná: plátce smí takovému odběrateli vystavit jen fakturu s příznakem zjednodušeného
 * daňového dokladu (§ 30 ZDPH) a jen do limitu včetně daně. Neplátce zjednodušené doklady
 * nevystavuje, jeho faktuře jako označení odběratele stačí jméno.
 */
export const SIMPLIFIED_LIMIT_WITH_VAT = 10000

export type SimplifiedOnlyClientProblem = 'requires_simplified' | 'over_limit' | null

export function simplifiedOnlyClientProblem(input: {
  simplifiedOnly: boolean
  supplierIsVatPayer: boolean
  invoiceType: string
  isSimplified: boolean
  totalWithVat: number
  limit?: number
}): SimplifiedOnlyClientProblem {
  if (!input.simplifiedOnly || !input.supplierIsVatPayer) return null
  if (input.invoiceType !== 'invoice' || !input.isSimplified) return 'requires_simplified'
  return Math.abs(input.totalWithVat) > (input.limit ?? SIMPLIFIED_LIMIT_WITH_VAT) ? 'over_limit' : null
}
