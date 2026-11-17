import { computed, ref } from 'vue'
import { useSupplierStore } from '@/stores/supplier'
import { useAuthStore } from '@/stores/auth'
import type { MembershipListFilters } from '@/api/nonprofitMembership'

/** Seznam členů a samostatná stránka detailu člena (jako seznam a detail kontaktu). */
export const MEMBERSHIP_LIST_PATH = '/accounting/nonprofit-membership'
export const memberDetailPath = (id: number) => `${MEMBERSHIP_LIST_PATH}/members/${id}`

/**
 * Hlášky pro stránku, která se zobrazí po akci na té druhé (smazání člena ohlásí seznam, založení detail).
 * Stránka se připojí znovu, proto je drží modul; stránka ji při připojení přečte a vymaže.
 */
export const membershipListNotice = ref('')
export const membershipDetailNotice = ref('')

/**
 * Předání stavu seznamu detailu otevřenému ve stejném panelu. Panely mají router nad paměťovou historií
 * (žádné `history.state.back`) a seznam se odchodem odpojí, takže si adresu s filtry, výběr do hromadného
 * předpisu a otevřeného člena uloží sem. Detail z toho vezme odkaz zpět, seznam po návratu výběr a fokus.
 * Platí jen pro tutéž firmu a filtry a jen krátce; obnovený výběr seznam jako každou obnovu ověří na serveru.
 */
export interface MembershipListHandoff { supplier: number; path: string; filters: MembershipListFilters; ids: number[]; openedId: number | null; at: number }
const HANDOFF_KEY = 'myinvoice.membership.listHandoff'
const HANDOFF_TTL_MS = 30 * 60 * 1000

export function saveListHandoff(state: Omit<MembershipListHandoff, 'at'>): void {
  try { sessionStorage.setItem(HANDOFF_KEY, JSON.stringify({ ...state, at: Date.now() })) } catch { /* bez úložiště jen bez návratu stavu */ }
}

/** Platné předání pro firmu, nebo null (jiná firma, staré, poškozené). */
export function readListHandoff(supplier: number): MembershipListHandoff | null {
  try {
    const raw: unknown = JSON.parse(sessionStorage.getItem(HANDOFF_KEY) ?? 'null')
    if (!raw || typeof raw !== 'object') return null
    const h = raw as Partial<MembershipListHandoff>
    if (h.supplier !== supplier || typeof h.at !== 'number' || Date.now() - h.at > HANDOFF_TTL_MS) return null
    if (typeof h.path !== 'string' || (h.path !== MEMBERSHIP_LIST_PATH && !h.path.startsWith(`${MEMBERSHIP_LIST_PATH}?`))) return null
    const ids = Array.isArray(h.ids) ? h.ids.filter((id): id is number => Number.isInteger(id) && id > 0).slice(0, 500) : []
    return { supplier, path: h.path, filters: h.filters as MembershipListFilters, ids, openedId: Number.isInteger(h.openedId) ? h.openedId as number : null, at: h.at }
  } catch { return null }
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
