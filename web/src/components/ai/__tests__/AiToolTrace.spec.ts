import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

const push = vi.hoisted(() => vi.fn())
vi.mock('vue-router', () => ({ useRouter: () => ({ push }) }))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => (params ? `${key}:${JSON.stringify(params)}` : key) }),
}))

import AiToolTrace from '../AiToolTrace.vue'
import AiMarkdown from '../AiMarkdown.vue'

describe('AiToolTrace', () => {
  it('shows manual citations as links and the tools used in a collapsed list', () => {
    const w = mount(AiToolTrace, {
      props: {
        manualSources: [{ section_id: '52_Ucetni_denik#524-krok-za-krokem-rucni-zapis', chapter: '52. Účetní deník', title: '52.4 Ruční zápis', url: '/manual?ch=52_Ucetni_denik#524-krok-za-krokem-rucni-zapis' }],
        toolCalls: [
          { tool: 'read_manual_section', kind: 'manual', label: 'Čtení manuálu', detail: '52.4 Ruční zápis', status: 'ok' },
          { tool: 'list_invoices', kind: 'data', label: 'Faktury', detail: '{"overdue":true}', status: 'error' },
        ],
      },
    })
    const link = w.get('[data-test="ai-manual-source"]')
    expect(link.attributes('href')).toBe('/manual?ch=52_Ucetni_denik#524-krok-za-krokem-rucni-zapis')
    expect(link.attributes('target')).toBe('_blank')
    expect(link.text()).toContain('52.4 Ruční zápis')

    const details = w.get('[data-test="ai-tool-calls"]')
    expect(details.attributes('open')).toBeUndefined()
    expect(details.text()).toContain('accounting.ai_assistant.sources.tools:{"count":2}')
    expect(details.text()).toContain('accounting.ai_assistant.sources.errors:{"count":1}')
    expect(details.text()).toContain('{"overdue":true}')
  })

  it('renders nothing for an answer without tools', () => {
    const w = mount(AiToolTrace, { props: { toolCalls: [], manualSources: null } })
    expect(w.find('[data-test="ai-tool-trace"]').exists()).toBe(false)
  })
})

describe('AiMarkdown links', () => {
  it('opens app pages through the router and leaves the manual to the browser', async () => {
    push.mockClear()
    const stopNavigation = (e: Event) => e.preventDefault()
    document.addEventListener('click', stopNavigation)
    const w = mount(AiMarkdown, {
      props: { text: '[Deník](/accounting/journal) a [manuál](/manual?ch=52_Ucetni_denik#524-krok-za-krokem-rucni-zapis)' },
      attachTo: document.body,
    })
    const [app, manual] = w.findAll('a')
    await app.trigger('click')
    expect(push).toHaveBeenCalledWith('/accounting/journal')
    await manual.trigger('click')
    expect(push).toHaveBeenCalledTimes(1)
    w.unmount()
    document.removeEventListener('click', stopNavigation)
  })
})
