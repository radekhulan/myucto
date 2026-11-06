import type { SupplierBrief } from '@/api/auth'

/**
 * Agendy, které dávají smysl jen části firem. Navigace i router se ptají tady,
 * aby běžná s.r.o. na rámci 500 neviděla položky NNO ani SVJ a nedostala se
 * na ně ani přímou adresou.
 *
 * - `nonprofit504`: firma s účinným rámcem vyhlášky 504 nebo s právní formou
 *   nevýdělečné organizace (DPPO 504, cenné papíry, kapitál, fondy, členství).
 * - `conversion504`: řízený převod rámce; navíc i SVJ, kterému stránka vysvětlí,
 *   proč a jak převádí jinak (doporučení v Nastavení na ni vede i u SVJ).
 * - `svj`: firma s právní formou společenství vlastníků jednotek.
 *
 * Profil podle právní formy počítá backend (`LegalFormProfileRecommendation`)
 * a posílá ho v `/auth/me` jako `entity_profile`.
 */
export type FrameworkScope = 'nonprofit504' | 'conversion504' | 'svj'

type ScopeSupplier = Pick<SupplierBrief, 'accounting_framework' | 'entity_profile'> | null | undefined

export function hasFrameworkScope(supplier: ScopeSupplier, scope: FrameworkScope): boolean {
  if (!supplier) return false
  if (scope === 'svj') return supplier.entity_profile === 'svj'
  const nonprofit = supplier.accounting_framework === 'cz_504' || supplier.entity_profile === 'nonprofit'
  return scope === 'conversion504' ? nonprofit || supplier.entity_profile === 'svj' : nonprofit
}
