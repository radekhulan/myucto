import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  monthlyChecklist: vi.fn(),
  prepareItem: vi.fn(),
  dispatchBatch: vi.fn(),
  prepareSickness: vi.fn(),
  push: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  PAYROLL_QUEUE_BATCH_SIZE: 25,
  payrollApi: {
    monthlyChecklist: m.monthlyChecklist,
    prepareMonthlyChecklistItem: m.prepareItem,
    dispatchSubmissionBatch: m.dispatchBatch,
  },
}))
vi.mock('@/api/dataBox', () => ({
  dataBoxApi: {
    downloadReceiptsInMobileKeySession: vi.fn(),
    gatewayStartPayroll: vi.fn(),
    mobileKeyProfile: vi.fn().mockResolvedValue({ saved: true, username: 'x', environment: 'production' }),
  },
}))
vi.mock('@/api/payrollSicknessCases', () => ({
  payrollSicknessCasesApi: { prepare: m.prepareSickness },
}))
vi.mock('vue-router', () => ({ useRouter: () => ({ push: m.push }) }))
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ locale: { value: 'cs' },
    t: (key: string, parameters?: Record<string, string | number>) =>
      parameters ? `${key} ${Object.values(parameters).join(' ')}` : key,
    te: () => true,
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

function agendaDutyItem() {
  return {
    key: 'agenda_duty:PPZ_2026:5:111',
    source: 'agenda_duty',
    agenda_code: 'PPZ_2026',
    agenda_label: 'PPZ_2026',
    subject: 'VZP (111)',
    period: '2026-08',
    due_on: '2026-09-21',
    phase: 'open',
    days_to_due: 18,
    is_overdue: false,
    status: 'not_prepared',
    document: { format: 'XML nebo PDF', note: '' },
    recipient: { label: 'VZP (111)', note: '', applicable: true },
    channel: { label: 'datová schránka — ručně', note: '', applicable: true },
    done: false,
    action: {
      kind: 'generate',
      label: 'Připravit',
      path: '/payroll/submissions/health',
      reason: null,
      prepare: { agenda_code: 'PPZ_2026', period: '2026-08', insurer_code: '111' },
    },
  }
}

function mountPanel() {
  return mount(PayrollMonthlyChecklistPanel, {
    props: { environment: 'production' },
    global: {
      stubs: {
        Modal: {
          props: ['title', 'widthClass'],
          template: '<div data-test="modal"><slot /><slot name="footer" /></div>',
        },
        RouterLink: { props: ['to'], template: '<a :data-to="typeof to === \'string\' ? to : JSON.stringify(to)"><slot /></a>' },
      },
    },
  })
}

