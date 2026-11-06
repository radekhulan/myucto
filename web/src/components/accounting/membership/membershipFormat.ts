import type { MemberRevision, MemberStatus } from '@/api/nonprofitMembership'
import { formatDate } from '@/composables/useFormat'

/** Částka jako desetinný řetězec na dvě místa (to, co backend čte jako `amount`), jinak null. */
export function amountDecimal(value: string): string | null {
  const text = value.trim().replace(',', '.')
  const match = /^(\d{1,13})(?:\.(\d{1,2}))?$/.exec(text)
  if (!match) return null
  const fraction = (match[2] ?? '').padEnd(2, '0')
  const cents = BigInt(match[1]!) * 100n + BigInt(fraction)
  if (cents <= 0n || cents > 999999999999999n) return null
  return `${match[1]!.replace(/^0+(?=\d)/, '')}.${fraction}`
}

export function isIsoDate(value: string): boolean {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value)
  if (!match) return false
  const date = new Date(Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])))
  return date.toISOString().slice(0, 10) === value
}

export const titleKeyPattern = /^[A-Za-z0-9][A-Za-z0-9_.:-]{0,79}$/

/** Rozsah dvou kalendářních dat stejně jako u ostatních neziskových agend („01. 01. 2026 – 31. 12. 2026“). */
export function formatDateRange(from: string, to: string): string {
  return `${formatDate(from)} – ${formatDate(to)}`
}

/** Štítek stavu ve stejném tvaru jako ostatní štítky aplikace. */
export const badgeBase = 'whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium'

const statusTones: Record<MemberStatus, string> = {
  active: 'bg-success-50 text-success-600',
  suspended: 'bg-warning-50 text-warning-700',
  ended: 'bg-neutral-100 text-neutral-700',
}

export function memberStatusBadge(status: MemberStatus): string {
  return `${badgeBase} ${statusTones[status] ?? statusTones.ended}`
}

/** Chybová hláška ve stejném rámečku jako sousední obrazovky. */
export const errorBox = 'rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700'

/** Upozornění ve stejném tvaru jako chybová hláška. */
export const warningBox = 'rounded-lg border border-warning-500/30 bg-warning-50 p-3 text-sm text-warning-700'

function ordered(history: readonly MemberRevision[]): MemberRevision[] {
  return [...history].sort((a, b) => a.effective_from.localeCompare(b.effective_from) || a.id - b.id)
}

/** Revize platná k datu: poslední, která už začala. Budoucí revize dnešní stav nemění. */
export function revisionAt(history: readonly MemberRevision[], date: string): MemberRevision | null {
  let match: MemberRevision | null = null
  for (const revision of ordered(history)) if (revision.effective_from <= date) match = revision
  return match
}

/**
 * Je členství aktivní po celé období příspěvku? Nápověda formuláře; rozhoduje server.
 * Zrcadlí `MembershipRevisionTimeline::intervals()` a kontrolu pokrytí v `MembershipChargeService::issueContext()`:
 * revize platí od svého dne do začátku další, všechny revize zasahující do období musí být aktivní a první z nich
 * musí začít nejpozději prvním dnem období. Konec poslední zasahující revize server také kontroluje, ale ta je vždy
 * ta, která platí k poslednímu dni období, takže končí až po něm; klient tu podmínku proto neopakuje.
 */
export function activeThroughout(history: readonly MemberRevision[], from: string, to: string): boolean {
  const rows = ordered(history)
  const covered = rows
    .map((row, index) => ({ row, end: rows[index + 1]?.effective_from ?? null }))
    .filter(({ row, end }) => row.effective_from <= to && (end === null || end > from))
  if (!covered.length || covered.some(({ row }) => row.status !== 'active')) return false
  return covered[0]!.row.effective_from <= from
}

export function lastRevision(history: readonly MemberRevision[]): MemberRevision | null {
  return history.reduce<MemberRevision | null>((last, row) => (last === null || row.revision_no > last.revision_no ? row : last), null)
}

export function errorStatus(e: unknown): number {
  return (e as { response?: { status?: number } })?.response?.status ?? 0
}

export function errorCode(e: unknown): string {
  return (e as { response?: { data?: { error?: { code?: string } } } })?.response?.data?.error?.code ?? ''
}

export function errorMessage(e: unknown): string {
  return (e as { response?: { data?: { error?: { message?: string } } } })?.response?.data?.error?.message ?? ''
}
