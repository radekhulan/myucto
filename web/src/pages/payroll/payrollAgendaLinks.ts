import type { LocationQuery, RouteLocationRaw } from 'vue-router'
import type { ActionIcon, ActionVariant } from '@/components/ui/buttonStyles'
import type { PayrollAgendaKey } from '@/api/payroll'
import type { PermissionKey } from '@/security/permissions'

/**
 * Rozcestník z karty zaměstnance do navazujících mzdových agend.
 *
 * Why: většina toho, co se u člověka pořizuje, nebydlí na jeho kartě — visí to
 * na pracovním vztahu v samostatné agendě. Karta o tom dosud mlčela, takže
 * uživatel odešel do menu, našel agendu a v ní zaměstnance hledal znovu.
 *
 * Katalog je JEDEN pro tlačítka i pro souhrn, aby se nemohly rozejít: pořadí,
 * popisek, ikona i cíl odkazu jsou tady, ne rozsypané po šabloně. Pořadí zrcadlí
 * `PayrollEmploymentAgendaSummaryRepository::AGENDA_KEYS` na backendu.
 *
 * `permission` musí sedět na `routePermissions` v `web/src/router/index.ts` —
 * kdyby se rozešly, tlačítko svítí a routa ho zahodí na homepage.
 */
/**
 * Klíč položky ROZCESTNÍKU — dnes totožný s {@see PayrollAgendaKey}.
 *
 * Byla to nadmnožina, dokud souhrn neuměl spočítat insolvenci, zákonnou
 * evidenci a vyživované osoby; v přehledu pak visely bez čísla a účetní z nich
 * nepoznala „nic tam není" od „nezeptali jsme se". Alias zůstává jako JMÉNO
 * role: katalog odkazů a souhrn ze serveru jsou dvě různé odpovědnosti, které
 * se zase můžou rozejít (odkaz na agendu, která se počítat nedá).
 */
export type PayrollAgendaLinkKey = PayrollAgendaKey

export interface PayrollAgendaDefinition {
  key: PayrollAgendaLinkKey
  /** Podle čeho se agenda zužuje: `employment` = vztah, `person` = osoba. */
  scope: 'employment' | 'person'
  permission: PermissionKey
  icon: ActionIcon
  variant: ActionVariant
  /**
   * Nejčastější denní práce účetní. V seznamu lidí se ukáže jako ikona přímo
   * v řádku; zbytek katalogu je až v nabídce „Další". Držet krátké — každá další
   * ikona ubírá řádku čitelnost, kterou má tenhle výběr chránit.
   */
  quick?: boolean
  /** Cíl odkazu i s předvyplněným zúžením na daného člověka. */
  to: (employmentId: number, employeeId: number) => RouteLocationRaw
}

/**
 * ⚠️ POŘADÍ je produktové rozhodnutí, ne pořadí vzniku — a musí se krýt
 * s `PayrollEmploymentAgendaSummaryRepository::AGENDA_KEYS` (hlídá
 * `PayrollEnumContractTest::testAgendaCatalogOrderMatchesTheClientCatalog()`).
 * Řadí se podle toho, jak často k agendě účetní chodí:
 *   1) měsíční rutina — nepřítomnosti, docházka, mzdové vstupy,
 *   2) osobní evidence, která rozhoduje o SPRÁVNOSTI výpočtu — chybějící
 *      prohlášení k dani nebo nezadané dítě se neprojeví jako chybějící záznam,
 *      ale jako špatně spočítaná mzda, takže patří nahoru, ne na konec mřížky,
 *   3) občasné agendy,
 *   4) výstupy na konec.
 */
