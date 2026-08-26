/**
 * Pomocné formátovací funkce pro peníze, datumy a procentua.
 */

import { i18n } from '@/i18n'

// Per-currency default decimals (ISO 4217). JPY/KRW/HUF = 0, BHD/JOD = 3, ostatní 2.
// Volající může override přes parametr `decimals`.
const CURRENCY_DECIMALS: Record<string, number> = {
  JPY: 0, KRW: 0, HUF: 0, ISK: 0, CLP: 0, VND: 0,
  BHD: 3, IQD: 3, JOD: 3, KWD: 3, LYD: 3, OMR: 3, TND: 3,
}

function defaultDecimals(currency: string): number {
  return CURRENCY_DECIMALS[(currency || '').toUpperCase()] ?? 2
}

function activeLocale(): string {
  return i18n.global.locale.value === 'en' ? 'en-US' : 'cs-CZ'
}

export function formatMoney(value: number | null | undefined, currency: string = 'CZK', decimals?: number): string {
  if (value === null || value === undefined || Number.isNaN(value)) return '—'
  const dec = decimals ?? defaultDecimals(currency)
  const formatter = new Intl.NumberFormat(activeLocale(), {
    style: 'decimal',
    minimumFractionDigits: dec,
    maximumFractionDigits: dec,
  })
  const symbol = currency === 'CZK' ? 'Kč' : currency === 'EUR' ? '€' : currency
  // Nezlomitelná mezera (U+00A0) před jednotkou. S obyčejnou mezerou se v úzkém
  // sloupci (účetní deník, banka) odlomilo „Kč" na druhý řádek a částka se
  // rozpadla na dva řádky. Odpovídá to i české typografii — číslo a jednotka
  // k sobě patří. Oddělovač tisíců z Intl(cs-CZ) je nezlomitelný už sám o sobě.
  return `${formatter.format(value)} ${symbol}`
}

/**
 * Peníze uložené v setinách (minor units) — tak je drží celý mzdový modul
 * i banka, aby se na částkách nikdy neprojevila plovoucí čárka.
 *
 * Existuje zvlášť od `formatMoney`, protože rozdíl mezi „12,34 Kč" a
 * „1 234,00 Kč" je jen v tom, kdo dělí stem — a když to dělá každá stránka
 * sama, dřív nebo později to jedna zapomene.
 */
export function formatMoneyMinor(
  value: number | null | undefined,
  currency: string = 'CZK',
  decimals?: number,
): string {
  if (value === null || value === undefined || Number.isNaN(value)) return '—'
  return formatMoney(value / 100, currency, decimals)
}

export function formatNumber(
  value: number | null | undefined,
  options: Intl.NumberFormatOptions = {},
): string {
  if (value === null || value === undefined || Number.isNaN(value)) return '—'
  return new Intl.NumberFormat(activeLocale(), options).format(value)
}

export function formatCompactNumber(value: number | null | undefined): string {
  if (value === null || value === undefined || Number.isNaN(value)) return '—'
  const fractionDigits = Math.abs(value) >= 1_000_000 ? 1 : 0
  return formatNumber(value, {
    notation: 'compact',
    compactDisplay: 'short',
    minimumFractionDigits: fractionDigits,
    maximumFractionDigits: fractionDigits,
  })
}

export function formatDate(date: string | null | undefined): string {
  if (!date) return '—'
  // „2026-08-15 10:30:00" (tvar, ve kterém timestampy vrací API) není podle
  // specifikace platný vstup `new Date()` — V8 ho spolkne, jiné enginy z něj
  // udělají NaN. Mezera na „T" to sjednotí; časová složka se stejně zahodí.
  // Holé „YYYY-MM-DD" spolkne `new Date()` jako PŮLNOC V UTC, takže v záporných
  // zónách (uživatel v USA) vyjde o den dřív. Kalendářní datum žádný okamžik nenese,
  // proto se skládá z lokálních složek.
  const dateOnly = /^(\d{4})-(\d{2})-(\d{2})$/.exec(date)
  const d = dateOnly
    ? new Date(Number(dateOnly[1]), Number(dateOnly[2]) - 1, Number(dateOnly[3]))
    : new Date(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/.test(date) ? date.replace(' ', 'T') : date)
  if (Number.isNaN(d.getTime())) return date
  return new Intl.DateTimeFormat(activeLocale(), { day: '2-digit', month: '2-digit', year: 'numeric' }).format(d)
}

