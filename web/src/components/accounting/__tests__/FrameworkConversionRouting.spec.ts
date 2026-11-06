import { describe, expect, it } from 'vitest'
import type { RouteRecordRaw } from 'vue-router'
import { applyAuthorizationMeta } from '@/router'
import { createWorkspaceRoutes } from '@/router/workspaceRoutes'
describe('Controlled conversion runtime route',() => {
 it('registers actual workspace route with accounting READ and supplier/mode scope',() => { const records: RouteRecordRaw[] = [{ path: '/', children: createWorkspaceRoutes() }]; applyAuthorizationMeta(records); const route = records[0]!.children!.find(record => record.name === 'accounting-framework-conversion'); expect(route?.path).toBe('accounting/framework-conversion'); expect(route?.meta).toMatchObject({ permission: 'accounting', access: 'read', requiresSupplier: true, requiresAccountingMode: true }) })
 it('loads the actual conversion view through the runtime lazy route',async () => { const route = createWorkspaceRoutes().find(record => record.name === 'accounting-framework-conversion'); expect(route).toBeDefined(); const module = await (route!.component as () => Promise<{ default: unknown }>)(); expect(module.default).toBeTruthy() })
})
