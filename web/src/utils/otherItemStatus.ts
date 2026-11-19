import type { OtherItem } from '@/api/otherItems'
import { statusBadgeClass } from '@/composables/useFormat'
import { isInvoiceDateOverdue } from '@/utils/invoiceOverdue'

/**
 * Stav ostatní položky pro odznak ve výpisu i detailu.
 *
 * Živý doklad (potvrzený, zaúčtovaný) přebije platební stav stejně jako u faktur
 * (`displayStatus` v useFormat): uhrazený a částečně uhrazený nese víc informace
 * než „Zaúčtováno". Převzaté položky (mzdy, daně) chodí rovnou s odvozeným stavem.
 */
type StatusSource = Pick<OtherItem, 'status' | 'paid_amount' | 'remaining_amount'> & { source_kind?: string }

const LIVE = ['posted', 'confirmed']

export function otherItemDisplayStatus(item: StatusSource): string {
  const status = String(item.status)
  if (!LIVE.includes(status)) return status
  if (Number(item.remaining_amount) <= 0) return 'paid'
  if (Number(item.paid_amount) > 0) return 'partial'
  return status
}

/** Třída odznaku podle sdílených stavových odznaků (`statusBadgeClass`), ne vlastní paleta. */
export function otherItemStatusClass(status: string): string {
  const shared: Record<string, string> = {
    draft: 'draft',
    forecast: 'draft',
    posted: 'issued',
    confirmed: 'issued',
    open: 'issued',
    paid: 'paid',
    partial: 'partially_paid',
    overdue: 'reminded',
    reversed: 'cancelled',
    cancelled: 'cancelled',
  }
  return statusBadgeClass(shared[status] ?? 'draft')
}

/** Po splatnosti je jen živý doklad, na kterém ještě něco zbývá. */
export function isOtherItemOverdue(item: StatusSource & Pick<OtherItem, 'due_on'>): boolean {
  return LIVE.includes(String(item.status)) && Number(item.remaining_amount) > 0
    && !!item.due_on && isInvoiceDateOverdue(item.due_on)
}