describe('PayrollMonthlyChecklistPanel', () => {
  afterEach(() => {
    vi.useRealTimers()
  })

  beforeEach(() => {
    vi.clearAllMocks()
    // Panel startuje na aktuálním měsíci. Test přepnutí období musí sáhnout na
    // JINÝ měsíc, než je dnešek — bez zafixovaných hodin se jednou za čas trefí
    // do téhož a přepnutí se pak vůbec nespustí.
    vi.useFakeTimers({ shouldAdvanceTime: true })
    vi.setSystemTime(new Date(2026, 7, 12, 9, 0, 0))
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
   * Q15-17: převzatý měsíc bez podaného JMHZ. Přehled dřív ukázal „žádná
   * otevřená položka"; teď nesplněný řádek s lidským názvem agendy a návodem.
   */
  it('ukáže nepodané hlášení převzatého měsíce jako nesplněnou položku s návodem', async () => {
    m.monthlyChecklist.mockResolvedValue(baseResponse({
      summary: { total: 1, send: 0, generate: 0, manual: 1, done: 0 },
      items: [{
        key: 'predecessor_jmhz:2026-08',
        source: 'predecessor_jmhz',
        agenda_code: 'JMHZ25',
        agenda_label: 'JMHZ25',
        subject: 'převzatý měsíc – hlášení nebylo podáno',
        period: '2026-08',
        due_on: '2026-09-21',
        phase: 'overdue',
        days_to_due: -6,
        is_overdue: true,
        status: 'open',
        document: { format: 'XML (JMHZ)', note: '' },
        recipient: { label: 'ČSSZ', note: '', applicable: true },
        channel: { label: null, note: 'Podává program, který mzdy za měsíc zpracoval.', applicable: true },
        done: false,
        action: {
          kind: 'manual',
          label: 'Otevřít podání předchozím programem',
          path: '/payroll/submissions/jmhz',
          reason: 'Hlášení za 8/2026 nebylo podáno.',
        },
      }],
    }))

    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.find('[data-test="monthly-checklist-empty"]').exists()).toBe(false)
    const row = wrapper.get('tbody tr[data-test="monthly-checklist-row"]')
    expect(row.text()).toContain('payroll.submissions.statutory.agenda.JMHZ25')
    expect(row.text()).toContain('Hlášení za 8/2026 nebylo podáno.')
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

  /**
   * Vložený panel (příprava mzdového běhu) nemá hlavičku s Obnovit, takže po
   * výpadku zbývala jen červená věta bez jakékoli cesty ven.
   */
  it('po neúspěšném načtení nabídne opakování i ve vloženém režimu', async () => {
    m.monthlyChecklist.mockRejectedValue(new Error('network down'))
    const wrapper = mount(PayrollMonthlyChecklistPanel, {
      props: { environment: 'production', period: '2026-08' },
      global: {
        stubs: { RouterLink: { props: ['to'], template: '<a :data-to="to"><slot /></a>' } },
      },
    })
    await flushPromises()

    m.monthlyChecklist.mockResolvedValue(baseResponse())
    await wrapper.get('[data-test="monthly-checklist-retry"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="monthly-checklist-error"]').exists()).toBe(false)
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
  /**
   * NULY MÍSTO PRÁCE — uzavřené období bez jediného podání vypadalo, že není
   * co dělat. Povinnost bez podání nemá kam odkázat, takže dostane TLAČÍTKO,
   * které ji připraví, ne odkaz „najděte si to".
   */
  it('nepřipravenou povinnost nabídne jako tlačítko Připravit, ne jako odkaz', async () => {
    m.monthlyChecklist.mockResolvedValue(baseResponse({
      summary: { total: 1, send: 0, generate: 1, manual: 0, done: 0 },
      items: [agendaDutyItem()],
    }))

    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.find('[data-test="monthly-checklist-action"]').exists()).toBe(false)
    const button = wrapper.get('tbody [data-test="monthly-checklist-prepare"]')
    expect(button.element.tagName).toBe('BUTTON')
    expect(button.text()).toContain('prepare_only')
    expect(wrapper.get('tbody [data-test="monthly-checklist-prepare-send"]').text()).toContain('prepare_and_send')
  })

  it('„Jen připravit vše" připraví i nemocenská podání a nic neodešle', async () => {
    const sickness = {
      ...agendaDutyItem(),
      key: 'sickness_case:payroll_sickness_case:7',
      source: 'sickness_case',
      agenda_code: 'NEMPRI',
      agenda_label: 'NEMPRI',
      action: {
        kind: 'generate',
        label: 'Připravit v Nemocenských případech',
        path: '/payroll/submissions/sickness',
        reason: null,
        prepare: null,
        sickness: { case_id: 7, document: 'nempri' },
      },
    }
    m.monthlyChecklist.mockResolvedValue(baseResponse({
      summary: { total: 2, send: 0, generate: 2, manual: 0, done: 0 },
      items: [agendaDutyItem(), sickness],
    }))
    m.prepareItem.mockResolvedValue({ prepared: 1, submission_ids: [42] })
    m.prepareSickness.mockResolvedValue({})

    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="monthly-checklist-prepare-all"]').trigger('click')
    await flushPromises()

    expect(m.prepareItem).toHaveBeenCalledTimes(1)
    expect(m.prepareSickness).toHaveBeenCalledWith('production', 7, 'nempri')
    expect(m.dispatchBatch).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="monthly-checklist-prepare-all-summary"]').text()).toContain('2 0')
  })

  it('„Jen připravit" připraví právě tu agendu, období a pojišťovnu a zůstane v přehledu', async () => {
    m.monthlyChecklist.mockResolvedValue(baseResponse({
      summary: { total: 1, send: 0, generate: 1, manual: 0, done: 0 },
      items: [agendaDutyItem()],
    }))
    m.prepareItem.mockResolvedValue({
      agenda_code: 'PPZ_2026',
      period: '2026-08',
      insurer_code: '111',
      prepared: 1,
      submission_ids: [42],
      path: '/payroll/submissions/health',
    })

    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('tbody [data-test="monthly-checklist-prepare"]').trigger('click')
    await flushPromises()

    expect(m.prepareItem).toHaveBeenCalledWith('production', {
      agenda_code: 'PPZ_2026',
      period: '2026-08',
      insurer_code: '111',
    })
    // Přehled se musí přečíst znovu, jinak by řádek zůstal „nepřipraveno"
    // i po tom, co podání vzniklo. Na záložku agendy se už nepřechází.
    expect(m.monthlyChecklist).toHaveBeenCalledTimes(2)
    expect(m.push).not.toHaveBeenCalled()
    expect(m.dispatchBatch).not.toHaveBeenCalled()
  })

  it('„Připravit a odeslat" připraví, po potvrzení odešle frontou a nabídne jedno potvrzení Mobilním klíčem', async () => {
    const prepared = {
      ...agendaDutyItem(),
      key: 'submission:9',
      source: 'submission',
      status: 'ready',
      submission_id: 42,
      dispatchable: true,
      dispatch_mode: 'isds_health',
      action: { kind: 'send', label: 'Odeslat', path: '/payroll/submissions/health', reason: null, prepare: null },
    }
    m.monthlyChecklist
      .mockResolvedValueOnce(baseResponse({ items: [agendaDutyItem()] }))
      .mockResolvedValue(baseResponse({ items: [prepared] }))
    m.prepareItem.mockResolvedValue({ submission_ids: [42], prepared: 1, path: '/payroll/submissions/health' })
    m.dispatchBatch.mockResolvedValue({
      environment: 'production',
      summary: { requested: 1, sent: 1, failed: 0 },
      results: [{
        ok: true,
        submission_id: 42,
        dispatched: true,
        message: '',
        mode: 'isds_health',
        outbox: {
          id: 77,
          created: true,
          recipient: { box_id: 'abcdefg', name: 'VZP' },
          subject: 'PPPZ',
          transport: { automatic: false, channel: 'mobile_key', reason: null },
        },
      }],
    })

    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('tbody [data-test="monthly-checklist-prepare-send"]').trigger('click')
    await flushPromises()

    // Ostré prostředí: jedno potvrzení se seznamem co → komu → jakou cestou.
    const dialog = wrapper.get('[data-test="production-send-confirm-items"]')
    expect(dialog.text()).toContain('VZP (111)')
    expect(dialog.text()).toContain('channel_isds')
    expect(m.dispatchBatch).not.toHaveBeenCalled()

    await wrapper.get('[data-test="production-send-confirm-yes"]').trigger('click')
    await flushPromises()

    expect(m.dispatchBatch).toHaveBeenCalledWith('production', [
      { submission_id: 42, idempotency_key: expect.any(String), mode: 'isds_health' },
    ])
    // Zpráva v odchozí frontě datovky dostane výběr všech způsobů přihlášení.
    expect(wrapper.find('[data-test="monthly-checklist-databox"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="isds-send-methods"]').exists()).toBe(true)
    expect(m.push).not.toHaveBeenCalled()
  })

  it('„Připravit a odeslat vše" pokryje nepřipravené i připravené řádky jedním potvrzením', async () => {
    const ready = {
      ...agendaDutyItem(),
      key: 'submission:3',
      source: 'submission',
      status: 'ready',
      submission_id: 30,
      dispatchable: true,
      dispatch_mode: 'vrep_jmhz',
      agenda_code: 'JMHZ25',
      action: { kind: 'send', label: 'Odeslat', path: '/payroll/submissions/jmhz', reason: null, prepare: null },
    }
    m.monthlyChecklist.mockResolvedValue(baseResponse({ items: [ready] }))
    m.dispatchBatch.mockResolvedValue({
      environment: 'production',
      summary: { requested: 1, sent: 1, failed: 0 },
      results: [{ ok: true, submission_id: 30, dispatched: true, message: '', mode: 'vrep_jmhz', outbox: null }],
    })

    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('[data-test="monthly-checklist-send-all"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="production-send-confirm-yes"]').trigger('click')
    await flushPromises()

    expect(m.prepareItem).not.toHaveBeenCalled()
    expect(m.dispatchBatch).toHaveBeenCalledTimes(1)
    expect(wrapper.get('[data-test="monthly-checklist-send-summary"]').text()).toContain('1')
  })

  /**
   * Řádek JMHZ i „Připravit a odeslat vše" posílaly vždy přes VREP. Se zvoleným
   * certifikátem jsou připravené oba kanály a účetní si vybere.
   */
  it('u JMHZ s oběma kanály pošle frontou zvolenou datovou schránku', async () => {
    const ready = {
      ...agendaDutyItem(),
      key: 'submission:4',
      source: 'submission',
      status: 'ready',
      submission_id: 31,
      dispatchable: true,
      dispatch_mode: 'vrep_jmhz',
      dispatch_modes: ['vrep_jmhz', 'isds_payroll'],
      agenda_code: 'JMHZ25',
      action: { kind: 'send', label: 'Odeslat', path: '/payroll/submissions/jmhz', reason: null, prepare: null },
    }
    m.monthlyChecklist.mockResolvedValue(baseResponse({ items: [ready] }))
    m.dispatchBatch.mockResolvedValue({
      environment: 'production',
      summary: { requested: 1, sent: 1, failed: 0 },
      results: [{ ok: true, submission_id: 31, dispatched: true, message: '', mode: 'isds_payroll', outbox: null }],
    })

    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('tbody [data-test="dispatch-mode-select"]').setValue('isds_payroll')
    await wrapper.get('tbody [data-test="monthly-checklist-send"]').trigger('click')
    await flushPromises()
    expect(wrapper.get('[data-test="production-send-confirm-items"]').text()).toContain('channel_isds')
    await wrapper.get('[data-test="production-send-confirm-yes"]').trigger('click')
    await flushPromises()

    expect(m.dispatchBatch).toHaveBeenCalledWith('production', [
      { submission_id: 31, idempotency_key: expect.any(String), mode: 'isds_payroll' },
    ])
  })

  it('zrušené potvrzení nic neodešle', async () => {
    const ready = {
      ...agendaDutyItem(),
      key: 'submission:3',
      source: 'submission',
      status: 'ready',
      submission_id: 30,
      dispatchable: true,
      dispatch_mode: 'vrep_jmhz',
      action: { kind: 'send', label: 'Odeslat', path: '/payroll/submissions/jmhz', reason: null, prepare: null },
    }
    m.monthlyChecklist.mockResolvedValue(baseResponse({ items: [ready] }))

    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('tbody [data-test="monthly-checklist-send"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="production-send-confirm-no"]').trigger('click')
    await flushPromises()

    expect(m.dispatchBatch).not.toHaveBeenCalled()
  })

  it('u odeslané zprávy bez doručenky nabídne Načíst doručenky', async () => {
    m.monthlyChecklist.mockResolvedValue(baseResponse({ items: [{
      ...agendaDutyItem(),
      key: 'submission:4',
      source: 'submission',
      status: 'submitted',
      submission_id: 40,
      dispatchable: false,
      dispatch_mode: 'isds_health',
      dispatch: { outbox_id: 5, dispatch_state: 'sent', delivered_at: null, has_receipt: false, delivery_proof: null },
      action: { kind: 'await', label: 'Čeká na doručení', path: '/payroll/submissions/health', reason: null, prepare: null },
    }] }))

    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.get('tbody [data-test="monthly-checklist-receipts"]').text()).toContain('awaiting_receipt')
    expect(wrapper.find('tbody [data-test="monthly-checklist-send"]').exists()).toBe(false)
    // Nahoře jasný další krok: kolik z kolika má doručenku a jedno tlačítko.
    const pending = wrapper.get('[data-test="monthly-checklist-receipts-pending"]')
    expect(pending.text()).toContain('receipts_pending 0 1')
    expect(pending.find('[data-test="mobile-key-receipts-action"]').text()).toContain('load_receipts_count 1')
  })

  it('oznámení HOZ z jiného měsíce označí jako mimo zvolené období', async () => {
    m.monthlyChecklist.mockResolvedValue(baseResponse({ items: [
      { ...agendaDutyItem(), key: 'submission:99', agenda_code: 'HOZ_2026', period: '2026-09', outside_period: true },
    ] }))

    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.get('tbody [data-test="monthly-checklist-outside-period"]').text()).toContain('outside_period')
  })

  it('akční karta agendy ukáže jen své agendy a bez souhrnných dlaždic', async () => {
    m.monthlyChecklist.mockResolvedValue(baseResponse({ items: [
      agendaDutyItem(),
      { ...agendaDutyItem(), key: 'agenda_duty:JMHZ25:5', agenda_code: 'JMHZ25', subject: 'účtárna' },
    ] }))

    const wrapper = mount(PayrollMonthlyChecklistPanel, {
      props: { environment: 'production', period: '2026-08', agendas: ['JMHZ'], compact: true },
      global: { stubs: { RouterLink: { props: ['to'], template: '<a><slot /></a>' } } },
    })
    await flushPromises()

    const rows = wrapper.findAll('tbody [data-test="monthly-checklist-row"]')
    expect(rows).toHaveLength(1)
    expect(rows[0]!.text()).toContain('účtárna')
    expect(wrapper.find('[data-test="monthly-checklist-summary"]').exists()).toBe(false)
  })

  it('bez zvoleného měsíce otevře měsíc navržený serverem', async () => {
    m.monthlyChecklist
      .mockResolvedValueOnce(baseResponse({ period: '2026-07', suggested_period: '2026-05' }))
      .mockResolvedValue(baseResponse({ period: '2026-05', suggested_period: '2026-05' }))

    mountPanel()
    await flushPromises()

    expect(m.monthlyChecklist).toHaveBeenLastCalledWith('production', '2026-05')
  })

  /**
   * Hláška patří K TÉ POLOŽCE. Ve společném pruhu nahoře by vypadala jako
   * výpadek celého přehledu a schovala by zbytek seznamu.
   */
  it('neúspěšná příprava hlásí chybu u položky, ne přes celý panel', async () => {
    m.monthlyChecklist.mockResolvedValue(baseResponse({
      summary: { total: 1, send: 0, generate: 1, manual: 0, done: 0 },
      items: [agendaDutyItem()],
    }))
    m.prepareItem.mockRejectedValue(new Error('nope'))

    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('tbody [data-test="monthly-checklist-prepare"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="monthly-checklist-error"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="monthly-checklist-prepare-error"]').text())
      .toContain('nope')
    expect(wrapper.find('[data-test="monthly-checklist-external-link"]').exists()).toBe(false)
    expect(m.push).not.toHaveBeenCalled()
  })

  /**
   * Kontrola 290: příprava hlášení po splatnosti se slevou čeká na vědomé
   * potvrzení účetní přímo u položky. Po potvrzení jde táž příprava znovu
   * s `confirm_late_discount` a otevře hotové podání.
   */
  it('u slevy po lhůtě se u položky zeptá na potvrzení a po něm připraví podání', async () => {
    m.monthlyChecklist.mockResolvedValue(baseResponse({
      summary: { total: 1, send: 0, generate: 1, manual: 0, done: 0 },
      items: [agendaDutyItem()],
    }))
    m.prepareItem
      .mockRejectedValueOnce({ response: { data: { error: {
        code: 'jmhz_submission_late_discount_confirmation_required',
        message: 'Hlášení se podává po lhůtě a uplatňuje slevu.',
      } } } })
      .mockResolvedValueOnce({ path: '/payroll/submissions/jmhz', prepared: 1 })

    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('tbody [data-test="monthly-checklist-prepare"]').trigger('click')
    await flushPromises()

    const confirm = wrapper.get('[data-test="monthly-checklist-late-discount"]')
    expect(confirm.text()).toContain('Hlášení se podává po lhůtě a uplatňuje slevu.')
    expect(wrapper.find('[data-test="monthly-checklist-prepare-error"]').exists()).toBe(false)
    expect(m.push).not.toHaveBeenCalled()

    await wrapper.get('[data-test="monthly-checklist-late-discount-yes"]').trigger('click')
    await flushPromises()

    expect(m.prepareItem).toHaveBeenLastCalledWith('production', {
      agenda_code: 'PPZ_2026',
      period: '2026-08',
      insurer_code: '111',
      confirm_late_discount: true,
    })
    expect(m.push).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="monthly-checklist-external-link"]').exists()).toBe(false)
  })

  /*
   * Měsíc, za který řádné hlášení podal předchozí program: hláška serveru říká
   * CO a PROČ, odkaz vede tam, kde se záznam ověří a případně odebere.
   */
  it('u měsíce podaného předchozím programem nabídne odkaz na převzatá podání', async () => {
    m.monthlyChecklist.mockResolvedValue(baseResponse({
      summary: { total: 1, send: 0, generate: 1, manual: 0, done: 0 },
      items: [agendaDutyItem()],
    }))
    m.prepareItem.mockRejectedValue({
      response: { data: { error: { code: 'jmhz_period_submitted_externally', message: 'Za období 07/2026 už řádné měsíční hlášení podal předchozí mzdový program.' } } },
    })

    const wrapper = mountPanel()
    await flushPromises()
    await wrapper.get('tbody [data-test="monthly-checklist-prepare"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="monthly-checklist-late-discount"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="monthly-checklist-prepare-error"]').text())
      .toContain('podal předchozí mzdový program')
    const link = wrapper.get('[data-test="monthly-checklist-external-link"]')
    expect(link.text()).toContain('payroll.external_jmhz.open_history')
    expect(link.attributes('data-to')).toContain('external-submissions')
  })
})
