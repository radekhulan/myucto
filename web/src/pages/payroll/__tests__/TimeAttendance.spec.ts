import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { config, flushPromises, mount, RouterLinkStub } from '@vue/test-utils'
import { formatPeriod } from '@/composables/useFormat'

const m = vi.hoisted(() => ({
  routeQuery: {} as Record<string, string | string[]>,
  routerReplace: vi.fn(),
  timeMonth: vi.fn(),
  timeHistory: vi.fn(),
  saveTimeEntry: vi.fn(),
  saveShift: vi.fn(),
  cancelTimeRecord: vi.fn(),
  absencesPage: vi.fn(),
  saveTimeEntryBatch: vi.fn(),
  previewTimeImport: vi.fn(),
  importTime: vi.fn(),
  approveTimeMonth: vi.fn(),
  reopenTimeMonth: vi.fn(),
  canWrite: vi.fn(),
  toastError: vi.fn(),
  revealField: vi.fn(),
}))

// Průvodce měsícem a akce řádků vedou odkazy do jiných agend.
config.global.stubs = { ...config.global.stubs, RouterLink: RouterLinkStub }

vi.mock('@/utils/revealField', () => ({ revealField: m.revealField }))

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
    timeHistory: m.timeHistory,
    saveTimeEntry: m.saveTimeEntry,
    saveShift: m.saveShift,
    cancelTimeRecord: m.cancelTimeRecord,
    saveTimeEntryBatch: m.saveTimeEntryBatch,
    previewTimeImport: m.previewTimeImport,
    importTime: m.importTime,
    approveTimeMonth: m.approveTimeMonth,
    reopenTimeMonth: m.reopenTimeMonth,
  },
}))

