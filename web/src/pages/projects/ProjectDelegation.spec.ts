import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'

const mocks = vi.hoisted(() => ({
  permissions: {} as Record<string, number>,
  list: vi.fn(), get: vi.fn(), profit: vi.fn(), invoices: vi.fn(), clients: vi.fn(), client: vi.fn(),
  categories: vi.fn(), currencies: vi.fn(), billingTemplates: vi.fn(),
}))
vi.mock('@/api/workReportBilling', () => ({ workReportBillingApi: { list: mocks.billingTemplates } }))
vi.mock('@/api/projects', () => ({ projectsApi: { list: mocks.list, get: mocks.get, profit: mocks.profit } }))
vi.mock('@/api/invoices', () => ({ invoicesApi: { listGrouped: mocks.invoices } }))
vi.mock('@/api/clients', () => ({ clientsApi: { list: mocks.clients, get: mocks.client } }))
vi.mock('@/api/revenueCategories', () => ({ revenueCategoriesApi: { list: mocks.categories } }))
vi.mock('@/api/codebooks', () => ({ codebooksApi: { currencies: mocks.currencies } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({
  isClientRole: true,
  canRead: (key: string) => (mocks.permissions[key] ?? 0) >= 1,
  canWrite: (key: string) => (mocks.permissions[key] ?? 0) >= 2,
}) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplier: {} }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }))
vi.mock('vue-i18n', async importOriginal => ({ ...await importOriginal<typeof import('vue-i18n')>(), useI18n: () => ({ t: (key: string) => key }) }))
import ProjectList from './ProjectList.vue'
import ProjectDetail from './ProjectDetail.vue'
import ProjectForm from './ProjectForm.vue'

const project = { id: 7, client_id: 8, name: 'Syntetická zakázka', hourly_rate: 10, currency: 'CZK', currency_id: 1, billing_emails: [], payment_due_days: 7, status: 'active' }
async function open(component: typeof ProjectList | typeof ProjectDetail | typeof ProjectForm, path: string) {
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/projects/:id?', component: { render: () => null } }, { path: '/projects/:id/edit', component: { render: () => null } }] })
  await router.push(path)
  const wrapper = mount({ ...component, render: () => null }, { global: { plugins: [router] } })
  await flushPromises()
  return wrapper
}

describe('delegated project requests', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    mocks.permissions = { projects: 1 }
    mocks.billingTemplates.mockResolvedValue([])
    mocks.list.mockResolvedValue({ data: [], meta: { total: 0, pages: 1 } })
    mocks.get.mockResolvedValue(project)
    mocks.invoices.mockResolvedValue({ data: [], meta: { total: 0, pages: 1 } })
    mocks.clients.mockResolvedValue({ data: [] })
    mocks.currencies.mockResolvedValue([{ id: 1, code: 'CZK' }])
  })

  it('lists projects without fetching the client directory', async () => {
    const wrapper = await open(ProjectList, '/projects')
    expect(mocks.list).toHaveBeenCalledOnce()
    expect(mocks.clients).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it.each([0, 1])('loads invoices only when their read permission is granted (%i)', async level => {
    mocks.permissions.invoices = level
    const wrapper = await open(ProjectDetail, '/projects/7')
    expect(mocks.get).toHaveBeenCalledWith(7)
    expect(mocks.invoices).toHaveBeenCalledTimes(level)
    expect(mocks.profit).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('edits an operational project without fetching clients or accounting classifications', async () => {
    mocks.permissions.projects = 2
    const wrapper = await open(ProjectForm, '/projects/7/edit')
    expect(mocks.get).toHaveBeenCalledWith(7)
    expect(mocks.client).not.toHaveBeenCalled()
    expect(mocks.categories).not.toHaveBeenCalled()
    wrapper.unmount()
  })
})


it.each([false, true])('ProjectForm nepřidá ovládání firmě bez šablon, uložená volba zůstane viditelná (%s)', async selected => {
  mocks.permissions = { projects: 2 }
  mocks.billingTemplates.mockResolvedValue([])
  mocks.currencies.mockResolvedValue([{ id: 1, code: 'CZK' }])
  mocks.get.mockResolvedValue({ ...project, billing_template_id: selected ? 7 : null })
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/projects/:id/edit', component: { render: () => null } }] })
  await router.push('/projects/7/edit')
  const wrapper = mount(ProjectForm, { global: { plugins: [router], mocks: { $i18n: { locale: 'cs' } }, stubs: { EntityDimensionDefaults: true } } })
  await flushPromises()
  expect(wrapper.text().includes('work_report_billing.template')).toBe(selected)
  wrapper.unmount()
})
