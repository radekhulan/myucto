import { beforeEach, describe, expect, it, vi } from 'vitest'

const { post } = vi.hoisted(() => ({ post: vi.fn() }))
vi.mock('../client', () => ({ api: { post } }))

import { settingsApi } from '../settings'

describe('certificate vault upload', () => {
  beforeEach(() => post.mockReset())

  it('sends the file password apart from the account password used for step-up', async () => {
    post.mockResolvedValueOnce({ data: { id: 1 } })

    await settingsApi.uploadCertificate({
      file: new File(['synthetic'], 'synteticky.p12'),
      label: 'Syntetický certifikát',
      password: 'heslo-souboru',
      proof: { password: 'heslo-uctu' },
      shareWithOtherSuppliers: false,
      shareOnlyWithoutValid: false,
    })

    const data = post.mock.calls[0]![1] as FormData
    expect(data.getAll('pfx_password')).toEqual(['heslo-souboru'])
    expect(data.getAll('password')).toEqual(['heslo-uctu'])
  })

  it('náhrada starého certifikátu má přednost před prostým sdílením', async () => {
    post.mockResolvedValueOnce({ data: { id: 2 } })

    await settingsApi.uploadCertificate({
      file: new File(['synthetic'], 'synteticky.p12'),
      label: 'Syntetický certifikát',
      password: 'heslo-souboru',
      proof: { password: 'heslo-uctu' },
      shareWithOtherSuppliers: true,
      shareOnlyWithoutValid: true,
      replaceCredentialId: 40,
    })

    const data = post.mock.calls.at(-1)![1] as FormData
    expect(data.getAll('replace_credential_id')).toEqual(['40'])
    expect(data.getAll('share_with_other_suppliers')).toEqual([])
  })
})
