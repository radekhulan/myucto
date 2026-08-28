import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'
import type {
  PayrollQuickInputRef,
  PayrollQuickInputRow,
  PayrollQuickSurchargeKind,
  PayrollQuickSurchargeState,
} from '@/api/payroll'

const m = vi.hoisted(() => ({
  routeQuery: {} as Record<string, string | string[]>,
  routerReplace: vi.fn(),
  load: vi.fn(),
  getPref: vi.fn(),
  putPref: vi.fn(),
  save: vi.fn(),
  canWrite: vi.fn(),
  success: vi.fn(),
  error: vi.fn(),
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
    quickInputs: m.load,
    saveQuickInputs: m.save,
  },
}))
vi.mock('@/api/preferences', () => ({
  preferencesApi: {
    getPreferenceKey: m.getPref,
    putPreferenceKey: m.putPref,
  },
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: m.canWrite }),
}))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.success, error: m.error }),
}))
// `useFormat` (sdílené formátování) táhne @/i18n, které volá skutečné
// `createI18n` — továrna proto musí původní modul rozprostřít, ne nahradit.
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ t: (key: string) => key, locale: ref('cs-CZ') }),
}))

// Preference tabulek jdou přes Pinii a API; v testu stačí prázdné výchozí.
vi.mock('@/composables/useUserPrefs', async () => {
  const { computed } = await import('vue')
  return {
    ensurePrefsLoaded: () => Promise.resolve(),
    getPagePrefs: () => computed(() => ({})),
    patchPagePrefs: () => {},
  }
})

import PayrollQuickInputs from '@/pages/payroll/PayrollQuickInputs.vue'

function inputRef(
  status: PayrollQuickInputRef['status'],
  overrides: Partial<PayrollQuickInputRef> = {},
): PayrollQuickInputRef {
  return {
    id: 101,
    amount_minor: 4_200_000,
    quantity_milliunits: null,
    source_kind: 'manual',
    status,
    row_version: 3,
    source_snapshot: null,
    ...overrides,
  }
}

/**
 * Výchozí stav zákonných příplatků: druh je dostupný, ale nic zadaného nemá.
 * Server ho posílá u každého řádku, takže fixtura ho posílat musí taky —
 * jinak by testy běžely nad tvarem, který v odpovědi nikdy nenastane.
 */
function surchargeStates(
  overrides: Partial<Record<PayrollQuickSurchargeKind, Partial<PayrollQuickSurchargeState>>> = {},
): Record<PayrollQuickSurchargeKind, PayrollQuickSurchargeState> {
  const sections: Record<PayrollQuickSurchargeKind, string> = {
    night: '§ 116',
    weekend: '§ 118',
    holiday: '§ 115',
    difficult_environment: '§ 117',
  }
  const kinds: PayrollQuickSurchargeKind[] = [
    'night', 'weekend', 'holiday', 'difficult_environment',
  ]
  return Object.fromEntries(kinds.map(kind => [kind, {
    kind,
    label: kind,
    section: sections[kind],
    component_code: `PRIPLATEK_${kind.toUpperCase()}`,
    basis: kind === 'difficult_environment' ? 'minimum_wage_hourly' : 'average_earning',
    basis_hourly_minor: 20_000,
    average_hourly_minor: 20_000,
    average_snapshot_id: 41,
    average_snapshot_version: 1,
    rate_basis_points: kind === 'holiday' ? 10_000 : 1_000,
    rate_is_agreed: false,
    requires_factors: kind === 'difficult_environment',
    default_factors: null,
    hours_milli: null,
    factors: null,
    amount_minor: 0,
    managed_amount_minor: 0,
    row_version: null,
    status: null,
    managed_elsewhere: false,
    from_attendance: false,
    conflict: false,
    available: true,
    entry_available: true,
    clear_only: false,
    unavailable_reason: null,
    ...overrides[kind],
  }])) as Record<PayrollQuickSurchargeKind, PayrollQuickSurchargeState>
}

