import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const mocks = vi.hoisted(() => ({ get: vi.fn(), invoices: vi.fn(), reschedule: vi.fn() }))
vi.mock('@/api/recurring', () => ({ recurringApi: mocks }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({}) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }))
vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: '1' } }),
  useRouter: () => ({ push: vi.fn() }),
  RouterLink: { template: '<a><slot /></a>' },
}))
vi.mock('vue-i18n', async () => {
  const { ref } = await import('vue')
  return { useI18n: () => ({ t: (key: string) => key, locale: ref('cs') }) }
})

import RecurringDetail from '../RecurringDetail.vue'

const template = {
  id: 1, name: 'Test schedule', next_run_date: '2027-09-01', anchor_date: '2027-01-01',
  end_date: null, frequency: 'annually', day_of_month: 1, end_of_month: false,
  status: 'active', language: 'cs', currency: 'CZK', items: [], draft_open_mode: 'at_issue',
}
let wrapper: ReturnType<typeof mount>

beforeEach(async () => {
  vi.useFakeTimers({ toFake: ['Date'] })
  vi.setSystemTime(new Date('2027-01-01T12:00:00Z'))
  mocks.get.mockResolvedValue({ ...template })
  mocks.invoices.mockResolvedValue({ data: [], meta: { total: 0, page: 1, pages: 1 } })
  mocks.reschedule.mockReset().mockResolvedValue({ ...template, next_run_date: '2027-02-01' })
  wrapper = mount(RecurringDetail, {
    attachTo: document.body,
    global: { stubs: {
      EmptyState: true,
      ActionBar: {
        props: ['actions'],
        template: '<button data-reschedule @click="actions.find(a => a.key === \'reschedule\').run()">Reschedule</button>',
      },
    } },
  })
  await flushPromises()
  await wrapper.get('[data-reschedule]').trigger('click')
})

afterEach(() => {
  wrapper?.unmount()
  document.body.innerHTML = ''
  vi.useRealTimers()
})

it('does not submit a stale valid date when the displayed text is invalid', async () => {
  const input = wrapper.get<HTMLInputElement>('input[type="text"]')
  await input.setValue('1.2.2027')
  await wrapper.get('input[type="checkbox"]').setValue(true)
  await input.setValue('31.2.2027')
  await input.trigger('blur')
  expect(input.element.validity.customError).toBe(true)
  await wrapper.get('form').trigger('submit')
  await flushPromises()
  expect(mocks.reschedule).not.toHaveBeenCalled()
  expect(wrapper.find('[role="dialog"]').exists()).toBe(true)
})

it('submits a valid changed date after confirmation', async () => {
  await wrapper.get('input[type="text"]').setValue('1.2.2027')
  await wrapper.get('input[type="checkbox"]').setValue(true)
  await wrapper.get('form').trigger('submit')
  await flushPromises()
  expect(mocks.reschedule).toHaveBeenCalledExactlyOnceWith(1, '2027-02-01', '2027-09-01')
})
