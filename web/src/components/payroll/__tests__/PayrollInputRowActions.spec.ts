import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ t: (key: string) => key, locale: ref('cs-CZ') }),
}))

import type { PayrollInput } from '@/api/payroll'
import PayrollInputRowActions from '@/components/payroll/PayrollInputRowActions.vue'

/**
 * Schválený únorový vstup na lednové, už skončené dohodě (případ z podpory):
 * výpis vstupů, na který vede kontrola běhu, ho musí schvalovateli nabídnout
 * ke zrušení. Server to pak povolí uživateli s právem zapisovat vstupy
 * a schvalovat mzdy.
 */
function input(overrides: Partial<PayrollInput> = {}): PayrollInput {
  return {
    id: 7,
    supplier_id: 1,
    employee_id: 3,
    employee_name: 'Syntetická Brigádnice',
    employment_id: 11,
    employment_code: 'DPP-1',
    relation_type: 'dpp',
    component_id: 5,
    component_code: 'ODMENA_DOHODA',
    component_name: 'Odměna z dohody',
    component_kind: 'base_wage',
    value_kind: 'monetary',
    period_start: '2026-02-01',
    source_period_start: null,
    amount_minor: 350_000,
    quantity_milliunits: null,
    source_kind: 'manual',
    external_id: null,
    import_id: null,
    status: 'approved',
    component_snapshot_json: null,
    benefit_basket: null,
    row_version: 2,
    created_by: 1,
    approved_by: 1,
    approved_at: '2026-02-02 10:00:00',
    created_at: '2026-02-02 09:00:00',
    updated_at: '2026-02-02 10:00:00',
    ...overrides,
  } as PayrollInput
}

function actions(row: PayrollInput, canWrite: boolean, canApprove: boolean) {
  const wrapper = mount(PayrollInputRowActions, {
    props: { input: row, canWrite, canApprove, saving: false },
  })
  return {
    wrapper,
    has: (name: string) => wrapper.find(`[data-testid="payroll-input-${name}"]`).exists(),
  }
}

describe('PayrollInputRowActions: zrušení vstupu z výpisu', () => {
  it('schválený vstup mimo běh nabídne ke zrušení uživateli s právem schvalovat a pošle ho rodiči', async () => {
    const row = input()
    const { wrapper, has } = actions(row, true, true)
    expect(has('cancel')).toBe(true)
    // Schválený vstup se z výpisu neupravuje ani znovu neschvaluje.
    expect(has('edit')).toBe(false)
    expect(has('approve')).toBe(false)
    expect(has('reverse-benefit')).toBe(false)
    await wrapper.find('[data-testid="payroll-input-cancel"]').trigger('click')
    expect(wrapper.emitted('cancel')).toEqual([[row]])
  })

  it('bez práva schvalovat schválený vstup ke zrušení nenabídne (server by ho odmítl)', () => {
    expect(actions(input(), true, false).has('cancel')).toBe(false)
    expect(actions(input(), false, true).has('cancel')).toBe(false)
  })

  it('koncept zruší i uživatel bez práva schvalovat, zrušený vstup už akci nemá', () => {
    expect(actions(input({ status: 'draft' }), true, false).has('cancel')).toBe(true)
    expect(actions(input({ status: 'draft' }), false, false).has('cancel')).toBe(false)
    expect(actions(input({ status: 'cancelled' }), true, true).has('cancel')).toBe(false)
  })

  it('schválený benefit se neruší, ale stornuje (koš osvobození)', () => {
    const { has } = actions(input({ benefit_basket: 'leisure' }), true, true)
    expect(has('cancel')).toBe(false)
    expect(has('reverse-benefit')).toBe(true)
  })
})
