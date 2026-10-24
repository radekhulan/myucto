import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import StereoNxMessages from '../StereoNxMessages.vue'
import type { StereoNxProtocolMessage } from '../stereoNxMessageGroups'

const labels: Record<string, string> = {
  'stereo_nx.messages.causes.document_rounding_account_review': 'Rounding account needs review',
  'stereo_nx.messages.causes.document_item_account_ambiguous': 'Ambiguous document item account',
  'stereo_nx.messages.causes.document_items_reconstructed': 'Document items reconstructed',
  'stereo_nx.review_reasons.synthetic_reason': 'Known synthetic review reason',
  'stereo_nx.document_kind.issued': 'Issued invoice',
}
vi.mock('vue-i18n', () => ({ useI18n: () => ({
  t: (key: string, values?: Record<string, unknown>) => labels[key] ?? (values ? `${key}: ${Object.entries(values).map(([name, value]) => `${name}=${value}`).join(', ')}` : key),
  te: (key: string) => Object.hasOwn(labels, key),
}) }))

function message(code: string, text: string, level: StereoNxProtocolMessage['level'] = 'warning', context: Record<string, unknown> = {}): StereoNxProtocolMessage {
  return { code, text, level, context, step: 'source_summary' }
}
function component(messages: StereoNxProtocolMessage[]) {
  return mount(StereoNxMessages, { props: { messages } })
}
async function openGroup(wrapper: ReturnType<typeof component>, code: string) {
  const details = wrapper.get(`details[data-message-code="${code}"]`)
  ;(details.element as HTMLDetailsElement).open = true
  await details.trigger('toggle')
  return details
}

