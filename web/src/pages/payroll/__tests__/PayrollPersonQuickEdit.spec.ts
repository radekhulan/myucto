import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type {
  PayrollEmployment,
  PayrollPerson,
  PayrollPersonProfile,
} from '@/api/payroll'
import { todayIso } from '@/pages/payroll/employmentLifecycleUi'

const mocks = vi.hoisted(() => ({
  person: vi.fn(),
  personProfile: vi.fn(),
  savePersonQuickEdit: vi.fn(),
  revealPersonSensitive: vi.fn(),
  countries: vi.fn(),
  success: vi.fn(),
  error: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    person: mocks.person,
    personProfile: mocks.personProfile,
    savePersonQuickEdit: mocks.savePersonQuickEdit,
    revealPersonSensitive: mocks.revealPersonSensitive,
  },
}))

vi.mock('@/api/codebooks', () => ({
  codebooksApi: {
    countries: mocks.countries,
  },
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({
    success: mocks.success,
    error: mocks.error,
  }),
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string) => key,
    locale: { value: 'cs' },
  }),
}))

import PayrollPersonQuickEdit from '@/pages/payroll/PayrollPersonQuickEdit.vue'

function employment(overrides: Partial<PayrollEmployment> = {}): PayrollEmployment {
  return {
    id: 31,
    employee_id: 17,
    office_id: null,
    office_code: null,
    office_name: null,
    code: 'ZAM-17',
    relation_type: 'employment',
    status: 'active',
    is_primary: true,
    start_date: '2026-01-01',
    actual_start_date: '2026-01-01',
    end_date: null,
    archived_at: null,
    is_legacy_projection: false,
    monthly_gross_minor: 4_200_000,
    row_version: 8,
    allowed_transitions: ['suspended', 'ended'],
    can_delete: false,
    delete_blocker: null,
    delete_cascade: {},
    accounting: {
      gross_debit: '521',
      gross_credit: '331',
      employer_insurance_debit: '524',
      employer_insurance_credit: '336',
    },
    terms: [{
      id: 41,
      office_id: null,
      office_code: null,
      effective_from: '2026-01-01',
      effective_to: null,
      contract_signed_on: '2025-12-15',
      planned_start_on: '2026-01-01',
      actual_start_on: '2026-01-01',
      fixed_term_end_on: null,
      weekly_hours: '40.00',
      workload_basis_points: 10000,
      work_place: 'Hlavní město Praha',
      regular_workplace: 'Praha',
      jmhz_workplace_municipality_code: '554782',
      jmhz_workplace_country_code: 'CZ',
      jmhz_apz_contribution_status: 'no',
      jmhz_apz_instrument_code: null,
      jmhz_functional_benefits_status: 'no',
      jmhz_temporary_assignment_status: 'unverified',
      cz_isco_code: '25120',
      activity_code: '1',
      jmhz_relationship_detail_code: '1',
      social_insurance_participation: 'automatic',
      health_insurance_participation: 'automatic',
      tax_regime: 'advance',
      foreign_legislation_country_code: null,
      a1_certificate_until: null,
      risky_work: false,
      social_employer_rate_category: 'ordinary',
      social_employer_rate_category_evidence: null,
      social_part_time_discount_reason: 'none' as const,
      social_part_time_discount_evidence: null,
      social_part_time_discount_notified_on: null,
      tax_declaration_signed: true,
      is_primary: true,
      change_reason: 'Počáteční podmínky',
      row_version: 2,
      created_at: '2026-01-01 08:00:00',
    }],
    checklist: [],
    timeline: [],
    ...overrides,
    meal_entitlement_basis: overrides.meal_entitlement_basis ?? 'shift',
  }
}

function person(primary = employment()): PayrollPerson {
  return {
    id: 17,
    full_name: 'Jana Testovací',
    is_active: true,
    profile_status: 'ready',
    legacy_taxpayer_type: 'employee',
    legacy_employment_type: 'hpp',
    employment_count: 1,
    relation_types: ['employment'],
    can_delete: false,
    delete_blocker: null,
    delete_cascade: {},
    setup_gaps: [],
    needs_setup: false,
    employments: [primary],
  }
}

