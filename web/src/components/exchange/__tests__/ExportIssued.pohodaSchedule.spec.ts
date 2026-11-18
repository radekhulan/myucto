import { ref } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ locale: ref('cs-CZ'), t: (key: string) => key, tm: () => [], rt: (s: string) => s }),
}))

const m = vi.hoisted(() => ({ enabled: false }))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ get scheduledPohodaExportEnabled() { return m.enabled } }),
}))
vi.mock('@/composables/useYearOptions', () => ({ useYearOptions: () => ref([2026]) }))
vi.mock('@/components/exchange/PohodaSchedulePanel.vue', async () => {
  const { h } = await import('vue')
  return { default: { name: 'PohodaSchedulePanel', render: () => h('section', { 'data-test': 'pohoda-schedule-panel' }) } }
})

import ExportIssued from '@/components/exchange/ExportIssued.vue'

// Automatický export do Pohody je volba instalace (export.scheduled_pohoda, výchozí vypnuto).
describe('ExportIssued – panel automatického exportu do Pohody', () => {
  beforeEach(() => {
    m.enabled = false
  })

  it('bez zapnuté volby instalace panel nezobrazí', () => {
    const wrapper = mount(ExportIssued)
    expect(wrapper.find('[data-test="pohoda-schedule-panel"]').exists()).toBe(false)
  })

  it('se zapnutou volbou instalace panel zobrazí', () => {
    m.enabled = true
    const wrapper = mount(ExportIssued)
    expect(wrapper.find('[data-test="pohoda-schedule-panel"]').exists()).toBe(true)
  })
})
