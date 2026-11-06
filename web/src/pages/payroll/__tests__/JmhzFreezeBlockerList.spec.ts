import { mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createI18n } from 'vue-i18n'
import { cs, i18nMessages } from '../../../../tests/locales'
import type { PayrollJmhzFreezeBlocker } from '@/api/payroll'
import JmhzFreezeBlockerList from '../JmhzFreezeBlockerList.vue'
import PayrollJmhzReturnBar from '../PayrollJmhzReturnBar.vue'

const m = vi.hoisted(() => ({
  route: { fullPath: '/payroll/submissions/jmhz?period=2026-09', query: {} as Record<string, unknown> },
  push: vi.fn(),
}))

vi.mock('vue-router', async () => {
  const actual = await vi.importActual<typeof import('vue-router')>('vue-router')
  return { ...actual, useRoute: () => m.route, useRouter: () => ({ push: m.push }) }
})

const RouterLinkStub = {
  props: ['to'],
  template: '<a :data-to="to"><slot /></a>',
}

function blocker(overrides: Partial<PayrollJmhzFreezeBlocker>): PayrollJmhzFreezeBlocker {
  return {
    code: 'jmhz_annual_request_source_missing',
    entity_type: 'person',
    entity_id: 11,
    attribute_ids: ['10319'],
    reason: 'Není doloženo, zda zaměstnanec požádal o roční zúčtování.',
    label: 'Jana Nováková',
    remediation_path: '/payroll/annual-settlement?person=11',
    ...overrides,
  }
}

function mountWith(component: object, props: Record<string, unknown>) {
  const i18n = createI18n({ legacy: false, locale: 'cs', fallbackLocale: 'cs', messages: i18nMessages })
  return mount(component, { props, global: { plugins: [i18n], stubs: { RouterLink: RouterLinkStub } } })
}

describe('blokace zmrazení JMHZ s proklikem na opravu', () => {
  beforeEach(() => {
    m.route.query = {}
    m.push.mockReset()
  })

  it('seskupí podle důvodu, jmenuje zaměstnance a odkaz nese návrat i rok zúčtování', () => {
    const wrapper = mountWith(JmhzFreezeBlockerList, {
      period: '2026-09',
      blockers: [
        blocker({}),
        blocker({ entity_id: 12, label: 'Petr Svoboda', remediation_path: '/payroll/annual-settlement?person=12' }),
        blocker({
          code: 'jmhz_average_hourly_earning_missing',
          entity_type: 'employment',
          entity_id: 22,
          reason: 'Chybí ověřený průměrný hodinový výdělek.',
          label: 'Eva Dvořáková (E7)',
          remediation_path: '/payroll/absences?employment=22&tab=averages',
        }),
      ],
    })

    expect(wrapper.findAll('h4').map(h => h.text())).toEqual([
      'Není doloženo, zda zaměstnanec požádal o roční zúčtování.',
      'Chybí ověřený průměrný hodinový výdělek.',
    ])
    expect(wrapper.findAll('[data-test="jmhz-freeze-blocker-row"]').map(r => r.find('span').text()))
      .toEqual(['Jana Nováková', 'Petr Svoboda', 'Eva Dvořáková (E7)'])

    const links = wrapper.findAll('[data-test="jmhz-freeze-blocker-fix"]')
    const first = new URL(links[0]!.attributes('data-to')!, 'http://x')
    expect(first.pathname).toBe('/payroll/annual-settlement')
    expect(first.searchParams.get('person')).toBe('11')
    expect(first.searchParams.get('year')).toBe('2025')
    expect(first.searchParams.get('return')).toBe('/payroll/submissions/jmhz?period=2026-09')
    expect(links[0]!.text()).toContain(cs.payroll.jmhz_gate.freeze_blockers.fix)

    const average = new URL(links[2]!.attributes('data-to')!, 'http://x')
    expect(average.searchParams.get('tab')).toBe('averages')
    expect(average.searchParams.has('year')).toBe(false)
  })

  it('na cílové stránce nabídne návrat jen pro bezpečný odkaz return', async () => {
    const at = (search: string) => {
      window.history.replaceState({}, '', `/payroll/annual-settlement${search}`)
      return mountWith(PayrollJmhzReturnBar, {}).find('[data-test="jmhz-return"]')
    }
    const link = at(`?person=11&return=${encodeURIComponent('/payroll/submissions/jmhz?period=2026-09')}`)
    expect(link.attributes('data-to')).toBe('/payroll/submissions/jmhz?period=2026-09')
    expect(link.text()).toContain(cs.payroll.jmhz_gate.return_to_jmhz)

    expect(at(`?return=${encodeURIComponent('//evil.example/x')}`).exists()).toBe(false)
    expect(at('?person=11').exists()).toBe(false)
    window.history.replaceState({}, '', '/')
  })
})
