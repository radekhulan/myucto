import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { AiCredentialsResponse, AiProvider } from '@/api/integrations'

const m = vi.hoisted(() => ({
  getAiCredentials: vi.fn(),
  listOpenAiModels: vi.fn(),
  setAiCredentials: vi.fn(),
  updateSupplier: vi.fn(),
}))

vi.mock('@/api/integrations', () => ({
  integrationsApi: {
    getIdokladCreds: vi.fn().mockResolvedValue(null),
    getFakturoidCreds: vi.fn().mockResolvedValue(null),
    getAiCredentials: m.getAiCredentials,
    listOpenAiModels: m.listOpenAiModels,
    setAiCredentials: m.setAiCredentials,
  },
}))

vi.mock('@/api/settings', () => ({
  settingsApi: {
    getAiAssist: vi.fn().mockRejectedValue(new Error('not needed')),
    updateSupplier: m.updateSupplier,
  },
}))

vi.mock('vue-router', () => ({ useRoute: () => ({ query: { tab: 'ai' } }) }))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ error: vi.fn(), success: vi.fn(), warning: vi.fn(), info: vi.fn() }),
}))

vi.mock('@/composables/useSessionAwarePolling', () => ({ useSessionAwarePolling: vi.fn() }))

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ locale: { value: 'cs' }, t: (key: string, params?: Record<string, unknown>) => params ? key + JSON.stringify(params) : key }),
}))

import Integrations from '@/pages/admin/Integrations.vue'
import { useSupplierStore } from '@/stores/supplier'
import type { SupplierBrief } from '@/api/auth'
import { aiProviderBadge } from '@/pages/admin/aiProviderBadge'

function creds(active: AiProvider, configured: Partial<Record<AiProvider, boolean>>): AiCredentialsResponse {
  const info = (p: AiProvider) => ({
    configured: configured[p] ?? false,
    default_model: 'model-x',
    models: ['model-x'],
    data_region: 'us',
    eu_capable: p === 'openai' || p === 'azure_openai',
    residency_label: null,
    extractions_count: 0,
  })
  return {
    ai_provider: active,
    ai_data_region: 'us',
    ai_eu_residency_required: false,
    ai_extraction_notes: '',
    ai_effort: 'default',
    ai_efforts: ['default', 'fast', 'accurate'],
    ai_extraction_notes_max: 2000,
    providers: {
      anthropic: info('anthropic'),
      azure_openai: info('azure_openai'),
      openai: info('openai'),
      gemini: info('gemini'),
    },
  } as unknown as AiCredentialsResponse
}

async function mountPage() {
  const w = mount(Integrations)
  await flushPromises()
  return w
}

describe('aiProviderBadge', () => {
  it('ukazuje „aktivní" jen u zvoleného poskytovatele s klíčem', () => {
    expect(aiProviderBadge('anthropic', 'anthropic', true)).toBe('active')
    expect(aiProviderBadge('anthropic', 'anthropic', false)).toBe('no_key')
    expect(aiProviderBadge('openai', 'anthropic', true)).toBeNull()
    expect(aiProviderBadge('openai', 'anthropic', false)).toBeNull()
    expect(aiProviderBadge('anthropic', null, true)).toBeNull()
  })
})