vi.mock('@/api/payrollAbsences', () => ({
  payrollAbsenceApi: { absencesPage: m.absencesPage },
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
  useI18n: () => ({ locale: { value: 'cs' },
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

/** Jeden měsíc historie — tatáž čísla, jaká pro ten měsíc vrací přehled. */
function historyMonth(period: string) {
  return {
    period,
    month: { status: 'open', row_version: 1 },
    summary: {
      fund_minutes: 9_600,
      planned_minutes: 9_600,
      actual_minutes: 9_120,
      difference_minutes: -480,
      category_minutes: {},
      incomplete: false,
    },
    shift_count: 20,
    entry_count: 19,
  }
}

afterEach(() => {
  vi.useRealTimers()
})

describe('TimeAttendance', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.canWrite.mockReturnValue(true)
    m.timeMonth.mockResolvedValue({ items: [], total: 0, limit: 25, offset: 0 })
    m.timeHistory.mockResolvedValue({
      employment: { id: 0, full_name: '', code: '', start_date: null, actual_start_date: null },
      items: [],
      total: 0,
      limit: 12,
      offset: 0,
      range: { from: '2026-01', to: '2026-01' },
    })
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

  it.each([[3, '0,001'], [50, '0:01'], [53, '0:01:03.6'], [0, '0:00'], [null, 'payroll.worked_hours.unknown']] as const)('ruční souhrn %s ukáže přesně a staré denní hodiny pouze jako nepoužité', async (units, text) => {
    m.routeQuery = { period: '2026-09', employment: '12' }
    const original = row(12, 'Syntetická Osoba')
    m.timeMonth.mockResolvedValue({ items: [{ ...original,
      month: { ...original.month, work_source: 'manual_summary' },
      summary: { ...original.summary, work_source: 'manual_summary', worked_duration_units: units, actual_minutes: null, difference_minutes: null, category_minutes: null },
      manual_summary: { id: 5, time_month_revision_no: 2, content_sha256: 'a'.repeat(64), reason: 'Syntetický ruční podklad', segments: [] },
      shifts: [], entries: [{ id: 101, row_version: 1, category: 'regular', starts_at: '2026-09-01T08:00:00+02:00', ends_at: '2026-09-01T16:00:00+02:00', timezone_name: 'Europe/Prague', net_minutes: 480, break_minutes: 0, status: 'approved' }],
    }], total: 1, limit: 25, offset: 0 })
    const wrapper = mount(TimeAttendance, { global: { stubs: { teleport: true } } })
    await flushPromises()
    expect(wrapper.get('[data-test="time-actual-12"]').text()).toBe(text)
    expect(wrapper.get('[data-test="grid-worked-total-12"]').text()).toBe(text)
    expect(wrapper.get('[data-test="grid-cell-12-2026-09-01"]').element).toHaveProperty('value', '')
    expect(wrapper.get('[data-test="grid-cell-12-2026-09-01"]').attributes('disabled')).toBeDefined()
    expect(wrapper.find('[data-test="time-selected-source"]').text()).toContain('source_manual')
    await openRowEditor(wrapper)
    expect(wrapper.get('[data-test="time-inactive-records-hint"]').text()).toContain('inactive_entries_hint')
    const old = wrapper.get('[data-test="time-record-entry-101"]')
    expect(old.get('[data-test="time-inactive-entry"]').text()).toContain('inactive_entry')
    expect(old.find('[data-test="time-record-edit"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="time-record-save-blocked"]').text()).toContain('daily_source_locked')
    wrapper.unmount()
    m.routeQuery = {}
  })

  it('importovaný měsíc měsíčně odměňovaného vztahu ponechá mřížku i přehled beze změny', async () => {
    m.routeQuery = { period: '2026-09', employment: '12' }
    const original = row(12, 'Syntetická Osoba')
    m.timeMonth.mockResolvedValue({ items: [{ ...original, hourly_remuneration_in_period: false,
      month: { ...original.month, work_source: 'import_summary' },
      summary: { ...original.summary, work_source: 'import_summary', worked_duration_units: 0, actual_minutes: 0, difference_minutes: -9_600 },
      shifts: [], entries: [],
    }], total: 1, limit: 25, offset: 0 })
    const wrapper = mount(TimeAttendance, { global: { stubs: { teleport: true } } })
    await flushPromises()
    expect(wrapper.find('[data-test="time-selected-source"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="grid-summary-source"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="grid-cell-12-2026-09-01"]').attributes('disabled')).toBeUndefined()
    await openRowEditor(wrapper)
    expect(wrapper.find('[data-test="time-inactive-records-hint"]').exists()).toBe(false)
    wrapper.unmount()
    m.routeQuery = {}
  })

  it('importovaný měsíc vztahu bez hodinové odměny pošle nastavit sazbu na kartu vztahu', async () => {
    m.routeQuery = { period: '2026-09', employment: '12' }
    const original = row(12, 'Syntetická Osoba')
    m.timeMonth.mockResolvedValue({ items: [{ ...original, hourly_remuneration_in_period: false,
      month: { ...original.month, status: 'open', work_source: 'import_summary' },
      summary: { ...original.summary, work_source: 'import_summary', worked_duration_units: 0, actual_minutes: 0, difference_minutes: -9_600 },
      shifts: [], entries: [],
    }], total: 1, limit: 25, offset: 0 })
    const wrapper = mount(TimeAttendance, { global: { stubs: { teleport: true } } })
    await flushPromises()
    expect(wrapper.get('[data-test="time-import-needs-rate-12"]').text()).toContain('payroll.worked_hours.switch_to_entries.needs_rate')
    wrapper.unmount()
    m.routeQuery = {}
  })

  it('historie měsíce použije přesný vybraný souhrn a ne archivní denní záznamy', async () => {
    m.routeQuery = { period: '2026-09', employment: '12' }
    m.timeMonth.mockResolvedValue({ items: [{ ...row(12, 'Syntetická Osoba'), entries: [] }], total: 1, limit: 25, offset: 0 })
    const old = historyMonth('2026-09')
    m.timeHistory.mockResolvedValue({ employment: { id: 12, full_name: 'Syntetická Osoba', code: 'SYN-12' }, items: [{ ...old, month: { ...old.month, work_source: 'manual_summary' }, summary: { ...old.summary, work_source: 'manual_summary', worked_duration_units: 3, actual_minutes: null, difference_minutes: null, category_minutes: null } }], total: 1, limit: 12, offset: 0, range: { from: '2026-01', to: '2026-12' } })
    const wrapper = mount(TimeAttendance)
    await flushPromises()
    await wrapper.get('[data-test="payroll-scope-year"]').trigger('click')
    await flushPromises()
    expect(wrapper.get('[data-test="history-actual-2026-09"]').text()).toBe('0,001')
    expect(wrapper.get('[data-test="history-row-2026-09"]').text()).toContain('inactive_record_counts')
    expect(wrapper.get('[data-test="history-row-2026-09"]').text()).toContain('payroll.worked_hours.unknown')
    wrapper.unmount()
    m.routeQuery = {}
  })

  it('neověřitelné přesčasy ukáže jako neznámé, nikoli nulu nebo prokázaný porušený zákaz', async () => {
    m.routeQuery = { period: '2026-09' }
    const item = row(12, 'Syntetická Osoba')
    m.timeMonth.mockResolvedValue({ items: [{ ...item, entries: [], shifts: [], overtime_consents: [], overtime_protections: [], overtime_compensations: [],
      overtime_limits: { employment_id: 12, evidence_status: 'manual_review_required', manual_summary_periods: ['2026-08'],
        findings: [{ code: 'overtime_manual_summary_review_required', severity: 'warning', message: 'Neověřený podklad', actual_minutes: null, limit_minutes: 9000, scope_from: '2026-01-01', scope_to: '2026-09-30', consent_evidenced: false, provision: '§ 93', requires_override: true }],
        weeks: [], ordered_year_minutes: null, agreed_year_minutes: null, ordered_year_limit_minutes: 9000,
        averaging_from: '2026-01-01', averaging_to: '2026-09-30', averaging_weeks: 26, averaging_minutes: null,
        averaging_limit_minutes: 12480, averaging_compensated_minutes: null, averaging_basis: 'statutory', averaging_reference: null,
        prohibited_minutes: {}, requires_override: true, consent_evidenced: false, limits_from_ruleset: true,
      },
    }], total: 1, limit: 25, offset: 0 })
    const wrapper = mount(TimeAttendance)
    await flushPromises()
    const limits = wrapper.get('[data-test="overtime-limits-12"]')
    expect(limits.text()).toContain('payroll.time.overtime.manual_review')
    expect(limits.text()).toContain('payroll.time.overtime.year_unknown')
    expect(limits.text()).toContain('payroll.time.overtime.averaging_unknown')
    expect(limits.find('[data-test="overtime-prohibition-banner"]').exists()).toBe(false)
    expect(limits.text()).not.toContain('payroll.time.overtime.year_summary')
    wrapper.unmount()
    m.routeQuery = {}
  })

  it.each(['open', 'approved'] as const)('proklik na JMHZ otevře existující potvrzení jen u otevřeného měsíce (%s)', async status => {
    m.routeQuery = { period: '2026-11', employment: '12', confirm_jmhz: '1' }
    const item = row(12, 'Syntetická Osoba')
    item.month.status = status
    m.timeMonth.mockResolvedValue({ items: [item], total: 1, limit: 25, offset: 0 })
    const wrapper = mount(TimeAttendance, { global: { stubs: { teleport: true } } })
    await flushPromises()
    expect(wrapper.find('[data-test="jmhz-confirm"]').exists()).toBe(status === 'open')
    expect(m.approveTimeMonth).not.toHaveBeenCalled()
    wrapper.unmount()
    m.routeQuery = {}
  })

  it('nabídne počet ztěžujících vlivů jen u ztíženého prostředí a pošle vyplněný počet nebo null', async () => {
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

  /*
   * Q8-21/22: „Uložit a další den" posunul konec dvakrát (sám a ještě watcher
   * začátku) — každá další směna končila o den později. A po pátku nabídl sobotu.
   */
  it('Uložit a další den drží konec ve stejném dni a přeskočí víkend', async () => {
    m.routeQuery = { period: '2026-09' }
    m.timeMonth.mockResolvedValue({
      items: [row(12, 'Syntetická osoba A')],
      total: 1,
      limit: 25,
      offset: 0,
    })
    const wrapper = mount(TimeAttendance)
    await flushPromises()
    await openRowEditor(wrapper)

    const [start, end] = wrapper.get('[data-test="time-record-form"]').findAll('input[type="datetime-local"]')
    await start.setValue('2026-09-17T08:00')
    await flushPromises()
    expect((end.element as HTMLInputElement).value).toBe('2026-09-17T16:30')

    await wrapper.get('[data-test="time-record-save-next"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="time-record-save-next"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="time-record-save-next"]').trigger('click')
    await flushPromises()

    const calls = m.saveTimeEntry.mock.calls.map(([payload]) => [payload.starts_at, payload.ends_at])
    expect(calls).toEqual([
      ['2026-09-17T08:00:00+02:00', '2026-09-17T16:30:00+02:00'],
      ['2026-09-18T08:00:00+02:00', '2026-09-18T16:30:00+02:00'],
      ['2026-09-21T08:00:00+02:00', '2026-09-21T16:30:00+02:00'],
    ])
    m.routeQuery = {}
    wrapper.unmount()
  })

  /* Q8-18: bez hledání šel člověk mezi stovkami vztahů najít jen z karty. */
  it('hledání podle jména pošle dotaz na server a vrátí se na první stránku', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
    m.routeQuery = { period: '2026-09' }
    const wrapper = mount(TimeAttendance)
    await flushPromises()
    await wrapper.get('[data-test="payroll-time-search"]').setValue('Nov')
    await flushPromises()
    await vi.advanceTimersByTimeAsync(350)
    await flushPromises()
    expect(m.timeMonth).toHaveBeenLastCalledWith('2026-09', false, { limit: 25, offset: 0 }, null, 'Nov')
    m.routeQuery = {}
    wrapper.unmount()
  })

  /* Q8-17: proklik z karty u budoucího nástupu otevřel měsíc, kdy vztah ještě nebyl. */
  it('zúžení na vztah, který nastoupí později, otevře měsíc nástupu', async () => {
    m.routeQuery = { employment: '236' }
    m.timeMonth.mockImplementation((period: string) => Promise.resolve(period === '2030-10'
      ? { items: [row(236, 'Syntetická osoba C')], total: 1, limit: 25, offset: 0, employment_id: 236 }
      : { items: [], total: 0, limit: 25, offset: 0, employment_id: 236 }))
    m.timeHistory.mockResolvedValue({
      employment: { id: 236, full_name: 'Syntetická osoba C', code: 'C', start_date: '2030-10-15', actual_start_date: null },
      items: [],
      total: 0,
      limit: 1,
      offset: 0,
      range: { from: '2030-10', to: '2030-10' },
    })
    const wrapper = mount(TimeAttendance)
    await flushPromises()
    expect(m.timeMonth).toHaveBeenLastCalledWith('2030-10', false, { limit: 25, offset: 0 }, 236)
    expect(m.routerReplace).toHaveBeenCalledWith({ query: { employment: '236', period: '2030-10' } })
    expect(wrapper.get('[data-test="payroll-focus-notice"]').text()).toContain('payroll.agendas.focus.title')
    m.routeQuery = {}
    wrapper.unmount()
  })

  /* Q8-19: DPN bez směn nešla schválit a směny šly zadat jen po jedné. */
  it('Rozvrhnout směny založí publikovanou směnu na každý pracovní den bez směny', async () => {
    m.routeQuery = { period: '2026-09' }
    let version = 1
    m.saveShift.mockImplementation(() => Promise.resolve({ month: { row_version: ++version } }))
    const item = { ...row(12, 'Syntetická osoba A'), shifts: [{
      id: 1, employment_id: 12, calendar_id: null, series_key: 's', revision_no: 1,
      starts_at: '2026-09-01T08:00:00+02:00', ends_at: '2026-09-01T16:30:00+02:00',
      timezone_name: 'Europe/Prague', break_minutes: 30, net_minutes: 480,
      remote_work: false, standby_minutes: 0, status: 'published', row_version: 1,
    }], entries: [] }
    m.timeMonth.mockResolvedValue({ items: [item], total: 1, limit: 25, offset: 0 })
    const wrapper = mount(TimeAttendance)
    await flushPromises()
    await wrapper.get('[data-test="plan-shifts-12"]').trigger('click')
    await flushPromises()
    // 22 pracovních dnů září 2026 bez odhadu svátků, jeden už směnu má.
    expect(m.saveShift).toHaveBeenCalledTimes(21)
    expect(m.saveShift.mock.calls[0][0]).toMatchObject({
      starts_at: '2026-09-02T08:00:00+02:00',
      ends_at: '2026-09-02T16:30:00+02:00',
      break_minutes: 30,
      publish: true,
      month_row_version: 1,
    })
    expect(m.saveShift.mock.calls[1][0]).toMatchObject({ month_row_version: 2 })
    m.routeQuery = {}
    wrapper.unmount()
  })

  it('zápis delší než 24 hodin neuloží a řekne proč', async () => {
    m.routeQuery = { period: '2026-09' }
    m.timeMonth.mockResolvedValue({
      items: [row(12, 'Syntetická osoba A')],
      total: 1,
      limit: 25,
      offset: 0,
    })
    const wrapper = mount(TimeAttendance)
    await flushPromises()
    await openRowEditor(wrapper)

    const [start, end] = wrapper.get('[data-test="time-record-form"]').findAll('input[type="datetime-local"]')
    await start.setValue('2026-09-15T08:00')
    await flushPromises()
    await end.setValue('2026-09-16T16:30')
    await flushPromises()
    expect(wrapper.get('[data-test="time-record-save-blocked"]').text()).toBe('payroll.time.editor.blocked_too_long')
    expect(wrapper.get('[data-test="time-record-save"]').attributes('disabled')).toBeDefined()
    m.routeQuery = {}
    wrapper.unmount()
  })

  it('ukáže zápisy měsíce a chybnou směnu jde upravit jako novou revizi', async () => {
    m.routeQuery = { period: '2026-09' }
    m.saveShift.mockResolvedValue({})
    m.cancelTimeRecord.mockResolvedValue({})
    const item = {
      ...row(12, 'Syntetická osoba A'),
      shifts: [{
        id: 5,
        employment_id: 12,
        calendar_id: null,
        series_key: 's',
        revision_no: 1,
        starts_at: '2026-09-15T08:00:00+02:00',
        ends_at: '2026-09-16T16:30:00+02:00',
        timezone_name: 'Europe/Prague',
        break_minutes: 30,
        net_minutes: 1920,
        remote_work: false,
        standby_minutes: 0,
        status: 'published',
        row_version: 3,
      }],
      entries: [],
    }
    m.timeMonth.mockResolvedValue({ items: [item], total: 1, limit: 25, offset: 0 })
    const wrapper = mount(TimeAttendance)
    await flushPromises()
    await openRowEditor(wrapper)

    const record = wrapper.get('[data-test="time-record-shift-5"]')
    expect(record.find('[data-test="time-record-too-long"]').exists()).toBe(true)
    await record.get('[data-test="time-record-edit"]').trigger('click')
    await flushPromises()
    const [, end] = wrapper.get('[data-test="time-record-form"]').findAll('input[type="datetime-local"]')
    expect((end.element as HTMLInputElement).value).toBe('2026-09-16T16:30')
    await end.setValue('2026-09-15T16:30')
    await flushPromises()
    await wrapper.get('[data-test="time-record-save"]').trigger('submit')
    await flushPromises()
    expect(m.saveShift).toHaveBeenLastCalledWith(expect.objectContaining({
      starts_at: '2026-09-15T08:00:00+02:00',
      ends_at: '2026-09-15T16:30:00+02:00',
      supersedes_id: 5,
      row_version: 3,
    }))

    await openRowEditor(wrapper)
    await wrapper.get('[data-test="time-record-shift-5"] [data-test="time-record-cancel"]').trigger('click')
    await flushPromises()
    const confirm = document.body.querySelector<HTMLButtonElement>('[data-test="time-record-cancel-confirm"]')
    expect(confirm).not.toBeNull()
    confirm!.click()
    await flushPromises()
    expect(m.cancelTimeRecord).toHaveBeenCalledWith('shifts', 5, {
      employment_id: 12,
      row_version: 3,
      month_row_version: 1,
    })
    m.routeQuery = {}
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

  it('vede problém pracovní doby přímo na účinné podmínky správného vztahu', async () => {
    const item = row(12, 'Syntetická osoba')
    item.jmhz_work_summary.preview.issues = [{ code: 'employment_terms_not_unique_for_month', message: 'Měsíc nemá jedinou konzistentní verzi týdenní pracovní doby.' }] as never[]
    m.timeMonth.mockResolvedValue({ items: [item] })
    const wrapper = mount(TimeAttendance, { global: { stubs: { teleport: true, RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' } } } })
    await flushPromises()
    await wrapper.findAll('button').find(button => button.text() === 'payroll.time.approve')!.trigger('click')
    expect(wrapper.get('[data-test="work-summary-guidance"] a').attributes('href'))
      .toBe('/payroll/people?employment=12&panel=employment_terms&field=weekly_hours')
    expect(wrapper.get('[data-test="work-summary-guidance"]').text()).toContain('payroll.remediation.work.steps.terms')
  })

  // Chybějící kalendář vztahu server hlásí za každý den; v září to bylo
  // 30 stejných odstavců s tlačítkem pod sebou.
  it('tentýž problém pracovního souhrnu za více dní ukáže jen jednou', async () => {
    const item = row(12, 'Syntetická osoba')
    item.jmhz_work_summary.preview.issues = [1, 2, 3].map(day => ({
      code: 'calendar_day_not_uniquely_covered',
      message: `Den 2026-09-0${day} nemá pracovní kalendář.`,
    })) as never[]
    m.timeMonth.mockResolvedValue({ items: [item] })
    const wrapper = mount(TimeAttendance, { global: { stubs: { teleport: true } } })
    await flushPromises()
    await wrapper.findAll('button').find(button => button.text() === 'payroll.time.approve')!.trigger('click')

    expect(wrapper.findAll('[data-test="work-summary-guidance"]')).toHaveLength(1)
    expect(wrapper.text()).toContain('Den 2026-09-03 nemá pracovní kalendář.')
  })

  it('na mobilu zvýrazní viditelnou akci docházky místo skryté mřížky', async () => {
    const item = row(12, 'Syntetická osoba')
    item.jmhz_work_summary.preview.issues = [{ code: 'worked_intervals_overlap', message: 'Synthetic overlap' }] as never[]
    m.timeMonth.mockResolvedValue({ items: [item] })
    const rects = vi.spyOn(HTMLElement.prototype, 'getClientRects').mockImplementation(function (this: HTMLElement) {
      return (this.closest('.hidden') ? [] : [{}]) as unknown as DOMRectList
    })
    const wrapper = mount(TimeAttendance, { attachTo: document.body, global: { stubs: { teleport: true } } })
    try {
      await flushPromises()
      await wrapper.findAll('button').find(button => button.text() === 'payroll.time.approve')!.trigger('click')
      await wrapper.get('[data-test="work-summary-guidance"] button').trigger('click')
      await flushPromises()
      const [selector, root] = m.revealField.mock.calls.at(-1) as [string, HTMLElement]
      const target = root.querySelector(selector)
      expect(target?.tagName).toBe('BUTTON')
      expect(target?.textContent).toBe('payroll.time.add')
      expect(target?.closest('.hidden')).toBeNull()
      expect(m.saveTimeEntry).not.toHaveBeenCalled()
    } finally {
      wrapper.unmount()
      rects.mockRestore()
    }
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
    // Měsíc bez absencí se otevře připravený: obě otázky mají „ne" a čísla jsou
    // z návrhu serveru, takže po doplnění fondu je schválení jeden klik.
    expect(wrapper.get('[data-test="jmhz-work-summary-form"] button[type="submit"]')
      .attributes('disabled')).toBeDefined()
    await wrapper.get('[data-test="jmhz-standard-fund"]').setValue('168')
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
        maternity_hours: null,
        paternity_hours: null,
        parental_hours: null,
        unpaid_leave_hours: null,
        unexcused_hours: null,
        compensatory_time_off_hours: null,
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
   * Měsíc s dovolenou i nemocí se dřív otevíral prázdný a účetní osm čísel
   * opisovala ručně z evidence, kterou aplikace už má; když je nechala prázdná
   * a měsíc schválila jako bezabsenční, shodilo to hlášení celé firmy. Server
   * je teď dopočítá a dialog je předvyplní — potvrdit je musí pořád člověk.
   */
  it('prefills the conditional JMHZ blocks from the absence suggestion', async () => {
    m.timeMonth.mockResolvedValue({
      items: [{
        employment: { id: 32, full_name: 'Osoba s dovolenou', code: 'ZAM-43' },
        month: { status: 'open', row_version: 4 },
        calendar: null,
        summary: {
          fund_minutes: 10_560,
          planned_minutes: 10_080,
          actual_minutes: 7_680,
          difference_minutes: -2_400,
          category_minutes: {},
          incomplete: false,
        },
        jmhz_work_summary: {
          preview: {
            derivation_version: 'jmhz-work-month.v2',
            source_snapshot_sha256: 'b'.repeat(64),
            suggestions: {
              standard_fund_hours: '168',
              agreed_fund_hours: '176',
              weekly_work_hours: '40.00',
              evidence_days: 30,
              worked_hours: '128',
              unworked_hours_occurred: true,
              work_obstacles_occurred: false,
              unworked_total_hours: '40',
              unworked_paid_hours: '40',
              dpn_without_employer_compensation_hours: null,
              dpn_with_employer_compensation_hours: null,
              vacation_hours: '40',
              care_hours: null,
              employee_obstacle_paid_hours: null,
              employer_obstacle_hours: null,
            },
            issues: [],
            requires_unworked_hours_followup: true,
          },
          current_revision: null,
        },
        shifts: [],
        entries: [],
      }],
    })
    const wrapper = mount(TimeAttendance, { global: { stubs: { teleport: true } } })
    await flushPromises()

    const approve = wrapper.findAll('button')
      .find(button => button.text() === 'payroll.time.approve')
    await approve!.trigger('click')

    expect((wrapper.get('[data-test="jmhz-unworked-yes"]').element as HTMLInputElement)
      .checked).toBe(true)
    expect((wrapper.get('[data-test="jmhz-obstacles-no"]').element as HTMLInputElement)
      .checked).toBe(true)
    expect((wrapper.get('[data-test="jmhz-unworked-total"]').element as HTMLInputElement)
      .value).toBe('40')
    expect((wrapper.get('[data-test="jmhz-unworked-paid"]').element as HTMLInputElement)
      .value).toBe('40')
    expect((wrapper.get('[data-test="jmhz-vacation"]').element as HTMLInputElement)
      .value).toBe('40')
    expect((wrapper.get('[data-test="jmhz-dpn-with-compensation"]').element as HTMLInputElement)
      .value).toBe('')

    // Předvyplněno ano, odesláno ne — potvrzení zůstává na člověku.
    expect(m.approveTimeMonth).not.toHaveBeenCalled()
    await wrapper.get('[data-test="jmhz-work-summary-form"]').trigger('submit')
    await flushPromises()

    expect(m.approveTimeMonth).toHaveBeenCalledWith(expect.any(String),
      expect.objectContaining({
        employment_id: 32,
        jmhz_work_summary: expect.objectContaining({
          unworked_hours_occurred: true,
          work_obstacles_occurred: false,
          unworked_total_hours: '40',
          unworked_paid_hours: '40',
          vacation_hours: '40',
          dpn_with_employer_compensation_hours: null,
          dpn_without_employer_compensation_hours: null,
          care_hours: null,
          employee_obstacle_paid_hours: null,
          employer_obstacle_hours: null,
        }),
      }),
    )
  })

  /**
   * Vzácné nepřítomnosti nesmí zdražit běžné vyplnění.
   *
   * Rodičovská, neplacené volno, PPM, otcovská ani neomluvená absence nemají
   * v měsíčním hlášení vlastní blok — hodiny se u nich sbírají jen proto, aby
   * šlo dny evidenčního listu doložit. Pole se proto ukáže jen u druhu, který
   * měsíc opravdu eviduje; měsíc s dovolenou ani bezabsenční měsíc o pole
   * navíc nepřijde (viz testy výš, kde `absence_types` vůbec nechodí).
   */
  it('shows the rare absence field only for the kind the month actually has', async () => {
    m.timeMonth.mockResolvedValue({
      items: [{
        employment: { id: 34, full_name: 'Osoba na rodičovské', code: 'ZAM-45' },
        month: { status: 'open', row_version: 6 },
        calendar: null,
        summary: {
          fund_minutes: 10_560,
          planned_minutes: 10_080,
          actual_minutes: 9_600,
          difference_minutes: -480,
          category_minutes: {},
          incomplete: false,
        },
        jmhz_work_summary: {
          preview: {
            derivation_version: 'jmhz-work-month.v3',
            source_snapshot_sha256: 'd'.repeat(64),
            suggestions: {
              standard_fund_hours: '168',
              agreed_fund_hours: '176',
              weekly_work_hours: '40.00',
              evidence_days: 30,
              worked_hours: '160',
              unworked_hours_occurred: true,
              work_obstacles_occurred: false,
              unworked_total_hours: '16',
              unworked_paid_hours: null,
              dpn_without_employer_compensation_hours: null,
              dpn_with_employer_compensation_hours: null,
              vacation_hours: null,
              care_hours: null,
              employee_obstacle_paid_hours: null,
              employer_obstacle_hours: null,
              maternity_hours: null,
              paternity_hours: null,
              parental_hours: '16',
              unpaid_leave_hours: null,
              unexcused_hours: null,
              compensatory_time_off_hours: null,
            },
            issues: [],
            requires_unworked_hours_followup: true,
            absence_types: ['parental'],
          },
          current_revision: null,
        },
        shifts: [],
        entries: [],
      }],
    })
    const wrapper = mount(TimeAttendance, { global: { stubs: { teleport: true } } })
    await flushPromises()

    const approve = wrapper.findAll('button')
      .find(button => button.text() === 'payroll.time.approve')
    await approve!.trigger('click')

    expect((wrapper.get('[data-test="jmhz-parental"]').element as HTMLInputElement)
      .value).toBe('16')
    for (const absent of ['maternity', 'paternity', 'unpaid_leave', 'unexcused']) {
      expect(wrapper.find(`[data-test="jmhz-${absent}"]`).exists()).toBe(false)
    }

    await wrapper.get('[data-test="jmhz-work-summary-form"]').trigger('submit')
    await flushPromises()

    expect(m.approveTimeMonth).toHaveBeenCalledWith(expect.any(String),
      expect.objectContaining({
        employment_id: 34,
        jmhz_work_summary: expect.objectContaining({
          unworked_hours_occurred: true,
          unworked_total_hours: '16',
          parental_hours: '16',
          unpaid_leave_hours: null,
          maternity_hours: null,
        }),
      }),
    )
    expect(m.approveTimeMonth.mock.calls.at(-1)?.[1].jmhz_work_summary).not.toHaveProperty('holiday_time_off_hours')
  })
  it.each(['jmhz-work-month.v11', 'jmhz-work-month.v12'])('confirms paid holiday time off for %s without adding it to totals again', async (version) => {
    m.timeMonth.mockResolvedValue({
      items: [{
        employment: { id: 34, full_name: 'Osoba na rodičovské', code: 'ZAM-45' },
        month: { status: 'open', row_version: 6 },
        calendar: null,
        summary: {
          fund_minutes: 10_560,
          planned_minutes: 10_080,
          actual_minutes: 9_600,
          difference_minutes: -480,
          category_minutes: {},
          incomplete: false,
        },
        jmhz_work_summary: {
          preview: {
            derivation_version: version,
            source_snapshot_sha256: 'd'.repeat(64),
            suggestions: {
              standard_fund_hours: '168',
              agreed_fund_hours: '176',
              weekly_work_hours: '40.00',
              evidence_days: 30,
              worked_hours: '160',
              unworked_hours_occurred: true,
              work_obstacles_occurred: false,
              unworked_total_hours: '16',
              unworked_paid_hours: null,
              dpn_without_employer_compensation_hours: null,
              dpn_with_employer_compensation_hours: null,
              vacation_hours: null,
              care_hours: null,
              employee_obstacle_paid_hours: null,
              employer_obstacle_hours: null,
              maternity_hours: null,
              paternity_hours: null,
              parental_hours: null,
              holiday_time_off_hours: '16',
              unpaid_leave_hours: null,
              unexcused_hours: null,
              compensatory_time_off_hours: null,
            },
            issues: [],
            requires_unworked_hours_followup: true,
            absence_types: ['holiday_compensatory_time_off'],
          },
          current_revision: null,
        },
        shifts: [],
        entries: [],
      }],
    })
    const wrapper = mount(TimeAttendance, { global: { stubs: { teleport: true } } })
    await flushPromises()

    const approve = wrapper.findAll('button')
      .find(button => button.text() === 'payroll.time.approve')
    await approve!.trigger('click')

    expect((wrapper.get('[data-test="jmhz-holiday_time_off"]').element as HTMLInputElement)
      .value).toBe('16')
    for (const absent of ['maternity', 'paternity', 'unpaid_leave', 'unexcused']) {
      expect(wrapper.find(`[data-test="jmhz-${absent}"]`).exists()).toBe(false)
    }

    await wrapper.get('[data-test="jmhz-work-summary-form"]').trigger('submit')
    await flushPromises()

    expect(m.approveTimeMonth).toHaveBeenCalledWith(expect.any(String),
      expect.objectContaining({
        employment_id: 34,
        jmhz_work_summary: expect.objectContaining({
          unworked_hours_occurred: true,
          unworked_total_hours: '16',
          holiday_time_off_hours: '16',
          unpaid_leave_hours: null,
          maternity_hours: null,
        }),
      }),
    )
  })
  it('ukáže svátky v jinak pracovní dny, které server přičte k neodpracovaným hodinám', async () => {
    m.timeMonth.mockResolvedValue({
      items: [{
        employment: { id: 35, full_name: 'Osoba se svátkem', code: 'ZAM-46' },
        month: { status: 'open', row_version: 3 },
        calendar: null,
        summary: {
          fund_minutes: 11_040,
          planned_minutes: 10_560,
          actual_minutes: 10_560,
          difference_minutes: 0,
          category_minutes: {},
          incomplete: false,
        },
        jmhz_work_summary: {
          preview: {
            derivation_version: 'jmhz-work-month.v7',
            source_snapshot_sha256: 'e'.repeat(64),
            suggestions: {
              standard_fund_hours: '184',
              agreed_fund_hours: '184',
              weekly_work_hours: '40.00',
              evidence_days: 31,
              worked_hours: '176',
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
              maternity_hours: null,
              paternity_hours: null,
              parental_hours: null,
              unpaid_leave_hours: null,
              unexcused_hours: null,
              compensatory_time_off_hours: null,
            },
            issues: [],
            holiday_millihours: 8_000,
            requires_unworked_hours_followup: false,
            absence_types: [],
          },
          current_revision: null,
        },
        shifts: [],
        entries: [],
      }],
    })
    const wrapper = mount(TimeAttendance, { global: { stubs: { teleport: true } } })
    await flushPromises()

    const approve = wrapper.findAll('button')
      .find(button => button.text() === 'payroll.time.approve')
    await approve!.trigger('click')

    expect(wrapper.get('[data-test="jmhz-holiday-hours"]').text())
      .toContain('payroll.time.jmhz.holiday_hours_hint')
    // Dialog neodpracované hodiny za svátek neposílá, přičte je server.
    await wrapper.get('[data-test="jmhz-work-summary-form"]').trigger('submit')
    await flushPromises()
    expect(m.approveTimeMonth).toHaveBeenCalledWith(expect.any(String),
      expect.objectContaining({
        jmhz_work_summary: expect.objectContaining({
          unworked_hours_occurred: false,
          unworked_total_hours: null,
        }),
      }),
    )
  })
  /**
   * Výkon veřejné funkce a neplacená překážka stojí v souhrnu na bloku
   * neplaceného volna, takže ho dialog musí ukázat i bez neplaceného volna.
   */
  it('shows the unpaid leave field for public office and an unpaid obstacle too', async () => {
    m.timeMonth.mockResolvedValue({
      items: [{
        employment: { id: 34, full_name: 'Osoba ve veřejné funkci', code: 'ZAM-45' },
        month: { status: 'open', row_version: 6 },
        calendar: null,
        summary: {
          fund_minutes: 10_560,
          planned_minutes: 10_080,
          actual_minutes: 9_600,
          difference_minutes: -480,
          category_minutes: {},
          incomplete: false,
        },
        jmhz_work_summary: {
          preview: {
            derivation_version: 'jmhz-work-month.v3',
            source_snapshot_sha256: 'd'.repeat(64),
            suggestions: {
              standard_fund_hours: '168',
              agreed_fund_hours: '176',
              weekly_work_hours: '40.00',
              evidence_days: 30,
              worked_hours: '160',
              unworked_hours_occurred: true,
              work_obstacles_occurred: false,
              unworked_total_hours: '16',
              unworked_paid_hours: null,
              dpn_without_employer_compensation_hours: null,
              dpn_with_employer_compensation_hours: null,
              vacation_hours: null,
              care_hours: null,
              employee_obstacle_paid_hours: null,
              employer_obstacle_hours: null,
              maternity_hours: null,
              paternity_hours: null,
              parental_hours: null,
              unpaid_leave_hours: '16',
              unexcused_hours: null,
              compensatory_time_off_hours: null,
            },
            issues: [],
            requires_unworked_hours_followup: true,
            absence_types: ['public_function', 'employee_obstacle_unpaid'],
          },
          current_revision: null,
        },
        shifts: [],
        entries: [],
      }],
    })
    const wrapper = mount(TimeAttendance, { global: { stubs: { teleport: true } } })
    await flushPromises()

    const approve = wrapper.findAll('button')
      .find(button => button.text() === 'payroll.time.approve')
    await approve!.trigger('click')

    expect((wrapper.get('[data-test="jmhz-unpaid_leave"]').element as HTMLInputElement)
      .value).toBe('16')
    for (const absent of ['maternity', 'paternity', 'parental', 'unexcused']) {
      expect(wrapper.find(`[data-test="jmhz-${absent}"]`).exists()).toBe(false)
    }

    await wrapper.get('[data-test="jmhz-work-summary-form"]').trigger('submit')
    await flushPromises()

    expect(m.approveTimeMonth).toHaveBeenCalledWith(expect.any(String),
      expect.objectContaining({
        employment_id: 34,
        jmhz_work_summary: expect.objectContaining({
          unworked_hours_occurred: true,
          unworked_total_hours: '16',
          parental_hours: null,
          unpaid_leave_hours: '16',
          maternity_hours: null,
        }),
      }),
    )
  })
  /**
   * Měsíc s absencí, kterou modul neumí doložit (server nepošle odpověď na
   * IN07), zůstává nezodpovězený — návrh se nedomýšlí.
   */
  it('leaves the interactions unanswered when the suggestion is undecided', async () => {
    m.timeMonth.mockResolvedValue({
      items: [{
        employment: { id: 33, full_name: 'Osoba s neplaceným volnem', code: 'ZAM-44' },
        month: { status: 'open', row_version: 2 },
        calendar: null,
        summary: {
          fund_minutes: 10_560,
          planned_minutes: 10_080,
          actual_minutes: 9_600,
          difference_minutes: -480,
          category_minutes: {},
          incomplete: false,
        },
        jmhz_work_summary: {
          preview: {
            derivation_version: 'jmhz-work-month.v2',
            source_snapshot_sha256: 'c'.repeat(64),
            suggestions: {
              standard_fund_hours: '168',
              agreed_fund_hours: '176',
              weekly_work_hours: '40.00',
              evidence_days: 30,
              worked_hours: '160',
              unworked_hours_occurred: null,
              work_obstacles_occurred: null,
              unworked_total_hours: null,
              unworked_paid_hours: null,
              dpn_without_employer_compensation_hours: null,
              dpn_with_employer_compensation_hours: null,
              vacation_hours: null,
              care_hours: null,
              employee_obstacle_paid_hours: null,
              employer_obstacle_hours: null,
            },
            issues: [],
            requires_unworked_hours_followup: true,
          },
          current_revision: null,
        },
        shifts: [],
        entries: [],
      }],
    })
    const wrapper = mount(TimeAttendance, { global: { stubs: { teleport: true } } })
    await flushPromises()

    const approve = wrapper.findAll('button')
      .find(button => button.text() === 'payroll.time.approve')
    await approve!.trigger('click')

    expect((wrapper.get('[data-test="jmhz-unworked-yes"]').element as HTMLInputElement)
      .checked).toBe(false)
    expect((wrapper.get('[data-test="jmhz-unworked-no"]').element as HTMLInputElement)
      .checked).toBe(false)
    expect(wrapper.get('[data-test="jmhz-work-summary-form"] button[type="submit"]')
      .attributes('disabled')).toBeDefined()
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

  /**
   * Rozsah (Měsíc / Rok / Vše) dává smysl jen tehdy, když je jasné, čí historii
   * ukázat — u celé firmy by to byl součin lidí a měsíců.
   */
  it('nabídne přepínač rozsahu jen u zúžení na jeden vztah', async () => {
    m.timeMonth.mockResolvedValue({
      items: [row(12, 'Syntetická osoba A')],
      total: 1,
      limit: 25,
      offset: 0,
      employment_id: null,
    })
    const wide = mount(TimeAttendance)
    await flushPromises()
    expect(wide.find('[data-test="payroll-scope-picker"]').exists()).toBe(false)
    wide.unmount()

    m.routeQuery = { employment: '77' }
    m.timeMonth.mockResolvedValue({
      items: [row(77, 'Syntetická osoba Z')],
      total: 1,
      limit: 25,
      offset: 0,
      employment_id: 77,
    })
    const narrowed = mount(TimeAttendance)
    await flushPromises()
    expect(narrowed.find('[data-test="payroll-scope-picker"]').exists()).toBe(true)
    narrowed.unmount()
    m.routeQuery = {}
  })

  /**
   * „Rok" nahradí měsíční zadávání výpisem historie a „Otevřít měsíc" vrátí
   * uživatele do zadávání na TEN měsíc, ne na ten, ze kterého odešel.
   */
  it('v rozsahu Rok ukáže historii místo mřížky a vrátí se na vybraný měsíc', async () => {
    m.routeQuery = { employment: '77' }
    m.timeMonth.mockResolvedValue({
      items: [{ ...row(77, 'Syntetická osoba Z'), entries: [] }],
      total: 1,
      limit: 25,
      offset: 0,
      employment_id: 77,
    })
    m.timeHistory.mockResolvedValue({
      employment: {
        id: 77,
        employee_id: 7,
        code: 'SYN-77',
        relation_type: 'employment',
        status: 'active',
        start_date: '2026-01-01',
        actual_start_date: '2026-01-01',
        end_date: null,
        full_name: 'Syntetická osoba Z',
      },
      items: [historyMonth('2026-08'), historyMonth('2026-07')],
      total: 2,
      limit: 12,
      offset: 0,
      range: { from: '2026-01', to: '2026-08' },
    })
    const wrapper = mount(TimeAttendance, {
      attachTo: document.body,
      global: { stubs: { teleport: true, RouterLink: { template: '<a><slot /></a>' } } },
    })
    await flushPromises()
    expect(wrapper.find('[data-test="payroll-time-grid"]').exists()).toBe(true)
    expect(m.timeHistory).not.toHaveBeenCalled()

    await wrapper.get('[data-test="payroll-scope-year"]').trigger('click')
    await flushPromises()

    expect(m.timeHistory).toHaveBeenCalledWith(77, expect.objectContaining({
      from: expect.stringMatching(/^\d{4}-01$/),
      to: expect.stringMatching(/^\d{4}-12$/),
      limit: 12,
      offset: 0,
    }))
    expect(wrapper.find('[data-test="payroll-time-history"]').exists()).toBe(true)
    // Mřížka i import pracují s jedním měsícem, takže v historii nemají co dělat.
    expect(wrapper.find('[data-test="payroll-time-grid"]').exists()).toBe(false)
    expect(wrapper.findAll('button').some(button => button.text() === 'payroll.time.import.button'))
      .toBe(false)
    // Období se v historii píše lidsky, stejně jako na ostatních mzdových
    // stránkách — `data-test` drží strojový tvar, text ten čitelný.
    expect(wrapper.find('[data-test="history-row-2026-07"]').text()).toContain(formatPeriod('2026-07'))

    await wrapper.get('[data-test="history-open-2026-07"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="payroll-time-history"]').exists()).toBe(false)
    expect((wrapper.find('input[type="month"]').element as HTMLInputElement).value)
      .toBe('2026-07')
    expect(m.timeMonth).toHaveBeenLastCalledWith('2026-07', false, { limit: 25, offset: 0 }, 77)
    wrapper.unmount()
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
    m.absencesPage.mockResolvedValue({ absences: [], total: 0, limit: 200, offset: 0 })
  })

  /*
   * Q8-29: vyplnění plnilo osm hodin i do dnů schválené DPN a ošetřovného.
   * Q8-30: čítač na Uložit i „Odpracováno" se měnily až po uložení.
   */
  it('vyplnění přeskočí dny schválené nepřítomnosti a součty se přepočítají hned', async () => {
    m.routeQuery = { period: '2026-09' }
    m.timeMonth.mockResolvedValue(gridPage(['Osoba A']))
    m.absencesPage.mockResolvedValue({
      absences: [
        { employment_id: 12, date_from: '2026-09-14', date_to: '2026-09-25', status: 'approved' },
        { employment_id: 12, date_from: '2026-09-29', date_to: '2026-09-30', status: 'requested' },
      ],
      total: 2,
      limit: 200,
      offset: 0,
    })
    const wrapper = mount(TimeAttendance, GRID_MOUNT)
    await flushPromises()

    const fill = wrapper.findAll('button')
      .find(button => button.text() === 'payroll.time.grid.fill_workdays')
    await fill!.trigger('click')
    await flushPromises()

    const cell = (date: string) => (wrapper.get(`[data-test="grid-cell-12-${date}"]`).element as HTMLInputElement).value
    expect(cell('2026-09-11')).toBe('8')
    expect(cell('2026-09-14')).toBe('')
    expect(cell('2026-09-25')).toBe('')
    expect(cell('2026-09-29')).toBe('8')
    // Září 2026 má 22 pracovních dnů, deset z nich pokrývá schválená DPN.
    expect(wrapper.get('[data-test="grid-save"]').text()).toContain('payroll.time.grid.save')
    expect(wrapper.get('[data-test="grid-note"]').text()).toContain('payroll.time.grid.skipped_absence')
    expect(wrapper.text()).toContain('96:00')

    await wrapper.get('[data-test="grid-cell-12-2026-09-29"]').setValue('')
    await flushPromises()
    expect(wrapper.text()).toContain('88:00')
    m.routeQuery = {}
    wrapper.unmount()
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
   * Svátek v jinak pracovní den je neodpracovaná hodina, která se do fondu
   * počítá (10268 + 10275 = 10260). Hromadné schválení dřív porovnávalo jen
   * odpracovanou dobu s fondem a u měsíce se svátkem varovalo, že práce fond
   * nepokrývá.
   */
  it('hromadné schválení nevaruje pod fondem, když chybějící hodiny pokryje svátek', async () => {
    const page = gridPage(['Osoba A', 'Osoba B'])
    const [holidayMonth, shortMonth] = page.items as any[]
    holidayMonth.summary = { ...holidayMonth.summary, fund_minutes: 10_080 }
    holidayMonth.jmhz_work_summary.preview.holiday_millihours = 8_000
    shortMonth.summary = { ...shortMonth.summary, fund_minutes: 10_080 }
    m.timeMonth.mockResolvedValue(page)
    const wrapper = mount(TimeAttendance, GRID_MOUNT)
    await flushPromises()

    await wrapper.find('thead input[type="checkbox"]').trigger('change')
    await wrapper.get('[data-test="bulk-approve-open"]').trigger('click')
    await flushPromises()

    const warning = wrapper.get('[data-test="bulk-approve-under-fund"]')
    expect(warning.text()).not.toContain('Osoba A')
    expect(warning.text()).toContain('Osoba B')
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
   * Člen orgánu a společník mají podle pokynů MPSV stanovený fond 10259
   * nulový. Společné pole dávky je fond plného úvazku pro ostatní vztahy;
   * statutár si nese vlastní nulový návrh serveru, ne 168 h.
   */
  it('hromadné schválení nepošle statutárovi fond plného úvazku', async () => {
    const page = gridPage(['Osoba A', 'Jednatel B'])
    page.items[1].employment.relation_type = 'statutory_body'
    Object.assign(page.items[1].jmhz_work_summary.preview.suggestions, {
      standard_fund_hours: '0',
      agreed_fund_hours: '0',
    })
    m.timeMonth.mockResolvedValue(page)
    const wrapper = mount(TimeAttendance, GRID_MOUNT)
    await flushPromises()

    await wrapper.find('thead input[type="checkbox"]').trigger('change')
    await wrapper.get('[data-test="bulk-approve-open"]').trigger('click')
    await wrapper.get('[data-test="bulk-standard-fund"]').setValue('168')
    await wrapper.get('[data-test="bulk-approve-form"]').trigger('submit')
    await flushPromises()

    expect(m.approveTimeMonth).toHaveBeenCalledTimes(2)
    expect(m.approveTimeMonth.mock.calls[0][1].jmhz_work_summary.standard_fund_hours).toBe('168')
    expect(m.approveTimeMonth.mock.calls[1][1]).toMatchObject({
      employment_id: 13,
      jmhz_work_summary: { standard_fund_hours: '0', agreed_fund_hours: '0' },
    })
    wrapper.unmount()
  })

  /*
   * C-11: návrh fondu je podle stanovené týdenní doby vztahu. Dávka dřív vzala
   * návrh prvního řádku (37,5 h → 165) a vnutila ho i vztahu na 40 h.
   */
  it('hromadné schválení s nezměněným fondem pošle každému vztahu jeho vlastní návrh', async () => {
    const page = gridPage(['Osoba A', 'Osoba B'])
    Object.assign(page.items[0].jmhz_work_summary.preview.suggestions, { standard_fund_hours: '165' })
    Object.assign(page.items[1].jmhz_work_summary.preview.suggestions, { standard_fund_hours: '176' })
    m.timeMonth.mockResolvedValue(page)
    const wrapper = mount(TimeAttendance, GRID_MOUNT)
    await flushPromises()

    await wrapper.find('thead input[type="checkbox"]').trigger('change')
    await wrapper.get('[data-test="bulk-approve-open"]').trigger('click')
    expect(wrapper.find('[data-test="bulk-standard-fund-per-employment"]').exists()).toBe(true)
    await wrapper.get('[data-test="bulk-approve-form"]').trigger('submit')
    await flushPromises()

    expect(m.approveTimeMonth.mock.calls.map(call => call[1].jmhz_work_summary.standard_fund_hours))
      .toEqual(['165', '176'])
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
   * Když neprojde nic, dialog zůstává otevřený i s tím, co do něj účetní
   * napsala. Zavřít ho znamenalo psát zákonný fond a poznámku celé znovu,
   * jen aby se narazilo na tutéž chybu.
   */
  it('po zcela neúspěšné dávce nechá dialog otevřený i s vyplněnými hodnotami', async () => {
    m.timeMonth.mockResolvedValue(gridPage(['Osoba A']))
    m.approveTimeMonth.mockRejectedValue({
      response: { data: { error: { code: 'average_earning_missing', message: '409' } } },
    })
    const wrapper = mount(TimeAttendance, GRID_MOUNT)
    await flushPromises()

    await wrapper.find('thead input[type="checkbox"]').trigger('change')
    await wrapper.get('[data-test="bulk-approve-open"]').trigger('click')
    await wrapper.get('[data-test="bulk-standard-fund"]').setValue('168')
    await wrapper.get('[data-test="bulk-note"]').setValue('Doplněno z docházky')
    await wrapper.get('[data-test="bulk-approve-form"]').trigger('submit')
    await flushPromises()

    expect(wrapper.find('[data-test="bulk-approve-form"]').exists()).toBe(true)
    expect((wrapper.get('[data-test="bulk-standard-fund"]').element as HTMLInputElement).value)
      .toBe('168')
    expect((wrapper.get('[data-test="bulk-note"]').element as HTMLTextAreaElement).value)
      .toBe('Doplněno z docházky')
    wrapper.unmount()
  })

  /**
   * Nález ze serveru tlačítko zamykal, ale věta pod ním o něm mlčela: účetní
   * vyplnila všechna pole a dívala se na šedé tlačítko bez vysvětlení.
   */
  it('u serverového nálezu řekne, proč potvrzení nejde, místo němého tlačítka', async () => {
    const page = gridPage(['Osoba A'])
    const issues: Array<{ code: string, message: string }> =
      page.items[0].jmhz_work_summary.preview.issues
    issues.push({
      code: 'worked_hours_exceed_fund',
      message: 'Odpracované hodiny přesahují fond.',
    })
    m.timeMonth.mockResolvedValue(page)
    const wrapper = mount(TimeAttendance, GRID_MOUNT)
    await flushPromises()

    const approve = wrapper.findAll('button')
      .find(button => button.text() === 'payroll.time.approve')
    await approve!.trigger('click')
    await wrapper.get('[data-test="jmhz-standard-fund"]').setValue('168')
    await wrapper.get('[data-test="jmhz-unworked-no"]').setValue(true)
    await wrapper.get('[data-test="jmhz-obstacles-no"]').setValue(true)

    expect(wrapper.get('[data-test="jmhz-confirm"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-test="jmhz-confirm-blocked"]').text())
      .toBe('payroll.time.jmhz.issues_blocked')

    // Odeslání klávesou dialog obcházelo: brána byla jen na tlačítku.
    await wrapper.get('[data-test="jmhz-work-summary-form"]').trigger('submit')
    await flushPromises()
    expect(m.approveTimeMonth).not.toHaveBeenCalled()
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
  /*
   * `required` na <input> v dialogu nic nevynutí — potvrzuje se kliknutím, ne
   * odesláním formuláře. Prázdný zákonný fond (server ho nenavrhuje NIKDY)
   * proto odešel na server a vrátil se jako „standard_fund_hours musí být
   * nezáporné desetinné číslo", což účetní nenavede na nic.
   */
  it('neodešle schválení s prázdným zákonným fondem a řekne, které pole chybí', async () => {
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
    await wrapper.get('[data-test="jmhz-unworked-no"]').setValue(true)
    await wrapper.get('[data-test="jmhz-obstacles-no"]').setValue(true)

    expect(wrapper.get('[data-test="jmhz-confirm"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-test="jmhz-confirm-blocked"]').text())
      .toBe('payroll.time.jmhz.required_missing')

    await wrapper.get('[data-test="jmhz-work-summary-form"]').trigger('submit')
    await flushPromises()
    expect(m.approveTimeMonth).not.toHaveBeenCalled()

    await wrapper.get('[data-test="jmhz-standard-fund"]').setValue('168')
    expect(wrapper.get('[data-test="jmhz-confirm"]').attributes('disabled')).toBeUndefined()

    wrapper.unmount()
  })

  /*
   * U vztahu, kde se docházka nesleduje (jednatel-společník), nemá účetní co
   * opisovat: odpracováno = sjednaný fond, žádné neodpracované hodiny ani
   * překážky. Zákonný fond tlačítko schválně nedoplňuje — to je stanovená
   * doba pro profesi, ne údaj ze smlouvy.
   */
  it('doplní odpracované hodiny ve výši sjednaného fondu jedním tlačítkem', async () => {
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
    await wrapper.get('[data-test="jmhz-as-agreed"]').trigger('click')

    expect((wrapper.get('[data-test="jmhz-worked"]').element as HTMLInputElement).value)
      .toBe('168')
    expect((wrapper.get('[data-test="jmhz-standard-fund"]').element as HTMLInputElement).value)
      .toBe('')
    expect(wrapper.get('[data-test="jmhz-confirm-blocked"]').text())
      .toBe('payroll.time.jmhz.required_missing')

    wrapper.unmount()
  })
  /*
   * Datum se v editoru zadává dvakrát a druhé zůstávalo na původním dni:
   * vznikl zápis od 5. 8. do 1. 8., tedy záporná délka, kterou odchytil až
   * server. Směna přes půlnoc je výjimka, jeden den je pravidlo.
   */
  it('posune konec zápisu spolu se změnou dne u začátku', async () => {
    m.timeMonth.mockResolvedValue({
      items: [{
        employment: { id: 12, full_name: 'Syntetická osoba', code: 'SYN-HPP' },
        month: { status: 'open', row_version: 3 },
        calendar: null,
        summary: {
          fund_minutes: 0, planned_minutes: 0, actual_minutes: 0,
          difference_minutes: 0, category_minutes: {}, incomplete: false,
        },
        jmhz_work_summary: { preview: null, current_revision: null },
        shifts: [],
        entries: [],
      }],
    })
    const wrapper = mount(TimeAttendance, {
      global: { stubs: { teleport: true } },
    })
    await flushPromises()

    const add = wrapper.findAll('button')
      .find(button => button.text() === 'payroll.time.add')
    await add!.trigger('click')

    const inputs = wrapper.findAll('input[type="datetime-local"]')
    const start = inputs[0]!
    const end = inputs[1]!
    const puvodniKonec = (end.element as HTMLInputElement).value

    await start.setValue(`2026-08-05T${(start.element as HTMLInputElement).value.slice(11)}`)

    expect((end.element as HTMLInputElement).value.slice(0, 10)).toBe('2026-08-05')
    // Čas konce zůstává, mění se jen den.
    expect((end.element as HTMLInputElement).value.slice(11))
      .toBe(puvodniKonec.slice(11))

    wrapper.unmount()
  })
})