function profile(): PayrollPersonProfile {
  return {
    employee_id: 17,
    full_name: 'Jana Testovací',
    profile_status: 'ready',
    payout_method: 'bank',
  partner_settlement_account_code: null,
    cash_allocation_basis_points: 0,
    payout_effective_on: '2026-01-01',
    secure_delivery_channel: 'portal',
    row_version: 5,
    identity_history: [{
      id: 51,
      full_name: 'Jana Testovací',
      first_name: 'Jana',
      last_name: 'Testovací',
      title_prefix: 'Ing.',
      title_suffix: 'Ph.D.',
      birth_surname_masked: 'N•••••••',
      birth_date: '1990-02-03',
      birth_place: 'Brno',
      birth_country_code: 'CZ',
      citizenship_country_code: 'SK',
      sex: 'female',
      effective_from: '2026-01-01',
      effective_to: null,
      row_version: 1,
    }],
    addresses: [{
      id: 52,
      address_type: 'residence',
      address_masked: 'T••••••• 1, P••••, 110 00, CZ',
      effective_from: '2026-01-01',
      effective_to: null,
      row_version: 1,
    }],
    contacts: [{
      id: 53,
      contact_type: 'email',
      value_masked: 'j•••@e••••••.invalid',
      is_primary: true,
      is_active: true,
      row_version: 1,
    }, {
      id: 54,
      contact_type: 'phone',
      value_masked: '+420 ••• ••• 789',
      is_primary: true,
      is_active: true,
      row_version: 1,
    }],
    identifiers: [{
      id: 55,
      identifier_type: 'birth_number',
      value_masked: '••••••/1234',
      row_version: 1,
    }],
    accounts: [],
    created_at: '2026-01-01 08:00:00',
    updated_at: '2026-08-01 08:00:00',
  }
}

/**
 * Karta se nově otevírá ve čtecím režimu — formulář se objeví až po „Upravit".
 * Testy, které zkoumají chování formuláře, tedy musí do editace nejdřív vstoupit;
 * read-only uživatel se do ní nedostane, tam se čte samotný čtecí pohled.
 */
async function mountedEditor(canWrite = true) {
  const wrapper = mount(PayrollPersonQuickEdit, {
    props: {
      personId: 17,
      canWrite,
    },
  })
  await flushPromises()
  if (canWrite) {
    await wrapper.get('[data-test="start-quick-edit"]').trigger('click')
    await flushPromises()
  }
  return wrapper
}

async function mountedReader(canReadSensitive = false) {
  const wrapper = mount(PayrollPersonQuickEdit, {
    props: { personId: 17, canWrite: true, canReadSensitive },
  })
  await flushPromises()
  return wrapper
}

