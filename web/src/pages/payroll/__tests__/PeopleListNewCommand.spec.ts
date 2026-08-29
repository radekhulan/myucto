import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'
import { nextTick, reactive } from 'vue'
import type { PayrollPersonListItem } from '@/api/payroll'

/**
 * Tři věci, které se dají rozbít potichu a uživatel na ně narazí až v provozu:
 *
 * 1. Povel `?new=1` z globálního „+" v hlavičce. Zakládání zaměstnance nemá
 *    vlastní routu, takže odkaz z nabídky by bez tohohle povelu nešel udělat.
 *    Povel se musí z adresy uklidit `replace`em (jednorázový, do historie
 *    nepatří) a formulář se smí otevřít i tehdy, když už na seznamu stojím.
 * 2. Historie u otevření karty. Otevření je `push`, zavření `replace` — dřív
 *    bylo obojí `replace` a tlačítko Zpět z karty přeskočilo seznam rovnou na
 *    `/payroll`.
 * 3. Osobní číslo v řádku. Bez něj se dva jmenovci v seznamu nedají rozlišit.
 */
const m = vi.hoisted(() => ({
  peoplePage: vi.fn(),
  person: vi.fn(),
  capabilities: vi.fn(),
  employerSettings: vi.fn(),
  routerPush: vi.fn(),
  routerReplace: vi.fn(),
  routeQuery: {} as Record<string, string>,
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ query: m.routeQuery }),
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
    employerSettings: m.employerSettings,
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canRead: () => true, canWrite: () => true }),
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

function ref(id: number, code: string, isPrimary = false): PayrollPersonListItem['employment_refs'][number] {
  return { id, code, relation_type: 'employment', status: 'active', is_primary: isPrimary }
}

function person(
  id: number,
  fullName: string,
  refs: PayrollPersonListItem['employment_refs'] = [ref(id * 10, `ZAM-${id}`, true)],
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

async function mountPage(people: PayrollPersonListItem[]) {
  m.peoplePage.mockResolvedValue({ items: people, total: people.length, limit: 25, offset: 0 })
  const wrapper = mount(PeopleList, {
    attachTo: document.body,
    global: {
      // RouterLink registruje globálně až plugin routeru; v testu ho dodáme sami,
      // jinak by se z klikacího řádku stal neznámý element bez `href`.
      components: {
        RouterLink: {
          name: 'RouterLink',
          props: ['to'],
          template: '<a :data-to="JSON.stringify(to)"><slot /></a>',
        },
      },
      stubs: {
        ActionBar: true,
        EmploymentCard: true,
        PayrollPersonQuickEdit: true,
        PayrollPersonProfilePanel: true,
        PayrollPersonStatutoryEvidencePanel: true,
        PayrollPersonDependantsPanel: true,
        PayrollPersonForeignPermitPanel: true,
        RowActionsMenu: true,
        ColumnPicker: true,
        DensityToggle: true,
        PaginationBar: true,
      },
    },
  })
  await flushPromises()
  return wrapper
}

enableAutoUnmount(afterEach)

/**
 * Atrapa routeru musí navigaci opravdu promítnout do `route.query`. Bez toho
 * by stránka viděla adresu, kterou sama před chvílí přepsala, a hlídač výběru
 * by kartu hned zase otevřel — testovaly by se pak artefakty atrapy, ne kód.
 */
function applyNavigation(to: { query?: Record<string, string | undefined> }) {
  for (const key of Object.keys(m.routeQuery)) delete m.routeQuery[key]
  for (const [key, value] of Object.entries(to.query ?? {})) {
    if (value !== undefined) m.routeQuery[key] = value
  }
  return Promise.resolve()
}

describe('PeopleList — povel ?new=1 a historie karty', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    resetPayrollOffices()
    resetDefaultHealthInsurerCode()
    m.routeQuery = reactive<Record<string, string>>({})
    m.routerPush.mockImplementation(applyNavigation)
    m.routerReplace.mockImplementation(applyNavigation)
    m.capabilities.mockResolvedValue({ state: { start_period: null } })
    m.employerSettings.mockResolvedValue({ offices: [], default_health_insurer_code: null })
    m.person.mockResolvedValue({ person: { id: 1, full_name: 'Jan Novák' }, employments: [] })
  })

  it('otevře zakládací formulář, když stránka přijde s ?new=1', async () => {
    m.routeQuery.new = '1'
    const wrapper = await mountPage([person(1, 'Jan Novák')])

    expect(wrapper.find('[data-test="new-employee-form"]').exists()).toBe(true)
  })

  it('uklidí povel z adresy replacem, ne pushem — jednorázový příkaz do historie nepatří', async () => {
    m.routeQuery.new = '1'
    m.routeQuery.q = 'nov'
    await mountPage([person(1, 'Jan Novák')])

    expect(m.routerReplace).toHaveBeenCalledWith({ query: { q: 'nov' } })
    expect(m.routerPush).not.toHaveBeenCalled()
  })

  it('otevře formulář i když už na seznamu stojím — stejná routa komponentu nepřemontuje', async () => {
    const wrapper = await mountPage([person(1, 'Jan Novák')])
    expect(wrapper.find('[data-test="new-employee-form"]').exists()).toBe(false)

    m.routeQuery.new = '1'
    await nextTick()
    await flushPromises()

    expect(wrapper.find('[data-test="new-employee-form"]').exists()).toBe(true)
    expect(m.routerReplace).toHaveBeenCalledWith({ query: {} })
  })

  it('otevření karty pushuje, aby Zpět vrátilo na seznam se zachovaným hledáním', async () => {
    m.routeQuery.q = 'nov'
    const wrapper = await mountPage([person(1, 'Jan Novák')])

    await wrapper.find('[data-test="edit-employee-1"]').trigger('click')
    await flushPromises()

    expect(m.routerPush).toHaveBeenCalledWith({ query: { q: 'nov', person: '1' } })
    expect(m.routerReplace).not.toHaveBeenCalled()
  })

  it('zavření karty replacuje, ať Zpět ze seznamu neskočí zpátky do karty', async () => {
    const wrapper = await mountPage([person(1, 'Jan Novák')])
    await wrapper.find('[data-test="edit-employee-1"]').trigger('click')
    await flushPromises()
    expect(m.routeQuery.person).toBe('1')
    m.routerPush.mockClear()

    // Seznam je při otevřené kartě schovaný, zpátky se jde drobečkem.
    const crumb = wrapper.find('[data-test="breadcrumb-people"]')
    expect(crumb.exists(), 'karta se neotevřela, drobeček není v DOM').toBe(true)
    await crumb.trigger('click')
    await flushPromises()

    expect(m.routerReplace).toHaveBeenCalledWith({ query: { person: undefined } })
    expect(m.routerPush).not.toHaveBeenCalled()
  })

  it('deep-link ?person=1 nepřidá do historie druhou položku — Zpět tak vede ven, ne na sebe', async () => {
    m.routeQuery.person = '1'
    await mountPage([person(1, 'Jan Novák')])

    expect(m.routerPush).not.toHaveBeenCalled()
    expect(m.routerReplace).not.toHaveBeenCalled()
  })
})

