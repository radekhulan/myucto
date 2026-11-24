import { useI18n } from 'vue-i18n'
import type { RouteLocationRaw } from 'vue-router'
import type {
  PayrollDeadlineGroup,
  PayrollDeadlineItem,
  PayrollDeadlinePhase,
  PayrollDeadlineSource,
} from '@/api/payroll'
import { usePayrollLabels } from '@/composables/usePayrollLabels'
import { formatMoneyMinor } from '@/composables/useFormat'

/**
 * Popisky a prokliky přehledu termínů. Sdílí je panel na přehledu mezd
 * i rozbalený seznam lidí jedné skupiny, aby o téže povinnosti neříkaly
 * dvě různé věci.
 */

/**
 * Pořadí je pořadím naléhavosti. `awaiting_result` a `action_required` posílá
 * posuzovač podání navíc; ztratit se nesmí, takže mají vlastní místo na konci.
 */
export const PHASE_ORDER: PayrollDeadlinePhase[] = [
  'overdue',
  'due_today',
  'due_soon',
  'action_required',
  'awaiting_result',
  'open',
]

/**
 * Nevyřízené položky checklistu BEZ termínu (přihláška ČSSZ u nástupu před
 * 1. 7. 2026, potvrzení o příjmech na žádost…). Posílá je jen seskupený
 * přehled, vždy až za všemi lhůtami, a do fází souhrnu se nesčítají.
 */
export const UNDATED_PHASE = 'undated'

export type PayrollDeadlinePanelPhase = PayrollDeadlinePhase | typeof UNDATED_PHASE

export const PANEL_PHASE_ORDER: PayrollDeadlinePanelPhase[] = [...PHASE_ORDER, UNDATED_PHASE]

export function isUndatedPhase(phase: string): boolean {
  return phase === UNDATED_PHASE
}

export const PHASE_TONE: Record<PayrollDeadlinePanelPhase, string> = {
  overdue: 'border-danger-500/40 bg-danger-50',
  due_today: 'border-warning-500/40 bg-warning-50',
  due_soon: 'border-warning-500/25 bg-warning-50/50',
  action_required: 'border-warning-500/25 bg-warning-50/50',
  awaiting_result: 'border-neutral-200 bg-neutral-50',
  open: 'border-neutral-200 bg-neutral-50',
  undated: 'border-dashed border-neutral-300 bg-neutral-50',
}

export const PHASE_BADGE: Record<PayrollDeadlinePanelPhase, string> = {
  overdue: 'bg-danger-50 text-danger-700',
  due_today: 'bg-warning-50 text-warning-800',
  due_soon: 'bg-warning-50 text-warning-700',
  action_required: 'bg-warning-50 text-warning-700',
  awaiting_result: 'bg-neutral-100 text-neutral-600',
  open: 'bg-neutral-100 text-neutral-600',
  undated: 'bg-neutral-100 text-neutral-600',
}

