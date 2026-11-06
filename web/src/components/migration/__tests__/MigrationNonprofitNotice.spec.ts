import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

const supplier = vi.hoisted(() => ({ current: null as null | { accounting_framework?: string; entity_profile?: string } }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ get currentSupplier() { return supplier.current } }) }))
vi.mock('vue-router', () => ({ RouterLink: { props: ['to'], template: '<a :data-to="to"><slot /></a>' } }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

import MigrationNonprofitNotice from '@/components/migration/MigrationNonprofitNotice.vue'

function render(current: typeof supplier.current, props: Record<string, unknown> = {}) {
  supplier.current = current
  return mount(MigrationNonprofitNotice, { props })
}

describe('MigrationNonprofitNotice', () => {
  it('běžné firmě nic nevykreslí', () => {
    expect(render({ accounting_framework: 'cz_500', entity_profile: 'unknown' }).find('[data-testid="migration-nonprofit-notice"]').exists()).toBe(false)
    expect(render(null).find('[data-testid="migration-nonprofit-notice"]').exists()).toBe(false)
  })

  it.each([
    [{ accounting_framework: 'cz_500', entity_profile: 'nonprofit' }],
    [{ accounting_framework: 'cz_500', entity_profile: 'svj' }],
    [{ accounting_framework: 'cz_504', entity_profile: 'unknown' }],
  ])('upozorní neziskovku podle profilu nebo rámce %j', (current) => {
    const notice = render(current).find('[data-testid="migration-nonprofit-notice"]')
    expect(notice.exists()).toBe(true)
    expect(notice.attributes('role')).toBe('alert')
    expect(notice.text()).toContain('migration_nonprofit_notice.body')
    expect(notice.find('a').attributes('data-to')).toBe('/admin/support')
  })

  it('upozornění z náhledu převodu se ukáže i běžné aktuální firmě (rámec 504 v historii)', () => {
    const wrapper = render({ accounting_framework: 'cz_500', entity_profile: 'unknown' }, { notice: { code: 'migration_nonprofit_untested' } })
    expect(wrapper.find('[data-testid="migration-nonprofit-notice"]').exists()).toBe(true)
  })

  it('převod z exportu MyÚčto má vlastní text', () => {
    const text = render({ accounting_framework: 'cz_504', entity_profile: 'nonprofit' }, { native: true }).text()
    expect(text).toContain('migration_nonprofit_notice.body_native')
  })
})
