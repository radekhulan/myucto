import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  routeQuery: {} as Record<string, string | string[]>,
  routerReplace: vi.fn(),
  timeMonth: vi.fn(),
  previewTimeImport: vi.fn(),
  importTime: vi.fn(),
  approveTimeMonth: vi.fn(),
  reopenTimeMonth: vi.fn(),
  canWrite: vi.fn(),
  toastError: vi.fn(),
}))

// Stránka čte předvýběr z adresy (odkaz z karty zaměstnance), takže potřebuje
// router. Originál se rozprostře, ať zůstanou i ostatní exporty (RouterLink).
vi.mock('vue-router', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-router')>()),
  useRoute: () => ({ query: m.routeQuery }),
  useRouter: () => ({ replace: m.routerReplace }),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    timeMonth: m.timeMonth,
    previewTimeImport: m.previewTimeImport,
    importTime: m.importTime,
    approveTimeMonth: m.approveTimeMonth,
    reopenTimeMonth: m.reopenTimeMonth,
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: m.canWrite }),
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), error: m.toastError }),
}))

// `useTablePrefs` táhne @/i18n, které volá skutečné `createI18n` — továrna
// proto musí původní modul rozprostřít, ne nahradit.
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string, values?: Record<string, unknown>) =>
      values?.name ? `${key}:${values.name}` : key,
  }),
}))

// `useTablePrefs` jde přes Pinii a API; v testu stačí prázdné výchozí předvolby.
vi.mock('@/composables/useUserPrefs', async () => {
  const { computed, ref } = await import('vue')
  // Stavová napodobenina: výběr sloupců se ukládá přes patchPagePrefs a musí
  // se hned projevit v tabulce — mock s neměnným prázdným objektem by test
  // skrývání sloupce udělal bezzubým.
  const store = ref<Record<string, unknown>>({})
  return {
    ensurePrefsLoaded: () => Promise.resolve(),
    getPagePrefs: () => computed(() => store.value),
    patchPagePrefs: (_page: string, patch: Record<string, unknown>) => {
      store.value = { ...store.value, ...patch }
    },
  }
})

import TimeAttendance from '@/pages/payroll/TimeAttendance.vue'

function row(employmentId: number, fullName: string) {
  return {
    employment: {
      id: employmentId,
      full_name: fullName,
      code: `SYN-${employmentId}`,
      relation_type: 'employment',
    },
    month: { status: 'open', row_version: 1 },
    calendar: null,
    summary: {
      fund_minutes: 9_600,
      planned_minutes: 9_600,
      actual_minutes: 9_600,
      difference_minutes: 0,
      category_minutes: {},
      incomplete: false,
    },
  }
}