describe('PeopleList — rozlišení jmenovců a klikací řádek', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    resetPayrollOffices()
    resetDefaultHealthInsurerCode()
    m.routeQuery = reactive<Record<string, string>>({})
    m.routerPush.mockImplementation(applyNavigation)
    m.routerReplace.mockImplementation(applyNavigation)
    m.capabilities.mockResolvedValue({ state: { start_period: null } })
    m.employerSettings.mockResolvedValue({ offices: [], default_health_insurer_code: null })
  })

  it('ukáže u jmenovců osobní číslo, takže se dají rozlišit', async () => {
    const wrapper = await mountPage([person(1, 'Jan Novák'), person(2, 'Jan Novák')])

    expect(wrapper.find('[data-test="person-code-1"]').text()).toContain('ZAM-1')
    expect(wrapper.find('[data-test="person-code-2"]').text()).toContain('ZAM-2')
  })

  it('u víc vztahů vypíše dva kódy a zbytek shrne číslem, ať řádek nenaroste', async () => {
    const wrapper = await mountPage([person(1, 'Jan Novák', [
      ref(11, 'ZAM-1'), ref(12, 'DPP-1', true), ref(13, 'DPC-1'),
    ])])

    // Hlavní vztah je první, pak jeden další a zbytek jako počet.
    expect(wrapper.find('[data-test="person-code-1"]').text()).toContain('DPP-1, ZAM-1 +1')
  })

  it('míří z řádku na kartu skutečným odkazem, ne posluchačem — kvůli Ctrl+kliku a klávesnici', async () => {
    m.routeQuery.q = 'nov'
    const wrapper = await mountPage([person(1, 'Jan Novák')])

    const row = wrapper.find('[data-test="person-row-link-1"]')
    const name = wrapper.find('[data-test="person-name-link-1"]')
    expect(row.element.tagName).toBe('A')
    expect(name.element.tagName).toBe('A')
    const target = { query: { q: 'nov', person: '1' } }
    expect(JSON.parse(row.attributes('data-to') ?? '{}')).toEqual(target)
    expect(JSON.parse(name.attributes('data-to') ?? '{}')).toEqual(target)
  })

  it('nepřekrývá overlayem rychlé akce ani text — obojí leží nad ním', async () => {
    const wrapper = await mountPage([person(1, 'Jan Novák')])

    // Overlay je z-0, obsah, který musí zůstat klikací a označitelný, z-10.
    expect(wrapper.find('[data-test="person-row-link-1"]').classes()).toContain('z-0')
    const actionsCell = wrapper.find('[data-test="edit-employee-1"]').element.closest('td')
    expect(actionsCell?.className).toContain('z-10')
    expect(wrapper.find('[data-test="person-name-link-1"]').element.closest('span')?.className)
      .toContain('z-10')
  })
})
