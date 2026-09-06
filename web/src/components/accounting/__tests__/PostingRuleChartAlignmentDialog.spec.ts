import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({ postingRuleChartAlignment: vi.fn(), applyPostingRuleChartAlignment: vi.fn() }))
vi.mock('@/api/accounting', () => ({ accountingApi: m }))
vi.mock('@/api/errors', () => ({ apiErrorMessage: () => 'error' }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
import PostingRuleChartAlignmentDialog from '../PostingRuleChartAlignmentDialog.vue'

function side(over: Record<string, unknown> = {}) {
  return { code: null, status: 'ok', effective_code: null, candidates: [], suggested_code: null, ...over }
}

const PREVIEW = {
  redirect_enabled: true,
  counts: { ok: 1, auto: 1, suggest: 1, context: 0, missing: 0 },
  rules: [
    {
      rule_key: 'invoice.services.received', description: 'Služby', is_override: false, status: 'suggest',
      debit: side({
        code: '518', status: 'suggest', effective_code: '518', suggested_code: '518.200',
        candidates: [
          { account_code: '518.100', name: 'Základní', is_deductible: true, is_dotted: true },
          { account_code: '518.200', name: 'IT', is_deductible: true, is_dotted: true },
        ],
      }),
      credit: side({ code: '321', status: 'ok', effective_code: '321' }),
    },
    {
      rule_key: 'invoice.goods.issued', description: 'Zboží', is_override: false, status: 'auto',
      debit: side({ code: '311', status: 'auto', effective_code: '311.100' }),
      credit: side({ code: '604', status: 'ok', effective_code: '604' }),
    },
    {
      rule_key: 'cash.revenue', description: 'Tržba', is_override: true, status: 'ok',
      debit: side({ code: '211.100', status: 'ok', effective_code: '211.100' }),
      credit: side({ code: '602.100', status: 'ok', effective_code: '602.100' }),
    },
  ],
}

async function openDialog() {
  const wrapper = mount(PostingRuleChartAlignmentDialog, { props: { modelValue: false } })
  await wrapper.setProps({ modelValue: true })
  await flushPromises()
  return wrapper
}

describe('PostingRuleChartAlignmentDialog', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.postingRuleChartAlignment.mockResolvedValue(structuredClone(PREVIEW))
    m.applyPostingRuleChartAlignment.mockResolvedValue({ applied: ['invoice.services.received'], skipped: [] })
  })

  it('ukáže ve výchozím stavu jen řádky k řešení a po přepnutí všechny', async () => {
    const wrapper = await openDialog()

    expect(wrapper.text()).toContain('invoice.services.received')
    // `auto` a `ok` si uživatel vyžádá; ve výchozím pohledu by jen zakryly práci.
    expect(wrapper.text()).not.toContain('invoice.goods.issued')

    await wrapper.findAll('input[type="checkbox"]')[0].setValue(false)
    await flushPromises()
    expect(wrapper.text()).toContain('invoice.goods.issued')
    expect(wrapper.text()).toContain('cash.revenue')
  })

  it('odešle jen zaškrtnuté řádky se změnou a druhou stranu nechá být', async () => {
    const wrapper = await openDialog()

    await wrapper.findAll('button').at(-1)!.trigger('click')
    await flushPromises()

    expect(m.applyPostingRuleChartAlignment).toHaveBeenCalledWith([
      { rule_key: 'invoice.services.received', debit_account_code: '518.200', credit_account_code: null },
    ])
  })

  it('nezapíše nic, když uživatel volbu odškrtne', async () => {
    const wrapper = await openDialog()

    // Druhý checkbox je řádek pravidla (první přepíná filtr).
    await wrapper.findAll('input[type="checkbox"]')[1].setValue(false)
    await wrapper.findAll('button').at(-1)!.trigger('click')
    await flushPromises()

    expect(m.applyPostingRuleChartAlignment).not.toHaveBeenCalled()
  })

  it('řádek, který dořeší engine, se zapsat nedá ani po zobrazení všech', async () => {
    const wrapper = await openDialog()
    await wrapper.findAll('input[type="checkbox"]')[0].setValue(false)
    await flushPromises()

    // Checkboxy: filtr + jediné pravidlo se stavem `suggest`. Řádky `auto`/`ok` žádný nemají.
    expect(wrapper.findAll('input[type="checkbox"]')).toHaveLength(2)
  })
})
