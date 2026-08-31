import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  monthlyChecklist: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: { monthlyChecklist: m.monthlyChecklist },
}))
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string, parameters?: Record<string, string | number>) =>
      parameters ? `${key} ${Object.values(parameters).join(' ')}` : key,
  }),
}))

import PayrollMonthlyChecklistPanel from '@/pages/payroll/PayrollMonthlyChecklistPanel.vue'

function baseResponse(overrides: Partial<Record<string, unknown>> = {}) {
  return {
    environment: 'production',
    period: '2026-08',
    window: { from: '2026-08-01', to: '2026-08-31' },
    summary: { total: 0, send: 0, generate: 0, manual: 0, done: 0 },
    items: [],
    ...overrides,
  }
}

function mountPanel() {
  return mount(PayrollMonthlyChecklistPanel, {
    props: { environment: 'production' },
    global: {
      stubs: {
        RouterLink: { props: ['to'], template: '<a :data-to="to"><slot /></a>' },
      },
    },
  })
}

describe('PayrollMonthlyChecklistPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('načte přehled za aktuální měsíc a produkci hned po připojení', async () => {
    m.monthlyChecklist.mockResolvedValue(baseResponse())

    mountPanel()
    await flushPromises()

    expect(m.monthlyChecklist).toHaveBeenCalledWith(
      'production',
      expect.stringMatching(/^[0-9]{4}-[0-9]{2}$/),
    )
  })

  it('zobrazí prázdný stav, když firma za období nemá žádnou položku', async () => {
    m.monthlyChecklist.mockResolvedValue(baseResponse())

    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.find('[data-test="monthly-checklist-empty"]').exists()).toBe(true)
  })

  it('u odeslatelné položky nabídne odkaz na existující záložku, ne vlastní odesílání', async () => {
    m.monthlyChecklist.mockResolvedValue(baseResponse({
      summary: { total: 1, send: 1, generate: 0, manual: 0, done: 0 },
      items: [{
        key: 'submission:7',
        source: 'submission',
        agenda_code: 'JMHZ25',
        agenda_label: 'JMHZ',
        subject: 'office:synthetic',
        period: '2026-08',
        due_on: '2026-09-20',
        phase: 'open',
        days_to_due: 30,
        is_overdue: false,
        status: 'ready',
        document: { format: 'XML (JMHZ)', note: '' },
        recipient: { label: 'ČSSZ', note: '', applicable: true },
        channel: { label: 'datová schránka — odesílací brána', note: '', applicable: true },
        done: false,
        action: { kind: 'send', label: 'Odeslat', path: '/payroll/submissions/jmhz', reason: null },
      }],
    }))

    const wrapper = mountPanel()
    await flushPromises()

    const action = wrapper.get('[data-test="monthly-checklist-action"]')
    expect(action.text()).toContain('Odeslat')
    expect(action.attributes('data-to')).toBe('/payroll/submissions/jmhz')
  })

  /**
   * Backend posílá u `submission`/`checklist` jen surový kód — bez tohohle
   * překladu by účetní v přehledu viděla `social_jmhz_change` místo
   * „Změna pro ČSSZ / JMHZ". Tři cesty musí vést na tři různé slovníky:
   * checklist item_key na kartu zaměstnance, kód, který zná katalog Dalších
   * povinností, na jeho slovník, a zbytek na vlastní `monthly_checklist.agenda`.
   */
  it('přeloží agendový kód na lidský název podle zdroje, ne surový kód', async () => {
    m.monthlyChecklist.mockResolvedValue(baseResponse({
      summary: { total: 3, send: 0, generate: 3, manual: 0, done: 0 },
      items: [{
        key: 'submission:1',
        source: 'submission',
        agenda_code: 'JMHZ25',
        agenda_label: 'JMHZ25',
        subject: null,
        period: '2026-08',
        due_on: '2026-09-20',
        phase: 'open',
        days_to_due: 30,
        is_overdue: false,
        status: 'ready',
        document: { format: null, note: '' },
        recipient: { label: null, note: '', applicable: true },
        channel: { label: null, note: '', applicable: true },
        done: false,
        action: { kind: 'generate', label: 'x', path: null, reason: null },
      }, {
        key: 'submission:2',
        source: 'submission',
        agenda_code: 'PREZEC26',
        agenda_label: 'PREZEC26',
        subject: null,
        period: '2026-08',
        due_on: '2026-09-20',
        phase: 'open',
        days_to_due: 30,
        is_overdue: false,
        status: 'ready',
        document: { format: null, note: '' },
        recipient: { label: null, note: '', applicable: true },
        channel: { label: null, note: '', applicable: true },
        done: false,
        action: { kind: 'generate', label: 'x', path: null, reason: null },
      }, {
        key: 'checklist:1',
        source: 'checklist',
        agenda_code: 'social_jmhz_change',
        agenda_label: 'social_jmhz_change',
        subject: 'Cyril Syntetický',
        period: null,
        due_on: '2026-08-15',
        phase: 'open',
        days_to_due: 15,
        is_overdue: false,
        status: 'pending',
        document: { format: null, note: '' },
        recipient: { label: null, note: '', applicable: false },
        channel: { label: null, note: 'x', applicable: true },
        done: false,
        action: { kind: 'generate', label: 'x', path: null, reason: null },
      }],
    }))

    const wrapper = mountPanel()
    await flushPromises()

    const rows = wrapper.findAll('tbody tr[data-test="monthly-checklist-row"]')
    expect(rows).toHaveLength(3)
    // Kód, který katalog Dalších povinností zná → jeho slovník.
    expect(rows[0]!.text()).toContain('payroll.submissions.statutory.agenda.JMHZ25')
    // Kód, který katalog nezná → vlastní slovník měsíčního přehledu.
    expect(rows[1]!.text()).toContain('payroll.submissions.monthly_checklist.agenda.PREZEC26')
    // Checklist item_key → slovník karty zaměstnance.
    expect(rows[2]!.text()).toContain('payroll.people.checklist.social_jmhz_change')
  })

  /**
   * „Netýká se" (pojem na řádek nesedí — úkon v kartě zaměstnance nikam
   * neodchází) je JINÁ informace než „neznámo" (appka to neví, ověřte).
   * Sloupec musí obě rozlišit, ne obojí schovat pod stejné slovo.
   */
  it('rozliší „netýká se" od „neznámo" ve sloupcích Kam a Jakou cestou', async () => {
    m.monthlyChecklist.mockResolvedValue(baseResponse({
      summary: { total: 2, send: 0, generate: 1, manual: 1, done: 0 },
      items: [{
        key: 'checklist:1',
        source: 'checklist',
        agenda_code: 'tax_declaration',
        agenda_label: 'tax_declaration',
        subject: 'Cyril Syntetický',
        period: null,
        due_on: '2026-08-15',
        phase: 'open',
        days_to_due: 15,
        is_overdue: false,
        status: 'pending',
        document: { format: null, note: '' },
        // Nikam neodchází — recipient se řádku netýká.
        recipient: { label: null, note: '', applicable: false },
        channel: { label: null, note: 'Vyřídí se v kartě zaměstnance.', applicable: true },
        done: false,
        action: { kind: 'generate', label: 'x', path: null, reason: null },
      }, {
        key: 'submission:9',
        source: 'submission',
        agenda_code: 'DZMH',
        agenda_label: 'DZMH',
        subject: null,
        period: '2026-08',
        due_on: '2026-09-20',
        phase: 'open',
        days_to_due: 30,
        is_overdue: false,
        status: 'ready',
        document: { format: null, note: 'Neznámo — appka pro tuhle agendu nemá ověřený generátor.' },
        // Appka to skutečně NEZNÁ — musí zůstat „neznámo", ne „netýká se".
        recipient: { label: null, note: '', applicable: true },
        channel: { label: null, note: '', applicable: true },
        done: false,
        action: { kind: 'manual', label: 'x', path: null, reason: 'x' },
      }],
    }))

    const wrapper = mountPanel()
    await flushPromises()

    const rows = wrapper.findAll('tbody tr[data-test="monthly-checklist-row"]')
    expect(rows[0]!.get('[data-test="monthly-checklist-recipient"]').text())
      .toBe('payroll.submissions.monthly_checklist.not_applicable')
    expect(rows[1]!.get('[data-test="monthly-checklist-recipient"]').text())
      .toBe('payroll.submissions.monthly_checklist.unknown')
    expect(rows[1]!.get('[data-test="monthly-checklist-channel"]').text())
      .toBe('payroll.submissions.monthly_checklist.unknown')
  })

  /**
   * U položek, které appka poslat neumí, MUSÍ být vidět jednovětý DŮVOD —
   * ne jen prázdné tlačítko nebo obecné „nepodporováno".
   */
  it('u nepodporované položky ukáže konkrétní důvod, ne jen tlačítko', async () => {
    m.monthlyChecklist.mockResolvedValue(baseResponse({
      summary: { total: 1, send: 0, generate: 0, manual: 1, done: 0 },
      items: [{
        key: 'submission:9',
        source: 'submission',
        agenda_code: 'ELDP',
        agenda_label: 'ELDP',
        subject: 'employment:4',
        period: '2026-08',
        due_on: '2026-09-20',
        phase: 'open',
        days_to_due: 30,
        is_overdue: false,
        status: 'ready',
        document: { format: 'XML (evidenční list důchodového pojištění)', note: '' },
        recipient: { label: 'ČSSZ', note: '', applicable: true },
        channel: { label: null, note: 'Aplikace XML sestaví a zvaliduje, ale odeslání nemá zapojené.', applicable: true },
        done: false,
        action: {
          kind: 'manual',
          label: 'Otevřít evidenční listy',
          path: '/payroll/submissions/eldp',
          reason: 'Appka evidenční list jen sestaví — odešlete ho ručně datovou schránkou nebo přes VREP.',
        },
      }],
    }))

    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.get('[data-test="monthly-checklist-reason"]').text())
      .toContain('odešlete ho ručně')
  })

  /**
   * Sloupec „Stav" je jeden ze sedmi povinných údajů zadání. Podání má
   * SKUTEČNÝ stav platformy podání (sdílený slovník), zatímco odvod/checklist
   * mají jen vlastní čtyři stavy z `PayrollMonthlyChecklistService` — obojí
   * musí být vidět, ne jen fáze lhůty.
   */
  it('ukáže skutečný stav podání i vlastní stav pramenů bez podání', async () => {
    m.monthlyChecklist.mockResolvedValue(baseResponse({
      summary: { total: 2, send: 0, generate: 2, manual: 0, done: 0 },
      items: [{
        key: 'submission:7',
        source: 'submission',
        agenda_code: 'JMHZ25',
        agenda_label: 'JMHZ',
        subject: 'office:synthetic',
        period: '2026-08',
        due_on: '2026-09-20',
        phase: 'open',
        days_to_due: 30,
        is_overdue: false,
        status: 'ready',
        document: { format: 'XML (JMHZ)', note: '' },
        recipient: { label: 'ČSSZ', note: '', applicable: true },
        channel: { label: null, note: '', applicable: false },
        done: false,
        action: { kind: 'generate', label: 'Připravit podání', path: '/payroll/submissions/jmhz', reason: null },
      }, {
        key: 'levy:1',
        source: 'levy',
        agenda_code: 'statutory_insurance',
        agenda_label: 'Zákonné pojištění odpovědnosti zaměstnavatele (úrazové)',
        subject: 'institution:statutory_insurance:123',
        period: null,
        due_on: '2026-08-31',
        phase: 'open',
        days_to_due: 5,
        is_overdue: false,
        status: 'open',
        document: { format: null, note: 'Bez dokumentu — jde o platbu, ne o podání.' },
        recipient: { label: 'institution:statutory_insurance:123', note: '', applicable: true },
        channel: { label: 'bankovní převod', note: '', applicable: true },
        done: false,
        action: { kind: 'generate', label: 'Otevřít platby', path: '/payroll/payments', reason: null },
      }],
    }))

    const wrapper = mountPanel()
    await flushPromises()

    const statuses = wrapper.findAll('tbody [data-test="monthly-checklist-status"]')
    expect(statuses).toHaveLength(2)
    expect(statuses[0]!.text()).toBe('payroll.submissions.overview.status.ready')
    expect(statuses[1]!.text()).toBe('payroll.submissions.monthly_checklist.status.open')
  })

  it('u splněné položky ukáže stav Hotovo místo tlačítka', async () => {
    m.monthlyChecklist.mockResolvedValue(baseResponse({
      summary: { total: 1, send: 0, generate: 0, manual: 0, done: 1 },
      items: [{
        key: 'submission:11',
        source: 'submission',
        agenda_code: 'JMHZ25',
        agenda_label: 'JMHZ',
        subject: 'office:synthetic',
        period: '2026-08',
        due_on: '2026-09-20',
        phase: 'fulfilled',
        days_to_due: -3,
        is_overdue: false,
        status: 'accepted',
        document: { format: 'XML (JMHZ)', note: '' },
        recipient: { label: 'ČSSZ', note: '', applicable: true },
        channel: { label: 'datová schránka — odesílací brána', note: '', applicable: true },
        done: true,
        action: { kind: 'send', label: 'Odeslat', path: '/payroll/submissions/jmhz', reason: null },
      }],
    }))

    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.get('[data-test="monthly-checklist-done"]').text()).toBeTruthy()
    expect(wrapper.find('[data-test="monthly-checklist-action"]').exists()).toBe(false)
  })

  it('při chybě ukáže hlášku a nepadne', async () => {
    m.monthlyChecklist.mockRejectedValue(new Error('network down'))

    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.get('[data-test="monthly-checklist-error"]').text()).toBeTruthy()
  })

  it('změna měsíce nebo prostředí přehled znovu načte', async () => {
    m.monthlyChecklist.mockResolvedValue(baseResponse())
    const wrapper = mountPanel()
    await flushPromises()
    m.monthlyChecklist.mockClear()

    await wrapper.get('[data-test="monthly-checklist-period"]').setValue('2026-09')
    await flushPromises()

    expect(m.monthlyChecklist).toHaveBeenCalledWith('production', '2026-09')
  })
})
