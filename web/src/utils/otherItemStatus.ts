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
type StatusSource = Pick<OtherItem, 'status' | 'paid_amount' | 'remaining_amount'> & { source_kind?: string; remaining_payment?: number }

const LIVE = ['posted', 'confirmed']

/**
 * Kolik zbývá ZAPLATIT: u rozdělených splátek i úrok a poplatek (`remaining_payment` posílá výpis
 * i detail), jinak zbytek jistiny. Výpis a detail z něj odvozují stav stejně; jinak by výpis hlásil
 * „Uhrazeno“ a detail téže položky „Částečně uhrazeno“ s nabídkou další úhrady.
 */
export function otherItemRemainingPayment(item: StatusSource): number {
  return Number(item.remaining_payment ?? item.remaining_amount ?? 0)
}

export function otherItemDisplayStatus(item: StatusSource): string {
  const status = String(item.status)
  if (!LIVE.includes(status)) return status
  const remaining = otherItemRemainingPayment(item)
  // Přeplatek jako u faktur; haléřová tolerance 0,005.
  if (remaining < -0.005) return 'overpaid'
  if (remaining <= 0) return 'paid'
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
    overpaid: 'overpaid',
    overdue: 'reminded',
    reversed: 'cancelled',
    cancelled: 'cancelled',
  }
  return statusBadgeClass(shared[status] ?? 'draft')
}

/** Po splatnosti je jen živý doklad, na kterém ještě něco zbývá. */
export function isOtherItemOverdue(item: StatusSource & Pick<OtherItem, 'due_on'>): boolean {
  return LIVE.includes(String(item.status)) && otherItemRemainingPayment(item) > 0
    && !!item.due_on && isInvoiceDateOverdue(item.due_on)
}