export const payrollAgendas: readonly PayrollAgendaDefinition[] = [
  {
    key: 'absences',
    scope: 'employment',
    permission: 'payroll',
    icon: 'calendar',
    variant: 'success',
    quick: true,
    to: employmentId => ({ name: 'payroll-absences', query: { employment: String(employmentId) } }),
  },
  {
    key: 'time',
    scope: 'employment',
    permission: 'payroll',
    icon: 'clipboardCheck',
    variant: 'primary',
    quick: true,
    to: employmentId => ({ name: 'payroll-time', query: { employment: String(employmentId) } }),
  },
  {
    key: 'quick_inputs',
    scope: 'employment',
    permission: 'payroll',
    icon: 'coin',
    variant: 'primary',
    quick: true,
    to: employmentId => ({
      name: 'payroll-quick-inputs',
      query: { employment: String(employmentId) },
    }),
  },
  {
    /*
     * Zákonná evidence (prohlášení k dani, zdravotní pojišťovna) a vyživované
     * osoby jsou panely karty osoby, ne samostatná stránka. Ze seznamu k nim
     * nevedla žádná zkratka — účetní musela otevřít kartu a panel v ní najít.
     * Odkaz proto míří na kartu a rovnou otevře ten správný panel.
     *
     * V rozcestníku vztahu byly chvíli vynechané (`showOnCard: false`), protože
     * odkaz „sem, kde právě stojíš" bez čísla nic nepřidával. S POČTEM je to
     * jiná věc: dlaždice nese informaci sama o sobě — a právě u nováčka, kde je
     * prázdno, je to důvod tam jít. Proklik navíc odscrolluje na správný panel
     * dlouhé stránky (`?panel=` → `PeopleList.focusPanel`), takže to není odkaz
     * naprázdno, ale navigace.
     */
    key: 'statutory_evidence',
    scope: 'person',
    permission: 'payroll',
    icon: 'badgeCheck',
    variant: 'neutral',
    to: (_employmentId, employeeId) => ({
      name: 'payroll-people',
      query: { person: String(employeeId), panel: 'statutory_evidence' },
    }),
  },
  {
    key: 'dependants',
    scope: 'person',
    permission: 'payroll',
    icon: 'user',
    variant: 'neutral',
    to: (_employmentId, employeeId) => ({
      name: 'payroll-people',
      query: { person: String(employeeId), panel: 'dependants' },
    }),
  },
  {
    key: 'components',
    scope: 'employment',
    permission: 'payroll',
    icon: 'cycle',
    variant: 'primary',
    to: employmentId => ({
      name: 'payroll-components',
      query: { employment: String(employmentId), tab: 'recurring' },
    }),
  },
  {
    key: 'travel',
    scope: 'employment',
    permission: 'payroll',
    icon: 'swap',
    variant: 'primary',
    to: employmentId => ({ name: 'payroll-travel', query: { employment: String(employmentId) } }),
  },
  {
    // Průměrný výdělek nemá vlastní routu — je to záložka nepřítomností, protože
    // se z něj počítá náhrada mzdy. Odkaz proto míří tam a rovnou přepne záložku.
    key: 'average_earnings',
    scope: 'employment',
    permission: 'payroll',
    icon: 'chart',
    variant: 'neutral',
    to: employmentId => ({
      name: 'payroll-absences',
      query: { employment: String(employmentId), tab: 'averages' },
    }),
  },
  {
    key: 'deduction_agreements',
    scope: 'person',
    permission: 'payroll',
    icon: 'tag',
    variant: 'warning',
    to: (_employmentId, employeeId) => ({
      name: 'payroll-deduction-agreements',
      query: { person: String(employeeId) },
    }),
  },
  {
    key: 'enforcement',
    scope: 'person',
    permission: 'payroll.enforcement',
    icon: 'lock',
    variant: 'warning',
    to: (_employmentId, employeeId) => ({
      name: 'payroll-enforcement',
      query: { person: String(employeeId) },
    }),
  },
  {
    /*
     * Insolvence je vlastní právo (`payroll.insolvency`), ne součást exekucí:
     * splátkový kalendář oddlužení čte a zapisuje jiná role než ta, která vede
     * srážky z exekučních příkazů. Bez téhle položky se k němu od člověka
     * nedalo dostat jinak než přes menu a nové vyhledání osoby.
     */
    key: 'insolvency',
    scope: 'person',
    permission: 'payroll.insolvency',
    icon: 'bell',
    variant: 'warning',
    to: (_employmentId, employeeId) => ({
      name: 'payroll-insolvency',
      query: { person: String(employeeId) },
    }),
  },
  {
    key: 'documents',
    scope: 'person',
    permission: 'payroll.documents',
    icon: 'doc',
    variant: 'neutral',
    to: (_employmentId, employeeId) => ({
      name: 'payroll-documents',
      query: { person: String(employeeId), tab: 'annual' },
    }),
  },
  {
    key: 'annual_settlement',
    scope: 'person',
    permission: 'payroll.documents',
    icon: 'archive',
    variant: 'neutral',
    to: (_employmentId, employeeId) => ({
      name: 'payroll-annual-settlement',
      query: { person: String(employeeId) },
    }),
  },
]

/**
 * Rozcestník karty pracovního vztahu — dnes CELÝ katalog.
 *
 * Vlastní jméno si drží schválně: panel na kartě a nabídka v řádku seznamu jsou
 * dvě různá místa se dvěma různými nároky a už jednou se rozešly (karta chvíli
 * vynechávala zákonnou evidenci a vyživované osoby). Až bude zase důvod něco
 * z karty vypustit, je to jeden filtr tady — ne úprava rozeseté po šablonách.
 */
export const payrollCardAgendas: readonly PayrollAgendaDefinition[] = payrollAgendas

/** Popisek agendy je JEDEN pro lištu, nabídku i souhrn — jinak se rozejdou. */
export function payrollAgendaLabelKey(key: PayrollAgendaLinkKey): string {
  return `payroll.agendas.items.${key}`
}

/**
 * Jedna hodnota z query stringu. Vue Router u opakovaného klíče vrací pole —
 * bez tohohle by `?employment=1&employment=2` skončilo na `NaN` a stránka by
 * mlčky ukázala neselektovaný seznam.
 */
export function payrollQueryValue(query: LocationQuery, name: string): string | null {
  const value = query[name]
  const raw = Array.isArray(value) ? value[0] : value
  return typeof raw === 'string' && raw !== '' ? raw : null
}

/** Kladné celé id z query stringu; cokoli jiného je slepý odkaz, ne chyba. */
export function payrollQueryId(query: LocationQuery, name: string): number | null {
  const raw = payrollQueryValue(query, name)
  if (raw === null) return null
  const id = Number(raw)
  return Number.isInteger(id) && id > 0 ? id : null
}
