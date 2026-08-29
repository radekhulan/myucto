import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import type { PayrollPeopleFilter, PayrollPersonListItem } from '@/api/payroll'

const m = vi.hoisted(() => ({
  peoplePage: vi.fn(),
  person: vi.fn(),
  createPerson: vi.fn(),
  createEmployment: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
  toastWarning: vi.fn(),
  routeQuery: {} as Record<string, string>,
  routerReplace: vi.fn(),
  // Otevření karty je od opravy historie `push` (zavření zůstalo `replace`),
  // takže mock musí umět obojí — jinak spadne watch na `expandedId`.
  routerPush: vi.fn(),
  deletePerson: vi.fn(),
  capabilities: vi.fn(),
  employerSettings: vi.fn(),
  saveStatutoryEvidence: vi.fn(),
  employmentAgendaSummary: vi.fn(),
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ query: m.routeQuery }),
  useRouter: () => ({ replace: m.routerReplace, push: m.routerPush }),
  RouterLink: {
    name: 'RouterLink',
    props: ['to'],
    template: '<a><slot /></a>',
  },
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    peoplePage: m.peoplePage,
    person: m.person,
    createPerson: m.createPerson,
    createEmployment: m.createEmployment,
    deletePerson: m.deletePerson,
    // Bez toho neexistovala jako funkce a `onMounted` házel TypeError JEŠTĚ před
    // `.catch()` — každý test skončil nezachycenou chybou v protokolu běhu.
    capabilities: m.capabilities,
    // Nabídka mzdových účtáren a výchozí pojišťovny pro nového zaměstnance.
    employerSettings: m.employerSettings,
    saveStatutoryEvidence: m.saveStatutoryEvidence,
    employmentAgendaSummary: m.employmentAgendaSummary,
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    canRead: () => true,
    canWrite: () => true,
  }),
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({
    success: m.toastSuccess,
    error: m.toastError,
    warning: m.toastWarning,
  }),
}))

// `useFormat` (sdílené formátování) táhne @/i18n, které volá skutečné
// `createI18n` — továrna proto musí původní modul rozprostřít, ne nahradit.
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) =>
      params ? `${key}:${JSON.stringify(params)}` : key,
  }),
}))

// `useTablePrefs` jde přes Pinii a API; v testu stačí prázdné výchozí předvolby.
vi.mock('@/composables/useUserPrefs', async () => {
  const { computed } = await import('vue')
  return {
    ensurePrefsLoaded: () => Promise.resolve(),
    getPagePrefs: () => computed(() => ({})),
    patchPagePrefs: () => {},
  }
})

import PeopleList from '@/pages/payroll/PeopleList.vue'
import { resetPayrollOffices } from '@/composables/usePayrollOffices'
import { resetDefaultHealthInsurerCode } from '@/composables/usePayrollDefaultInsurer'

/** Velikost stránky, se kterou obrazovka chodí na server. */
const PAGE_SIZE = 25

function person(
  id: number,
  fullName: string,
  isActive: boolean,
  needsSetup: boolean,
): PayrollPersonListItem {
  return {
    id,
    full_name: fullName,
    is_active: isActive,
    profile_status: needsSetup ? 'setup' : 'ready',
    legacy_taxpayer_type: 'employee',
    legacy_employment_type: 'hpp',
    employment_count: 0,
    relation_types: [],
    employment_refs: [],
    setup_gaps: needsSetup ? ['residence'] : [],
    needs_setup: needsSetup,
    can_delete: true,
    delete_blocker: null,
    delete_cascade: { employments: 0, profile: 1 },
  }
}

interface PageParams {
  limit: number
  offset: number
  filter?: PayrollPeopleFilter
  q?: string
}

/**
 * Náhradní server: zužuje a stránkuje SÁM, přesně jak to dělá `GET /payroll/people`.
 * Kdyby obrazovka zužovala u sebe, testy s ním neprojdou — a přesně to je ta
 * chyba, kterou hlídají (hledání jen v načtené stránce).
 */
let roster: PayrollPersonListItem[] = []

