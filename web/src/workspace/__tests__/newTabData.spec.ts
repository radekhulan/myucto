import { describe, expect, it } from 'vitest'
import type { WorkspaceWindowState } from '@/stores/workspace'
import { isWorkspaceWindowId, openTabsSummary, sanitizeMenuItems, sanitizeOpenTabs } from '../newTabData'

function window(id: number, extra: Partial<WorkspaceWindowState> = {}): WorkspaceWindowState {
  return { id: `window-${id}`, fullPath: `/page-${id}`, title: `Stránka ${id}`, paneId: null, ...extra }
}

describe('data nové záložky', () => {
  it('přehled ukazuje záložky, ne okna, a vynechá prázdné nové záložky', () => {
    const summary = openTabsSummary([
      window(4, { tab: 'tab-4', fullPath: '/new-tab', title: '/new-tab' }),
      window(3, { tab: '1:/invoices', title: 'FV2026-0012', subtitle: 'Odběratel s.r.o.', supplierName: 'Firma A' }),
      window(2, { tab: '1:/invoices', title: 'Vydané faktury' }),
      window(1, { tab: '1:/bank', title: '/bank', fullPath: '/bank' }),
    ])
    expect(summary).toEqual([
      { windowId: 'window-3', title: 'FV2026-0012', subtitle: 'Odběratel s.r.o.', supplierName: 'Firma A', windows: 2 },
      { windowId: 'window-1', title: '/bank', subtitle: '', supplierName: '', windows: 1 },
    ])
  })

  it('ze zprávy převezme jen platné položky menu', () => {
    expect(sanitizeMenuItems([
      { to: '/invoices', label: 'Vydané faktury', icon: 'M0' },
      { to: '//evil.example', label: 'x', icon: '' },
      { to: 'javascript:alert(1)', label: 'x', icon: '', external: true },
      { to: 'https://myucto.cz/manual', label: 'Manuál', icon: '', external: true },
      { to: '/x', label: 1, icon: '' },
      null,
    ])).toEqual([
      { to: '/invoices', label: 'Vydané faktury', icon: 'M0' },
      { to: 'https://myucto.cz/manual', label: 'Manuál', icon: '', external: true },
    ])
    expect(sanitizeMenuItems('nic')).toEqual([])
  })

  it('ze zprávy převezme jen záložky s platným id okna', () => {
    expect(sanitizeOpenTabs([
      { windowId: 'window-7', title: 'Klienti', windows: 3 },
      { windowId: 'window-x', title: 'Podvrh' },
      { windowId: 'window-8', title: 'Banka', windows: -1, subtitle: 5 },
    ])).toEqual([
      { windowId: 'window-7', title: 'Klienti', subtitle: '', supplierName: '', windows: 3 },
      { windowId: 'window-8', title: 'Banka', subtitle: '', supplierName: '', windows: 1 },
    ])
    expect(isWorkspaceWindowId('window-12')).toBe(true)
    expect(isWorkspaceWindowId('../window-12')).toBe(false)
  })
})