export function usePayrollDeadlineLabels() {
  const { t, te } = useI18n()
  const { submissionAgendaLabel } = usePayrollLabels()

  /**
   * Agendové kódy jdou přes sdílený slovník podání; ostatní prameny mají
   * vlastní číselníky a nepřeložený kód se ukáže tak, jak je.
   */
  function titleFor(source: PayrollDeadlineSource, title: string): string {
    if (source === 'submission' || source === 'sickness_case') {
      return submissionAgendaLabel(title)
    }
    const path = source === 'levy'
      ? `payroll.payments.kind.${title}`
      : source === 'registration_change'
        ? `payroll.people.registration.changes.duty_short.${title}`
        : source === 'tax_statement'
          ? `payroll.dashboard.deadlines.tax_statement.form.${title}`
          : source === 'annual_settlement' || source === 'foreign_permit'
            || source === 'taxable_income_request' || source === 'pension_request'
            || source === 'business_trip'
            ? `payroll.dashboard.deadlines.kind.${title}`
            : `payroll.people.checklist.${title}`
    return te(path) ? t(path) : title
  }

  function itemTitle(item: PayrollDeadlineItem): string {
    return titleFor(item.source, item.title)
  }

  /** Roční vyúčtování nemá jméno, zato rok; `period` by lhal jako měsíc. */
  function itemSubject(item: PayrollDeadlineItem): string {
    if (item.source === 'tax_statement' && item.statement_year !== undefined) {
      return t('payroll.dashboard.deadlines.tax_statement.subject', {
        year: item.statement_year,
      })
    }
    if (item.source === 'annual_settlement' && item.undecided_count !== undefined) {
      return t('payroll.dashboard.deadlines.annual_settlement_undecided', {
        count: item.undecided_count,
        year: item.statement_year ?? '',
      })
    }
    if (item.source === 'foreign_permit' && item.permit_label) {
      return `${item.subject} · ${item.permit_label}`
    }
    if (item.source === 'business_trip' && item.trip_label) {
      return `${item.subject} · ${item.trip_label}`
    }
    if ((item.source === 'taxable_income_request' || item.source === 'pension_request') && item.period) {
      return `${item.subject} · ${item.period}`
    }
    return item.subject
  }

  /**
   * Kam se to řeší. Pojmenované routy místo serverové `path` — přežijí
   * přesun cesty a stránka lidí umí rovnou konečný tvar s dotazem.
   */
  function itemLink(item: PayrollDeadlineItem): RouteLocationRaw {
    if (item.source === 'levy') return { name: 'payroll-payments' }
    if ((item.source === 'checklist' || item.source === 'registration_change')
      && item.employee_id !== undefined
    ) {
      return { name: 'payroll-people', query: { person: String(item.employee_id) } }
    }
    if (item.source === 'checklist') return { name: 'payroll-people' }
    if (item.source === 'foreign_permit' && item.employee_id !== undefined) {
      return { name: 'payroll-people', query: { person: String(item.employee_id) } }
    }
    if ((item.source === 'taxable_income_request' || item.source === 'pension_request')
      && item.employee_id !== undefined
    ) {
      const query: Record<string, string> = {
        person: String(item.employee_id),
        panel: item.source === 'pension_request' ? 'pension_requests' : 'taxable_income_requests',
      }
      if (item.employment_id !== undefined && item.employment_id !== null) {
        query.employment = String(item.employment_id)
      }
      return { name: 'payroll-people', query }
    }
    if (item.source === 'business_trip') {
      const query: Record<string, string> = {}
      if (item.trip_period) query.period = item.trip_period
      if (item.trip_id !== undefined) query.trip = String(item.trip_id)
      return { name: 'payroll-travel', query }
    }
    if (item.source === 'annual_settlement') {
      const query: Record<string, string> = {}
      if (item.statement_year !== undefined) query.year = String(item.statement_year)
      if (item.employee_id !== undefined && item.employee_id !== null) {
        query.person = String(item.employee_id)
      }
      return { name: 'payroll-annual-settlement', query }
    }
    if (item.source === 'tax_statement') {
      return {
        name: 'payroll-dashboard',
        query: item.statement_year === undefined
          ? {}
          : { taxStatementYear: String(item.statement_year) },
        hash: '#payroll-tax-statement',
      }
    }
    if (item.source === 'sickness_case') {
      return { name: 'payroll-submissions-tab', params: { tab: 'sickness' } }
    }
    return { name: 'payroll-submissions' }
  }

  /** „Po termínu o 3 dny" / „Zbývají 3 dny" — znaménko drží backend. */
  function daysLabel(daysToDue: number): string {
    const days = Math.abs(daysToDue)
    if (daysToDue < 0) return t('payroll.dashboard.deadlines.overdue_by', days)
    if (daysToDue === 0) return t('payroll.dashboard.deadlines.due_today_label')
    return t('payroll.dashboard.deadlines.due_in', days)
  }

  /**
   * Odeslané podání čeká na výsledek i po lhůtě; „po termínu o N dnů" by ho
   * vydávalo za nesplněné. Že odešlo pozdě, říká text, ne fáze.
   */
  function dueLabel(item: PayrollDeadlineItem): string {
    if (isUndatedPhase(item.phase) || item.days_to_due === null) {
      return t('payroll.dashboard.deadlines.undated_label')
    }
    if (item.phase === 'awaiting_result') {
      return item.submitted_late
        ? t('payroll.dashboard.deadlines.submitted_late_label')
        : t('payroll.dashboard.deadlines.awaiting_label')
    }
    return daysLabel(item.days_to_due)
  }

  /** Odpověď na podání odeslané datovou schránkou se tam vyzvedává ručně. */
  function awaitsIsdsReply(item: PayrollDeadlineItem): boolean {
    return item.phase === 'awaiting_result' && item.dispatched_via_isds === true
  }

  function amountLabel(item: PayrollDeadlineItem): string {
    if (item.remaining_minor === undefined) return ''
    return t('payroll.dashboard.deadlines.remaining', {
      amount: formatMoneyMinor(item.remaining_minor),
    })
  }

  /** U skupiny se ukazuje nejstarší prodlení; „nejstarší" jen když se liší. */
  function groupDueLabel(group: PayrollDeadlineGroup): string {
    if (isUndatedPhase(group.phase) || group.min_days_to_due === null) {
      return t('payroll.dashboard.deadlines.undated_label')
    }
    if (group.phase === 'awaiting_result') {
      return t('payroll.dashboard.deadlines.awaiting_label')
    }
    const label = daysLabel(group.min_days_to_due)
    return group.min_days_to_due === group.max_days_to_due
      ? label
      : t('payroll.dashboard.deadlines.oldest', { label })
  }

  function groupCountLabel(group: PayrollDeadlineGroup): string {
    return group.per_person
      ? t('payroll.dashboard.deadlines.people_count', group.count)
      : t('payroll.dashboard.deadlines.items_count', group.count)
  }

  return {
    titleFor,
    itemTitle,
    itemSubject,
    itemLink,
    daysLabel,
    dueLabel,
    awaitsIsdsReply,
    amountLabel,
    groupDueLabel,
    groupCountLabel,
  }
}
