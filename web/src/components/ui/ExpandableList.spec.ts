import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { h } from 'vue'

// Klíč i s parametry, ať test vidí počty, které se do věty dosazují.
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) =>
      params ? `${key} ${JSON.stringify(params)}` : key,
  }),
}))

import ExpandableList from '@/components/ui/ExpandableList.vue'

interface Person { id: number, name: string }

const people: Person[] = Array.from({ length: 60 }, (_, index) => ({
  id: index + 1,
  name: `Zaměstnanec ${String(index + 1).padStart(2, '0')}`,
}))

function mountList(props: Record<string, unknown> = {}) {
  return mount(ExpandableList, {
    props: {
      items: people,
      itemKey: (person: unknown) => (person as Person).id,
      searchText: (person: unknown) => (person as Person).name,
      testId: 'list',
      ...props,
    },
    slots: {
      item: ({ item }: { item: unknown }) => h('span', { 'data-test': 'row' }, (item as Person).name),
    },
  })
}

describe('ExpandableList', () => {
  it('ukáže jen prvních osm položek a nabídne rozbalení na všechny', () => {
    const wrapper = mountList()

    const rows = wrapper.findAll('[data-test="row"]')
    expect(rows).toHaveLength(8)
    expect(rows[0]?.text()).toBe('Zaměstnanec 01')
    expect(wrapper.get('[data-test="list-toggle"]').text()).toContain('60')
    expect(wrapper.find('[data-test="list-search"]').exists()).toBe(false)
  })

  it('rozbalený seznam stránkuje po 25 a jde zase sbalit', async () => {
    const wrapper = mountList()

    await wrapper.get('[data-test="list-toggle"]').trigger('click')
    expect(wrapper.findAll('[data-test="row"]')).toHaveLength(25)
    expect(wrapper.get('[data-test="list-pagination"]').text()).toContain('1 / 3')

    const next = wrapper.get('[data-test="list-pagination"]').findAll('button')[1]
    await next?.trigger('click')
    expect(wrapper.findAll('[data-test="row"]')[0]?.text()).toBe('Zaměstnanec 26')

    await wrapper.get('[data-test="list-toggle"]').trigger('click')
    expect(wrapper.findAll('[data-test="row"]')).toHaveLength(8)
    expect(wrapper.find('[data-test="list-pagination"]').exists()).toBe(false)
  })

  it('hledá bez ohledu na diakritiku a velikost písmen a vrací se na první stránku', async () => {
    const wrapper = mountList()

    await wrapper.get('[data-test="list-toggle"]').trigger('click')
    await wrapper.get('[data-test="list-pagination"]').findAll('button')[1]?.trigger('click')
    await wrapper.get('[data-test="list-search"]').setValue('zamestnanec 4')

    const rows = wrapper.findAll('[data-test="row"]')
    expect(rows.map(row => row.text())).toEqual([
      'Zaměstnanec 40', 'Zaměstnanec 41', 'Zaměstnanec 42', 'Zaměstnanec 43', 'Zaměstnanec 44',
      'Zaměstnanec 45', 'Zaměstnanec 46', 'Zaměstnanec 47', 'Zaměstnanec 48', 'Zaměstnanec 49',
    ])
    expect(wrapper.find('[data-test="list-pagination"]').exists()).toBe(false)

    await wrapper.get('[data-test="list-search"]').setValue('nikdo takový')
    expect(wrapper.findAll('[data-test="row"]')).toHaveLength(0)
    expect(wrapper.find('[data-test="list-no-results"]').exists()).toBe(true)
  })

  it('krátký seznam kreslí celý, bez tlačítka a bez stránkování', () => {
    const wrapper = mountList({ items: people.slice(0, 5) })

    expect(wrapper.findAll('[data-test="row"]')).toHaveLength(5)
    expect(wrapper.find('[data-test="list-toggle"]').exists()).toBe(false)
  })

  /*
   * Server posílá u nálezu nejvýš 25 osob a skutečný počet zvlášť. Tlačítko
   * smí rozbalit jen to, co dorazilo, ale věta o zbytku musí počítat ze
   * skutečného počtu, ne z délky seznamu.
   */
  it('u ořezaného výčtu přizná, kolik dalších se nevešlo', () => {
    const wrapper = mountList({ items: people.slice(0, 25), total: 225 })

    // „Zobrazit všech 25" by lhalo, když nález má 225 osob.
    const toggle = wrapper.get('[data-test="list-toggle"]').text()
    expect(toggle).toContain('common.expandable_list.show_listed')
    expect(toggle).toContain('"count":25')
    expect(toggle).toContain('"total":225')
    const note = wrapper.get('[data-test="list-not-listed"]').text()
    expect(note).toContain('225')
    expect(note).toContain('200')
  })
})