describe('Externí integrace → AI extrakce', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    m.getAiCredentials.mockReset()
    m.listOpenAiModels.mockReset()
    m.setAiCredentials.mockReset()
    m.updateSupplier.mockReset().mockResolvedValue({})
  })

  it('firma bez klíče nevidí u Anthropicu odznak „aktivní"', async () => {
    m.getAiCredentials.mockResolvedValue(creds('anthropic', {}))
    const w = await mountPage()
    expect(w.find('[data-test="badge-active"]').exists()).toBe(false)
    const noKey = w.findAll('[data-test="badge-no-key"]')
    expect(noKey).toHaveLength(1)
    expect(noKey[0].element.closest('button')?.textContent).toContain('aiGateway.provider.anthropic')
  })

  it('odznak „aktivní" se objeví, až má zvolený poskytovatel klíč', async () => {
    m.getAiCredentials.mockResolvedValue(creds('anthropic', { anthropic: true, openai: true }))
    const w = await mountPage()
    const active = w.findAll('[data-test="badge-active"]')
    expect(active).toHaveLength(1)
    expect(active[0].element.closest('button')?.textContent).toContain('aiGateway.provider.anthropic')
    expect(w.find('[data-test="badge-no-key"]').exists()).toBe(false)
  })

  it('načte modely projektu OpenAI a uloží zvolený model s existujícím klíčem', async () => {
    m.getAiCredentials.mockResolvedValue(creds('openai', { openai: true }))
    m.listOpenAiModels.mockResolvedValue({ models: ['gpt-6.2-sol'] })
    m.setAiCredentials.mockResolvedValue({ saved: true, tested: false, test_ok: null })
    const w = await mountPage()
    const model = w.get('#ai-extraction-default-model')
    expect(model.find('option[value="gpt-6.2-sol"]').exists()).toBe(false)

    await w.findAll('button').find(b => b.text() === 'aiGateway.openai_models_load')!.trigger('click')
    await flushPromises()
    expect(m.listOpenAiModels).toHaveBeenCalledExactlyOnceWith(expect.any(AbortSignal))
    await model.setValue('gpt-6.2-sol')
    await w.findAll('button').find(b => b.text() === 'integrations.idoklad.save_and_test')!.trigger('click')
    await flushPromises()

    expect(m.setAiCredentials).toHaveBeenCalledExactlyOnceWith({
      provider: 'openai', default_model: 'gpt-6.2-sol', base_url: '',
    })
    w.unmount()
  })

  it('volba „jen do firem s nenastavenou AI" se ukáže až po zapnutí hromadného uložení', async () => {
    m.getAiCredentials.mockResolvedValue(creds('anthropic', {}))
    const w = await mountPage()
    const bulk = w.find('[data-test="bulk-apply"]')
    expect((bulk.element as HTMLInputElement).checked).toBe(false)
    expect(w.find('[data-test="bulk-only-unconfigured"]').exists()).toBe(false)

    await bulk.setValue(true)
    const only = w.find('[data-test="bulk-only-unconfigured"]')
    expect(only.exists()).toBe(true)
    expect((only.element as HTMLInputElement).checked).toBe(true)
  })

  it('hromadné uložení posílá příznaky serveru a vypíše výsledek', async () => {
    m.getAiCredentials.mockResolvedValue(creds('anthropic', {}))
    m.setAiCredentials.mockResolvedValue({
      saved: true, test_ok: true, test_error: null, model: 'model-x',
      bulk: { applied: true, updated: [{ id: 2, name: 'Firma B' }], skipped_configured: [{ id: 3, name: 'Firma C' }], skipped_forbidden: [], skipped_constraint: [] },
    })
    const w = await mountPage()
    await w.find('input[type="password"]').setValue('sk-ant-synthetic')
    await w.find('[data-test="bulk-apply"]').setValue(true)
    const save = w.findAll('button').find(b => b.text().includes('integrations.idoklad.save_and_test'))!
    await save.trigger('click')
    await flushPromises()

    expect(m.updateSupplier).toHaveBeenCalledWith({ ai_provider: 'anthropic', ai_data_region: 'us', ai_eu_residency_required: false })
    expect(m.setAiCredentials).toHaveBeenCalledWith(expect.objectContaining({
      provider: 'anthropic', api_key: 'sk-ant-synthetic', apply_to_all_companies: true, only_unconfigured: true,
    }))
    const result = w.find('[data-test="bulk-result"]')
    expect(result.text()).toContain('Firma B')
    expect(result.text()).toContain('Firma C')
  })

  it('bez zaškrtnutí se do dalších firem nic neposílá', async () => {
    m.getAiCredentials.mockResolvedValue(creds('anthropic', {}))
    m.setAiCredentials.mockResolvedValue({ saved: true, test_ok: true, test_error: null, model: 'model-x' })
    const w = await mountPage()
    await w.find('input[type="password"]').setValue('sk-ant-synthetic')
    const save = w.findAll('button').find(b => b.text().includes('integrations.idoklad.save_and_test'))!
    await save.trigger('click')
    await flushPromises()

    const payload = m.setAiCredentials.mock.calls[0][0]
    expect(payload).not.toHaveProperty('apply_to_all_companies')
    expect(payload).not.toHaveProperty('only_unconfigured')
    expect(w.find('[data-test="bulk-result"]').exists()).toBe(false)
  })
})