describe('TimeAttendance', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.canWrite.mockReturnValue(true)
    m.timeMonth.mockResolvedValue({ items: [], total: 0, limit: 25, offset: 0 })
    m.previewTimeImport.mockResolvedValue({
      supported: true,
      total_rows: 1,
      accepted_rows: 1,
      rejected_rows: 0,
      duplicate_rows: 0,
      rows: [],
      errors: [],
    })
    m.reopenTimeMonth.mockResolvedValue({})
    m.approveTimeMonth.mockResolvedValue({})
  })

  /**
   * Docházka staví na každý řádek fond kalendáře, náhled JMHZ i limity
   * přesčasu. Kdyby si stránka brala celý měsíc a zbytek zahodila v prohlížeči,
   * server by tu práci odvedl pro celou firmu při každém otevření.
   */
  it('asks the server for one bounded page and offers the next one', async () => {
    m.timeMonth.mockResolvedValue({
      items: [row(12, 'Syntetická osoba A')],
      total: 60,
      limit: 25,
      offset: 0,
    })
    const wrapper = mount(TimeAttendance)
    await flushPromises()

    expect(m.timeMonth).toHaveBeenCalledWith(
      expect.any(String),
      false,
      { limit: 25, offset: 0 },
      null,
    )

    m.timeMonth.mockResolvedValue({
      items: [row(13, 'Syntetická osoba B')],
      total: 60,
      limit: 25,
      offset: 25,
    })
    const next = wrapper.findAll('button')
      .find(button => button.text().includes('common.next'))
    expect(next).toBeDefined()
    await next!.trigger('click')
    await flushPromises()

    expect(m.timeMonth).toHaveBeenLastCalledWith(
      expect.any(String),
      false,
      { limit: 25, offset: 25 },
      null,
    )
    expect(wrapper.text()).toContain('Syntetická osoba B')
    expect(wrapper.text()).not.toContain('Syntetická osoba A')
  })

  it('uses a keyboard-searchable employment selector in the editor', async () => {
    m.timeMonth.mockResolvedValue({
      items: [
        row(12, 'Syntetická osoba A'),
        row(13, 'Syntetická osoba B'),
      ],
      total: 2,
      limit: 25,
      offset: 0,
    })
    const wrapper = mount(TimeAttendance)
    await flushPromises()

    const add = wrapper.findAll('button')
      .find(button => button.text() === 'payroll.time.add')
    await add!.trigger('click')

    const selector = wrapper.get('[data-test="payroll-time-employment"]')
    expect(selector.find('[role="combobox"]').exists()).toBe(true)
    expect(selector.find('select').exists()).toBe(false)
    await selector.get('[role="combobox"]').setValue('osoba B')

    expect(selector.findAll('[role="option"]')).toHaveLength(1)
    expect(selector.text()).toContain('Syntetická osoba B')
  })

  /** Zúžení „jen nedokončené" mění obsah, takže musí vrátit stránku na začátek. */
  it('returns to the first page when the incomplete filter changes', async () => {
    m.timeMonth.mockResolvedValue({
      items: [row(12, 'Syntetická osoba A')],
      total: 60,
      limit: 25,
      offset: 0,
    })
    const wrapper = mount(TimeAttendance)
    await flushPromises()

    const next = wrapper.findAll('button')
      .find(button => button.text().includes('common.next'))
    await next!.trigger('click')
    await flushPromises()
    expect(m.timeMonth).toHaveBeenLastCalledWith(
      expect.any(String),
      false,
      { limit: 25, offset: 25 },
      null,
    )

    await wrapper.find('input[type="checkbox"][class*="rounded"]').setValue(true)
    await flushPromises()

    expect(m.timeMonth).toHaveBeenLastCalledWith(
      expect.any(String),
      true,
      { limit: 25, offset: 0 },
      null,
    )
  })

  /** Skrytý sloupec zmizí z hlavičky i z buněk, mobilní karta ho drží dál. */
  it('hides a column from the desktop table without touching the mobile card', async () => {
    m.timeMonth.mockResolvedValue({
      items: [row(12, 'Syntetická osoba A')],
      total: 1,
      limit: 25,
      offset: 0,
    })
    const wrapper = mount(TimeAttendance)
    await flushPromises()

    expect(wrapper.find('table').text()).toContain('payroll.time.columns.fund')

    const picker = wrapper.findAll('button')
      .find(button => button.text() === 'common.columns')
    expect(picker).toBeDefined()
    await picker!.trigger('click')
    const fundToggle = wrapper.findAll('label')
      .find(label => label.text() === 'payroll.time.columns.fund')
    expect(fundToggle).toBeDefined()
    await fundToggle!.find('input').trigger('change')
    await flushPromises()

    expect(wrapper.find('table').text()).not.toContain('payroll.time.columns.fund')
    // Mobilní karta má vlastní rozvržení a výběr sloupců se jí netýká.
    const mobile = wrapper.findAll('div')
      .find(node => node.classes().includes('md:hidden') && node.text() !== '')
    expect(mobile).toBeDefined()
    expect(mobile!.text()).toContain('payroll.time.columns.fund')
  })

  it('loads attendance CSV through the shared drag-and-drop control', async () => {
    const wrapper = mount(TimeAttendance)
    await flushPromises()
    const importButton = wrapper.findAll('button')
      .find(button => button.text() === 'payroll.time.import.button')
    await importButton!.trigger('click')

    const file = new File(
      ['employment_code,starts_at,ends_at\nSYN-HPP,2026-08-03T08:00,2026-08-03T16:00'],
      'attendance.csv',
      { type: 'text/csv' },
    )
    Object.defineProperty(file, 'text', {
      value: vi.fn().mockResolvedValue('employment_code,starts_at,ends_at'),
    })
    await wrapper.get('[data-testid="payroll-time-import-dropzone"]').trigger('drop', {
      dataTransfer: { files: [file] },
    })

    await vi.waitFor(() => {
      expect(wrapper.get('[data-testid="payroll-time-import-selected"]').attributes('title'))
        .toBe('attendance.csv')
      const preview = wrapper.findAll('button')
        .find(button => button.text() === 'payroll.time.import.preview')
      expect(preview!.attributes('disabled')).toBeUndefined()
    })

    const preview = wrapper.findAll('button')
      .find(button => button.text() === 'payroll.time.import.preview')
    await preview!.trigger('click')
    await flushPromises()
    expect(m.previewTimeImport).toHaveBeenCalledWith(expect.objectContaining({
      format: 'csv',
      original_name: 'attendance.csv',
    }))
  })

  it('reads XLSX as an ArrayBuffer and sends only its Base64 payload', async () => {
    const wrapper = mount(TimeAttendance)
    await flushPromises()
    const importButton = wrapper.findAll('button')
      .find(button => button.text() === 'payroll.time.import.button')
    await importButton!.trigger('click')

    const file = new File(
      [new Uint8Array([0x50, 0x4b, 0x03, 0x04])],
      'attendance.xlsx',
      { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' },
    )
    await wrapper.get('[data-testid="payroll-time-import-dropzone"]').trigger('drop', {
      dataTransfer: { files: [file] },
    })
    await vi.waitFor(() => {
      expect(wrapper.get('[data-testid="payroll-time-import-selected"]').attributes('title'))
        .toBe('attendance.xlsx')
      const preview = wrapper.findAll('button')
        .find(button => button.text() === 'payroll.time.import.preview')
      expect(preview!.attributes('disabled')).toBeUndefined()
    })

    const preview = wrapper.findAll('button')
      .find(button => button.text() === 'payroll.time.import.preview')
    await preview!.trigger('click')
    await flushPromises()

    expect(m.previewTimeImport).toHaveBeenCalledWith(expect.objectContaining({
      format: 'xlsx',
      original_name: 'attendance.xlsx',
      content: 'UEsDBA==',
    }))
    expect(wrapper.text()).toContain('payroll.time.import.xlsx_security')
  })

  it('rejects an XLSX over five megabytes before FileReader or API use', async () => {
    const wrapper = mount(TimeAttendance)
    await flushPromises()
    const importButton = wrapper.findAll('button')
      .find(button => button.text() === 'payroll.time.import.button')
    await importButton!.trigger('click')

    const file = new File(
      [new Uint8Array(5_000_001)],
      'too-large.xlsx',
      { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' },
    )
    await wrapper.get('[data-testid="payroll-time-import-dropzone"]').trigger('drop', {
      dataTransfer: { files: [file] },
    })

    expect(wrapper.get('[role="alert"]').text()).toBe('payroll.time.import.file_too_large')
    expect(m.previewTimeImport).not.toHaveBeenCalled()
  })

  it('shows a payroll-styled error and clears a previous selection after rejection', async () => {
    const wrapper = mount(TimeAttendance)
    await flushPromises()
    const importButton = wrapper.findAll('button')
      .find(button => button.text() === 'payroll.time.import.button')
    await importButton!.trigger('click')

    const unsupported = new File(['data'], 'attendance.txt', { type: 'text/plain' })
    await wrapper.get('[data-testid="payroll-time-import-dropzone"]').trigger('drop', {
      dataTransfer: { files: [unsupported] },
    })

    expect(wrapper.get('[role="alert"]').text())
      .toBe('payroll.time.import.unsupported_file')
    expect(wrapper.find('[data-testid="payroll-time-import-selected"]').exists()).toBe(false)
    expect(m.toastError).toHaveBeenCalledWith('payroll.time.import.unsupported_file')
  })

  it('reopens an approved month through a modal and keeps the exact API error inline', async () => {
    m.timeMonth.mockResolvedValue({
      items: [{
        employment: { id: 12, full_name: 'Syntetická osoba', code: 'SYN-HPP' },
        month: { status: 'approved', row_version: 4 },
        calendar: null,
        summary: {
          fund_minutes: 9_600,
          planned_minutes: 9_600,
          actual_minutes: 9_600,
          difference_minutes: 0,
          incomplete: false,
        },
      }],
    })
    m.reopenTimeMonth.mockRejectedValueOnce({
      response: { data: { error: { message: 'Přesná konfliktní chyba z API.' } } },
    })
    const prompt = vi.spyOn(window, 'prompt')
    const wrapper = mount(TimeAttendance, {
      global: { stubs: { teleport: true } },
    })
    await flushPromises()

    const reopen = wrapper.findAll('button')
      .find(button => button.text() === 'payroll.time.reopen')
    await reopen!.trigger('click')

    expect(prompt).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="reopen-modal"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="reopen-employee"]').text()).toContain('Syntetická osoba')

    await wrapper.find('[data-test="reopen-reason"]').setValue('Oprava syntetických podkladů')
    await wrapper.find('[data-test="reopen-form"]').trigger('submit')
    await flushPromises()

    expect(m.reopenTimeMonth).toHaveBeenCalledWith(expect.any(String), {
      employment_id: 12,
      row_version: 4,
      reason: 'Oprava syntetických podkladů',
    })
    expect(wrapper.find('[data-test="reopen-error"]').text())
      .toBe('Přesná konfliktní chyba z API.')
    expect(wrapper.find('[data-test="reopen-modal"]').exists()).toBe(true)
    expect(m.toastError).not.toHaveBeenCalledWith('Přesná konfliktní chyba z API.')

    await wrapper.find('[data-test="reopen-form"]').trigger('submit')
    await flushPromises()
    expect(m.reopenTimeMonth).toHaveBeenCalledTimes(2)
    expect(wrapper.find('[data-test="reopen-modal"]').exists()).toBe(false)
    prompt.mockRestore()
  })

  it('freezes exact JMHZ core values together with month approval', async () => {
    m.timeMonth.mockResolvedValue({
      items: [{
        employment: { id: 12, full_name: 'Syntetická osoba', code: 'SYN-HPP' },
        month: { status: 'open', row_version: 3 },
        calendar: null,
        summary: {
          fund_minutes: 10_080,
          planned_minutes: 10_080,
          actual_minutes: 450,
          difference_minutes: -9_630,
          category_minutes: {},
          incomplete: false,
        },
        jmhz_work_summary: {
          preview: {
            derivation_version: 'jmhz-work-month.v2',
            source_snapshot_sha256: 'a'.repeat(64),
            suggestions: {
              standard_fund_hours: null,
              agreed_fund_hours: '168',
              weekly_work_hours: '40',
              evidence_days: 31,
              worked_hours: '7.5',
            },
            issues: [],
            requires_unworked_hours_followup: false,
          },
          current_revision: null,
        },
        shifts: [],
        entries: [],
      }],
    })
    const wrapper = mount(TimeAttendance, {
      global: { stubs: { teleport: true } },
    })
    await flushPromises()

    const approve = wrapper.findAll('button')
      .find(button => button.text() === 'payroll.time.approve')
    await approve!.trigger('click')
    await wrapper.get('[data-test="jmhz-standard-fund"]').setValue('168')
    expect(wrapper.get('[data-test="jmhz-work-summary-form"] button[type="submit"]')
      .attributes('disabled')).toBeDefined()
    await wrapper.get('[data-test="jmhz-unworked-no"]').setValue(true)
    await wrapper.get('[data-test="jmhz-obstacles-no"]').setValue(true)
    expect(wrapper.get('[data-test="jmhz-work-summary-form"] button[type="submit"]')
      .attributes('disabled')).toBeUndefined()
    await wrapper.get('[data-test="jmhz-work-summary-form"]').trigger('submit')
    await flushPromises()

    expect(m.approveTimeMonth).toHaveBeenCalledWith(expect.any(String), {
      employment_id: 12,
      row_version: 3,
      jmhz_work_summary: {
        source_snapshot_sha256: 'a'.repeat(64),
        standard_fund_hours: '168',
        agreed_fund_hours: '168',
        weekly_work_hours: '40',
        worked_hours: '7.5',
        unworked_hours_occurred: false,
        work_obstacles_occurred: false,
        unworked_total_hours: null,
        unworked_paid_hours: null,
        dpn_without_employer_compensation_hours: null,
        dpn_with_employer_compensation_hours: null,
        vacation_hours: null,
        care_hours: null,
        employee_obstacle_paid_hours: null,
        employer_obstacle_hours: null,
        confirmation_note: '',
      },
    })

    const approveAgain = wrapper.findAll('button')
      .find(button => button.text() === 'payroll.time.approve')
    await approveAgain!.trigger('click')
    await wrapper.get('[data-test="jmhz-standard-fund"]').setValue('168')
    await wrapper.get('[data-test="jmhz-note"]').setValue('Potvrzeno ze syntetické absence.')
    await wrapper.get('[data-test="jmhz-unworked-no"]').setValue(true)
    await wrapper.get('[data-test="jmhz-unworked-yes"]').setValue(true)
    await wrapper.get('[data-test="jmhz-unworked-total"]').setValue('80')
    await wrapper.get('[data-test="jmhz-unworked-paid"]').setValue('0')
    await wrapper.get('[data-test="jmhz-dpn-with-compensation"]').setValue('80')
    expect(wrapper.get('[data-test="jmhz-work-summary-form"] button[type="submit"]')
      .attributes('disabled')).toBeDefined()
    await wrapper.get('[data-test="jmhz-obstacles-yes"]').setValue(true)
    await wrapper.get('[data-test="jmhz-employee-obstacle"]').setValue('80')
    await wrapper.get('[data-test="jmhz-work-summary-form"]').trigger('submit')
    await flushPromises()

    expect(m.approveTimeMonth).toHaveBeenLastCalledWith(expect.any(String),
      expect.objectContaining({
        jmhz_work_summary: expect.objectContaining({
          unworked_hours_occurred: true,
          work_obstacles_occurred: true,
          unworked_total_hours: '80',
          unworked_paid_hours: '0',
          dpn_with_employer_compensation_hours: '80',
          employee_obstacle_paid_hours: '80',
        }),
      }),
    )
  })

  /**
   * Porušený zákaz práce přesčas nesmí splynout s překročeným limitem: panel se
   * obarvuje jako chyba, u každého nálezu je vidět ustanovení a přibude věta,
   * že bez ruční výjimky běh neschválíte.
   */
  it('marks a breached overtime ban apart from an exceeded limit', async () => {
    m.timeMonth.mockResolvedValue({
      items: [{
        employment: { id: 12, full_name: 'Syntetická osoba', code: 'SYN-HPP' },
        month: { status: 'open', row_version: 3 },
        calendar: null,
        summary: {
          fund_minutes: 9_600,
          planned_minutes: 9_600,
          actual_minutes: 9_720,
          difference_minutes: 120,
          category_minutes: {},
          incomplete: false,
        },
        overtime_limits: {
          employment_id: 12,
          findings: [{
            code: 'overtime_prohibited_juvenile',
            severity: 'warning',
            message: 'Mladistvému zaměstnanci je evidován přesčas.',
            actual_minutes: 120,
            limit_minutes: 0,
            scope_from: '2026-05-04',
            scope_to: '2026-05-04',
            consent_evidenced: false,
            provision: '§ 245 odst. 1 zákoníku práce',
            requires_override: true,
          }],
          weeks: [],
          ordered_year_minutes: 120,
          ordered_year_limit_minutes: 9_000,
          agreed_year_minutes: 0,
          averaging_from: '2026-01-05',
          averaging_to: '2026-05-03',
          averaging_weeks: 17,
          averaging_minutes: 120,
          averaging_limit_minutes: 8_160,
          averaging_compensated_minutes: 60,
          averaging_basis: 'collective_agreement',
          averaging_reference: 'KS/2026',
          prohibited_minutes: { juvenile: 120 },
          requires_override: true,
          consent_evidenced: false,
          limits_from_ruleset: true,
        },
        overtime_consents: [],
        overtime_protections: [],
        overtime_compensations: [],
      }],
    })
    const wrapper = mount(TimeAttendance, { global: { stubs: { teleport: true } } })
    await flushPromises()

    const panel = wrapper.get('[data-test="overtime-limits-12"]')
    expect(panel.html()).toContain('border-danger-500/50')
    expect(wrapper.find('[data-test="overtime-prohibition-banner"]').exists()).toBe(true)
    expect(panel.find('[data-test="overtime-finding-overtime_prohibited_juvenile"]').text())
      .toContain('§ 245 odst. 1 zákoníku práce')
    expect(wrapper.get('[data-test="overtime-averaging-12"]').text())
      .toContain('payroll.time.overtime.averaging_compensated')
  })
  /**
   * Zúžení na jeden vztah musí odejít NA SERVER.
   *
   * Dokud filtroval prohlížeč nad načtenou stránkou, vztah ležící na jiné
   * straně se tiše neprojevil: seznam zůstal celý a lišta zmizela. Test proto
   * hlídá, že se `employment_id` posílá do dotazu, a že když server nic
   * nevrátí, řekne to obrazovka větou místo prázdna.
   */
  it('sends the employment narrowing to the server', async () => {
    m.routeQuery = { employment: '77' }
    m.timeMonth.mockResolvedValue({
      items: [row(77, 'Syntetická osoba Z')],
      total: 1,
      limit: 25,
      offset: 0,
      employment_id: 77,
    })
    const wrapper = mount(TimeAttendance)
    await flushPromises()

    expect(m.timeMonth).toHaveBeenCalledWith(
      expect.any(String),
      false,
      { limit: 25, offset: 0 },
      77,
    )
    expect(wrapper.find('[data-test="payroll-focus-notice"]').exists()).toBe(true)
    m.routeQuery = {}
  })

  /** Server zúžení uplatnil a nezbylo nic — obrazovka to musí říct, ne mlčet. */
  it('names an empty narrowing instead of showing an empty list', async () => {
    m.routeQuery = { employment: '404' }
    m.timeMonth.mockResolvedValue({
      items: [],
      total: 0,
      limit: 25,
      offset: 0,
      employment_id: 404,
    })
    const wrapper = mount(TimeAttendance)
    await flushPromises()

    const notice = wrapper.find('[data-test="payroll-focus-notice"]')
    expect(notice.exists()).toBe(true)
    expect(notice.text()).toContain('payroll.agendas.focus.missing')
    m.routeQuery = {}
  })
})
