import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({
  peoplePage: vi.fn(),
  person: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    peoplePage: m.peoplePage,
    person: m.person,
  },
}))

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ t: (key: string) => key }),
}))

import PayrollPersonSearchSelect from '@/components/payroll/PayrollPersonSearchSelect.vue'

function person(id: number, fullName = `Syntetická osoba ${id}`) {
  return {
    id,
    full_name: fullName,
    is_active: true,
    needs_setup: false,
  }
}

describe('PayrollPersonSearchSelect', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.clearAllMocks()
    m.peoplePage.mockResolvedValue({
      items: Array.from({ length: 25 }, (_, index) => person(index + 1)),
      total: 30,
      limit: 25,
      offset: 0,
    })
    m.person.mockImplementation(async (id: number) => person(id))
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('načítá serverově nejvýše omezenou první stránku a přizná další shody', async () => {
    const wrapper = mount(PayrollPersonSearchSelect, {
      props: { modelValue: null, label: 'Zaměstnanec' },
    })

    await wrapper.get('input[role="combobox"]').trigger('focus')
    await flushPromises()

    expect(m.peoplePage).toHaveBeenCalledWith({ limit: 25, offset: 0, q: '' })
    expect(wrapper.findAll('[role="option"]')).toHaveLength(25)
    expect(wrapper.get('[data-test="searchable-select-truncated"]').text())
      .toBe('payroll.person_search.truncated')
  })

  it('debouncuje serverové hledání a vrátí vybranou hodnotu', async () => {
    m.peoplePage.mockResolvedValue({
      items: [person(77, 'Marie Hledaná')],
      total: 1,
      limit: 25,
      offset: 0,
    })
    const wrapper = mount(PayrollPersonSearchSelect, {
      props: { modelValue: null, label: 'Zaměstnanec' },
    })
    const input = wrapper.get('input[role="combobox"]')

    await input.setValue('Marie')
    await vi.advanceTimersByTimeAsync(249)
    expect(m.peoplePage).not.toHaveBeenCalled()
    await vi.advanceTimersByTimeAsync(1)
    await flushPromises()

    expect(m.peoplePage).toHaveBeenCalledWith({ limit: 25, offset: 0, q: 'Marie' })
    await wrapper.get('[role="option"]').trigger('click')
    expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual([77])
  })

  it('zobrazí osobu z deep-linku, i když neleží v první stránce', async () => {
    m.person.mockResolvedValue(person(287, 'Deep Linková'))
    const wrapper = mount(PayrollPersonSearchSelect, {
      props: { modelValue: 287, label: 'Zaměstnanec' },
    })
    await flushPromises()

    expect(m.person).toHaveBeenCalledWith(287)
    expect((wrapper.get('input[role="combobox"]').element as HTMLInputElement).value)
      .toBe('Deep Linková')
  })

  it('u omezeného lokálního zdroje kandidátů nikdy nevyrenderuje stovky položek', async () => {
    const candidates = Array.from({ length: 100 }, (_, index) => ({
      value: index + 1,
      label: `Osoba ${String(index + 1).padStart(3, '0')}`,
    }))
    const wrapper = mount(PayrollPersonSearchSelect, {
      props: { modelValue: null, label: 'Osoba', candidates },
    })
    const input = wrapper.get('input[role="combobox"]')

    await input.trigger('focus')
    expect(wrapper.findAll('[role="option"]')).toHaveLength(25)
    expect(m.peoplePage).not.toHaveBeenCalled()

    await input.setValue('099')
    await vi.advanceTimersByTimeAsync(250)
    expect(wrapper.findAll('[role="option"]')).toHaveLength(1)
    expect(wrapper.get('[role="option"]').text()).toContain('Osoba 099')
  })
})