export function formatDateTime(date: string | null | undefined): string {
  if (!date) return '—'
  const normalized = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/.test(date)
    ? date.replace(' ', 'T')
    : date
  const d = new Date(normalized)
  if (Number.isNaN(d.getTime())) return date
  return new Intl.DateTimeFormat(activeLocale(), {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(d)
}

export function formatUtcDateTime(date: string | null | undefined): string {
  if (!date) return '—'
  const normalized = /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/.test(date)
    ? `${date.replace(' ', 'T')}Z`
    : date
  return formatDateTime(normalized)
}

/**
 * Období „YYYY-MM" jako „srpen 2026" / „August 2026".
 *
 * Strojové `2026-08` je pro člověka horší než zbytečné — v sestavě plné dat
 * ve tvaru `01.08.2026` se od nich pohledem nedá odlišit a čte se jako
 * useknuté datum. Kdekoli se období ukazuje uživateli, patří sem.
 *
 * Vstup, který na období nevypadá, se vrací beze změny — je to popisek, ne
 * validace, a pád na půl vykreslené sestavy by byl horší než syrový řetězec.
 */
export function formatPeriod(period: string | null | undefined): string {
  if (!period) return '—'
  const [y, m] = period.split('-').map(Number)
  if (!y || !m || m < 1 || m > 12) return period
  const monthsCs = ['leden', 'únor', 'březen', 'duben', 'květen', 'červen', 'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec']
  const monthsEn = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December']
  const months = i18n.global.locale.value === 'en' ? monthsEn : monthsCs
  return `${months[m - 1]} ${y}`
}

/** Historický název `formatPeriod`; drží se kvůli volajícím mimo mzdy. */
export function formatMonth(yyyymm: string): string {
  return yyyymm ? formatPeriod(yyyymm) : yyyymm
}

export function formatPercent(value: number | null | undefined, decimals?: number): string {
  if (value === null || value === undefined || Number.isNaN(value)) return '—'
  const fractionDigits = decimals ?? (value % 1 === 0 ? 0 : 2)
  return `${formatNumber(value, {
    minimumFractionDigits: fractionDigits,
    maximumFractionDigits: fractionDigits,
  })} %`
}

/**
 * Splatnost jako „14 dní" / „1 měsíc" / „3× měsíc".
 *
 * `unit === null` neznamená dny, ale „dědí se z nadřazené úrovně" — u klienta
 * tedy z dodavatele (backend to řeší v PaymentDueResolver). Bez `fallbackUnit`
 * by se klientovi se zděděnou měsíční splatností zobrazovaly dny.
 */
export function paymentDueLabel(
  value: number | null | undefined,
  unit: 'days' | 'month' | null | undefined,
  fallbackUnit: 'days' | 'month' | null | undefined,
  emptyLabel: string,
): string {
  if (value === null || value === undefined) return emptyLabel
  const t = i18n.global.t
  if ((unit ?? fallbackUnit ?? 'days') === 'month') {
    return value === 1
      ? t('client.payment_due_preset_month')
      : `${value}× ${t('client.payment_due_preset_month').toLowerCase()}`
  }
  return t('client.due_days_n', { n: value })
}

export function statusLabel(status: string): string {
  const t = i18n.global.t
  const key = `status.${status}`
  const v = t(key)
  return v === key ? status : v
}

export function typeLabel(type: string): string {
  const t = i18n.global.t
  const key = `type.${type}`
  const v = t(key)
  return v === key ? type : v
}

/**
 * Vrací class string pro status badge.
 *
 * Pravidlo „barva = anomálie": běžný průběh života dokladu (koncept → vystaveno →
 * odesláno → zaplaceno) je tichý — barvu nese jen 6px tečka, text zůstává neutrální.
 * Plnou výplň dostávají výhradně stavy, které po uživateli něco chtějí nebo
 * signalizují nesoulad (upomínáno, částečně uhrazeno, přeplaceno). Díky tomu
 * v seznamu stovky faktur okamžitě vidíš ty, kterými se musíš zabývat.
 *
 * Vzhled samotných tříd je v styles/main.css (.badge-*).
 */
export function statusBadgeClass(status: string): string {
  const classes: Record<string, string> = {
    draft:     'badge badge-quiet badge-draft',
    issued:    'badge badge-quiet badge-issued',
    sent:      'badge badge-quiet badge-sent',
    paid:      'badge badge-quiet badge-paid',
    cancelled: 'badge badge-quiet badge-cancelled',
    reminded:        'badge badge-loud badge-reminded bg-warning-50 text-warning-700',
    // Odvozený platební stav (#89) — zobrazuje se místo lifecycle badge, když nese informaci.
    partially_paid:  'badge badge-loud badge-partial bg-amber-50 text-amber-700',
    overpaid:        'badge badge-loud badge-overpaid bg-purple-50 text-purple-700',
  }
  return classes[status] ?? 'badge badge-quiet badge-draft'
}

/**
 * Badge stav k zobrazení: lifecycle status, přepsaný odvozeným platebním stavem
 * (partially_paid / overpaid), pokud nese informaci navíc (#89).
 */
export function displayStatus(status: string, paymentStatus?: string | null): string {
  if (paymentStatus === 'partially_paid') return 'partially_paid'
  if (paymentStatus === 'overpaid') return 'overpaid'
  return status
}

/**
 * Drobné barevné odlišení DUZP (tax_date), když se liší od data vystavení:
 *  - DUZP dříve než vystaveno (nižší)   → amber (text-warning-600)
 *  - DUZP později než vystaveno (vyšší) → modrá (text-accent-600)
 *  - shodné / chybějící                 → neutrální
 * Porovnává ISO řetězce 'YYYY-MM-DD' (lexikograficky = chronologicky), bez parsování.
 */
export function taxDateClass(taxDate: string | null | undefined, issueDate: string | null | undefined): string {
  if (!taxDate || !issueDate || taxDate === issueDate) return 'text-neutral-600'
  return taxDate < issueDate ? 'text-warning-600' : 'text-accent-600'
}

export function isOverdue(dueDate: string, status: string): boolean {
  if (status !== 'issued' && status !== 'sent' && status !== 'reminded') return false
  const due = new Date(dueDate)
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  return due <= today
}

/**
 * Vrací CSS classy pro řádek faktury podle stavu — vizuální zvýraznění:
 *  - overdue (issued/sent + past due_date) → jemný červený podklad
 *  - paid                                  → ztlumený text (jako „hotovo")
 *  - cancelled                             → ještě více ztlumený + přeškrtnutý
 *  - jinak                                 → bez změny
 */
export function invoiceRowClass(dueDate: string, status: string): string {
  // Po splatnosti: 2px hrana vlevo (.row-flagged) + jen velmi jemný tint.
  // Plošná červená byla při dvaceti řádcích na obrazovce hlučnější než signál,
  // který měla nést.
  if (isOverdue(dueDate, status)) return 'row-flagged bg-danger-50/35 hover:bg-danger-50'
  // Zaplaceno je normální stav, ne úspěch hodný zvýraznění — tint pryč,
  // informaci nese tichý badge se zelenou tečkou.
  if (status === 'paid')      return 'hover:bg-neutral-50'
  if (status === 'cancelled') return 'opacity-40 line-through hover:opacity-70'
  return ''
}
