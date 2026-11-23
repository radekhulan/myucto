import { payrollApi, type PayrollRun } from '@/api/payroll'

/**
 * Stavy běhu, ve kterých měsíc ještě čeká na schválení. Zrušený běh a běh
 * čekající na opravu sem nepatří: první je opuštěný, druhý má schválené údaje.
 */
const UNAPPROVED_STATUSES = new Set<PayrollRun['status']>([
  'draft',
  'inputs_locked',
  'calculated',
  'reviewed',
  'reopened',
])

/**
 * Nejstarší měsíc s neschváleným mzdovým během (`RRRR-MM`), nebo `null`.
 *
 * Hromadné dialogy v Zaměstnancích (výchozí zákonné údaje, pojišťovny) dřív
 * předvyplňovaly zpracovávaný měsíc podle dneška. Agentura, která v listopadu
 * dohání únor, tak zapsala údaje platné od října a únor zůstal blokovaný.
 * Seznam běhů bez období kontrolu před během nepočítá, dotaz je levný.
 */
export async function oldestUnapprovedRunPeriod(): Promise<string | null> {
  const page = await payrollApi.runsPage(undefined, { limit: 50 })
  const periods = page.runs
    .filter(run => UNAPPROVED_STATUSES.has(run.status))
    .map(run => run.period_start.slice(0, 7))
    .sort()
  return periods[0] ?? null
}