describe('StereoNxMessages', () => {
  it('shows severity counts, stable severity order and expanded errors while warning and info rows are unmounted', async () => {
    const wrapper = component([
      message('information', 'Informational detail', 'info'),
      message('document_rounding_account_review', 'Warning summary', 'warning', { summary: true, count: 8 }),
      message('document_rounding_account_review', 'Warning detail'),
      message('failure', 'Error detail', 'error'),
    ])
    expect(wrapper.findAll('details').map(group => group.attributes('data-message-level'))).toEqual(['error', 'warning', 'info'])
    expect(wrapper.findAll('[data-testid="stereo-message-group-level"]').map(badge => badge.text())).toEqual(['stereo_nx.level.error', 'stereo_nx.level.warning', 'stereo_nx.level.info'])
    expect(wrapper.get('[data-testid="stereo-messages-count-all"]').text()).toBe('10')
    expect(wrapper.get('[data-testid="stereo-messages-count-warning"]').text()).toBe('8')
    expect(wrapper.findAll('[data-testid="stereo-message-row"]').map(row => row.text())).toEqual(['Error detail'])
    expect(wrapper.get('details[data-message-code="document_rounding_account_review"] summary').text()).toContain('Rounding account needs review')
    await wrapper.get('[data-testid="stereo-messages-filter-info"]').trigger('click')
    expect(wrapper.findAll('details')).toHaveLength(1)
    expect(wrapper.findAll('[data-testid="stereo-message-row"]')).toHaveLength(0)
    expect(wrapper.get('[data-testid="stereo-messages-filter-info"]').attributes('aria-pressed')).toBe('true')
  })

  it('retains and progressively renders every detail, including exact duplicates, in batches of fifty', async () => {
    const messages = Array.from({ length: 121 }, (_, index) => message('bulk', `Detail ${index}`, 'warning', { document_no: `SYN-${index}` }))
    messages.push(messages[120]!)
    const wrapper = component(messages)
    expect(wrapper.findAll('[data-testid="stereo-message-row"]')).toHaveLength(0)
    const group = await openGroup(wrapper, 'bulk')
    expect(group.findAll('[data-testid="stereo-message-row"]')).toHaveLength(50)
    expect(group.get('[data-testid="stereo-message-group-count"]').text()).toBe('122')
    await group.get('[data-testid="stereo-messages-load-more"]').trigger('click')
    expect(group.findAll('[data-testid="stereo-message-row"]')).toHaveLength(100)
    await group.get('[data-testid="stereo-messages-load-more"]').trigger('click')
    expect(group.findAll('[data-testid="stereo-message-row"]')).toHaveLength(122)
    expect(group.findAll('[data-testid="stereo-message-row"]').filter(row => row.text().includes('Detail 120'))).toHaveLength(2)
    expect(group.find('[data-testid="stereo-messages-load-more"]').exists()).toBe(false)
    ;(group.element as HTMLDetailsElement).open = false
    await group.trigger('toggle')
    expect(wrapper.findAll('[data-testid="stereo-message-row"]')).toHaveLength(0)
  })

  it('searches all details by number or text beyond the initial batch and never exposes internal context', async () => {
    const messages = Array.from({ length: 110 }, (_, index) => message('bulk', `Synthetic detail ${index}`, 'warning', { document_no: `SYN-${index}`, source_key: 'secret-internal-key', kind: 'issued' }))
    messages[109] = message('bulk', 'České zaokrouhlení\nSpecific second line', 'warning', { document_no: 'SYN-LAST', source_key: 'secret-internal-key', kind: 'issued' })
    const wrapper = component(messages)
    await wrapper.get('[data-testid="stereo-messages-search"]').setValue('syn-last')
    const group = await openGroup(wrapper, 'bulk')
    expect(group.findAll('[data-testid="stereo-message-row"]')).toHaveLength(1)
    const row = group.get('[data-testid="stereo-message-row"]')
    expect(row.text()).toContain('SYN-LAST')
    expect(row.text()).toContain('Issued invoice')
    expect(row.get('p').classes()).toContain('whitespace-pre-line')
    await wrapper.get('[data-testid="stereo-messages-search"]').setValue('CESKE ZAOKROUHLENI')
    expect(wrapper.findAll('[data-testid="stereo-message-row"]')).toHaveLength(1)
    expect(wrapper.html()).not.toContain('secret-internal-key')
    await wrapper.get('[data-testid="stereo-messages-search"]').setValue('secret-internal-key')
    expect(wrapper.findAll('details')).toHaveLength(0)
    expect(wrapper.text()).toContain('stereo_nx.messages.no_results')
  })

  it('shows unknown summary titles and the available-detail limit without inventing document counts', async () => {
    const wrapper = component([
      message('unknown_code', 'A readable summary', 'warning', { summary: true, count: 12 }),
      message('unknown_code', 'One available detailed message', 'warning', { document_no: 'SYN-ONE' }),
      message('synthetic_reason', 'Review detail', 'info'),
    ])
    const group = await openGroup(wrapper, 'unknown_code')
    expect(group.get('summary').text()).toContain('A readable summary')
    expect(group.get('[data-testid="stereo-message-partial-details"]').text()).toBe('stereo_nx.messages.available_details: count=12, details=1, occurrences=1')
    expect(group.findAll('[data-testid="stereo-message-row"]')).toHaveLength(1)
    expect(wrapper.get('details[data-message-code="synthetic_reason"] summary').text()).toContain('Known synthetic review reason')
    expect(group.text()).not.toContain('unknown_code')
  })

  it('keeps all related details when searching by the translated cause title', async () => {
    const wrapper = component([
      message('document_rounding_account_review', 'Review summary', 'warning', { summary: true, count: 2 }),
      message('document_rounding_account_review', 'Specific entry one', 'warning', { document_no: 'SYN-ONE' }),
      message('document_rounding_account_review', 'Specific entry two', 'warning', { document_no: 'SYN-TWO' }),
    ])
    await wrapper.get('[data-testid="stereo-messages-search"]').setValue('ROUNDING ACCOUNT')
    const group = await openGroup(wrapper, 'document_rounding_account_review')
    expect(group.findAll('[data-testid="stereo-message-row"]')).toHaveLength(2)
    expect(group.text()).toContain('SYN-ONE')
    expect(group.text()).toContain('SYN-TWO')
  })

  it('counts aggregated detail occurrences without claiming that their detailed breakdown is missing', async () => {
    const wrapper = component([
      message('bank_currency_unresolved', 'A message covering seven source entries', 'warning', { count: 7 }),
      message('bank_currency_unresolved', 'One additional source entry'),
    ])
    const group = await openGroup(wrapper, 'bank_currency_unresolved')
    expect(group.get('[data-testid="stereo-message-group-count"]').text()).toBe('8')
    expect(wrapper.get('[data-testid="stereo-messages-count-warning"]').text()).toBe('8')
    expect(group.find('[data-testid="stereo-message-partial-details"]').exists()).toBe(false)
    expect(group.get('[data-testid="stereo-message-detail-occurrences"]').text()).toBe('stereo_nx.messages.detail_occurrences: count=7')
    expect(group.findAll('[data-testid="stereo-message-row"]')).toHaveLength(2)
    expect(group.text()).toContain('A message covering seven source entries')
  })

  it('renders server text as text, supports summary-only causes and does not render internal identifiers', async () => {
    const unsafe = '<img src=x onerror="alert(1)">'
    const wrapper = component([
      message('summary-only', unsafe, 'error', { summary: true, count: 4, source_key: 'private-source' }),
      message('detail', unsafe, 'error', { document_no: '<script>synthetic</script>', source_key: 'private-source' }),
    ])
    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.find('script').exists()).toBe(false)
    expect(wrapper.text()).toContain(unsafe)
    expect(wrapper.text()).toContain('<script>synthetic</script>')
    expect(wrapper.html()).not.toContain('private-source')
    expect(wrapper.get('details[data-message-code="summary-only"] [data-testid="stereo-message-partial-details"]').text()).toContain('details=0')
    expect(wrapper.findAll('[data-testid="stereo-message-row"]')).toHaveLength(1)
  })

  it('provides the full long title as a tooltip and preserves its entire detail after opening', async () => {
    const text = `Long\n\n  fallback message ${'😀'.repeat(180)}`
    const wrapper = component([message('long-unknown', text)])
    const title = wrapper.get('details summary [title]')
    expect(title.attributes('title')).toBe(`Long fallback message ${'😀'.repeat(180)}`)
    expect(wrapper.findAll('[data-testid="stereo-message-row"]')).toHaveLength(0)
    expect(wrapper.props('messages')[0]?.text).toBe(text)
    const group = await openGroup(wrapper, 'long-unknown')
    expect(group.get('[data-testid="stereo-message-row"] p').element.textContent).toBe(text)
  })
})
