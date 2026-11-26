import { describe, it, expect, vi, beforeEach } from 'vitest'

const { post, get } = vi.hoisted(() => ({ post: vi.fn(), get: vi.fn() }))
vi.mock('../client', () => ({ api: { post, get } }))

import { integrationsApi } from '../integrations'

describe('integrationsApi.listOllamaModels', () => {
  beforeEach(() => {
    post.mockReset().mockResolvedValue({ data: { models: [] } })
    get.mockReset().mockResolvedValue({ data: { models: [] } })
  })

  // Dotaz na adresu Ollamy jde ze serveru ven — GET by spustil i podvržený odkaz (SameSite=Lax bez CSRF).
  it('posílá adresu v těle POST requestu, ne v GET query', async () => {
    await integrationsApi.listOllamaModels({ base_url: 'http://192.168.1.50:11434' })

    expect(get).not.toHaveBeenCalled()
    expect(post).toHaveBeenCalledWith('/admin/imports/ai/ollama/models', { base_url: 'http://192.168.1.50:11434' })
  })
})
