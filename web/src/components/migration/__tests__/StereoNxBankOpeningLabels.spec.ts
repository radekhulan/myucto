import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import cs from '@/i18n/cs.json'
import en from '@/i18n/en.json'
import StereoNxSummary from '../StereoNxSummary.vue'
import StereoNxMessages from '../StereoNxMessages.vue'

describe('Stereo NX bank opening labels', () => {
  it.each([
    { locale: 'cs', opening: 'Počáteční zůstatky banky', movement: 'Bankovní pohyby', cause: 'Počáteční zůstatek banky převzatý bez peněžního pohybu' },
    { locale: 'en', opening: 'Opening bank balances', movement: 'Bank transactions', cause: 'Opening bank balance transferred without a cash movement' },
  ])('distinguishes the opening balance count and information group in $locale', ({ locale, opening, movement, cause }) => {
    const i18n = createI18n({ legacy: false, locale, fallbackLocale: false, messages: { cs, en } })
    const counts = mount(StereoNxSummary, {
      global: { plugins: [i18n] },
      props: { steps: [{ key: 'source_summary', status: 'ok', counts: { bank_openings: 1, bank_transactions: 2 }, messages: [] }] },
    })
    expect(counts.get('[data-count-key="bank_openings"]').findAll('td').map(cell => cell.text())).toEqual([i18n.global.t('stereo_nx.steps.source_summary'), opening, '1'])
    expect(counts.get('[data-count-key="bank_transactions"]').text()).toContain(movement)
    const messages = mount(StereoNxMessages, {
      global: { plugins: [i18n] },
      props: { messages: [{ code: 'bank_opening_balance', level: 'info', text: 'Synthetic opening balance detail', context: {}, step: 'source_summary' }] },
    })
    expect(messages.get('details[data-message-code="bank_opening_balance"]').attributes('data-message-level')).toBe('info')
    expect(messages.get('details summary').text()).toContain(cause)
    expect(messages.get('[data-testid="stereo-messages-count-info"]').text()).toBe('1')
    expect(messages.get('[data-testid="stereo-messages-count-warning"]').text()).toBe('0')
  })

  it.each([
    { locale: 'cs', count: 'Počáteční stavy pokladny', cause: 'Počáteční stav pokladny zastoupený v otevíracím zápisu' },
    { locale: 'en', count: 'Opening cash balances', cause: 'Opening cash balance represented by the opening journal entry' },
  ])('labels the separate cash opening count as information in $locale', ({ locale, count, cause }) => {
    const i18n = createI18n({ legacy: false, locale, fallbackLocale: false, messages: { cs, en } })
    const summary = mount(StereoNxSummary, { global: { plugins: [i18n] }, props: {
      steps: [{ key: 'source_summary', status: 'ok', counts: { cash_openings: 1, cash_transactions: 2 }, messages: [] }],
    } })
    expect(summary.get('[data-count-key="cash_openings"]').text()).toContain(count)
    expect(summary.get('[data-count-key="cash_transactions"]').findAll('td').at(-1)?.text()).toBe('2')
    const messages = mount(StereoNxMessages, { global: { plugins: [i18n] }, props: { messages: [{
      code: 'cash_opening_balance', level: 'info', text: 'Synthetic cash opening detail', context: {}, step: 'source_summary',
    }] } })
    expect(messages.get('details summary').text()).toContain(cause)
    expect(messages.get('[data-testid="stereo-messages-count-info"]').text()).toBe('1')
    expect(messages.get('[data-testid="stereo-messages-count-warning"]').text()).toBe('0')
  })

  it.each([
    { locale: 'cs', cause: 'Počáteční stav banky koliduje s jiným zůstatkem' },
    { locale: 'en', cause: 'Opening bank balance conflicts with another balance' },
  ])('names conflicting bank balances as errors in $locale', ({ locale, cause }) => {
    const i18n = createI18n({ legacy: false, locale, fallbackLocale: false, messages: { cs, en } })
    const messages = mount(StereoNxMessages, { global: { plugins: [i18n] }, props: { messages: [{
      code: 'bank_opening_conflict', level: 'error', text: 'Synthetic conflicting balance', context: {}, step: 'source_summary',
    }] } })
    expect(messages.get('details summary').text()).toContain(cause)
    expect(messages.get('[data-testid="stereo-messages-count-error"]').text()).toBe('1')
  })
})