function fixture(overrides: Partial<PayrollQuickInputRow> = {}): PayrollQuickInputRow {
  return {
    employee_id: 8,
    employment_id: 12,
    employment_row_version: 7,
    full_name: 'Syntetická osoba',
    birth_number_masked: '******/**42',
    employment_code: 'SYN-HPP',
    relation_type: 'employment',
    effective_status: 'active',
    suspended_in_month: false,
    base_amount_minor: 4_200_000,
    base_managed_elsewhere: false,
    base_conflict: false,
    partial_month: false,
    base_requires_entry: false,
    overtime_mode: 'amount',
    overtime_hours_milli: null,
    overtime_amount_minor: 25_000,
    overtime_hourly_rate_minor: null,
    overtime_average_snapshot_id: null,
    overtime_average_snapshot_version: null,
    overtime_hours_available: false,
    overtime_hours_relation_supported: true,
    overtime_managed_elsewhere: false,
    overtime_conflict: false,
    bonus_amount_minor: 50_000,
    bonus_managed_elsewhere: false,
    bonus_conflict: false,
    other_amount_minor: 0,
    non_monetary_amount_minor: 0,
    excluded_from_gross_amount_minor: 0,
    gross_preview_minor: 4_275_000,
    inputs: { base: null, overtime: null, bonus: null },
    surcharges: surchargeStates(),
    surcharge_amount_minor: 0,
    blockers: [],
    ...overrides,
  }
}

function mountPage() {
  return mount(PayrollQuickInputs, {
    global: {
      stubs: {
        RouterLink: {
          props: ['to'],
          template: '<a :data-to="JSON.stringify(to)"><slot /></a>',
        },
      },
    },
  })
}