describe('povolení klientského vytěžování', () => {
  it('načte uložené povolení i u firmy bez aktivního klíče', async () => {
    setActivePinia(createPinia())
    m.getAiCredentials.mockResolvedValue({ ...creds('anthropic', {}), client_ai_extraction_enabled: true })
    const w = await mountPage()
    expect((w.get('[data-test="client-ai-extraction"]').element as HTMLInputElement).checked).toBe(true)
    expect(w.get('[data-test="save-client-ai-extraction"]').attributes('disabled')).toBeDefined()
    w.unmount()
  })

  it('uloží přepínač samostatně bez změny vybraného poskytovatele', async () => {
    setActivePinia(createPinia())
    m.getAiCredentials.mockResolvedValue(creds('anthropic', { anthropic: true }))
    m.updateSupplier.mockReset().mockResolvedValue({ client_ai_extraction_enabled: true })
    const w = await mountPage()
    expect((w.get('[data-test="client-ai-extraction"]').element as HTMLInputElement).checked).toBe(false)
    expect(w.get('[data-test="save-client-ai-extraction"]').attributes('disabled')).toBeDefined()
    await w.get('[data-test="client-ai-extraction"]').setValue(true)
    await w.get('[data-test="save-client-ai-extraction"]').trigger('click')
    await flushPromises()
    expect(m.updateSupplier).toHaveBeenCalledExactlyOnceWith({ client_ai_extraction_enabled: true, client_ai_extraction_monthly_limit: 100 })
    w.unmount()
  })
})


describe('měsíční limit klientského vytěžování', () => {
  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia())
    m.updateSupplier.mockReset().mockResolvedValue({})
    m.getAiCredentials.mockReset().mockResolvedValue({
      ...creds('anthropic', { anthropic: true }), client_ai_extraction_enabled: true,
      client_ai_extraction_usage: { period: '2026-10', limit: 25, used: 7, remaining: 18 },
    })
  })

  it('zobrazí čerpání a uloží limit včetně nuly', async () => {
    const w = await mountPage()
    expect((w.get('[data-test="client-ai-monthly-limit"]').element as HTMLInputElement).value).toBe('25')
    expect(w.get('[data-test="client-ai-usage"]').text()).toContain('\"used\":7')
    expect(w.get('[data-test="client-ai-usage"]').text()).toContain('\"remaining\":18')
    await w.get('[data-test="client-ai-monthly-limit"]').setValue('0')
    await w.get('[data-test="save-client-ai-extraction"]').trigger('click')
    await flushPromises()
    expect(m.updateSupplier).toHaveBeenCalledExactlyOnceWith({ client_ai_extraction_enabled: true, client_ai_extraction_monthly_limit: 0 })
    w.unmount()
  })

  it('po přepnutí firmy ignoruje opožděné čerpání původní firmy', async () => {
    useSupplierStore().setAvailable([{ id: 1 }, { id: 2 }] as SupplierBrief[], 1)
    let finish!: (value: AiCredentialsResponse) => void
    m.getAiCredentials.mockImplementationOnce(() => new Promise(resolve => { finish = resolve }))
    const w = await mountPage()
    useSupplierStore().setSupplier(2)
    await flushPromises()
    finish({ ...creds('anthropic', {}), client_ai_extraction_usage: { period: '2026-10', limit: 1, used: 1, remaining: 0 } })
    await flushPromises()
    expect((w.get('[data-test="client-ai-monthly-limit"]').element as HTMLInputElement).value).toBe('25')
    expect(w.get('[data-test="client-ai-usage"]').text()).toContain('"remaining":18')
    w.unmount()
  })

  it.each(['-1', '100001', '1.5', ''])('neuloží neplatný limit %s', async value => {
    const w = await mountPage()
    await w.get('[data-test="client-ai-monthly-limit"]').setValue(value)
    expect(w.get('[data-test="save-client-ai-extraction"]').attributes('disabled')).toBeDefined()
    expect(m.updateSupplier).not.toHaveBeenCalled()
    w.unmount()
  })
})
