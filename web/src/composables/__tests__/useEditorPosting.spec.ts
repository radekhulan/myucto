import { describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({
  auth: { isClientRole: false, saveAndPostEnabled: false, perms: new Set<string>(), readPerms: new Set<string>() },
}))

vi.mock('@/api/accounting', () => ({ accountingApi: { listPostingRules: vi.fn(), listChart: vi.fn() } }))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    get isClientRole() { return m.auth.isClientRole },
    get saveAndPostEnabled() { return m.auth.saveAndPostEnabled },
    canWrite: (key: string) => m.auth.perms.has(key),
    canRead: (key: string) => m.auth.readPerms.has(key) || m.auth.perms.has(key),
  }),
}))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplier: { accounting_mode: 'double_entry' } }) }))

import { useEditorPosting } from '../useEditorPosting'

describe('useEditorPosting: kdo vidí zaúčtování v editoru faktury', () => {
  it('jen čtení účetnictví nestačí', () => {
    m.auth.perms = new Set()
    m.auth.readPerms = new Set(['accounting'])
    expect(useEditorPosting().enabled.value).toBe(false)
  })

  it('s právem zaúčtovat se pole i náhled ukážou', () => {
    m.auth.perms = new Set(['accounting.journal.post'])
    expect(useEditorPosting().enabled.value).toBe(true)
  })

  it('tlačítko Uložit a zaúčtovat navíc potřebuje volbu instalace', () => {
    m.auth.perms = new Set(['accounting.journal.post'])
    m.auth.saveAndPostEnabled = false
    expect(useEditorPosting().canPost.value).toBe(false)
    m.auth.saveAndPostEnabled = true
    expect(useEditorPosting().canPost.value).toBe(true)
  })
})