describe('PayrollQuickInputs', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.routeQuery = {}
    m.canWrite.mockReturnValue(true)
    m.load.mockImplementation(async period => ({
      period,
      items: [fixture()],
    }))
    m.save.mockImplementation(async payload => ({
      month: { period: payload.period, items: [], total: 0 },
      failures: [],
    }))
  })

  it('keeps two employments of one person separate and labels statutory income correctly', async () => {
    m.load.mockImplementation(async period => ({
      period,
      items: [
        fixture(),
        fixture({
          employment_id: 13,
          employment_row_version: 4,
          employment_code: 'SYN-JED',
          relation_type: 'statutory_body',
          base_amount_minor: 800_000,
          overtime_amount_minor: 0,
          bonus_amount_minor: 0,
          gross_preview_minor: 800_000,
          overtime_hours_relation_supported: false,
        }),
      ],
    }))

    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.get('[data-layout="desktop"]').findAll('tbody tr')).toHaveLength(2)
    expect(wrapper.get('[data-testid="quick-relation-12"]').text())
      .toBe('payroll.people.relations.employment')
    expect(wrapper.get('[data-testid="quick-relation-13"]').text())
      .toBe('payroll.people.relations.statutory_body')
    expect(wrapper.get('[data-testid="quick-income-label-13"]').text())
      .toBe('payroll.quick_inputs.income_labels.statutory_body')
    expect(wrapper.find('[data-testid="overtime-mode-hours-13"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('payroll.quick_inputs.amount_only_relation_hint')
  })

  it('keeps partner dependent income amount-only as well', async () => {
    m.load.mockImplementation(async period => ({
      period,
      items: [fixture({
        relation_type: 'partner_dependent',
        overtime_hours_relation_supported: false,
      })],
    }))

    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.get('[data-testid="quick-income-label-12"]').text())
      .toBe('payroll.quick_inputs.income_labels.partner_dependent')
    expect(wrapper.find('[data-testid="overtime-mode-hours-12"]').exists()).toBe(false)
  })

  it('labels a suspension that occurred during an otherwise active month', async () => {
    m.load.mockImplementation(async period => ({
      period,
      items: [fixture({
        effective_status: 'active',
        suspended_in_month: true,
        base_requires_entry: true,
        base_amount_minor: 0,
        blockers: ['suspended_month_base_required'],
      })],
    }))

    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.get('[data-testid="quick-status-12"]').text())
      .toBe('payroll.quick_inputs.suspended_in_month')
    expect(wrapper.text()).toContain('payroll.quick_inputs.blockers.suspended_month_base_required')
  })

  it('renders the mobile form, masked identifier and lg sticky action bar', async () => {
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.get('[data-layout="mobile"]').text()).toContain('Syntetická osoba')
    expect(wrapper.find('[data-testid="quick-base-mobile-12"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="quick-relation-mobile-12"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('******/**42')
    expect(wrapper.text()).not.toContain('123456/7842')
    const actionBar = wrapper.get('[data-testid="quick-payroll-save"]').element.parentElement
    expect(actionBar?.classList.contains('lg:sticky')).toBe(true)
    expect(actionBar?.classList.contains('md:sticky')).toBe(false)
    expect(wrapper.get('[data-testid="quick-payroll-save"]').classes()).toContain('w-full')
    expect(wrapper.get('[data-testid="quick-payroll-runs"]').text())
      .toContain('payroll.quick_inputs.continue_to_runs')
  })

  it('keeps hour mode unavailable without an average and sends employment concurrency version', async () => {
    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.get('[data-testid="overtime-mode-hours-12"]').attributes('disabled')).toBeDefined()

    await wrapper.get('[data-testid="quick-payroll-save"]').trigger('click')
    await flushPromises()

    expect(m.save).toHaveBeenCalledTimes(1)
    expect(m.save.mock.calls[0][0].rows[0]).toMatchObject({
      employment_id: 12,
      employment_row_version: 7,
      base_amount_minor: 4_200_000,
      overtime_mode: 'amount',
      overtime_amount_minor: 25_000,
      bonus_amount_minor: 50_000,
    })
  })

  it('explains and protects locked, approved, draft and externally managed fields', async () => {
    m.load.mockImplementation(async period => ({
      period,
      items: [
        fixture({
          inputs: {
            base: inputRef('locked'),
            overtime: inputRef('approved', { id: 102, amount_minor: 25_000 }),
            bonus: inputRef('draft', { id: 103, amount_minor: 50_000 }),
          },
        }),
        fixture({
          employment_id: 13,
          employment_code: 'SYN-DPC',
          relation_type: 'dpc',
          bonus_managed_elsewhere: true,
        }),
      ],
    }))

    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.get('[data-testid="quick-base-12"]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-testid="quick-base-state-12"]').text())
      .toBe('payroll.quick_inputs.field_state.locked')
    expect(wrapper.get('[data-testid="quick-overtime-state-12"]').text())
      .toBe('payroll.quick_inputs.field_state.approved')
    expect(wrapper.get('[data-testid="quick-bonus-state-12"]').text())
      .toBe('payroll.quick_inputs.field_state.draft')
    expect(wrapper.get('[data-testid="quick-bonus-state-13"]').text())
      .toBe('payroll.quick_inputs.field_state.managed')
  })

  it('shows effective suspended status and requires an explicit base', async () => {
    m.load.mockImplementation(async period => ({
      period,
      items: [fixture({
        effective_status: 'suspended',
        suspended_in_month: true,
        base_requires_entry: true,
        base_amount_minor: 0,
        blockers: ['suspended_month_base_required'],
      })],
    }))

    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.get('[data-testid="quick-status-12"]').text())
      .toBe('payroll.quick_inputs.suspended_in_month')
    expect(wrapper.text()).toContain('payroll.quick_inputs.blockers.suspended_month_base_required')
    expect(wrapper.get('[data-testid="quick-base-12"]').element).toHaveProperty('value', '')
    // Prázdný základ ukládání neblokuje: znamená „základ neřeším". Východiskem
    // z blokátoru je zadat nulu — viz test níž, který ji pošle jako 0.
    expect(wrapper.get('[data-testid="quick-payroll-save"]').attributes('disabled')).toBeUndefined()
  })

  it('tells an entered zero apart from an unfilled base', async () => {
    const partialMonthRow = () => fixture({
      base_requires_entry: true,
      base_amount_minor: 0,
      blockers: ['partial_month_base_required'],
    })
    m.load.mockImplementation(async period => ({
      period,
      items: [partialMonthRow()],
    }))
    // Uložení vrací řádek zpátky, aby druhá polovina testu měla do čeho psát.
    m.save.mockImplementation(async payload => ({
      month: { period: payload.period, items: [partialMonthRow()], total: 1 },
      failures: [],
    }))

    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.get('[data-testid="quick-base-12"]').element).toHaveProperty('value', '')

    // Nevyplněné pole → null, na serveru „základ neřeším".
    await wrapper.get('[data-testid="quick-payroll-save"]').trigger('click')
    await flushPromises()
    expect(m.save.mock.calls[0][0].rows[0].base_amount_minor).toBeNull()

    // Zadaná nula → 0, na serveru plnohodnotný nulový základ.
    await wrapper.get('[data-testid="quick-base-12"]').setValue('0')
    await wrapper.get('[data-testid="quick-payroll-save"]').trigger('click')
    await flushPromises()
    expect(m.save.mock.calls[1][0].rows[0].base_amount_minor).toBe(0)
  })

  it('uses read-only mode while keeping the payroll-run navigation available', async () => {
    m.canWrite.mockReturnValue(false)
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.get('[data-testid="quick-base-12"]').attributes('disabled')).toBeDefined()
    expect(wrapper.find('[data-testid="quick-payroll-save"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="quick-payroll-runs"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('payroll.quick_inputs.readonly_hint')
  })

  // Prázdno je legitimní jen u základní mzdy. U odměny nese nulu jedině zadaná
  // nula, takže prázdné pole tam zůstává chybou a ukládání dál blokuje.
  it('blocks saving when an editable amount is empty or invalid', async () => {
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.get('[data-testid="quick-bonus-12"]').setValue('')

    expect(wrapper.get('[data-testid="quick-payroll-save"]').attributes('disabled')).toBeDefined()
    await wrapper.get('[data-testid="quick-payroll-save"]').trigger('click')
    expect(m.save).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('payroll.quick_inputs.validation.amount_required')
    expect(wrapper.find('[data-testid="quick-payroll-validation-summary"]').exists()).toBe(true)
    expect(wrapper.get('[data-testid="quick-bonus-12"]').attributes('aria-invalid')).toBe('true')
  })

  it('rejects a negative amount locally and keeps the gross preview fail-safe', async () => {
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.get('[data-testid="quick-base-12"]').setValue('-1')

    expect(wrapper.text()).toContain('payroll.quick_inputs.validation.amount_non_negative')
    expect(wrapper.get('[data-testid="quick-payroll-save"]').attributes('disabled')).toBeDefined()
    expect(m.save).not.toHaveBeenCalled()
  })

  it('keeps the exact API failure visible and offers reload after employment conflict', async () => {
    m.save.mockRejectedValueOnce({
      response: {
        data: {
          error: {
            code: 'employment_row_version_conflict',
            message: 'Syntetický vztah mezitím změnil jiný uživatel.',
          },
        },
      },
    })
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.get('[data-testid="quick-payroll-save"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid="quick-payroll-save-error"]').text())
      .toContain('Syntetický vztah mezitím změnil jiný uživatel.')
    expect(wrapper.find('[data-testid="quick-payroll-conflict-refresh"]').exists()).toBe(true)
    expect(m.error).toHaveBeenCalledWith('Syntetický vztah mezitím změnil jiný uživatel.')

    await wrapper.get('[data-testid="quick-payroll-conflict-refresh"]').trigger('click')
    await flushPromises()
    expect(m.load).toHaveBeenCalledTimes(2)
  })

  it('does not apply a stale response after the payroll period changes', async () => {
    let resolveFirst: ((value: unknown) => void) | undefined
    m.load.mockReset()
    m.load
      .mockImplementationOnce(() => new Promise(resolve => { resolveFirst = resolve }))
      .mockResolvedValueOnce({ period: '2026-07', items: [] })

    const wrapper = mountPage()
    await flushPromises()
    const periodInput = wrapper.get('[data-testid="quick-payroll-period"]')
    expect(periodInput.attributes('disabled')).toBeDefined()
    const vm = wrapper.vm as unknown as { period: string; load: () => Promise<void> }
    vm.period = '2026-07'
    void vm.load()
    resolveFirst?.({ period: '2026-06', items: [fixture({ full_name: 'Starý měsíc' })] })
    await flushPromises()

    expect(wrapper.text()).not.toContain('Starý měsíc')
    expect(m.load).toHaveBeenLastCalledWith('2026-07', { limit: 25, offset: 0 }, undefined)
  })

  /**
   * Zúžení z karty zaměstnance zužuje SERVER, ne prohlížeč nad načtenou stránkou.
   * Vztah z druhé strany by se jinak tiše neprojevil: seznam by zůstal celý,
   * nebo by vyšel prázdný, a obojí vypadá jako legitimní výsledek.
   */
  it('sends the narrowing to the server instead of filtering the loaded page', async () => {
    m.routeQuery = { employment: '9999' }
    mountPage()
    await flushPromises()

    // Období závisí na dnešku, na kontraktu záleží zbytek: stránka zůstává
    // normální a vztah jde na server jako parametr.
    expect(m.load).toHaveBeenLastCalledWith(expect.any(String), { limit: 25, offset: 0 }, 9999)
  })

  /**
   * Prázdné zúžení musí být pojmenované. Tichá prázdná tabulka vypadá stejně
   * jako měsíc bez lidí a uživatel nemá jak poznat, že se dívá na zúžený
   * seznam — ani jak se ze zúžení dostat ven.
   */
  it('names an empty narrowing instead of showing a silent empty table', async () => {
    m.routeQuery = { employment: '9999' }
    m.load.mockImplementation(async period => ({ period, items: [], total: 0 }))
    const wrapper = mountPage()
    await flushPromises()

    const notice = wrapper.find('[data-test="payroll-focus-notice"]')
    expect(notice.exists()).toBe(true)
    expect(notice.text()).toContain('payroll.agendas.focus.missing')
    expect(wrapper.find('[data-test="payroll-focus-clear"]').exists()).toBe(true)
  })

  it('invalidates old rows when loading a new payroll period fails', async () => {
    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.text()).toContain('Syntetická osoba')

    m.load.mockRejectedValueOnce(new Error('synthetic load failure'))
    const vm = wrapper.vm as unknown as { period: string; load: () => Promise<void> }
    vm.period = '2026-07'
    await vm.load()
    await flushPromises()

    expect(wrapper.text()).not.toContain('Syntetická osoba')
    expect(wrapper.find('[data-testid="quick-payroll-save"]').exists()).toBe(false)
    expect(m.save).not.toHaveBeenCalled()
  })
  /*
   * X-04: jeden vadný řádek nesmí uzamknout uložení celé stránky. Konflikt
   * s jiným vstupem je věc jednoho zaměstnance, ne dalších čtyřiadvaceti.
   */
  it('saves the healthy rows and pins the backend reason to the broken field', async () => {
    m.load.mockImplementation(async period => ({
      period,
      total: 2,
      items: [
        fixture({ base_conflict: true }),
        fixture({ employment_id: 13, employment_code: 'SYN-DRUHY', full_name: 'Druhá osoba' }),
      ],
    }))
    m.save.mockImplementation(async payload => ({
      month: {
        period: payload.period,
        total: 2,
        items: [
          fixture({ base_conflict: true }),
          fixture({ employment_id: 13, employment_code: 'SYN-DRUHY', full_name: 'Druhá osoba' }),
        ],
      },
      failures: [{
        employment_id: 12,
        field: 'base',
        code: 'input_state_conflict',
        message: 'Základní mzda je v měsíci evidována rychlým i jiným vstupem.',
        current_row_version: null,
      }],
    }))

    const wrapper = mountPage()
    await flushPromises()

    const button = wrapper.get('[data-testid="quick-payroll-save"]')
    expect(button.attributes('disabled')).toBeUndefined()
    await button.trigger('click')
    await flushPromises()

    // Odeslaly se OBA řádky — o tom, co uložit nejde, rozhoduje server.
    expect(m.save.mock.calls[0][0].rows).toHaveLength(2)
    expect(wrapper.get('[data-testid="quick-base-server-error-12"]').text())
      .toContain('Základní mzda je v měsíci evidována')
    // Chyba se nesmí smrsknout na obecné „nepodařilo se".
    expect(wrapper.get('[data-testid="quick-payroll-save-error"]').text())
      .toContain('payroll.quick_inputs.saved_partially')
  })

  /*
   * X-05: rozepsaný řádek přežije přelistování. Dřív se ukládala jen zobrazená
   * stránka, takže 40 lidí znamenalo dvě uložení a dvě šance na konflikt verzí.
   */
  it('carries edits from other pages into a single save', async () => {
    m.load.mockImplementation(async (period, page) => ({
      period,
      total: 2,
      items: page.offset === 0
        ? [fixture()]
        : [fixture({ employment_id: 13, employment_code: 'SYN-DRUHA', full_name: 'Druhá osoba' })],
    }))

    const wrapper = mountPage()
    await flushPromises()
    await wrapper.get('[data-testid="quick-bonus-12"]').setValue('900')

    const vm = wrapper.vm as unknown as { goToPage: (page: number) => void }
    vm.goToPage(2)
    await flushPromises()
    expect(wrapper.find('[data-testid="quick-bonus-12"]').exists()).toBe(false)
    await wrapper.get('[data-testid="quick-bonus-13"]').setValue('700')

    await wrapper.get('[data-testid="quick-payroll-save"]').trigger('click')
    await flushPromises()

    const sent = m.save.mock.calls[0][0].rows
    expect(sent.map((row: { employment_id: number }) => row.employment_id).sort())
      .toEqual([12, 13])
    expect(sent.find((row: { employment_id: number }) => row.employment_id === 12)
      .bonus_amount_minor).toBe(90_000)
  })

  /*
   * X-01: kdo smí schvalovat, ukládá rovnou schválené vstupy — a musí je jít
   * ještě opravit, dokud je nepohltil mzdový běh. Bez toho práva zůstává
   * schválený vstup zamčený jako dřív.
   */
  it('keeps an approved field editable only for someone who may approve', async () => {
    m.load.mockImplementation(async period => ({
      period,
      total: 1,
      items: [fixture({
        inputs: {
          base: inputRef('approved'),
          overtime: null,
          bonus: inputRef('locked', { id: 103 }),
        },
      })],
    }))

    const withApproval = mountPage()
    await flushPromises()
    expect(withApproval.get('[data-testid="quick-base-12"]').attributes('disabled'))
      .toBeUndefined()
    // Uzamčený vstup patří mzdovému běhu a nesmí jít přepsat ani schvalovateli.
    expect(withApproval.get('[data-testid="quick-bonus-12"]').attributes('disabled'))
      .toBeDefined()

    m.canWrite.mockImplementation((permission: string) => permission !== 'payroll.approve')
    const withoutApproval = mountPage()
    await flushPromises()
    expect(withoutApproval.get('[data-testid="quick-base-12"]').attributes('disabled'))
      .toBeDefined()
  })

  /** Přepnutí měsíce rozepsané řádky zahazuje — patřily jinému období. */
  it('drops pending edits when the payroll period changes', async () => {
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.get('[data-testid="quick-bonus-12"]').setValue('900')

    await wrapper.get('[data-testid="quick-payroll-period"]').setValue('2026-07')
    await wrapper.get('[data-testid="quick-payroll-period"]').trigger('change')
    await flushPromises()

    await wrapper.get('[data-testid="quick-payroll-save"]').trigger('click')
    await flushPromises()
    expect(m.save.mock.calls[0][0].rows.map((row: { bonus_amount_minor: number }) =>
      row.bonus_amount_minor)).toEqual([50_000])
  })
/*
   * Příplatkové sloupce odkrývá JEDEN přepínač nad tabulkou.
   *
   * Kdyby se rozbalovaly u řádku, bylo by to při 500 zaměstnancích 500
   * kliknutí — přesně ten vzorec, který rychlý měsíční vstup odstraňuje.
   * Tenhle test hlídá, že se sloupce objeví u VŠECH řádků naráz.
   */
  it('reveals surcharge columns for every row from a single toggle', async () => {
    m.load.mockImplementation(async period => ({
      period,
      total: 2,
      items: [fixture(), fixture({ employment_id: 13, employee_id: 9 })],
    }))
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('[data-testid="quick-surcharge-night-12"]').exists()).toBe(false)

    await wrapper.get('[data-testid="quick-surcharges-toggle"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="quick-surcharge-night-12"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="quick-surcharge-night-13"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="quick-surcharge-weekend-13"]').exists()).toBe(true)
    // Stav přepínače si směnný provoz nesmí nastavovat každý měsíc znovu.
    expect(m.putPref).toHaveBeenCalledWith(
      'payroll.quick_inputs.surcharges',
      { visible: true },
    )
  })

  /** Uložená preference přepínač zapne ještě před prvním kliknutím. */
  it('restores the remembered toggle state', async () => {
    m.getPref.mockResolvedValue({ visible: true })
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('[data-testid="quick-surcharge-night-12"]').exists()).toBe(true)
  })

  /*
   * Řádek s příplatkem sekci otevře sám. Skrytá data by se jinak uživateli
   * ztratila z očí jen proto, že přepínač zůstal z minula vypnutý — a to je
   * u zákonného nároku horší než sloupec navíc.
   */
  it('opens the section on its own when a row already carries a surcharge', async () => {
    m.load.mockImplementation(async period => ({
      period,
      total: 1,
      items: [fixture({
        surcharges: surchargeStates({
          night: { hours_milli: 10_000, amount_minor: 20_000, row_version: 2, status: 'draft' },
        }),
        surcharge_amount_minor: 20_000,
      })],
    }))
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('[data-testid="quick-surcharge-night-12"]').exists()).toBe(true)
    expect((wrapper.get('[data-testid="quick-surcharge-night-12"]')
      .element as HTMLInputElement).value).toBe('10')
  })

  /** Zadané hodiny odcházejí na server po druzích, i s verzí řádku. */
  it('sends entered hours per surcharge kind', async () => {
    m.getPref.mockResolvedValue({ visible: true })
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.get('[data-testid="quick-surcharge-night-12"]').setValue('12,5')
    await wrapper.get('[data-testid="quick-payroll-save"]').trigger('click')
    await flushPromises()

    const row = m.save.mock.calls[0][0].rows[0]
    expect(row.surcharges.night).toEqual({ hours_milli: 12_500, factors: null })
    // Druh, který uživatel nevyplnil, se posílá jako `null` — smí ho měnit,
    // takže mlčet o něm by znamenalo nemoci ho vyprázdnit.
    expect(row.surcharges.weekend).toEqual({ hours_milli: null, factors: null })
    expect(row.versions.surcharges.night).toBeNull()
  })

  /*
   * Druh, který uživatel měnit NESMÍ (drží ho docházka), se do požadavku
   * nesmí dostat vůbec. Kdyby se posílal jako prázdný, uložení by zrušilo
   * zákonný nárok, o kterém uživatel ani nevěděl.
   */
  it('never sends kinds the user is not allowed to change', async () => {
    m.getPref.mockResolvedValue({ visible: true })
    m.load.mockImplementation(async period => ({
      period,
      total: 1,
      items: [fixture({
        surcharges: surchargeStates({
          night: {
            from_attendance: true,
            entry_available: false,
            unavailable_reason: 'claimed_by_attendance',
            amount_minor: 20_000,
            managed_amount_minor: 20_000,
            managed_elsewhere: true,
          },
        }),
      })],
    }))
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.get('[data-testid="quick-surcharge-night-12"]').attributes('disabled'))
      .toBeDefined()
    expect(wrapper.get('[data-testid="quick-surcharge-blocked-night-12"]').text())
      .toContain('claimed_by_attendance')

    await wrapper.get('[data-testid="quick-payroll-save"]').trigger('click')
    await flushPromises()
    expect(m.save.mock.calls[0][0].rows[0].surcharges.night).toBeUndefined()
  })

  /*
   * § 117 náleží ZA KAŽDÝ ztěžující vliv. Bez jejich počtu se nedá počítat —
   * odhadnout jedničku by byl tichý nedoplatek, tak se řádek radši neuloží.
   */
  it('requires the aggravating factor count for Section 117', async () => {
    m.getPref.mockResolvedValue({ visible: true })
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.get('[data-testid="quick-surcharge-difficult_environment-12"]').setValue('8')
    await flushPromises()

    expect(wrapper.get('[data-testid="quick-payroll-save"]').attributes('disabled'))
      .toBeDefined()

    await wrapper.get('[data-testid="quick-surcharge-factors-difficult_environment-12"]')
      .setValue('3')
    await flushPromises()

    await wrapper.get('[data-testid="quick-payroll-save"]').trigger('click')
    await flushPromises()
    expect(m.save.mock.calls[0][0].rows[0].surcharges.difficult_environment)
      .toEqual({ hours_milli: 8_000, factors: 3 })
  })

  /*
   * Dopočtená částka u pole. Účetní musí vidět, co z hodin vyjde, ještě než
   * uloží — stejně jako u náhledu hrubé mzdy.
   */
  it('previews the amount next to the entered hours', async () => {
    m.getPref.mockResolvedValue({ visible: true })
    const wrapper = mountPage()
    await flushPromises()

    // 200,00 Kč/h × 10 % × 10 h = 200,00 Kč.
    await wrapper.get('[data-testid="quick-surcharge-night-12"]').setValue('10')
    await flushPromises()

    expect(wrapper.get('[data-testid="quick-surcharge-preview-night-12"]').text())
      .toContain('200')
  })

  /** Chyba serveru se ukáže u KONKRÉTNÍHO příplatkového pole, ne jen v toastu. */
  it('shows a server failure at the surcharge field it belongs to', async () => {
    m.getPref.mockResolvedValue({ visible: true })
    m.save.mockImplementation(async payload => ({
      month: { period: payload.period, total: 1, items: [fixture()] },
      failures: [{
        employment_id: 12,
        field: 'surcharge_holiday',
        code: 'input_state_conflict',
        message: 'Zasada svatku neni sjednana.',
        current_row_version: null,
      }],
    }))
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.get('[data-testid="quick-surcharge-holiday-12"]').setValue('8')
    await wrapper.get('[data-testid="quick-payroll-save"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-testid="quick-surcharge-server-error-holiday-12"]').text())
      .toContain('Zasada svatku neni sjednana.')
  })

})