describe('PayrollPersonQuickEdit', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mocks.person.mockResolvedValue(person())
    mocks.personProfile.mockResolvedValue(profile())
    mocks.countries.mockResolvedValue([{
      id: 1,
      iso2: 'CZ',
      iso3: 'CZE',
      name_cs: 'Česko',
      name_en: 'Czechia',
      is_eu: true,
    }])
    mocks.savePersonQuickEdit.mockResolvedValue({
      profile: profile(),
      employment: employment(),
    })
  })

  it('ukáže běžné údaje v jediném formuláři bez záložek a citlivé hodnoty jen maskovaně', async () => {
    const wrapper = await mountedEditor()

    expect(wrapper.find('[role="tablist"]').exists()).toBe(false)
    expect(wrapper.findAll('form')).toHaveLength(1)
    expect(wrapper.get<HTMLInputElement>('[data-test="first-name"]').element.value).toBe('Jana')
    expect(wrapper.get<HTMLInputElement>('[data-test="last-name"]').element.value).toBe('Testovací')
    expect(wrapper.get<HTMLInputElement>('[data-test="birth-number"]').attributes('placeholder'))
      .toBe('••••••/1234')
    expect(wrapper.get<HTMLInputElement>('[data-test="email"]').attributes('placeholder'))
      .toBe('j•••@e••••••.invalid')
    expect(wrapper.get<HTMLInputElement>('[data-test="phone"]').attributes('placeholder'))
      .toBe('+420 ••• ••• 789')
    expect(wrapper.text()).toContain('T••••••• 1, P••••, 110 00, CZ')
    expect(wrapper.get<HTMLInputElement>('[data-test="birth-number"]').element.value).toBe('')
    expect(wrapper.get<HTMLInputElement>('[data-test="email"]').element.value).toBe('')
    expect(wrapper.get<HTMLInputElement>('[data-test="phone"]').element.value).toBe('')
    expect(wrapper.get<HTMLInputElement>('[data-test="weekly-hours"]').element.value).toBe('40.00')
    expect(wrapper.get<HTMLInputElement>('[data-test="monthly-gross"]').element.value).toBe('42000')
  })

  it('uloží profil a primární vztah jedním atomickým požadavkem s oběma verzemi', async () => {
    const wrapper = await mountedEditor()

    await wrapper.get('[data-test="first-name"]').setValue('Jana Marie')
    await wrapper.get('[data-test="last-name"]').setValue('Bezpečná')
    await wrapper.get('[data-test="birth-number"]').setValue('530101123')
    await wrapper.get('[data-test="street-line"]').setValue('Testovací 12')
    await wrapper.get('[data-test="city"]').setValue('Praha')
    await wrapper.get('[data-test="postal-code"]').setValue('110 00')
    const country = wrapper.get('[data-test="country-code"] input')
    await country.trigger('focus')
    await country.setValue('Česko')
    await country.trigger('keydown', { key: 'Enter' })
    await wrapper.get('[data-test="email"]').setValue('jana@example.invalid')
    await wrapper.get('[data-test="phone"]').setValue('+420 777 888 999')
    await wrapper.get('[data-test="weekly-hours"]').setValue('37.5')
    await wrapper.get('[data-test="monthly-gross"]').setValue('45000')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(mocks.savePersonQuickEdit).toHaveBeenCalledWith(
      17,
      expect.objectContaining({
        profile: expect.objectContaining({
          row_version: 5,
          identity_history: expect.arrayContaining([
            expect.objectContaining({
              id: 51,
              full_name: 'Jana Testovací',
              first_name: 'Jana',
              last_name: 'Testovací',
              effective_to: expect.stringMatching(/^\d{4}-\d{2}-\d{2}$/),
            }),
            expect.objectContaining({
              full_name: 'Jana Marie Bezpečná',
              first_name: 'Jana Marie',
              last_name: 'Bezpečná',
              effective_from: expect.stringMatching(/^\d{4}-\d{2}-\d{2}$/),
              effective_to: null,
            }),
          ]),
          addresses: expect.arrayContaining([
            expect.objectContaining({
              id: 52,
              effective_to: expect.stringMatching(/^\d{4}-\d{2}-\d{2}$/),
            }),
            expect.objectContaining({
              address_type: 'residence',
              street_line: 'Testovací 12',
              city: 'Praha',
              postal_code: '110 00',
              country_code: 'CZ',
              effective_from: expect.stringMatching(/^\d{4}-\d{2}-\d{2}$/),
              effective_to: null,
            }),
          ]),
          contacts: expect.arrayContaining([
            expect.objectContaining({
              id: 53,
              is_primary: false,
              is_active: false,
            }),
            expect.objectContaining({
              contact_type: 'email',
              value: 'jana@example.invalid',
              is_primary: true,
              is_active: true,
            }),
            expect.objectContaining({
              id: 54,
              is_primary: false,
              is_active: false,
            }),
            expect.objectContaining({
              contact_type: 'phone',
              value: '+420 777 888 999',
              is_primary: true,
              is_active: true,
            }),
          ]),
          identifiers: [expect.objectContaining({
            id: 55,
            value: '530101123',
          })],
        }),
        employment: expect.objectContaining({
          id: 31,
          row_version: 8,
          monthly_gross_minor: 4_500_000,
          terms: expect.objectContaining({
            weekly_hours: '37.5',
            planned_start_on: '2026-01-01',
            actual_start_on: '2026-01-01',
            work_place: 'Hlavní město Praha',
            jmhz_workplace_municipality_code: '554782',
            jmhz_workplace_country_code: 'CZ',
            jmhz_apz_contribution_status: 'no',
            jmhz_apz_instrument_code: null,
            jmhz_functional_benefits_status: 'no',
            jmhz_temporary_assignment_status: 'unverified',
            activity_code: '1',
            jmhz_relationship_detail_code: '1',
          }),
        }),
      }),
    )
    expect(JSON.stringify(mocks.savePersonQuickEdit.mock.calls[0][1]))
      .not.toContain('••')
    expect(mocks.success).toHaveBeenCalledWith('payroll.people.quick_edit.saved')

    // Po uložení se karta vrací ke čtení a citlivá pole nesmí držet zadanou hodnotu.
    expect(wrapper.find('[data-test="quick-edit-read"]').exists()).toBe(true)
    await wrapper.get('[data-test="start-quick-edit"]').trigger('click')
    expect(wrapper.get<HTMLInputElement>('[data-test="birth-number"]').element.value).toBe('')
    expect(wrapper.get<HTMLInputElement>('[data-test="email"]').element.value).toBe('')
    expect(wrapper.get<HTMLInputElement>('[data-test="phone"]').element.value).toBe('')
  })

  it('při změně jen osobního údaje nevytvoří zbytečnou verzi pracovních podmínek', async () => {
    const wrapper = await mountedEditor()
    await wrapper.get('[data-test="first-name"]').setValue('Jana Marie')

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(mocks.savePersonQuickEdit).toHaveBeenCalledWith(
      17,
      expect.objectContaining({
        profile: expect.objectContaining({ row_version: 5 }),
        employment: null,
      }),
    )
  })

  it('umožní zaměstnance bez rodného čísla a nevytvoří prázdný identifikátor', async () => {
    mocks.personProfile.mockResolvedValueOnce({
      ...profile(),
      identifiers: [],
    })
    const wrapper = await mountedEditor()
    await wrapper.get('[data-test="first-name"]').setValue('Jana Marie')

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(mocks.savePersonQuickEdit).toHaveBeenCalledWith(
      17,
      expect.objectContaining({
        profile: expect.objectContaining({
          identifiers: [],
        }),
      }),
    )
  })

  it('změnu jména historizuje uzavřením starého a založením nového řádku', async () => {
    const wrapper = await mountedEditor()
    await wrapper.get('[data-test="first-name"]').setValue('Jana Marie')
    await wrapper.get('[data-test="last-name"]').setValue('Nová')

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    const payload = mocks.savePersonQuickEdit.mock.calls[0][1]
    expect(payload.profile.identity_history).toHaveLength(2)
    expect(payload.profile.identity_history[0]).toEqual(expect.objectContaining({
      id: 51,
      full_name: 'Jana Testovací',
      first_name: 'Jana',
      last_name: 'Testovací',
      citizenship_country_code: 'SK',
      effective_from: '2026-01-01',
      effective_to: expect.stringMatching(/^\d{4}-\d{2}-\d{2}$/),
    }))
    expect(payload.profile.identity_history[1]).toEqual(expect.objectContaining({
      full_name: 'Jana Marie Nová',
      first_name: 'Jana Marie',
      last_name: 'Nová',
      birth_surname_source_id: 51,
      title_prefix: 'Ing.',
      title_suffix: 'Ph.D.',
      birth_date: '1990-02-03',
      birth_place: 'Brno',
      birth_country_code: 'CZ',
      citizenship_country_code: 'SK',
      sex: 'female',
      effective_from: expect.stringMatching(/^\d{4}-\d{2}-\d{2}$/),
      effective_to: null,
    }))
    expect(payload.profile.identity_history[1]).not.toHaveProperty('id')
  })

  it('novou adresou nemění uzavřenou adresní historii', async () => {
    mocks.personProfile.mockResolvedValueOnce({
      ...profile(),
      addresses: [{
        ...profile().addresses[0],
        effective_from: '2025-01-01',
        effective_to: '2025-12-31',
      }],
    })
    const wrapper = await mountedEditor()
    await wrapper.get('[data-test="street-line"]').setValue('Nová 12')
    await wrapper.get('[data-test="city"]').setValue('Praha')
    await wrapper.get('[data-test="postal-code"]').setValue('110 00')
    const country = wrapper.get('[data-test="country-code"] input')
    await country.trigger('focus')
    await country.setValue('Česko')
    await country.trigger('keydown', { key: 'Enter' })

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    const addresses = mocks.savePersonQuickEdit.mock.calls[0][1].profile.addresses
    expect(addresses).toEqual([
      expect.objectContaining({
        id: 52,
        effective_from: '2025-01-01',
        effective_to: '2025-12-31',
      }),
      expect.objectContaining({
        address_type: 'residence',
        street_line: 'Nová 12',
        effective_to: null,
      }),
    ])
  })

  /**
   * Stát u české adresy nikdo needituje, a přesto vracel formulář „Vyplňte
   * ulici, obec, PSČ i stát." — čtvrtou povinnou položkou kvůli hodnotě, která
   * je vždycky stejná. Dosadí se, místo aby blokovala uložení.
   */
  it('adresu bez vyplněného státu doplní na CZ, místo aby zablokovala uložení', async () => {
    const wrapper = await mountedEditor()
    await wrapper.get('[data-test="street-line"]').setValue('Nová 12')
    await wrapper.get('[data-test="city"]').setValue('Praha')
    await wrapper.get('[data-test="postal-code"]').setValue('110 00')

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.find('[data-test="quick-edit-error"]').exists()).toBe(false)
    const addresses = mocks.savePersonQuickEdit.mock.calls[0][1].profile.addresses
    expect(addresses.at(-1)).toEqual(expect.objectContaining({
      street_line: 'Nová 12',
      city: 'Praha',
      postal_code: '110 00',
      country_code: 'CZ',
    }))
  })

  /** Nepovinná pole nesmí nést hvězdičku — jinak nese značka stejně málo informace jako žádná. */
  it('hvězdičkou označí jen jméno a příjmení, ne rodné číslo ani kontakty', async () => {
    const wrapper = await mountedEditor()

    expect(wrapper.findAll('[data-test="required-mark"]')).toHaveLength(2)
    expect(wrapper.get('[data-test="birth-number"]').attributes('required')).toBeUndefined()
    expect(wrapper.get('[data-test="street-line"]').attributes('required')).toBeUndefined()
    expect(wrapper.get('[data-test="email"]').attributes('required')).toBeUndefined()
    expect(wrapper.get('[data-test="weekly-hours"]').attributes('required')).toBeUndefined()
    expect(wrapper.get('[data-test="quick-edit-required-hint"]').text())
      .toContain('payroll.people.quick_edit.required_hint')
  })

  it('jméno založené dnes opraví na místě bez další historické verze', async () => {
    mocks.personProfile.mockResolvedValueOnce({
      ...profile(),
      identity_history: [{
        ...profile().identity_history[0],
        effective_from: todayIso(),
      }],
    })
    const wrapper = await mountedEditor()
    await wrapper.get('[data-test="first-name"]').setValue('Jana Marie')
    await wrapper.get('[data-test="last-name"]').setValue('Opravená')

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    const payload = mocks.savePersonQuickEdit.mock.calls[0][1]
    expect(payload.profile.identity_history).toEqual([
      expect.objectContaining({
        id: 51,
        full_name: 'Jana Marie Opravená',
        first_name: 'Jana Marie',
        last_name: 'Opravená',
        effective_from: todayIso(),
        effective_to: null,
      }),
    ])
  })

  it('zpřístupní EČP, VČP a zahraniční identifikátor bez otevírání pokročilé evidence', async () => {
    mocks.personProfile.mockResolvedValueOnce({
      ...profile(),
      identifiers: [
        ...profile().identifiers,
        {
          id: 56,
          identifier_type: 'ecp',
          value_masked: '••••ECP1',
          row_version: 1,
        },
      ],
    })
    const wrapper = await mountedEditor()

    expect(wrapper.get('[data-test="identifier-ecp"]').attributes('placeholder'))
      .toBe('••••ECP1')
    expect(wrapper.find('[data-test="identifier-vcp"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="identifier-foreign-tax"]').exists()).toBe(true)

    await wrapper.get('[data-test="identifier-ecp"]').setValue('ECP-NOVE-1')
    await wrapper.get('[data-test="identifier-vcp"]').setValue('VCP-NOVE-2')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(mocks.savePersonQuickEdit).toHaveBeenCalledWith(
      17,
      expect.objectContaining({
        profile: expect.objectContaining({
          identifiers: expect.arrayContaining([
            expect.objectContaining({
              id: 56,
              identifier_type: 'ecp',
              value: 'ECP-NOVE-1',
            }),
            expect.objectContaining({
              identifier_type: 'vcp',
              value: 'VCP-NOVE-2',
            }),
          ]),
        }),
      }),
    )
  })

  it('nikdy neodvozuje jméno a příjmení z full_name', async () => {
    mocks.personProfile.mockResolvedValueOnce({
      ...profile(),
      full_name: 'Jan Křtitel z Testova',
      identity_history: [{
        ...profile().identity_history[0],
        full_name: 'Jan Křtitel z Testova',
        first_name: null,
        last_name: null,
      }],
    })
    const wrapper = await mountedEditor()

    expect(wrapper.get<HTMLInputElement>('[data-test="first-name"]').element.value)
      .toBe('')
    expect(wrapper.get<HTMLInputElement>('[data-test="last-name"]').element.value)
      .toBe('')

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(mocks.savePersonQuickEdit).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="quick-edit-error"]').text())
      .toContain('payroll.people.quick_edit.name_required')
  })

  it('nepřepisuje nerozlišené starší jméno automatickým odhadem', async () => {
    mocks.personProfile.mockResolvedValueOnce({
      ...profile(),
      identity_history: [
        {
          ...profile().identity_history[0],
          id: 50,
          full_name: 'Historické Víceslovné Jméno',
          first_name: null,
          last_name: null,
          effective_from: '2025-01-01',
          effective_to: '2025-12-31',
        },
        profile().identity_history[0],
      ],
    })
    const wrapper = await mountedEditor()

    await wrapper.get('[data-test="first-name"]').setValue('Jana Marie')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(mocks.savePersonQuickEdit).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="quick-edit-error"]').text())
      .toContain('payroll.people.quick_edit.structured_history_required')
  })

  it('ponechá při chybě celý formulář beze změny a ukáže přesnou atomickou chybu inline', async () => {
    mocks.savePersonQuickEdit.mockRejectedValueOnce({
      response: {
        status: 409,
        data: {
          error: {
            code: 'row_version_conflict',
            message: 'Profil nebo pracovní vztah mezitím změnil jiný uživatel.',
          },
        },
      },
    })
    const wrapper = await mountedEditor()
    await wrapper.get('[data-test="first-name"]').setValue('Neuložená')
    await wrapper.get('[data-test="monthly-gross"]').setValue('47000')

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.get('[data-test="quick-edit-error"]').text())
      .toContain('Profil nebo pracovní vztah mezitím změnil jiný uživatel.')
    expect(wrapper.get<HTMLInputElement>('[data-test="first-name"]').element.value)
      .toBe('Neuložená')
    expect(wrapper.get<HTMLInputElement>('[data-test="monthly-gross"]').element.value)
      .toBe('47000')
    expect(mocks.error).not.toHaveBeenCalled()
  })

  it('v režimu pouze pro čtení nenechá běžné údaje měnit ani ukládat', async () => {
    const wrapper = await mountedEditor(false)

    expect(wrapper.find('[data-test="quick-edit-read"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="start-quick-edit"]').exists()).toBe(false)
    expect(wrapper.find('form').exists()).toBe(false)
    expect(wrapper.find('[data-test="save-quick-edit"]').exists()).toBe(false)
  })

  it('kartu otevře ve čtecím režimu, ne jako formulář', async () => {
    const wrapper = await mountedReader()

    expect(wrapper.find('[data-test="quick-edit-read"]').exists()).toBe(true)
    expect(wrapper.find('form').exists()).toBe(false)
    // Datum účinnosti se objeví, teprve když se úvazek nebo mzda opravdu mění.
    await wrapper.get('[data-test="start-quick-edit"]').trigger('click')
    expect(wrapper.find('[data-test="employment-effective-from-field"]').exists()).toBe(false)
    await wrapper.get('[data-test="monthly-gross"]').setValue('51000')
    expect(wrapper.find('[data-test="employment-effective-from-field"]').exists()).toBe(true)
  })

  /**
   * Endpoint na odkrytí existoval od začátku, ale frontend ho nikdy nezavolal —
   * uživatel viděl „••••4523" a odkrýt to nešlo.
   */
  it('bez oprávnění odkrývat nenabídne tlačítko, s ním ukáže skutečné hodnoty', async () => {
    expect((await mountedReader(false)).find('[data-test="reveal-sensitive"]').exists()).toBe(false)

    mocks.revealPersonSensitive.mockResolvedValue({
      employee_id: 17,
      identifiers: [{ id: 1, identifier_type: 'birth_number', value: '760815/4523' }],
      contacts: [{ id: 53, contact_type: 'email', value: 'jana@example.cz' }],
      accounts: [],
      dependants: [],
      addresses: [],
    })

    const wrapper = await mountedReader(true)
    expect(wrapper.get('[data-test="read-birth-number"]').text()).toContain('••')

    await wrapper.get('[data-test="reveal-sensitive"]').trigger('click')
    await flushPromises()
    expect(wrapper.get('[data-test="read-birth-number"]').text()).toBe('760815/4523')
    expect(wrapper.get('[data-test="read-email"]').text()).toBe('jana@example.cz')

    // Druhé kliknutí zase zamaskuje a nevolá server znovu.
    await wrapper.get('[data-test="reveal-sensitive"]').trigger('click')
    expect(wrapper.get('[data-test="read-birth-number"]').text()).toContain('••')
    expect(mocks.revealPersonSensitive).toHaveBeenCalledTimes(1)
  })
})
