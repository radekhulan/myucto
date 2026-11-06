import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import StereoNxSummary from '../StereoNxSummary.vue'
import type { MoneyS3Step } from '@/api/moneyS3'

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key, te: () => true }) }))

function summary() {
  const steps: MoneyS3Step[] = [
    { key: 'source_summary', status: 'ok', messages: [], counts: {
      assets_source: 0, assets_ready: 0, assets_skipped: 0,
      small_assets_source: 0, small_assets_ready: 0,
      route_templates_source: 0, route_templates_skipped: 0, children_source: 0, children_skipped: 0,
      tax_items_source: 0, tax_items_skipped: 0, leave_records_source: 0, leave_records_skipped: 0, average_records_source: 0, average_records_skipped: 0,
      employees_source: 1, employees_ready: 0, employees_skipped: 1,
      requires_draft: 0, requires_review: 4, requires_movement_review: 5,
      custom_zero: 0, custom_positive: 7,
    } },
    { key: 'would_write', status: 'ok', messages: [], counts: { employees_created: 0, custom_positive: 2 } },
  ]
  return mount(StereoNxSummary, { props: { steps } })
}

describe('StereoNxSummary', () => {
  it('defaults to hiding rows whose supplied counts are all zero, including incomplete agendas, in both tables', () => {
    const wrapper = summary()
    expect((wrapper.get('[data-testid="stereo-counts-show-all"]').element as HTMLInputElement).checked).toBe(false)
    expect(wrapper.find('[data-agenda="assets"]').exists()).toBe(false)
    for (const key of ['small_assets', 'route_templates', 'children', 'tax_items', 'leave_records', 'average_records']) expect(wrapper.find(`[data-agenda="${key}"]`).exists()).toBe(false)
    expect(wrapper.get('[data-agenda="employees"]').findAll('td').map(cell => cell.text())).toEqual(['1', '0', '1', '—'])
    expect(wrapper.find('[data-agenda="documents_review"]').exists()).toBe(false)
    expect(wrapper.find('[data-count-key="custom_zero"]').exists()).toBe(false)
    expect(wrapper.find('[data-count-key="employees_created"]').exists()).toBe(false)
    expect(wrapper.findAll('[data-count-key="custom_positive"]').map(row => row.findAll('td').at(-1)!.text())).toEqual(['7', '2'])
  })

  it('show all restores every supplied metric once and can be switched off again', async () => {
    const wrapper = summary()
    await wrapper.get('[data-testid="stereo-counts-show-all"]').setValue(true)
    expect(wrapper.get('[data-agenda="assets"]').findAll('td').map(cell => cell.text())).toEqual(['0', '0', '0', '—'])
    expect(wrapper.get('[data-agenda="documents_review"]').findAll('td').at(-1)!.text()).toBe('0')
    expect(wrapper.get('[data-agenda="small_assets"]').findAll('td').map(cell => cell.text())).toEqual(['0', '0', '—', '—'])
    for (const key of ['route_templates', 'children', 'tax_items', 'leave_records', 'average_records']) expect(wrapper.get(`[data-agenda="${key}"]`).findAll('td').map(cell => cell.text())).toEqual(['0', '—', '0', '—'])
    expect(wrapper.findAll('[data-count-key]')).toHaveLength(25)
    expect(wrapper.findAll('[data-count-key="custom_zero"]')).toHaveLength(1)
    expect(wrapper.findAll('[data-count-key="employees_created"]')).toHaveLength(1)
    await wrapper.get('[data-testid="stereo-counts-show-all"]').setValue(false)
    expect((wrapper.get('[data-testid="stereo-counts-show-all"]').element as HTMLInputElement).checked).toBe(false)
    expect(wrapper.find('[data-agenda="assets"]').exists()).toBe(false)
    expect(wrapper.find('[data-count-key="custom_zero"]').exists()).toBe(false)
  })

  it('keeps source and resulting movement review counts distinct without inventing absent keys', () => {
    const wrapper = summary()
    expect(wrapper.get('[data-count-key="requires_review"]').text()).toBe('4')
    expect(wrapper.get('[data-count-key="requires_movement_review"]').text()).toBe('5')
    expect(wrapper.findAll('[data-count-key="requires_review"]')).toHaveLength(1)
    expect(wrapper.findAll('[data-count-key="requires_movement_review"]')).toHaveLength(1)
    const sourceOnly = mount(StereoNxSummary, { props: { steps: [{ key: 'source_summary', status: 'ok', messages: [], counts: { requires_review: 4 } }] } })
    expect(sourceOnly.get('[data-count-key="requires_review"]').text()).toBe('4')
    expect(sourceOnly.find('[data-count-key="requires_movement_review"]').exists()).toBe(false)

  })
})
