import { describe, expect, it, vi } from 'vitest'
import { announceApprovalRequested } from '../purchaseApproval'
import { approvalErrorMessage, isApprovalNoApprover } from '@/api/purchaseApprovals'

const t = (key: string, params?: Record<string, unknown>) => params ? `${key}:${JSON.stringify(params)}` : key

describe('announceApprovalRequested', () => {
  it('při approval_requested ukáže toast „odesláno ke schválení" a vrátí true', () => {
    const toast = { info: vi.fn() }
    expect(announceApprovalRequested({ approval_requested: true }, toast, t)).toBe(true)
    expect(toast.info).toHaveBeenCalledWith('purchase_approval.toast.requested')
  })

  it('bez příznaku nic neukazuje a vrátí false', () => {
    const toast = { info: vi.fn() }
    expect(announceApprovalRequested({}, toast, t)).toBe(false)
    expect(announceApprovalRequested({ approval_requested: false }, toast, t)).toBe(false)
    expect(announceApprovalRequested(null, toast, t)).toBe(false)
    expect(toast.info).not.toHaveBeenCalled()
  })
})

describe('approvalErrorMessage', () => {
  const noApprover = (error: Record<string, unknown>) => ({ response: { data: { error: { code: 'approval_no_approver', message: 'x', ...error } } } })

  it('approval_no_approver se jménem střediska z dimension_value', () => {
    const err = noApprover({ dimension_value: { id: 3, name: 'Provoz' } })
    expect(isApprovalNoApprover(err)).toBe(true)
    expect(approvalErrorMessage(err, t)).toBe('purchase_approval.no_approver:{"name":"Provoz"}')
  })

  it('approval_no_approver se seznamem hodnot skládá popisek stejně jako zbytek aplikace', () => {
    const err = noApprover({ dimension_values: [
      { code: 'STR01', name: 'Brno' },
      { code: 'unit:12', name: 'Jednotka 1', semantic_key: 'unit:12' },
      { code: 'Praha', name: 'Praha' },
      { code: '', name: 'Ostrava' },
    ] })
    expect(approvalErrorMessage(err, t)).toBe('purchase_approval.no_approver:{"name":"STR01 Brno, Jednotka 1, Praha, Ostrava"}')
  })

  it('approval_no_approver bez jména spadne na obecnou hlášku', () => {
    expect(approvalErrorMessage(noApprover({}), t)).toBe('purchase_approval.no_approver_generic')
  })

  it('ostatní chyby jdou přes běžnou hlášku serveru', () => {
    const err = { response: { data: { error: { code: 'forbidden', message: 'Zakázáno' } } } }
    expect(isApprovalNoApprover(err)).toBe(false)
    expect(approvalErrorMessage(err, t)).toBe('Zakázáno')
  })
})