function serveRoster(params: PageParams) {
  const filter = params.filter ?? 'all'
  const needle = (params.q ?? '').toLowerCase()
  const matched = roster.filter((item) => {
    const passesFilter = filter === 'all'
      || (filter === 'active' && item.is_active)
      || (filter === 'needs_setup' && item.needs_setup)
    return passesFilter && (needle === '' || item.full_name.toLowerCase().includes(needle))
  })

  return Promise.resolve({
    items: matched.slice(params.offset, params.offset + params.limit),
    total: matched.length,
    limit: params.limit,
    offset: params.offset,
  })
}

/** Hledání je odložené — test musí počkat, než odklad doběhne. */
async function settleSearch() {
  await new Promise(resolve => setTimeout(resolve, 350))
  await flushPromises()
}

function mountPage() {
  return mount(PeopleList, {
    global: {
      stubs: {
        ActionBar: {
          props: ['actions'],
          template: '<div data-test="person-actions"><button v-for="action in actions" v-show="action.show" :key="action.key" type="button" :data-test="`action-${action.key}`" @click="action.run && action.run()">{{ action.label }}</button></div>',
        },
        RouterLink: {
          name: 'RouterLink',
          props: ['to'],
          template: '<a data-test="router-link"><slot /></a>',
        },
        EmploymentCard: true,
        PayrollPersonQuickEdit: {
          props: ['personId', 'canWrite'],
          template: '<div data-test="quick-edit-stub">{{ personId }}</div>',
        },
        PayrollPersonProfilePanel: true,
        PayrollPersonStatutoryEvidencePanel: true,
      },
    },
  })
}

