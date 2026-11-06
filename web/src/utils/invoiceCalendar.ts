/**
 * Typy kalendářů, zrcadlo PHP InvoiceCalendar.
 *
 * `rent_calendar` (§ 31 ZDPH) a `advance_calendar` (§ 31a ZDPH) nesou pohledávku, daň i úhradu
 * na interních dokladech splátek. `payment_calendar` je původní společný typ: už vystavené
 * doklady zůstávají, jak byly, koncept tímto typem vystavit nejde.
 */
export type CalendarInvoiceType = 'rent_calendar' | 'advance_calendar'

export function isCalendarType(type: string | null | undefined): type is CalendarInvoiceType {
  return type === 'rent_calendar' || type === 'advance_calendar'
}

/** Doklad s rozpisem plateb, včetně původního společného typu. */
export function hasPaymentSchedule(type: string | null | undefined): type is CalendarInvoiceType | 'payment_calendar' {
  return isCalendarType(type) || type === 'payment_calendar'
}
