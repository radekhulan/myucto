import { computed } from 'vue'
import { useSupplierStore } from '@/stores/supplier'
import { useAuthStore } from '@/stores/auth'
import type { MembershipListFilters } from '@/api/nonprofitMembership'

/** Seznam členů a samostatná stránka detailu člena (jako seznam a detail kontaktu). */
export const MEMBERSHIP_LIST_PATH = '/accounting/nonprofit-membership'
export const memberDetailPath = (id: number) => `${MEMBERSHIP_LIST_PATH}/members/${id}`

/**
 * Předání stavu seznamu detailu otevřenému ve stejném panelu. Panely mají router nad paměťovou historií
 * (žádné `history.state.back`) a seznam se odchodem odpojí, takže si adresu s filtry, výběr do hromadného
 * předpisu a otevřeného člena uloží sem. Detail z toho vezme odkaz zpět, seznam po návratu výběr a fokus.
 * Platí jen pro tutéž firmu a filtry a jen krátce; obnovený výběr seznam jako každou obnovu ověří na serveru.
 *
 * Proč ne sdílený useListNavigation (šipky mezi doklady a odkaz zpět u faktur): ten stránkuje čísly stránek
 * (fetchPage(page)), kdežto seznam členů jde kurzorem po id (keyset). Hlavně ale seznam předává i výběr do
 * hromadného předpisu, který useListNavigation nenese.
 */
export interface MembershipListHandoff { supplier: number; pane: string | null; returning: boolean; path: string; filters: MembershipListFilters; ids: number[]; openedId: number | null; at: number }
const HANDOFF_KEY = 'myinvoice.membership.listHandoff'
const HANDOFF_TTL_MS = 30 * 60 * 1000

export function saveListHandoff(state: Omit<MembershipListHandoff, 'at'>): void {
  try { sessionStorage.setItem(HANDOFF_KEY, JSON.stringify({ ...state, at: Date.now() })) } catch { /* bez úložiště jen bez návratu stavu */ }
}

/**
 * Platné předání pro firmu a panel, nebo null (jiná firma, jiný panel, staré, poškozené). Panely pracovní
 * plochy sdílejí sessionStorage, proto předání nese panel, ze kterého seznam detail otevřel.
 */
export function readListHandoff(supplier: number, pane: string | null): MembershipListHandoff | null {
  try {
    const raw: unknown = JSON.parse(sessionStorage.getItem(HANDOFF_KEY) ?? 'null')
    if (!raw || typeof raw !== 'object') return null
    const h = raw as Partial<MembershipListHandoff>
    if (h.supplier !== supplier || (h.pane ?? null) !== pane || typeof h.at !== 'number' || Date.now() - h.at > HANDOFF_TTL_MS) return null
    if (typeof h.path !== 'string' || (h.path !== MEMBERSHIP_LIST_PATH && !h.path.startsWith(`${MEMBERSHIP_LIST_PATH}?`))) return null
    const ids = Array.isArray(h.ids) ? h.ids.filter((id): id is number => Number.isInteger(id) && id > 0).slice(0, 500) : []
    return { supplier, pane, returning: h.returning === true, path: h.path, filters: h.filters as MembershipListFilters, ids, openedId: Number.isInteger(h.openedId) ? h.openedId as number : null, at: h.at }
  } catch { return null }
}

/** Smazaný člen: z předání zmizí z výběru i jako otevřený (seznam pak nehlásí jeho vyřazení a fokus dá na nadpis). */
export function forgetMemberInHandoff(supplier: number, pane: string | null, memberId: number): void {
  const handoff = readListHandoff(supplier, pane)
  if (!handoff) return
  saveListHandoff({ ...handoff, returning: true, ids: handoff.ids.filter(id => id !== memberId), openedId: handoff.openedId === memberId ? null : handoff.openedId })
}

/**
 * Návrat z detailu na seznam (odkaz Zpět, smazání): jen tehdy seznam převezme výběr a fokus. Seznam otevřený
 * z menu nebo jinou cestou předání nepoužije, i když má stejnou adresu.
 */
export function markListReturn(supplier: number, pane: string | null): void {
  const handoff = readListHandoff(supplier, pane)
  if (handoff) saveListHandoff({ ...handoff, returning: true })
}

export function clearListHandoff(): void {
  try { sessionStorage.removeItem(HANDOFF_KEY) } catch { /* nic */ }
}

/** Firma a práva stránek členské evidence; seznam i detail rozhodují stejně. */
export function useMembershipAccess() {
  const supplier = useSupplierStore(), auth = useAuthStore()
  const supplierId = computed(() => Number(supplier.currentSupplierId))
  const canManage = computed(() => Boolean(auth.user && auth.csrfToken && auth.can('accounting.journal.post', 'write')))
  const canManageRates = computed(() => Boolean(auth.user && auth.csrfToken && auth.can('accounting.templates', 'write')))
  return { supplierId, canManage, canManageRates }
}