describe('PeopleList toolbar and shared employee creation', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    // Nabídka účtáren i výchozí pojišťovna se drží v paměti modulu na celý běh.
    resetPayrollOffices()
    resetDefaultHealthInsurerCode()
    for (const key of Object.keys(m.routeQuery)) delete m.routeQuery[key]
    roster = [
      person(1, 'Alfa Aktivní', true, false),
      person(2, 'Beta Neaktivní', false, false),
      person(3, 'Gama K doplnění', true, true),
    ]
    m.peoplePage.mockImplementation(serveRoster)
    m.person.mockResolvedValue({
      ...person(4, 'Delta Nová', true, true),
      employments: [],
    })
    m.createPerson.mockResolvedValue({
      id: 4,
      full_name: 'Delta Nová',
      employments: [{
        id: 44,
        employee_id: 4,
        relation_type: 'employment',
      }],
    })
    m.createEmployment.mockResolvedValue({
      id: 44,
      employee_id: 4,
      relation_type: 'employment',
    })
    m.capabilities.mockResolvedValue({ state: { start_period: '2026-01-01' } })
    m.employerSettings.mockResolvedValue({ offices: [], default_health_insurer_code: null })
    m.saveStatutoryEvidence.mockResolvedValue({})
    m.employmentAgendaSummary.mockResolvedValue({
      employment_id: 44,
      employee_id: 4,
      agendas: [],
    })
  })

  /*
   * Selhání načtení a prázdná agenda jsou dva různé stavy, které tahle
   * obrazovka dřív kreslila stejně — „Zatím tu nikdo není" po výpadku sítě.
   */
  it('offers a retry instead of an empty state when the people fail to load', async () => {
    m.peoplePage.mockRejectedValue(new Error('network'))

    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('[data-test="load-failed"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('payroll.people.load_failed_hint')
    expect(wrapper.text()).not.toContain('payroll.people.empty_title')

    roster = []
    m.peoplePage.mockImplementation(serveRoster)
    await wrapper.get('[data-test="load-failed"] [data-test="empty-state-cta"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="load-failed"]').exists()).toBe(false)
  })

  it('shows the empty state when the company genuinely has nobody', async () => {
    roster = []

    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('[data-test="load-failed"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('payroll.people.empty_title')
    expect(wrapper.text()).not.toContain('payroll.people.load_failed_hint')
  })

  /*
   * Zúžení nesmí zůstat v prohlížeči: kdyby hledal jen v načtené stránce,
   * o člověku ze třetí stránky by obrazovka tvrdila, že neexistuje.
   */
  it('sends the search term and the filter to the server instead of narrowing the page', async () => {
    const wrapper = mountPage()
    await flushPromises()

    expect(m.peoplePage).toHaveBeenLastCalledWith({
      limit: PAGE_SIZE,
      offset: 0,
      filter: 'active',
      q: '',
    })
    expect(wrapper.text()).toContain('Alfa Aktivní')
    expect(wrapper.text()).toContain('Gama K doplnění')
    expect(wrapper.text()).not.toContain('Beta Neaktivní')

    const callsBeforeTyping = m.peoplePage.mock.calls.length
    await wrapper.get('[data-test="people-search"]').setValue('Gama')
    // Na každé písmeno požadavek nejde — odklad ho musí spojit do jednoho.
    expect(m.peoplePage.mock.calls.length).toBe(callsBeforeTyping)

    await settleSearch()
    expect(m.peoplePage).toHaveBeenLastCalledWith({
      limit: PAGE_SIZE,
      offset: 0,
      filter: 'active',
      q: 'Gama',
    })
    expect(wrapper.text()).toContain('Gama K doplnění')
    expect(wrapper.text()).not.toContain('Alfa Aktivní')

    await wrapper.get('[data-test="people-search"]').setValue('')
    await settleSearch()

    const filter = wrapper.get('[data-test="people-filter"]')
    await filter.get('input').trigger('focus')
    const allOption = filter.findAll('[role="option"]')
      .find(option => option.text() === 'payroll.people.filters.all')
    expect(allOption).toBeDefined()
    await allOption!.trigger('click')
    await flushPromises()
    expect(m.peoplePage).toHaveBeenLastCalledWith(expect.objectContaining({ filter: 'all' }))
    expect(wrapper.text()).toContain('Beta Neaktivní')

    await filter.get('input').trigger('focus')
    const needsSetupOption = filter.findAll('[role="option"]')
      .find(option => option.text() === 'payroll.people.filters.needs_setup')
    expect(needsSetupOption).toBeDefined()
    await needsSetupOption!.trigger('click')
    await flushPromises()
    expect(m.peoplePage).toHaveBeenLastCalledWith(expect.objectContaining({ filter: 'needs_setup' }))
    expect(wrapper.text()).toContain('Gama K doplnění')
    expect(wrapper.text()).not.toContain('Alfa Aktivní')
    expect(wrapper.text()).not.toContain('Beta Neaktivní')
    expect(wrapper.get('[data-test="quick-inputs-link"]').classes())
      .toContain('border')
  })

  /*
   * Seznam osob je stránkovaný, takže musí mít čím listovat — a další stránku
   * si musí vyžádat na serveru, ne ukrojit z toho, co má načtené.
   */
  it('renders the shared pagination bar and asks the server for the next page', async () => {
    m.peoplePage.mockImplementation((params: PageParams) => Promise.resolve({
      items: [person(1, 'Alfa Aktivní', true, false)],
      total: 60,
      limit: params.limit,
      offset: params.offset,
    }))

    const wrapper = mountPage()
    await flushPromises()

    const pager = wrapper.get('[data-testid="payroll-people-pagination"]')
    expect(pager.text()).toContain('1 / 3')

    await pager.findAll('button')[1]!.trigger('click')
    await flushPromises()

    expect(m.peoplePage).toHaveBeenLastCalledWith(expect.objectContaining({
      limit: PAGE_SIZE,
      offset: PAGE_SIZE,
    }))
  })

  it('names the next setup step and gives each employee a matching CTA', async () => {
    const wrapper = mountPage()
    await flushPromises()

    const incompleteRows = wrapper.findAll('[data-test="person-next-step-3"]')
    expect(incompleteRows.length).toBeGreaterThan(0)
    expect(incompleteRows[0]!.text())
      .toContain('payroll.people.next_step.residence')

    const incompleteActions = wrapper.findAll('[data-test="edit-employee-3"]')
    expect(incompleteActions.length).toBeGreaterThan(0)
    expect(incompleteActions[0]!.text())
      .toContain('payroll.people.next_step.action.residence')

    const readyActions = wrapper.findAll('[data-test="edit-employee-1"]')
    expect(readyActions.length).toBeGreaterThan(0)
    expect(readyActions[0]!.text())
      .toContain('payroll.people.next_step.action.ready')
  })

  it('opens the reading summary first and keeps advanced editors collapsed', async () => {
    m.person.mockResolvedValue({
      ...person(1, 'Alfa Aktivní', true, false),
      employments: [],
    })
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.get('[data-test="edit-employee-1"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="selected-person-editor"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="quick-edit-stub"]').text()).toBe('1')
    expect(wrapper.get('[data-test="advanced-person-profile"]').attributes('open')).toBeUndefined()
  })

  /**
   * Účtárnu má smysl nabízet jen firmě, která jich má víc. S jedinou účtárnou by
   * to bylo pole s jednou možností, kterou stejně dosadí server z výchozí
   * účtárny zaměstnavatele.
   */
  it.each([
    [1, false],
    [2, true],
  ])('nabídne u nového vztahu výběr účtárny až od druhé účtárny (%i)', async (count, visible) => {
    m.person.mockResolvedValue({
      ...person(1, 'Alfa Aktivní', true, false),
      employments: [],
    })
    m.employerSettings.mockResolvedValue({
      offices: Array.from({ length: count }, (_, index) => ({
        id: index + 1,
        code: `O${index + 1}`,
        name: `Účtárna ${index + 1}`,
        social_security_variable_symbol: null,
        is_active: true,
        row_version: 1,
      })),
    })
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.get('[data-test="edit-employee-1"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="action-add-employment"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="new-employment-office"]').exists()).toBe(visible)
  })

  /**
   * Zakládání osoby vyžaduje jen jméno, druh vztahu a datum nástupu — a formulář
   * to teď říká rovnou, místo aby to uživatel zkoušel podle chybových hlášek.
   */
  it('u zakládání osoby značí hvězdičkou jen povinná pole', async () => {
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.get('[data-test="add-employee"]').trigger('click')
    await nextTick()

    const form = wrapper.get('[data-test="new-employee-form"]')
    expect(form.findAll('[data-test="required-mark"]')).toHaveLength(3)
    expect(wrapper.get('[data-test="new-employee-required-hint"]').text())
      .toContain('payroll.people.create.required_hint')
    expect(form.get('[data-test="new-employee-birth-number"]').attributes('required'))
      .toBeUndefined()
  })

  /**
   * „Přidat zaměstnance" je i uvnitř prázdného stavu dole, takže kdo klikl tam,
   * zůstal odscrollovaný u seznamu a formulář nahoře vůbec neviděl. Formulář
   * proto seznam schová — stejně jako editace osoby.
   */
  it('při zakládání zaměstnance schová seznam i toolbar', async () => {
    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.find('[data-test="people-list"]').exists()).toBe(true)

    await wrapper.get('[data-test="add-employee"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="new-employee-form"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="people-list"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="people-search"]').exists()).toBe(false)

    await wrapper.get('[data-test="new-employee-form"]').get('button').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="people-list"]').exists()).toBe(true)
  })

  /**
   * Detaily (rodné číslo, nástup, úvazek, účtárna, pojišťovna) patří do TÉHOŽ
   * formuláře — dřív se úvazek dosazoval natvrdo na 40 hodin a pojišťovnu bylo
   * nutné doplnit až v zákonné evidenci na kartě.
   */
  it('založí zaměstnance i s úvazkem, účtárnou a zdravotní pojišťovnou najednou', async () => {
    m.employerSettings.mockResolvedValue({
      default_health_insurer_code: '111',
      offices: [
        { id: 1, code: 'O1', name: 'Praha', social_security_variable_symbol: null, is_active: true, row_version: 1 },
        { id: 2, code: 'O2', name: 'Brno', social_security_variable_symbol: null, is_active: true, row_version: 1 },
      ],
    })
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.get('[data-test="add-employee"]').trigger('click')
    await flushPromises()

    // Výchozí pojišťovna zaměstnavatele se předvyplní.
    expect((wrapper.get('[data-test="new-employee-insurer"]').element as HTMLSelectElement).value)
      .toBe('111')
    await wrapper.get('[data-test="new-employee-name"]').setValue('Delta Nová')
    await wrapper.get('[data-test="new-employee-planned-start"]').setValue('2026-09-01')
    await wrapper.get('[data-test="new-employee-weekly-hours"]').setValue('20.00')
    wrapper.findAllComponents({ name: 'SearchableSelect' })
      .find(component => component.attributes('data-test') === 'new-employee-office')!
      .vm.$emit('update:modelValue', 2)
    await wrapper.get('[data-test="new-employee-form"]').trigger('submit')
    await flushPromises()

    // Pojišťovna je zákonná evidence osoby, ne sloupec karty — jde ale TÝMŽ
    // požadavkem, aby ji nemohl minout zaměstnanec založený druhým voláním.
    expect(m.createPerson).toHaveBeenCalledWith(expect.objectContaining({
      weekly_hours: '20.00',
      office_id: 2,
      planned_start_on: '2026-09-01',
      health_insurer_code: '111',
    }))
    expect(m.saveStatutoryEvidence).not.toHaveBeenCalled()
  })

  it('names the edited person in the header even without a structured name', async () => {
    // Osoba „test" má vyplněné jen zobrazované jméno — strukturované pole je
    // prázdné a formulář by bez hlavičky vypadal anonymně.
    m.person.mockResolvedValue({
      ...person(1, 'test', true, true),
      employment_count: 2,
      employments: [],
    })
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.get('[data-test="edit-employee-1"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="person-header-name"]').text()).toBe('test')
    expect(wrapper.get('[data-test="person-breadcrumbs"]').text()).toContain('test')
    expect(wrapper.getComponent({ name: 'RouterLink' }).props('to'))
      .toEqual({ name: 'payroll-dashboard' })
    expect(wrapper.get('[data-test="person-header-employments"]').text())
      .toContain('payroll.people.header_employments')
    expect(wrapper.text()).toContain('payroll.people.needs_setup')
  })

  it('hides the list while editing so no other person stays in view', async () => {
    m.person.mockResolvedValue({
      ...person(1, 'Alfa Aktivní', true, false),
      employments: [],
    })
    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.text()).toContain('Gama K doplnění')

    await wrapper.get('[data-test="edit-employee-1"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="people-list"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('Gama K doplnění')
    expect(wrapper.get('[data-test="person-header-name"]').text()).toBe('Alfa Aktivní')
  })

  it('returns to the list from the breadcrumb and keeps the search and filter', async () => {
    m.person.mockResolvedValue({
      ...person(3, 'Gama K doplnění', true, true),
      employments: [],
    })
    const wrapper = mountPage()
    await flushPromises()

    const filter = wrapper.get('[data-test="people-filter"]')
    await filter.get('input').trigger('focus')
    const allOption = filter.findAll('[role="option"]')
      .find(option => option.text() === 'payroll.people.filters.all')
    await allOption!.trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="people-search"]').setValue('Gama')
    await settleSearch()

    await wrapper.get('[data-test="edit-employee-3"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="people-list"]').exists()).toBe(false)

    await wrapper.get('[data-test="breadcrumb-people"]').trigger('click')
    await nextTick()

    expect(wrapper.find('[data-test="people-list"]').exists()).toBe(true)
    // Návrat, který resetuje filtr, je horší než žádný.
    expect((wrapper.get('[data-test="people-search"]').element as HTMLInputElement).value)
      .toBe('Gama')
    expect(wrapper.text()).toContain('Gama K doplnění')
    expect(wrapper.text()).not.toContain('Alfa Aktivní')
  })

  it('names what disappears before deleting the person and drops them from the list', async () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    m.deletePerson.mockResolvedValue({})
    m.person.mockResolvedValue({
      ...person(1, 'Alfa Aktivní', true, false),
      employments: [],
    })
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.get('[data-test="edit-employee-1"]').trigger('click')
    await flushPromises()

    await wrapper.get('[data-test="action-delete-person"]').trigger('click')
    await flushPromises()

    // Dialog musí předem říct, že kaskáda odklidí i vztahy osoby.
    expect(confirm).toHaveBeenCalledWith(
      expect.stringContaining('payroll.people.delete.person_confirm'),
    )
    expect(confirm.mock.calls[0]![0]).toContain('person_cascade.profile')
    expect(m.deletePerson).toHaveBeenCalledWith(1)
    expect(wrapper.find('[data-test="people-list"]').exists()).toBe(true)
    expect(wrapper.text()).not.toContain('Alfa Aktivní')
    confirm.mockRestore()
  })

  it('explains why a person cannot be deleted instead of hiding the reason', async () => {
    roster = [{
      ...person(1, 'Alfa Aktivní', true, false),
      can_delete: false,
      delete_blocker: {
        code: 'payroll_employee_in_run',
        message: 'Zaměstnanec je zahrnutý v revizi mzdového běhu.',
        employment_id: null,
        employment_code: null,
      },
    }]
    m.person.mockResolvedValue({
      ...person(1, 'Alfa Aktivní', true, false),
      employments: [],
    })
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.get('[data-test="edit-employee-1"]').trigger('click')
    await flushPromises()

    // Trvalý banner nad kartou zabíral nejlepší místo stránky vysvětlením něčeho,
    // co uživatel zrovna nedělá — akce se proto nabízí a důvod přijde na kliknutí.
    expect(wrapper.find('[data-test="person-delete-blocker"]').exists()).toBe(false)
    await wrapper.get('[data-test="action-delete-person"]').trigger('click')
    expect(m.toastError)
      .toHaveBeenCalledWith('Zaměstnanec je zahrnutý v revizi mzdového běhu.')
  })

  it('creates the shared accounting employee, reloads payroll people and opens next-step detail', async () => {
    roster = []
    m.createPerson.mockImplementation(() => {
      roster = [person(4, 'Delta Nová', true, true)]
      return Promise.resolve({
        id: 4,
        full_name: 'Delta Nová',
        employments: [{ id: 44, employee_id: 4, relation_type: 'employment' }],
      })
    })
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.get('[data-test="add-employee"]').trigger('click')
    expect(wrapper.find('[data-test="new-employee-relation"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="new-employee-planned-start"]').exists()).toBe(true)
    const relation = wrapper.get('[data-test="new-employee-relation"]')
    await relation.get('input').trigger('focus')
    expect(relation.findAll('[role="option"]').map(option => option.text())).toEqual([
      'payroll.people.relations.employment',
      'payroll.people.relations.small_scale_employment',
      'payroll.people.relations.dpp',
      'payroll.people.relations.dpc',
      'payroll.people.relations.partner_dependent',
      'payroll.people.relations.statutory_body',
    ])
    await wrapper.get('[data-test="new-employee-name"]').setValue(' Delta Nová ')
    await wrapper.get('[data-test="new-employee-birth-number"]').setValue('0001010009')
    await wrapper.get('[data-test="new-employee-form"]').trigger('submit')
    await flushPromises()

    expect(m.createPerson).toHaveBeenCalledWith({
      full_name: 'Delta Nová',
      birth_date: null,
      birth_number: '0001010009',
      relation_type: 'employment',
      planned_start_on: expect.stringMatching(/^\d{4}-\d{2}-\d{2}$/),
      monthly_gross: null,
      office_id: null,
      // Úvazek jde nově rovnou ze zakládacího formuláře, ne až z nové verze podmínek.
      weekly_hours: '40.00',
      health_insurer_code: null,
    })
    // Nová osoba musí být vidět i tehdy, když ji předchozí zúžení schovalo.
    expect(m.peoplePage).toHaveBeenLastCalledWith({
      limit: PAGE_SIZE,
      offset: 0,
      filter: 'all',
      q: '',
    })
    expect(m.createEmployment).not.toHaveBeenCalled()
    expect(m.person).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="employee-created-next"]').text())
      .toContain('payroll.people.create.next_steps')
    expect(wrapper.text()).not.toContain('0001010009')
    expect(wrapper.find('[data-test="new-employee-form"]').exists()).toBe(false)
    expect(m.toastSuccess).toHaveBeenCalledWith(
      'payroll.people.create.created',
    )
  })

  it('reports an exact atomic creation error without reloading a partial person', async () => {
    roster = []
    m.createPerson.mockRejectedValue({
      response: {
        data: {
          error: {
            message: 'Zaměstnance a pracovní vztah nelze založit.',
            fields: {
              planned_start_on: ['Datum nástupu je mimo povolené období; nic nebylo uloženo.'],
            },
          },
        },
      },
    })
    const wrapper = mountPage()
    await flushPromises()
    const callsBeforeSubmit = m.peoplePage.mock.calls.length

    await wrapper.get('[data-test="add-employee"]').trigger('click')
    await wrapper.get('[data-test="new-employee-name"]').setValue('Delta Nová')
    await wrapper.get('[data-test="new-employee-form"]').trigger('submit')
    await flushPromises()

    expect(m.createPerson).toHaveBeenCalledOnce()
    expect(m.createEmployment).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="new-employee-form"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="new-employee-error"]').text())
      .toContain('nic nebylo uloženo')
    expect(m.peoplePage.mock.calls.length).toBe(callsBeforeSubmit)
  })

  it('shows the exact backend validation message', async () => {
    roster = []
    m.createPerson.mockRejectedValue({
      response: {
        data: {
          error: {
            message: 'Zaměstnanec již existuje.',
            fields: {
              full_name: ['Použijte existujícího zaměstnance.'],
            },
          },
        },
      },
    })
    const wrapper = mountPage()
    await flushPromises()

    await wrapper.get('[data-test="add-employee"]').trigger('click')
    await wrapper.get('[data-test="new-employee-name"]').setValue('Duplicitní')
    await wrapper.get('[data-test="new-employee-form"]').trigger('submit')
    await flushPromises()

    expect(m.toastError).toHaveBeenCalledWith(
      'Zaměstnanec již existuje.: Použijte existujícího zaměstnance.',
    )
    expect(wrapper.get('[data-test="new-employee-error"]').text())
      .toContain('Použijte existujícího zaměstnance.')
  })

  /*
   * Deep-link musí fungovat i na osobu, která na načtené stránce není —
   * neaktivní člověk ve výchozím filtru chybí a listovat kvůli odkazu celý
   * seznam by stálo tolik požadavků, kolik má firma stránek.
   */
  it('opens a person missing from the page by fetching that single detail', async () => {
    m.routeQuery.person = '2'
    m.person.mockResolvedValue({
      ...person(2, 'Beta Neaktivní', false, false),
      employments: [],
    })
    const wrapper = mountPage()
    await flushPromises()

    expect(m.person).toHaveBeenCalledWith(2)
    expect(m.peoplePage).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[data-test="selected-person-editor"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="person-header-name"]').text()).toBe('Beta Neaktivní')
  })

  it('opens the employee card from an employment deep-link', async () => {
    m.routeQuery.employment = '44'
    m.person.mockResolvedValue({
      ...person(4, 'Delta Nová', true, true),
      employments: [],
    })
    const wrapper = mountPage()
    await flushPromises()

    expect(m.employmentAgendaSummary).toHaveBeenCalledWith(44)
    expect(m.person).toHaveBeenCalledWith(4)
    expect(wrapper.find('[data-test="selected-person-editor"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="person-header-name"]').text()).toBe('Delta Nová')
  })

  it('prefers an explicit person deep-link over an accompanying employment id', async () => {
    m.routeQuery.person = '2'
    m.routeQuery.employment = '44'
    m.person.mockResolvedValue({
      ...person(2, 'Beta Neaktivní', false, false),
      employments: [],
    })
    const wrapper = mountPage()
    await flushPromises()

    expect(m.employmentAgendaSummary).not.toHaveBeenCalled()
    expect(m.person).toHaveBeenCalledWith(2)
    expect(wrapper.get('[data-test="person-header-name"]').text()).toBe('Beta Neaktivní')
  })

  it.each(['0', '-1', 'not-an-id'])(
    'ignores invalid employment deep-link %s without fetching a detail',
    async (employment) => {
      m.routeQuery.employment = employment
      const wrapper = mountPage()
      await flushPromises()

      expect(m.employmentAgendaSummary).not.toHaveBeenCalled()
      expect(m.person).not.toHaveBeenCalled()
      expect(wrapper.find('[data-test="selected-person-editor"]').exists()).toBe(false)
    },
  )

  it('keeps a missing employment deep-link silent and closed', async () => {
    m.routeQuery.employment = '404'
    m.employmentAgendaSummary.mockRejectedValue(new Error('not found'))
    const wrapper = mountPage()
    await flushPromises()

    expect(m.person).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="selected-person-editor"]').exists()).toBe(false)
    expect(m.toastError).not.toHaveBeenCalled()
  })

  it('ignores an unknown person id in the query', async () => {
    m.routeQuery.person = '999'
    m.person.mockRejectedValue(new Error('not found'))
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('[data-test="selected-person-editor"]').exists()).toBe(false)
    expect(m.toastError).not.toHaveBeenCalled()
  })
})
