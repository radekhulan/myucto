import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import type { PayrollPersonListItem } from '@/api/payroll'

/**
 * Rychlé akce v řádku seznamu zaměstnanců (balík W34).
 *
 * Hlídá tři věci, které se dají rozbít potichu: že seznam kvůli tlačítkům
 * NEPOUŠTÍ další dotaz na server, že osoba s jedním vztahem klikne rovnou a
 * osoba s víc vztahy dostane otázku, a že akce bez oprávnění zůstane vidět
 * i s důvodem — ne že zmizí.
 */
const m = vi.hoisted(() => ({
  peoplePage: vi.fn(),
  person: vi.fn(),
  capabilities: vi.fn(),
  routerPush: vi.fn(),
  routerReplace: vi.fn(),
  canRead: vi.fn(),
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ query: {} }),
  useRouter: () => ({ push: m.routerPush, replace: m.routerReplace }),
  RouterLink: {
    name: 'RouterLink',
    props: ['to'],
    template: '<a :data-to="JSON.stringify(to)"><slot /></a>',
  },
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    peoplePage: m.peoplePage,
    person: m.person,
    capabilities: m.capabilities,
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canRead: m.canRead, canWrite: () => true }),
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn() }),
}))

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) =>
      params ? `${key}:${JSON.stringify(params)}` : key,
  }),
}))

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

function person(
  id: number,
  fullName: string,
  refs: PayrollPersonListItem['employment_refs'],
): PayrollPersonListItem {
  return {
    id,
    full_name: fullName,
    is_active: true,
    profile_status: 'ready',
    legacy_taxpayer_type: 'employee',
    legacy_employment_type: 'hpp',
    employment_count: refs.length,
    relation_types: ['employment'],
    employment_refs: refs,
    setup_gaps: [],
    needs_setup: false,
    can_delete: true,
    delete_blocker: null,
    delete_cascade: {},
  }
}

function ref(id: number, isPrimary = false): PayrollPersonListItem['employment_refs'][number] {
  return { id, code: `HPP-${id}`, relation_type: 'employment', status: 'active', is_primary: isPrimary }
}

function mountPage(people: PayrollPersonListItem[]) {
  m.peoplePage.mockResolvedValue({ items: people, total: people.length, limit: 25, offset: 0 })
  return mount(PeopleList, {
    attachTo: document.body,
    global: {
      stubs: {
        ActionBar: true,
        EmploymentCard: true,
        PayrollPersonQuickEdit: true,
        PayrollPersonProfilePanel: true,
        PayrollPersonStatutoryEvidencePanel: true,
        PayrollPersonDependantsPanel: true,
        PayrollPersonForeignPermitPanel: true,
      },
    },
  })
}

// Dialog i nabídka jsou teleportované — odmontovat je musí VTU, ne mazání <body>.
enableAutoUnmount(afterEach)

describe('PeopleList — rychlé akce v řádku', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    resetPayrollOffices()
    resetDefaultHealthInsurerCode()
    m.canRead.mockReturnValue(true)
    m.capabilities.mockResolvedValue({ state: { start_period: null } })
  })

  it('nabídne rychlé akce bez jediného dotazu navíc', async () => {
    mountPage([person(1, 'Alfa', [ref(11, true)])])
    await flushPromises()

    // Jeden dotaz na stránku seznamu — cíle agend jedou s ní, ne zvlášť na řádek.
    expect(m.peoplePage).toHaveBeenCalledTimes(1)
    expect(m.person).not.toHaveBeenCalled()
  })

  it('u osoby s jedním vztahem míří akce rovnou na její vztah, bez ptaní', async () => {
    const wrapper = mountPage([person(1, 'Alfa', [ref(11, true)])])
    await flushPromises()

    const links = wrapper.findAll('[data-test="person-quick-actions-1"] a')
    const targets = links.map(link => link.attributes('data-to') ?? '')
    expect(targets.some(target => target.includes('"employment":"11"'))).toBe(true)
    // Odkaz, ne tlačítko: jde otevřít prostředním tlačítkem na novou kartu.
    expect(links.length).toBeGreaterThan(0)
  })

  it('u osoby s víc vztahy se nejdřív zeptá, do kterého vztahu zápis patří', async () => {
    const wrapper = mountPage([person(1, 'Alfa', [ref(11, true), ref(12)])])
    await flushPromises()

    const buttons = wrapper.findAll('[data-test="person-quick-actions-1"] button')
    // Vztahové agendy jsou tlačítka (otevřou dialog), ne odkazy na konkrétní vztah.
    await buttons[0]!.trigger('click')
    await flushPromises()

    expect(document.querySelector('[data-test="agenda-picker"]')).not.toBeNull()
    const choice = document.querySelector<HTMLElement>('[data-test="agenda-picker-12"]')
    expect(choice).not.toBeNull()
    choice!.click()
    await flushPromises()

    expect(m.routerPush).toHaveBeenCalledTimes(1)
    expect(JSON.stringify(m.routerPush.mock.calls[0]![0])).toContain('"employment":"12"')
  })

  it('akci bez oprávnění neschová — zůstane zašedlá i s důvodem', async () => {
    m.canRead.mockImplementation((permission: string) => permission !== 'payroll.enforcement')
    const wrapper = mountPage([person(1, 'Alfa', [ref(11, true)])])
    await flushPromises()

    await wrapper.get('[data-test="person-quick-actions-1"] button[aria-haspopup="menu"]').trigger('click')
    await flushPromises()

    const menu = document.querySelector('[role="menu"]')
    expect(menu).not.toBeNull()
    expect(menu!.querySelector('[aria-disabled="true"]')).not.toBeNull()
    expect(menu!.textContent).toContain('payroll.people.quick_actions.no_permission')
  })

  it('bez pracovního vztahu vysvětlí, že agenda visí na vztahu', async () => {
    const wrapper = mountPage([person(1, 'Alfa', [])])
    await flushPromises()

    // Vztahové agendy jsou mezi nejčastějšími třemi, tedy inline v řádku:
    // zůstanou zašedlé a důvod nese tooltip (v nabídce by byl i jako věta).
    const first = wrapper.get('[data-test="person-quick-actions-1"] button')
    expect(first.attributes('disabled')).toBeDefined()
    expect(first.attributes('title')).toBe('payroll.people.quick_actions.no_employment')
  })
})
