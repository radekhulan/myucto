import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { J6DraftEnvelope, J6LegalProfileStatus } from '@/api/nonprofitTaxJ6Types'
import J6AssessmentWorkspace from '../J6AssessmentWorkspace.vue'

const api = vi.hoisted(() => ({
  periodSummary: vi.fn(), history: vi.fn(), currentDraft: vi.fn(), draftSource: vi.fn(),
  openDraft: vi.fn(), resetDraft: vi.fn(), pinLegalProfile: vi.fn(), preview: vi.fn(),
}))
vi.mock('@/api/nonprofitTaxJ6', () => ({ nonprofitTaxJ6Api: api }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => params ? `${key} ${JSON.stringify(params)}` : key,
  te: (key: string) => key === 'nonprofitTaxJ6.legalProfile.errors.nonprofit_dppo_legal_profile_invalid', locale: { value: 'cs' } }) }))

const RouterLink = { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' }
// ActionBar jen vypíše viditelné akce jako tlačítka, aby šlo ověřit jejich stav.
const ActionBar = {
  props: ['actions'],
  template: '<div><button v-for="a in actions.filter((x) => x.show !== false)" :key="a.key" type="button" :data-action="a.key" :disabled="a.disabled" @click="a.run()">{{ a.label }}</button></div>',
}

const period = { id: 7, fiscal_year: 2026, starts_on: '2026-01-01', ends_on: '2026-12-31' }
const current = { id: 31, revision_no: 2, kind: 'narrow_vpp' as const, entity_type: 'association' }
function status(over: Partial<J6LegalProfileStatus>): J6LegalProfileStatus {
  return { state: null, current: null, pinned: null, pinnable: false, ...over }
}
function envelope(legal: J6LegalProfileStatus, kind: 'narrow_vpp' | 'broad_vpp' = 'narrow_vpp', revision = 3): J6DraftEnvelope {
  return {
    draft: { id: 11, supplier_id: 1, period_id: 7, revision_no: revision, source_manifest_id: 5, source_input_hash: 'a'.repeat(64),
      header: { profile: { kind, evidence: 'Synthetic statutes' }, ...(legal.pinned ? { legal_profile: { ...legal.pinned, record_hash: 'b'.repeat(64) } } : {}) },
      created_by: 1, created_at: '2026-11-24', updated_at: '2026-11-24' },
    capabilities: { can_edit: true, can_preview: true, can_confirm: true, source_complete: true, reason_code: null },
    legal_profile: legal,
  }
}

function render(canManage = true) {
  return mount(J6AssessmentWorkspace, {
    props: { supplierId: 1, periodId: 7, canManage, reloadKey: 0 },
    global: { stubs: { RouterLink, ActionBar, J6SourceFindings: true } },
  })
}

beforeEach(() => {
  vi.resetAllMocks()
  api.periodSummary.mockResolvedValue({ source: { header: { period, ruleset: 'r' }, input_hash: 'h', row_count: 0, finding_count: 0 }, latest: null })
  api.history.mockResolvedValue({ head: { assessment_id: null, revision_no: 0, status: null }, rows: [], after: null })
  api.draftSource.mockResolvedValue({ draft: {}, rows: [], findings: [], after: null })
})

describe('J6 workspace and the confirmed CIT legal profile', () => {
  it('locks the posture to the confirmed profile and opens the draft without sending it', async () => {
    api.currentDraft.mockResolvedValue({ draft: null, capabilities: null, legal_profile: status({ current }) })
    api.openDraft.mockResolvedValue(envelope(status({ state: 'pinned_current', current, pinned: { id: 31, revision_no: 2 } })))
    const w = render()
    await flushPromises()

    const select = w.get('[data-test=profile-kind]')
    expect((select.element as HTMLSelectElement).value).toBe('narrow_vpp')
    expect(select.attributes('disabled')).toBeDefined()
    expect(w.get('[data-test=profile-evidence]').attributes('disabled')).toBeDefined()
    expect(w.get('[data-test=legal-profile-status]').text()).toContain('nonprofitTaxJ6.legalProfile.adopted {"revision":2}')
    expect(w.get('[data-test=legal-profile-link]').attributes('data-to')).toBe(JSON.stringify({ name: 'accounting-nonprofit-dppo', query: { period: '7' } }))
    expect(w.get('[data-action=open]').attributes('disabled')).toBeUndefined()

    await w.get('[data-action=open]').trigger('click')
    await flushPromises()
    expect(api.openDraft).toHaveBeenCalledWith(1, 7, {})
    expect(w.find('[data-test=legal-profile-pin]').exists()).toBe(false)
  })

  it('offers pinning the current revision of the same posture and keeps the decisions', async () => {
    const outdated = status({ state: 'pinned_same_kind_outdated', current, pinned: { id: 30, revision_no: 1 }, pinnable: true })
    api.currentDraft.mockResolvedValue(envelope(outdated))
    api.pinLegalProfile.mockResolvedValue(envelope(status({ state: 'pinned_current', current, pinned: { id: 31, revision_no: 2 } }), 'narrow_vpp', 4))
    const w = render()
    await flushPromises()

    expect(w.get('[data-test=legal-profile-status]').text()).toContain('nonprofitTaxJ6.legalProfile.outdated {"pinned":1,"current":2}')
    await w.get('[data-test=legal-profile-pin]').trigger('click')
    await flushPromises()
    expect(api.pinLegalProfile).toHaveBeenCalledWith(1, 7, 11, { expected_revision: 3 })
    expect(api.resetDraft).not.toHaveBeenCalled()
    expect(w.get('[data-test=legal-profile-status]').text()).toContain('nonprofitTaxJ6.legalProfile.adopted {"revision":2}')
    expect(w.find('[data-test=legal-profile-pin]').exists()).toBe(false)
  })

  it('warns about a different posture, blocks the preview and resets without sending a posture', async () => {
    const conflict = status({ state: 'conflict', current, pinned: null })
    api.currentDraft.mockResolvedValue(envelope(conflict, 'broad_vpp'))
    api.resetDraft.mockResolvedValue(envelope(status({ state: 'pinned_current', current, pinned: { id: 31, revision_no: 2 } }), 'narrow_vpp', 4))
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    const w = render()
    await flushPromises()

    const box = w.get('[data-test=legal-profile-status]')
    expect(box.text()).toContain('nonprofitTaxJ6.legalProfile.conflictTitle')
    expect(box.text()).toContain('"draft":"nonprofitTaxJ6.profiles.broad_vpp","profile":"nonprofitTaxJ6.profiles.narrow_vpp"')
    expect(w.get('[data-action=preview]').attributes('disabled')).toBeDefined()
    expect(w.find('[data-test=legal-profile-pin]').exists()).toBe(false)

    await w.get('[data-test=legal-profile-reset]').trigger('click')
    await flushPromises()
    expect(api.resetDraft).toHaveBeenCalledWith(1, 7, 11, { expected_revision: 3 })
    expect((w.get('[data-test=profile-kind]').element as HTMLSelectElement).value).toBe('narrow_vpp')
    expect(w.get('[data-action=preview]').attributes('disabled')).toBeUndefined()
  })

  it('keeps the manual posture without a profile and refreshes after the profile is confirmed elsewhere', async () => {
    api.currentDraft.mockResolvedValueOnce({ draft: null, capabilities: null, legal_profile: status({}) })
    const w = render()
    await flushPromises()

    expect(w.get('[data-test=profile-kind]').attributes('disabled')).toBeUndefined()
    expect(w.get('[data-test=legal-profile-status]').text()).toContain('nonprofitTaxJ6.legalProfile.none')
    expect(w.get('[data-test=legal-profile-link]').text()).toContain('nonprofitTaxJ6.legalProfile.confirm')
    expect(w.get('[data-action=open]').attributes('disabled')).toBeDefined()

    // Návrat do záložky v KeepAlive zvýší reloadKey; profil mezitím potvrzený jinde se převezme.
    api.currentDraft.mockResolvedValueOnce({ draft: null, capabilities: null, legal_profile: status({ current }) })
    await w.setProps({ reloadKey: 1 })
    await flushPromises()
    expect(w.get('[data-test=profile-kind]').attributes('disabled')).toBeDefined()
    expect(w.get('[data-action=open]').attributes('disabled')).toBeUndefined()
  })

  it('does not show the legal profile box to read-only users', async () => {
    const w = render(false)
    await flushPromises()
    expect(api.currentDraft).not.toHaveBeenCalled()
    expect(w.find('[data-test=legal-profile-status]').exists()).toBe(false)
  })

  it('opens a documented manual draft with the locked profile by sending only the method', async () => {
    api.currentDraft.mockResolvedValue({ draft: null, capabilities: null, legal_profile: status({ current }) })
    api.openDraft.mockResolvedValue(envelope(status({ state: 'pinned_current', current, pinned: { id: 31, revision_no: 2 } })))
    const w = render()
    await flushPromises()
    await w.get('input[type=checkbox]').setValue(true)
    expect((w.get('[data-test=profile-kind]').element as HTMLSelectElement).value).toBe('documented')
    expect((w.get('[data-test=entity-kind]').element as HTMLInputElement).value).toBe('nonprofitTaxJ6.profiles.narrow_vpp')
    const areas = w.findAll('textarea').filter(a => a.attributes('data-test') !== 'profile-evidence')
    await areas[0]!.setValue('Synthetic method basis')
    await areas[1]!.setValue('Synthetic method evidence')
    await w.get('[data-action=open]').trigger('click')
    await flushPromises()
    expect(api.openDraft).toHaveBeenCalledWith(1, 7, { method: { mode: 'documented_manual', valid_from: '2026-01-01', valid_to: '2026-12-31',
      legal_basis: 'Synthetic method basis', evidence: 'Synthetic method evidence' } })
  })

  it('does not offer pinning a manual draft that does not assert the profile posture', async () => {
    const manual = envelope(status({ state: 'manual', current, pinnable: false }))
    manual.draft.header = { profile: { kind: 'documented', evidence: 'Synthetic' }, method: { mode: 'documented_manual', valid_from: '2026-01-01', valid_to: '2026-12-31', legal_basis: 'b', evidence: 'e' } }
    api.currentDraft.mockResolvedValue(manual)
    const w = render()
    await flushPromises()
    expect(w.get('[data-test=legal-profile-status]').text()).toContain('nonprofitTaxJ6.legalProfile.unverified')
    expect(w.find('[data-test=legal-profile-pin]').exists()).toBe(false)
  })

  it('explains an SVJ module conflict without a profile and resets only after choosing the SVJ posture', async () => {
    api.currentDraft.mockResolvedValue(envelope(status({ state: 'conflict', current: null })))
    api.resetDraft.mockResolvedValue(envelope(status({ state: 'none' }), 'narrow_vpp', 4))
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    const w = render()
    await flushPromises()
    expect(w.get('[data-test=legal-profile-status]').text()).toContain('nonprofitTaxJ6.legalProfile.svjModule')
    expect(w.get('[data-test=legal-profile-status]').text()).not.toContain('nonprofitTaxJ6.legalProfile.conflict ')
    expect(w.get('[data-test=legal-profile-reset]').attributes('disabled')).toBeDefined()
    await w.get('[data-test=profile-kind]').setValue('svj')
    expect(w.get('[data-test=legal-profile-reset]').attributes('disabled')).toBeUndefined()
    await w.get('[data-test=legal-profile-reset]').trigger('click')
    await flushPromises()
    expect(api.resetDraft).toHaveBeenCalledWith(1, 7, 11, { expected_revision: 3, profile: { kind: 'svj', evidence: 'Synthetic statutes' } })
  })

  it('reloads the legal profile when the server reports a posture mismatch', async () => {
    api.currentDraft.mockResolvedValueOnce({ draft: null, capabilities: null, legal_profile: status({}) })
      .mockResolvedValueOnce({ draft: null, capabilities: null, legal_profile: status({ current }) })
    api.openDraft.mockRejectedValue({ response: { status: 422, data: { error: { code: 'nonprofit_tax_legal_profile_mismatch', message: 'x' } } } })
    const w = render()
    await flushPromises()
    await w.get('[data-test=profile-kind]').setValue('broad_vpp')
    await w.get('[data-test=profile-evidence]').setValue('Synthetic')
    await w.get('[data-action=open]').trigger('click')
    await flushPromises()
    expect(w.text()).toContain('nonprofitTaxJ6.errors.nonprofit_tax_legal_profile_mismatch')
    expect(api.currentDraft).toHaveBeenCalledTimes(2)
    expect(w.get('[data-test=profile-kind]').attributes('disabled')).toBeDefined()
    expect((w.get('[data-test=profile-kind]').element as HTMLSelectElement).value).toBe('narrow_vpp')
  })

  it('keeps opening disabled while the legal profile state is unknown', async () => {
    api.currentDraft.mockRejectedValue({ response: { status: 503, data: { error: { code: 'unavailable', message: 'down' } } } })
    const w = render()
    await flushPromises()
    expect(w.get('[role=alert]').text()).toContain('down')
    expect(w.get('[data-action=open]').attributes('disabled')).toBeDefined()
  })

  it('shows the profile revision of the latest confirmed assessment and an unavailable profile', async () => {
    api.periodSummary.mockResolvedValue({ source: { header: { period, ruleset: 'r' }, input_hash: 'h', row_count: 0, finding_count: 0 },
      latest: { id: 9, revision_no: 1, status: 'confirmed', summary: null, legal_profile: { id: 31, revision_no: 2, record_hash: 'c'.repeat(64) } } })
    api.currentDraft.mockResolvedValue(envelope(status({ state: 'unavailable', error: { code: 'nonprofit_dppo_legal_profile_invalid', message: 'Synthetic invalid.' } })))
    const w = render()
    await flushPromises()
    expect(w.get('[data-test=latest-legal-profile]').text()).toContain('nonprofitTaxJ6.legalProfile.latest {"revision":2}')
    // Známý kód se přeloží (en nedostane českou serverovou hlášku); akce, které server odmítne, jsou vypnuté.
    expect(w.get('[data-test=legal-profile-error]').text()).toContain('nonprofitTaxJ6.legalProfile.errors.nonprofit_dppo_legal_profile_invalid')
    expect(w.get('[data-test=legal-profile-error]').text()).not.toContain('Synthetic invalid.')
    expect(w.get('[data-action=preview]').attributes('disabled')).toBeDefined()
    expect(w.get('[data-action=reset]').attributes('disabled')).toBeDefined()
  })

  it('disables opening a draft while the legal profile cannot be verified and shows an unknown reason as sent', async () => {
    api.currentDraft.mockResolvedValue({ draft: null, capabilities: null, legal_profile: status({ current, error: { code: 'synthetic_other', message: 'Synthetic reason.' } }) })
    const w = render()
    await flushPromises()
    expect(w.get('[data-test=legal-profile-error]').text()).toContain('Synthetic reason.')
    expect(w.get('[data-action=open]').attributes('disabled')).toBeDefined()
  })

  it('offers the reset in the action bar only when it fixes an SVJ module conflict', async () => {
    api.currentDraft.mockResolvedValue(envelope(status({ state: 'conflict', current: null })))
    const w = render()
    await flushPromises()
    expect(w.get('[data-action=reset]').attributes('disabled')).toBeDefined()
    await w.get('[data-test=profile-kind]').setValue('svj')
    expect(w.get('[data-action=reset]').attributes('disabled')).toBeUndefined()
  })

  it('shows the reset target in the locked posture of a conflicting draft regardless of the previous method state', async () => {
    const conflict = () => envelope(status({ state: 'conflict', current }), 'broad_vpp')
    api.currentDraft.mockResolvedValue(conflict())
    const w = render()
    await flushPromises()
    expect((w.get('[data-test=profile-kind]').element as HTMLSelectElement).value).toBe('narrow_vpp')
    expect((w.get('[data-test=profile-evidence]').element as HTMLTextAreaElement).value).toBe('')
    // Přepnutí metody tam a zpět ani nové načtení téhož konceptu výběr nezmění.
    await w.get('input[type=checkbox]').setValue(true)
    await w.get('input[type=checkbox]').setValue(false)
    await w.setProps({ reloadKey: 1 })
    await flushPromises()
    expect((w.get('[data-test=profile-kind]').element as HTMLSelectElement).value).toBe('narrow_vpp')
  })

  it('locks the posture of an existing draft after a mismatch when the profile was confirmed elsewhere', async () => {
    api.currentDraft.mockResolvedValueOnce(envelope(status({ state: 'none' })))
      .mockResolvedValueOnce(envelope(status({ state: 'conflict', current })))
    api.resetDraft.mockRejectedValue({ response: { status: 422, data: { error: { code: 'nonprofit_tax_legal_profile_mismatch', message: 'x' } } } })
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    const w = render()
    await flushPromises()
    await w.get('[data-test=profile-kind]').setValue('broad_vpp')
    await w.get('[data-action=reset]').trigger('click')
    await flushPromises()
    expect(w.get('[data-test=profile-kind]').attributes('disabled')).toBeDefined()
    expect((w.get('[data-test=profile-kind]').element as HTMLSelectElement).value).toBe('narrow_vpp')
  })

  it('does not carry the posture adopted from another period profile into a period without a profile', async () => {
    api.currentDraft.mockResolvedValueOnce({ draft: null, capabilities: null, legal_profile: status({ current }) })
      .mockResolvedValueOnce({ draft: null, capabilities: null, legal_profile: status({}) })
    const w = render()
    await flushPromises()
    expect((w.get('[data-test=profile-kind]').element as HTMLSelectElement).value).toBe('narrow_vpp')
    await w.setProps({ periodId: 8, reloadKey: 1 })
    await flushPromises()
    expect(w.get('[data-test=profile-kind]').attributes('disabled')).toBeUndefined()
    expect((w.get('[data-test=profile-kind]').element as HTMLSelectElement).value).toBe('')
    expect(w.get('[data-action=open]').attributes('disabled')).toBeDefined()
  })

  it('keeps a typed manual posture when the same period reloads', async () => {
    api.currentDraft.mockResolvedValue({ draft: null, capabilities: null, legal_profile: status({}) })
    const w = render()
    await flushPromises()
    await w.get('[data-test=profile-kind]').setValue('broad_vpp')
    await w.get('[data-test=profile-evidence]').setValue('Synthetic typed evidence')
    await w.setProps({ reloadKey: 1 })
    await flushPromises()
    expect((w.get('[data-test=profile-kind]').element as HTMLSelectElement).value).toBe('broad_vpp')
    expect((w.get('[data-test=profile-evidence]').element as HTMLTextAreaElement).value).toBe('Synthetic typed evidence')
  })

  it('marks the draft stale instead of showing a profile state of a newer draft revision', async () => {
    const pinned = status({ state: 'pinned_current', current, pinned: { id: 31, revision_no: 2 } })
    api.currentDraft.mockResolvedValueOnce(envelope(pinned))
      .mockResolvedValueOnce(envelope(status({ state: 'conflict', current }), 'narrow_vpp', 4))
    api.preview.mockRejectedValue({ response: { status: 409, data: { error: { code: 'nonprofit_tax_legal_profile_conflict', message: 'x' } } } })
    const w = render()
    await flushPromises()
    await w.get('[data-action=preview]').trigger('click')
    await flushPromises()
    expect(w.text()).toContain('nonprofitTaxJ6.stale')
    expect(w.get('[data-test=legal-profile-status]').text()).not.toContain('nonprofitTaxJ6.legalProfile.conflictTitle')
    // Rada k resetu by odporovala pruhu „obnovte přehled“ (reset je u zastaralého konceptu vypnutý).
    expect(w.find('[role=alert]').exists()).toBe(false)
  })

  it('discards a profile refresh that finishes after the local draft changed', async () => {
    let resolveRefresh: (value: unknown) => void = () => {}
    api.currentDraft.mockResolvedValueOnce(envelope(status({ state: 'pinned_current', current, pinned: { id: 31, revision_no: 2 } })))
      .mockImplementationOnce(() => new Promise((resolve) => { resolveRefresh = resolve }))
    api.preview.mockRejectedValue({ response: { status: 409, data: { error: { code: 'nonprofit_tax_legal_profile_conflict', message: 'x' } } } })
    api.resetDraft.mockResolvedValue(envelope(status({ state: 'pinned_current', current, pinned: { id: 31, revision_no: 2 } }), 'narrow_vpp', 4))
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    const w = render()
    await flushPromises()
    await w.get('[data-action=preview]').trigger('click')
    await flushPromises()
    await w.get('[data-action=reset]').trigger('click')
    await flushPromises()
    resolveRefresh(envelope(status({ state: 'conflict', current }), 'narrow_vpp', 3))
    await flushPromises()
    expect(w.text()).not.toContain('nonprofitTaxJ6.stale')
    expect(w.get('[data-action=reset]').attributes('disabled')).toBeUndefined()
    expect(w.get('[data-test=legal-profile-status]').text()).toContain('nonprofitTaxJ6.legalProfile.adopted')
  })

  it('clears the stale state when the overview reloads without a draft', async () => {
    api.currentDraft.mockResolvedValueOnce(envelope(status({ state: 'pinned_current', current, pinned: { id: 31, revision_no: 2 } })))
      .mockResolvedValueOnce({ draft: null, capabilities: null, legal_profile: status({ current }) })
      .mockResolvedValueOnce({ draft: null, capabilities: null, legal_profile: status({ current }) })
    api.preview.mockRejectedValue({ response: { status: 409, data: { error: { code: 'nonprofit_tax_legal_profile_conflict', message: 'x' } } } })
    const w = render()
    await flushPromises()
    await w.get('[data-action=preview]').trigger('click')
    await flushPromises()
    expect(w.text()).toContain('nonprofitTaxJ6.stale')
    await w.setProps({ reloadKey: 1 })
    await flushPromises()
    expect(w.text()).not.toContain('nonprofitTaxJ6.stale')
  })

  it('does not ask to repeat a mismatched action when the draft turned out stale', async () => {
    api.currentDraft.mockResolvedValueOnce(envelope(status({ state: 'none' })))
      .mockResolvedValueOnce(envelope(status({ state: 'conflict', current }), 'narrow_vpp', 4))
    api.resetDraft.mockRejectedValue({ response: { status: 422, data: { error: { code: 'nonprofit_tax_legal_profile_mismatch', message: 'Synthetic mismatch.' } } } })
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    const w = render()
    await flushPromises()
    await w.get('[data-test=profile-kind]').setValue('broad_vpp')
    await w.get('[data-action=reset]').trigger('click')
    await flushPromises()
    expect(w.text()).toContain('nonprofitTaxJ6.stale')
    expect(w.find('[role=alert]').exists()).toBe(false)
  })

  it('asks to reload the overview after a mismatch when the profile state cannot be refreshed', async () => {
    api.currentDraft.mockResolvedValueOnce({ draft: null, capabilities: null, legal_profile: status({}) })
      .mockRejectedValueOnce({ response: { status: 503, data: { error: { code: 'unavailable', message: 'down' } } } })
    api.openDraft.mockRejectedValue({ response: { status: 422, data: { error: { code: 'nonprofit_tax_legal_profile_mismatch', message: 'Synthetic mismatch.' } } } })
    const w = render()
    await flushPromises()
    await w.get('[data-test=profile-kind]').setValue('broad_vpp')
    await w.get('[data-test=profile-evidence]').setValue('Synthetic')
    await w.get('[data-action=open]').trigger('click')
    await flushPromises()
    expect(w.get('[role=alert]').text()).toContain('nonprofitTaxJ6.legalProfile.refreshFailed')
    expect(w.get('[role=alert]').text()).not.toContain('Synthetic mismatch.')
  })

  it('ignores a late profile refresh after the overview reloaded the same draft revision', async () => {
    let resolveRefresh: (value: unknown) => void = () => {}
    const pinned = status({ state: 'pinned_current', current, pinned: { id: 31, revision_no: 2 } })
    api.currentDraft.mockResolvedValueOnce(envelope(pinned))
      .mockImplementationOnce(() => new Promise((resolve) => { resolveRefresh = resolve }))
      .mockResolvedValueOnce(envelope(status({ state: 'conflict', current })))
    api.preview.mockRejectedValue({ response: { status: 409, data: { error: { code: 'nonprofit_tax_legal_profile_conflict', message: 'x' } } } })
    const w = render()
    await flushPromises()
    await w.get('[data-action=preview]').trigger('click')
    await flushPromises()
    await w.setProps({ reloadKey: 1 })
    await flushPromises()
    resolveRefresh(envelope(pinned))
    await flushPromises()
    expect(w.find('[role=alert]').exists()).toBe(false)
    expect(w.get('[data-test=legal-profile-status]').text()).toContain('nonprofitTaxJ6.legalProfile.conflictTitle')
  })

  it('keeps the history reload notice when an earlier profile refresh finishes later', async () => {
    let rejectRefresh: (value: unknown) => void = () => {}
    // Selhaná obnova po nesouladu nepřepíše novější hlášku o přenačtené historii.
    api.history.mockResolvedValueOnce({ head: { assessment_id: null, revision_no: 0, status: null }, rows: [], after: 'cursor-1' })
      .mockRejectedValueOnce({ response: { status: 409, data: { error: { code: 'nonprofit_tax_cursor_stale', message: 'x' } } } })
    api.currentDraft.mockResolvedValueOnce({ draft: null, capabilities: null, legal_profile: status({}) })
      .mockImplementationOnce(() => new Promise((_, reject) => { rejectRefresh = reject }))
    api.openDraft.mockRejectedValue({ response: { status: 422, data: { error: { code: 'nonprofit_tax_legal_profile_mismatch', message: 'x' } } } })
    const w = render()
    await flushPromises()
    await w.get('[data-test=profile-kind]').setValue('broad_vpp')
    await w.get('[data-test=profile-evidence]').setValue('Synthetic')
    await w.get('[data-action=open]').trigger('click')
    await flushPromises()
    await w.get('[data-test=history-next]').trigger('click')
    await flushPromises()
    expect(w.get('[role=alert]').text()).toContain('nonprofitTaxJ6.historyChanged')
    rejectRefresh({ response: { status: 503, data: { error: { code: 'unavailable', message: 'down' } } } })
    await flushPromises()
    expect(w.get('[role=alert]').text()).toContain('nonprofitTaxJ6.historyChanged')
  })

  it('applies a profile refresh that finishes after paging the history', async () => {
    let resolveRefresh: (value: unknown) => void = () => {}
    api.history.mockResolvedValueOnce({ head: { assessment_id: null, revision_no: 0, status: null }, rows: [], after: 'cursor-1' })
      .mockRejectedValueOnce({ response: { status: 409, data: { error: { code: 'nonprofit_tax_cursor_stale', message: 'x' } } } })
    api.currentDraft.mockResolvedValueOnce({ draft: null, capabilities: null, legal_profile: status({}) })
      .mockImplementationOnce(() => new Promise((resolve) => { resolveRefresh = resolve }))
    api.openDraft.mockRejectedValue({ response: { status: 422, data: { error: { code: 'nonprofit_tax_legal_profile_mismatch', message: 'x' } } } })
    const w = render()
    await flushPromises()
    await w.get('[data-test=profile-kind]').setValue('broad_vpp')
    await w.get('[data-test=profile-evidence]').setValue('Synthetic')
    await w.get('[data-action=open]').trigger('click')
    await flushPromises()
    await w.get('[data-test=history-next]').trigger('click')
    await flushPromises()
    resolveRefresh({ draft: null, capabilities: null, legal_profile: status({ current }) })
    await flushPromises()
    expect(w.get('[role=alert]').text()).toContain('nonprofitTaxJ6.historyChanged')
    expect(w.get('[data-test=profile-kind]').attributes('disabled')).toBeDefined()
    expect((w.get('[data-test=profile-kind]').element as HTMLSelectElement).value).toBe('narrow_vpp')
  })

  it('keeps the translated missing-profile message when the profile state cannot be refreshed', async () => {
    api.currentDraft.mockResolvedValueOnce(envelope(status({ state: 'pinned_same_kind_outdated', current, pinned: { id: 30, revision_no: 1 }, pinnable: true })))
      .mockRejectedValueOnce({ response: { status: 503, data: { error: { code: 'unavailable', message: 'down' } } } })
    api.pinLegalProfile.mockRejectedValue({ response: { status: 409, data: { error: { code: 'nonprofit_tax_legal_profile_missing', message: 'Synthetic missing.' } } } })
    const w = render()
    await flushPromises()
    await w.get('[data-test=legal-profile-pin]').trigger('click')
    await flushPromises()
    expect(w.get('[role=alert]').text()).toContain('nonprofitTaxJ6.errors.nonprofit_tax_legal_profile_missing')
    expect(w.get('[role=alert]').text()).not.toContain('Synthetic missing.')
  })

  it('sends one reset for a double click', async () => {
    let resolveReset: (value: unknown) => void = () => {}
    api.currentDraft.mockResolvedValue(envelope(status({ state: 'pinned_current', current, pinned: { id: 31, revision_no: 2 } })))
    api.resetDraft.mockImplementation(() => new Promise((resolve) => { resolveReset = resolve }))
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    const w = render()
    await flushPromises()
    await w.get('[data-action=reset]').trigger('click')
    expect(w.get('[data-action=reset]').attributes('disabled')).toBeDefined()
    await w.get('[data-action=reset]').trigger('click')
    expect(api.resetDraft).toHaveBeenCalledTimes(1)
    resolveReset(envelope(status({ state: 'pinned_current', current, pinned: { id: 31, revision_no: 2 } }), 'narrow_vpp', 4))
    await flushPromises()
  })

  it('clears an earlier error after a successful reset', async () => {
    api.currentDraft.mockResolvedValueOnce(envelope(status({ state: 'pinned_current', current, pinned: { id: 31, revision_no: 2 } })))
      .mockRejectedValueOnce({ response: { status: 503, data: { error: { code: 'unavailable', message: 'down' } } } })
    api.preview.mockRejectedValue({ response: { status: 409, data: { error: { code: 'nonprofit_tax_legal_profile_conflict', message: 'x' } } } })
    api.resetDraft.mockResolvedValue(envelope(status({ state: 'pinned_current', current, pinned: { id: 31, revision_no: 2 } }), 'narrow_vpp', 4))
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    const w = render()
    await flushPromises()
    await w.get('[data-action=preview]').trigger('click')
    await flushPromises()
    expect(w.find('[role=alert]').exists()).toBe(true)
    await w.get('[data-action=reset]').trigger('click')
    await flushPromises()
    expect(api.resetDraft).toHaveBeenCalled()
    expect(w.find('[role=alert]').exists()).toBe(false)
  })

  it('ignores a profile refresh that finishes after switching the period', async () => {
    let resolveRefresh: (value: unknown) => void = () => {}
    api.currentDraft.mockResolvedValueOnce({ draft: null, capabilities: null, legal_profile: status({}) })
      .mockImplementationOnce(() => new Promise((resolve) => { resolveRefresh = resolve }))
      .mockResolvedValueOnce({ draft: null, capabilities: null, legal_profile: status({}) })
    api.openDraft.mockRejectedValue({ response: { status: 422, data: { error: { code: 'nonprofit_tax_legal_profile_mismatch', message: 'x' } } } })
    const w = render()
    await flushPromises()
    await w.get('[data-test=profile-kind]').setValue('broad_vpp')
    await w.get('[data-test=profile-evidence]').setValue('Synthetic')
    await w.get('[data-action=open]').trigger('click')
    await flushPromises()
    await w.setProps({ periodId: 8 })
    await flushPromises()
    resolveRefresh({ draft: null, capabilities: null, legal_profile: status({ current }) })
    await flushPromises()
    expect(w.get('[data-test=profile-kind]').attributes('disabled')).toBeUndefined()
    expect(w.get('[data-test=legal-profile-status]').text()).toContain('nonprofitTaxJ6.legalProfile.none')
  })

  it('explains a posture conflict from the server only in the profile box', async () => {
    api.currentDraft.mockResolvedValueOnce(envelope(status({ state: 'pinned_current', current, pinned: { id: 31, revision_no: 2 } })))
      .mockResolvedValueOnce(envelope(status({ state: 'conflict', current: { ...current, kind: 'broad_vpp' } })))
    api.preview.mockRejectedValue({ response: { status: 409, data: { error: { code: 'nonprofit_tax_legal_profile_conflict', message: 'x' } } } })
    const w = render()
    await flushPromises()
    await w.get('[data-action=preview]').trigger('click')
    await flushPromises()
    expect(w.get('[data-test=legal-profile-status]').text()).toContain('nonprofitTaxJ6.legalProfile.conflictTitle')
    expect(w.find('[role=alert]').exists()).toBe(false)
  })

  it('keeps the conflict error in the alert when the profile state cannot be refreshed', async () => {
    api.currentDraft.mockResolvedValueOnce(envelope(status({ state: 'pinned_current', current, pinned: { id: 31, revision_no: 2 } })))
      .mockRejectedValueOnce({ response: { status: 503, data: { error: { code: 'unavailable', message: 'down' } } } })
    api.preview.mockRejectedValue({ response: { status: 409, data: { error: { code: 'nonprofit_tax_legal_profile_conflict', message: 'x' } } } })
    const w = render()
    await flushPromises()
    await w.get('[data-action=preview]').trigger('click')
    await flushPromises()
    expect(w.get('[role=alert]').text()).toContain('nonprofitTaxJ6.errors.nonprofit_tax_legal_profile_conflict')
  })
})
