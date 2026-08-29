import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  routeQuery: {} as Record<string, string | string[]>,
  routerReplace: vi.fn(),
  timeMonth: vi.fn(),
  saveTimeEntry: vi.fn(),
  saveTimeEntryBatch: vi.fn(),
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
    saveTimeEntry: m.saveTimeEntry,
    saveTimeEntryBatch: m.saveTimeEntryBatch,
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
    /*
     * Měsíc bez absencí, se kterým si hromadné schválení poradí: server
     * navrhuje dohodnutý fond, týdenní dobu i odpracované hodiny a
     * `requires_unworked_hours_followup = false` říká, že IN07/IN08 jsou
     * odvozené, ne lidské rozhodnutí. Zákonný fond server nenavrhuje nikdy.
     */
    jmhz_work_summary: {
      preview: {
        derivation_version: 'v1',
        control_catalog_key: null,
        control_manifest_sha256: null,
        source_snapshot_sha256: 'a'.repeat(64),
        suggestions: {
          standard_fund_hours: null,
          agreed_fund_hours: '160',
          weekly_work_hours: '40',
          evidence_days: 20,
          worked_hours: '160',
        },
        issues: [],
        requires_unworked_hours_followup: false,
      },
      current_revision: null,
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
    m.saveTimeEntry.mockResolvedValue({})
  })

  /**
   * § 117 — počet ztěžujících vlivů jednoho zápisu. Backend sloupec i validaci
   * má od migrace 1625, ale formulář pole nenabízel, takže se příplatek za
   * ztížené prostředí vždycky počítal jen z obvyklého počtu na zásadě vztahu.
   */
  async function openRowEditor(wrapper: ReturnType<typeof mount>) {
    const add = wrapper.findAll('button').filter(button => button.text() === 'payroll.time.add')
    await add[1].trigger('click')
    await flushPromises()
  }

  it('nabídne počet ztěžujících vlivů jen u ztíženého prostředí', async () => {
    m.timeMonth.mockResolvedValue({
      items: [row(12, 'Syntetická osoba A')],
      total: 1,
      limit: 25,
      offset: 0,
    })
    const wrapper = mount(TimeAttendance)
    await flushPromises()
    await openRowEditor(wrapper)

    const field = '[data-test="time-editor-difficulty-factors"]'
    expect(wrapper.find(field).exists()).toBe(false)

    const selects = wrapper.get('[data-test="time-record-form"]').findAll('select')
    const categorySelect = selects[1]
    await categorySelect.setValue('difficult_environment')
    await flushPromises()
    expect(wrapper.find(field).exists()).toBe(true)

    await categorySelect.setValue('night')
    await flushPromises()
    expect(wrapper.find(field).exists()).toBe(false)
    wrapper.unmount()
  })

  it('pošle počet ztěžujících vlivů, a bez vyplnění pošle null', async () => {
    m.timeMonth.mockResolvedValue({
      items: [row(12, 'Syntetická osoba A')],
      total: 1,
      limit: 25,
      offset: 0,
    })
    const wrapper = mount(TimeAttendance)
    await flushPromises()
    await openRowEditor(wrapper)

    const categorySelect = wrapper.get('[data-test="time-record-form"]').findAll('select')[1]
    await categorySelect.setValue('difficult_environment')
    await flushPromises()

    await wrapper.get('[data-test="time-record-save"]').trigger('submit')
    await flushPromises()
    expect(m.saveTimeEntry).toHaveBeenLastCalledWith(
      expect.objectContaining({
        category: 'difficult_environment',
        difficulty_factor_count: null,
      }),
    )

    await openRowEditor(wrapper)
    await wrapper.get('[data-test="time-record-form"]').findAll('select')[1]
      .setValue('difficult_environment')
    await flushPromises()
    await wrapper.get('[data-test="time-editor-difficulty-factors"]').setValue('3')
    await wrapper.get('[data-test="time-record-save"]').trigger('submit')
    await flushPromises()
    expect(m.saveTimeEntry).toHaveBeenLastCalledWith(
      expect.objectContaining({ difficulty_factor_count: 3 }),
    )
    wrapper.unmount()
  })

  it('mimo rozsah § 117 uložení zablokuje a řekne proč', async () => {
    m.timeMonth.mockResolvedValue({
      items: [row(12, 'Syntetická osoba A')],
      total: 1,
      limit: 25,
      offset: 0,
    })
    const wrapper = mount(TimeAttendance)
    await flushPromises()
    await openRowEditor(wrapper)

    await wrapper.get('[data-test="time-record-form"]').findAll('select')[1]
      .setValue('difficult_environment')
    await flushPromises()
    await wrapper.get('[data-test="time-editor-difficulty-factors"]').setValue('300')
    await flushPromises()

    expect(wrapper.get('[data-test="time-record-save"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-test="time-record-save-blocked"]').text())
      .toContain('payroll.time.editor.blocked_difficulty_factors')
    expect(m.saveTimeEntry).not.toHaveBeenCalled()
    wrapper.unmount()
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

    expect(wrapper.find('[data-test="payroll-time-summary"]').text()).toContain('payroll.time.columns.fund')

    const picker = wrapper.findAll('button')
      .find(button => button.text() === 'common.columns')
    expect(picker).toBeDefined()
    await picker!.trigger('click')
    const fundToggle = wrapper.findAll('label')
      .find(label => label.text() === 'payroll.time.columns.fund')
    expect(fundToggle).toBeDefined()
    await fundToggle!.find('input').trigger('change')
    await flushPromises()

    expect(wrapper.find('[data-test="payroll-time-summary"]').text()).not.toContain('payroll.time.columns.fund')
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

/*
 * ─── Měsíční mřížka (nález X-02) ────────────────────────────────────────────
 *
 * Docházka šla zadat jen po jednom intervalu v editoru, který se po uložení
 * zavíral a datum si přepsal na první den měsíce. Testy tady hlídají to, čím se
 * to nahradilo: hromadné vyplnění, JEDNO uložení místo požadavku na buňku,
 * částečný výsledek u konkrétní buňky a ovládání klávesnicí (nález X-07).
 */
describe('TimeAttendance — měsíční mřížka', () => {
  const GRID_MOUNT = {
    attachTo: document.body,
    global: {
      // `teleport` se stubuje, aby obsah modalu (hromadné schválení) zůstal
      // ve wrapperu — jinak skončí v `document.body` a `wrapper.get()` ho mine.
      stubs: { RouterLink: { template: '<a><slot /></a>' }, teleport: true },
    },
  }

  beforeEach(() => {
    vi.clearAllMocks()
    m.canWrite.mockReturnValue(true)
    m.timeMonth.mockResolvedValue({ items: [], total: 0, limit: 25, offset: 0 })
    m.approveTimeMonth.mockResolvedValue({})
  })

  function gridPage(names: string[]) {
    return {
      items: names.map((name, index) => ({ ...row(12 + index, name), entries: [] })),
      total: names.length,
      limit: 25,
      offset: 0,
    }
  }

  function periodOf(wrapper: ReturnType<typeof mount>): string {
    return (wrapper.find('input[type="month"]').element as HTMLInputElement).value
  }

  it('kreslí mřížku zaměstnanci × dny, ne jeden souhrnný řádek', async () => {
    m.timeMonth.mockResolvedValue(gridPage(['Syntetická osoba A', 'Syntetická osoba B']))
    const wrapper = mount(TimeAttendance, GRID_MOUNT)
    await flushPromises()

    const grid = wrapper.find('[data-test="payroll-time-grid"]')
    expect(grid.exists()).toBe(true)
    const period = periodOf(wrapper)
    const [year, month] = period.split('-').map(Number)
    const days = new Date(Date.UTC(year, month, 0)).getUTCDate()
    expect(grid.findAll('[data-grid-pos]')).toHaveLength(days * 2)
    // Řádkový editor zůstává k dispozici pro výjimky, mřížka ho nenahrazuje.
    expect(wrapper.findAll('button').some(button => button.text() === 'payroll.time.add'))
      .toBe(true)
    wrapper.unmount()
  })

  /** X-07: bez klávesnice je hromadné zadávání čísel k ničemu. */
  it('Enter posune kurzor o řádek níž, šipka doprava o den dál', async () => {
    m.timeMonth.mockResolvedValue(gridPage(['Osoba A', 'Osoba B']))
    const wrapper = mount(TimeAttendance, GRID_MOUNT)
    await flushPromises()

    const first = wrapper.find('[data-grid-pos="0-0"]')
    ;(first.element as HTMLInputElement).focus()
    await first.trigger('keydown', { key: 'Enter' })
    expect((document.activeElement as HTMLElement).getAttribute('data-grid-pos')).toBe('1-0')

    await wrapper.find('[data-grid-pos="1-0"]').trigger('keydown', { key: 'ArrowRight' })
    expect((document.activeElement as HTMLElement).getAttribute('data-grid-pos')).toBe('1-1')
    wrapper.unmount()
  })

  it('vyplní pracovní dny a uloží celou stránku JEDNÍM požadavkem', async () => {
    const page = gridPage(['Osoba A'])
    m.timeMonth.mockResolvedValue(page)
    m.saveTimeEntryBatch.mockImplementation((payload: any) => Promise.resolve({
      saved: payload.cells.length,
      failures: [],
      month: page,
    }))
    const wrapper = mount(TimeAttendance, GRID_MOUNT)
    await flushPromises()

    const fill = wrapper.findAll('button')
      .find(button => button.text() === 'payroll.time.grid.fill_workdays')
    expect(fill).toBeDefined()
    await fill!.trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="grid-note"]').text()).toContain('payroll.time.grid.filled')

    await wrapper.find('[data-test="grid-save"]').trigger('click')
    await flushPromises()

    expect(m.saveTimeEntryBatch).toHaveBeenCalledTimes(1)
    const payload = m.saveTimeEntryBatch.mock.calls[0][0]
    // Bez kalendáře se odhaduje pondělí až pátek — v žádném měsíci jich není
    // míň než dvacet a víc než třiadvacet.
    expect(payload.cells.length).toBeGreaterThanOrEqual(20)
    expect(payload.cells.length).toBeLessThanOrEqual(23)
    expect(payload.cells[0]).toMatchObject({ category: 'regular', month_row_version: 1 })
    wrapper.unmount()
  })

  it('částečné uložení nechá vadnou buňku rozepsanou i s důvodem', async () => {
    const page = gridPage(['Osoba A'])
    m.timeMonth.mockResolvedValue(page)
    m.saveTimeEntryBatch.mockResolvedValue({
      saved: 0,
      failures: [{
        index: 0,
        employment_id: 12,
        date: '',
        category: 'regular',
        code: 'row_version_conflict',
        message: 'Záznam mezitím změnil jiný uživatel.',
      }],
      month: page,
    })
    const wrapper = mount(TimeAttendance, GRID_MOUNT)
    await flushPromises()

    const cell = wrapper.find('[data-grid-pos="0-0"]')
    await cell.setValue('8')
    await wrapper.find('[data-test="grid-save"]').trigger('click')
    await flushPromises()

    const panel = wrapper.find('[data-test="grid-save-error"]')
    expect(panel.exists()).toBe(true)
    expect(panel.text()).toContain('payroll.time.grid.saved_partially')
    expect(panel.text()).toContain('Záznam mezitím změnil jiný uživatel.')
    // Neuložená hodnota nesmí z políčka zmizet, jinak ji uživatel píše znovu.
    expect((wrapper.find('[data-grid-pos="0-0"]').element as HTMLInputElement).value).toBe('8')
    wrapper.unmount()
  })

  it('nečitelná hodina se pojmenuje u buňky a neodešle se na server', async () => {
    const page = gridPage(['Osoba A'])
    m.timeMonth.mockResolvedValue(page)
    const wrapper = mount(TimeAttendance, GRID_MOUNT)
    await flushPromises()

    await wrapper.find('[data-grid-pos="0-0"]').setValue('osm hodin')
    await wrapper.find('[data-test="grid-save"]').trigger('click')
    await flushPromises()

    expect(m.saveTimeEntryBatch).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="grid-save-error"]').text())
      .toContain('payroll.time.grid.problems.unparsable')
    wrapper.unmount()
  })

  /**
   * Schválení nově materializuje příplatky, takže padá i na chybějícím
   * podkladu. Toast to zamlčí; uživatel musí vidět, co chybí a kam jít.
   */
  it('selhané schválení pojmenuje chybějící podklad a nabídne cíl', async () => {
    m.timeMonth.mockResolvedValue(gridPage(['Osoba A']))
    m.approveTimeMonth.mockRejectedValue({
      response: { data: { error: { code: 'holiday_arrangement_missing', message: '409' } } },
    })
    const wrapper = mount(TimeAttendance, GRID_MOUNT)
    await flushPromises()

    await wrapper.find('thead input[type="checkbox"]').trigger('change')
    await wrapper.get('[data-test="bulk-approve-open"]').trigger('click')
    await wrapper.get('[data-test="bulk-standard-fund"]').setValue('168')
    await wrapper.get('[data-test="bulk-approve-form"]').trigger('submit')
    await flushPromises()

    const panel = wrapper.find('[data-test="approve-error"]')
    expect(panel.exists()).toBe(true)
    expect(panel.text()).toContain('payroll.time.approve_errors.holiday_arrangement_missing')
    expect(wrapper.find('[data-test="approve-error-link"]').exists()).toBe(true)
    wrapper.unmount()
  })

  /**
   * X-18: hromadné schválení posílalo `approveTimeMonth` BEZ souhrnu JMHZ,
   * zatímco schválení jednoho vztahu šlo přes modal s dvanácti poli. Měsíc se
   * tak dal schválit dvěma různě úplnými způsoby. Souhrn se teď skládá i
   * v dávce — z návrhu serveru pro každý řádek plus jednoho zákonného fondu.
   */
  it('hromadné schválení pošle souhrn JMHZ z návrhu, ne prázdno', async () => {
    m.timeMonth.mockResolvedValue(gridPage(['Osoba A', 'Osoba B']))
    const wrapper = mount(TimeAttendance, GRID_MOUNT)
    await flushPromises()

    await wrapper.find('thead input[type="checkbox"]').trigger('change')
    await wrapper.get('[data-test="bulk-approve-open"]').trigger('click')
    await wrapper.get('[data-test="bulk-standard-fund"]').setValue('168')
    await wrapper.get('[data-test="bulk-approve-form"]').trigger('submit')
    await flushPromises()

    expect(m.approveTimeMonth).toHaveBeenCalledTimes(2)
    expect(m.approveTimeMonth.mock.calls[0][1]).toMatchObject({
      employment_id: 12,
      jmhz_work_summary: {
        source_snapshot_sha256: 'a'.repeat(64),
        standard_fund_hours: '168',
        agreed_fund_hours: '160',
        weekly_work_hours: '40',
        worked_hours: '160',
        unworked_hours_occurred: false,
        work_obstacles_occurred: false,
      },
    })
    wrapper.unmount()
  })

  /**
   * Bez zákonného fondu by souhrn nebyl úplný — tlačítko proto drží a věta
   * pod ním říká proč (zašedlé tlačítko bez důvodu je slepá ulička).
   */
  it('hromadné schválení bez zákonného fondu neodešle a řekne proč', async () => {
    m.timeMonth.mockResolvedValue(gridPage(['Osoba A']))
    const wrapper = mount(TimeAttendance, GRID_MOUNT)
    await flushPromises()

    await wrapper.find('thead input[type="checkbox"]').trigger('change')
    await wrapper.get('[data-test="bulk-approve-open"]').trigger('click')
    await wrapper.get('[data-test="bulk-approve-form"]').trigger('submit')
    await flushPromises()

    expect(m.approveTimeMonth).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="bulk-approve-blocked"]').text())
      .toContain('payroll.time.bulk.blocked_no_standard_fund')
    wrapper.unmount()
  })

  /**
   * Měsíc s absencí se do dávky nevezme: odpověď na IN07/IN08 je tam lidské
   * rozhodnutí, ne údaj z evidence. Vypadnout ale musí VIDITELNĚ, jménem —
   * tiché vynechání by tvrdilo, že je hotovo.
   */
  it('vztah s absencí z dávky vyřadí a pojmenuje ho', async () => {
    const page = gridPage(['Osoba A', 'Osoba B'])
    page.items[1].jmhz_work_summary.preview.requires_unworked_hours_followup = true
    m.timeMonth.mockResolvedValue(page)
    const wrapper = mount(TimeAttendance, GRID_MOUNT)
    await flushPromises()

    await wrapper.find('thead input[type="checkbox"]').trigger('change')
    await wrapper.get('[data-test="bulk-approve-open"]').trigger('click')

    const excluded = wrapper.get('[data-test="bulk-approve-excluded"]')
    expect(excluded.text()).toContain('Osoba B')
    expect(excluded.text()).toContain('payroll.time.bulk.excluded.absences')

    await wrapper.get('[data-test="bulk-standard-fund"]').setValue('168')
    await wrapper.get('[data-test="bulk-approve-form"]').trigger('submit')
    await flushPromises()

    expect(m.approveTimeMonth).toHaveBeenCalledTimes(1)
    expect(m.approveTimeMonth.mock.calls[0][1]).toMatchObject({ employment_id: 12 })
    wrapper.unmount()
  })

  /**
   * Selhání se sbírají VŠECHNA. „Nepodařilo se schválit 3 vztahy" znamená
   * otevřít tři řádky a hádat, který na čem spadl.
   */
  it('vypíše každý neúspěšný řádek dávky jménem', async () => {
    m.timeMonth.mockResolvedValue(gridPage(['Osoba A', 'Osoba B']))
    m.approveTimeMonth.mockRejectedValue({
      response: { data: { error: { code: 'average_earning_missing', message: '409' } } },
    })
    const wrapper = mount(TimeAttendance, GRID_MOUNT)
    await flushPromises()

    await wrapper.find('thead input[type="checkbox"]').trigger('change')
    await wrapper.get('[data-test="bulk-approve-open"]').trigger('click')
    await wrapper.get('[data-test="bulk-standard-fund"]').setValue('168')
    await wrapper.get('[data-test="bulk-approve-form"]').trigger('submit')
    await flushPromises()

    const rows = wrapper.findAll('[data-test="approve-error-row"]')
    expect(rows).toHaveLength(2)
    expect(rows[0].text()).toContain('Osoba A')
    expect(rows[1].text()).toContain('Osoba B')
    wrapper.unmount()
  })

  /**
   * Klíč buňky je „vztah|den" bez kategorie, takže rozepsané hodnoty se při
   * přepnutí vrstvy musí zahodit. Jinak by se osm hodin běžné práce zapsalo
   * jako osm hodin přesčasu.
   */
  it('přepnutí kategorie zahodí rozepsané buňky, nepřelije je', async () => {
    m.timeMonth.mockResolvedValue(gridPage(['Osoba A']))
    const wrapper = mount(TimeAttendance, GRID_MOUNT)
    await flushPromises()

    await wrapper.find('[data-grid-pos="0-0"]').setValue('8')
    expect(wrapper.find('[data-test="grid-save"]').text()).toContain('payroll.time.grid.save')
    await wrapper.find('[data-test="grid-category"]').setValue('overtime')
    await flushPromises()

    expect((wrapper.find('[data-grid-pos="0-0"]').element as HTMLInputElement).value).toBe('')
    expect(wrapper.find('[data-test="grid-save-blocked"]').text())
      .toContain('payroll.time.grid.blocked_nothing_changed')
    wrapper.unmount()
  })

  /** Příznaky se nesčítají do odpracované doby — a mřížka to musí říct. */
  it('u příznakové kategorie odmítne hromadné vyplnění a vysvětlí proč', async () => {
    m.timeMonth.mockResolvedValue(gridPage(['Osoba A']))
    const wrapper = mount(TimeAttendance, GRID_MOUNT)
    await flushPromises()

    await wrapper.find('[data-test="grid-category"]').setValue('night')
    await flushPromises()

    expect(wrapper.find('[data-test="grid-flag-notice"]').text())
      .toContain('payroll.time.grid.flag_notice')
    const fill = wrapper.findAll('button')
      .find(button => button.text() === 'payroll.time.grid.fill_workdays')
    expect(fill!.attributes('disabled')).toBeDefined()
    expect(fill!.attributes('title')).toContain('payroll.time.grid.blocked_flag_category')
    wrapper.unmount()
  })

  /** Mřížka na telefon nepatří — místo ní musí být věta, ne prázdno. */
  it('na mobilu nabídne řádkové zadání větou místo jednatřiceti sloupců', async () => {
    m.timeMonth.mockResolvedValue(gridPage(['Osoba A']))
    const wrapper = mount(TimeAttendance, GRID_MOUNT)
    await flushPromises()

    expect(wrapper.find('[data-test="payroll-time-grid"]').classes()).toContain('md:block')
    expect(wrapper.find('[data-test="grid-mobile-note"]').classes()).toContain('md:hidden')
    wrapper.unmount()
  })
})
