import { describe, expect, it } from 'vitest'
import { PERMISSION_KEYS } from '@/security/permissions'
import {
  payrollAgendaLabelKey,
  payrollAgendas,
  payrollCardAgendas,
  payrollQueryId,
  payrollQueryValue,
} from '@/pages/payroll/payrollAgendaLinks'
import cs from '@/i18n/cs.json'
import en from '@/i18n/en.json'

/**
 * Katalog agend je kontrakt mezi kartou zaměstnance, routerem a backendem.
 * Rozejde-li se, tlačítko svítí a routa ho zahodí na homepage — bez jediné
 * chybové hlášky. Proto se kontroluje testem, ne pohledem.
 */
describe('payrollAgendaLinks', () => {
  it('každá agenda míří na existující routu a předává zúžení na člověka', () => {
    for (const agenda of payrollAgendas) {
      const target = agenda.to(12, 5) as { name: string; query: Record<string, string> }
      expect(target.name, agenda.key).toMatch(/^payroll-/)
      if (agenda.scope === 'employment') {
        expect(target.query.employment, agenda.key).toBe('12')
      } else {
        expect(target.query.person, agenda.key).toBe('5')
      }
    }
  })

  it('používá jen oprávnění, která v katalogu práv opravdu existují', () => {
    for (const agenda of payrollAgendas) {
      expect(PERMISSION_KEYS, agenda.key).toContain(agenda.permission)
    }
  })

  it('nemá dvě agendy se stejným klíčem', () => {
    const keys = payrollAgendas.map(agenda => agenda.key)
    expect(new Set(keys).size).toBe(keys.length)
  })

  /*
   * Rychlá tlačítka v řádku seznamu jsou tři. Čtvrté už řádek přeplácá — a
   * o tom, která to jsou, rozhoduje katalog, ne šablona. Pořadí ikon v řádku
   * dědí pořadí katalogu, takže je tady taky: „nepřítomnosti, docházka, vstupy".
   */
  it('drží krátký výběr nejčastějších agend pro řádek seznamu', () => {
    const quick = payrollAgendas.filter(agenda => agenda.quick === true)
    expect(quick.map(agenda => agenda.key)).toEqual(['absences', 'time', 'quick_inputs'])
  })

  /*
   * Pořadí je produktové rozhodnutí (jak často k agendě účetní chodí), ne
   * pořadí vzniku — a je to KONTRAKT s backendem, kde totéž drží `AGENDA_KEYS`.
   * Shodu obou stran hlídá `PayrollEnumContractTest`, který tenhle soubor čte;
   * tady je pořadí zafixované jmenovitě, aby přeskládání nebyl tichý side effect
   * přidání jedné agendy doprostřed.
   */
  it('řadí agendy podle toho, jak často se používají', () => {
    expect(payrollAgendas.map(agenda => agenda.key)).toEqual([
      // měsíční rutina
      'absences', 'time', 'quick_inputs',
      // osobní evidence, na které stojí správnost výpočtu
      'statutory_evidence', 'dependants',
      // občasné agendy
      'components', 'travel', 'average_earnings',
      'deduction_agreements', 'enforcement', 'insolvency',
      // výstupy
      'documents', 'annual_settlement',
    ])
  })

  /*
   * Karta ukazuje CELÝ katalog, i agendy, které má na téže stránce vlastním
   * panelem. Vynechané byly, dokud dlaždice neuměla počet; s číslem nese
   * informaci sama o sobě („u nováčka je prázdno" je důvod tam jít) a proklik
   * odscrolluje na panel. Zafixované, ať to nikdo nevrátí zpátky.
   */
  it('rozcestník karty ukazuje všechny agendy katalogu, i ty s vlastním panelem', () => {
    const keys = payrollCardAgendas.map(agenda => agenda.key)
    expect(keys).toContain('statutory_evidence')
    expect(keys).toContain('dependants')
    expect(keys).toContain('insolvency')
    expect(keys).toEqual(payrollAgendas.map(agenda => agenda.key))
  })

  it('každá agenda má český i anglický popisek', () => {
    for (const agenda of payrollAgendas) {
      const path = payrollAgendaLabelKey(agenda.key).split('.')
      const read = (dict: unknown) =>
        path.reduce<unknown>((node, part) => (node as Record<string, unknown>)?.[part], dict)
      expect(read(cs), agenda.key).toBeTypeOf('string')
      expect(read(en), agenda.key).toBeTypeOf('string')
    }
  })

  it('bere z opakovaného query parametru první hodnotu, ne pole', () => {
    expect(payrollQueryValue({ employment: ['7', '9'] }, 'employment')).toBe('7')
    expect(payrollQueryValue({ employment: '' }, 'employment')).toBeNull()
    expect(payrollQueryValue({}, 'employment')).toBeNull()
  })

  it('nepustí dál nečíselné ani nekladné id', () => {
    expect(payrollQueryId({ employment: '7' }, 'employment')).toBe(7)
    expect(payrollQueryId({ employment: 'abc' }, 'employment')).toBeNull()
    expect(payrollQueryId({ employment: '0' }, 'employment')).toBeNull()
    expect(payrollQueryId({ employment: '-3' }, 'employment')).toBeNull()
    expect(payrollQueryId({ employment: '7.5' }, 'employment')).toBeNull()
  })
})
