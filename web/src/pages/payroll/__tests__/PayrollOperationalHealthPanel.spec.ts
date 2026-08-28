import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({
  operationalHealth: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    operationalHealth: m.operationalHealth,
  },
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string, params?: { count?: number }) => (
      params?.count === undefined ? key : `${key}:${params.count}`
    ),
    locale: { value: 'cs' },
  }),
}))

import PayrollOperationalHealthPanel from '@/pages/payroll/PayrollOperationalHealthPanel.vue'

function healthFixture() {
  return {
    document_batches: {
      queued: 1,
      running: 2,
      retry_wait: 3,
      failed: 4,
      oldest_pending_at: '2026-08-27T10:00:00Z',
      oldest_pending_age_seconds: 7_200,
      last_completed_at: '2026-08-27T09:00:00Z',
    },
    period_export_jobs: {
      queued: 12,
      processing: 13,
      retry_wait: 14,
      failed: 15,
      oldest_pending_at: '2026-08-27T08:00:00Z',
      oldest_pending_age_seconds: 90_000,
      last_completed_at: null,
    },
    submissions: { rejected: 5, correction_required: 6, open_blocker_or_error_issues: 7 },
    isds_outbox: { failed: 8, send_uncertain: 9, rejected: 10 },
    archive_capacity: {
      measured: true,
      content_bytes: 1_572_864,
      object_count: 16,
      components: {},
    },
    reconciliation: {
      open: 17,
      diff: 8,
      blocked: 5,
      not_materialized: 4,
      periods: 3,
      oldest_first_seen_at: '2026-08-26 08:00:00.000000',
    },
    overdue_unpaid_liabilities: 11,
  }
}

describe('PayrollOperationalHealthPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.operationalHealth.mockResolvedValue(healthFixture())
  })

  it('shows only the aggregate operational counts', async () => {
    const wrapper = mount(PayrollOperationalHealthPanel)
    await flushPromises()

    expect(wrapper.get('[data-test="operational-health"]').text())
      .toContain('payroll.dashboard.operational_health.title')
    expect(wrapper.get('[data-test="document-queued"]').text()).toBe('1')
    expect(wrapper.get('[data-test="document-failed"]').text()).toBe('4')
    expect(wrapper.get('[data-test="period-export-queued"]').text()).toBe('12')
    expect(wrapper.get('[data-test="period-export-failed"]').text()).toBe('15')
    expect(wrapper.get('[data-test="document-oldest-age"]').text()).toContain('2')
    expect(wrapper.get('[data-test="document-last-completed"]').text()).not.toBe('')
    expect(wrapper.get('[data-test="period-export-oldest-age"]').text()).toContain('1')
    expect(wrapper.get('[data-test="period-export-last-completed"]').text())
      .toContain('payroll.dashboard.operational_health.never_completed')
    expect(wrapper.get('[data-test="submission-rejected"]').text()).toBe('5')
    expect(wrapper.get('[data-test="submission-issues"]').text()).toBe('7')
    expect(wrapper.get('[data-test="outbox-uncertain"]').text()).toBe('9')
    expect(wrapper.get('[data-test="archive-capacity-bytes"]').text()).toContain('1,5 MiB')
    expect(wrapper.get('[data-test="archive-capacity-objects"]').text()).toContain('16')
    expect(wrapper.get('[data-test="reconciliation-open"]').text()).toBe('17')
    expect(wrapper.get('[data-test="reconciliation-diff"]').text()).toBe('8')
    expect(wrapper.get('[data-test="reconciliation-blocked"]').text()).toBe('5')
    expect(wrapper.get('[data-test="reconciliation-card"]').classes()).toContain('bg-warning-50')
    expect(wrapper.get('[data-test="liabilities-overdue"]').text()).toBe('11')
    expect(wrapper.get('[data-test="liabilities-card"]').classes()).toContain('bg-warning-50')
    expect(wrapper.text()).not.toContain('Synthetic health test')
  })

  it('uses a success tone for fully settled liabilities', async () => {
    m.operationalHealth.mockResolvedValue({
      document_batches: {
        queued: 0,
        running: 0,
        retry_wait: 0,
        failed: 0,
        oldest_pending_at: null,
        oldest_pending_age_seconds: null,
        last_completed_at: null,
      },
      period_export_jobs: {
        queued: 0,
        processing: 0,
        retry_wait: 0,
        failed: 0,
        oldest_pending_at: null,
        oldest_pending_age_seconds: null,
        last_completed_at: null,
      },
      submissions: { rejected: 0, correction_required: 0, open_blocker_or_error_issues: 0 },
      isds_outbox: { failed: 0, send_uncertain: 0, rejected: 0 },
      archive_capacity: {
        measured: false,
        content_bytes: null,
        object_count: null,
        components: {},
      },
      reconciliation: {
        open: 0,
        diff: 0,
        blocked: 0,
        not_materialized: 0,
        periods: 0,
        oldest_first_seen_at: null,
      },
      overdue_unpaid_liabilities: 0,
    })
    const wrapper = mount(PayrollOperationalHealthPanel)
    await flushPromises()

    expect(wrapper.get('[data-test="liabilities-card"]').classes()).toContain('bg-success-50')
    expect(wrapper.get('[data-test="liabilities-card"]').classes()).not.toContain('bg-warning-50')
    expect(wrapper.get('[data-test="archive-capacity-card"]').classes()).toContain('bg-warning-50')
    expect(wrapper.get('[data-test="reconciliation-card"]').classes()).toContain('bg-success-50')
    expect(wrapper.get('[data-test="archive-capacity-bytes"]').text())
      .toContain('payroll.dashboard.operational_health.archive_measurement_failed')
    expect(wrapper.find('[data-test="archive-capacity-objects"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="document-oldest-age"]').text())
      .toContain('payroll.dashboard.operational_health.never_pending')
    expect(wrapper.get('[data-test="period-export-oldest-age"]').text())
      .toContain('payroll.dashboard.operational_health.never_pending')
  })

  it('renders nothing while loading and recovers from the retryable warning', async () => {
    let rejectInitial!: (reason?: unknown) => void
    const pending = new Promise((_, reject) => { rejectInitial = reject })
    m.operationalHealth.mockReturnValueOnce(pending)

    const wrapper = mount(PayrollOperationalHealthPanel)
    expect(wrapper.find('[data-test="operational-health"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="operational-health-unavailable"]').exists()).toBe(false)

    rejectInitial(new Error('403'))
    await flushPromises()

    expect(wrapper.find('[data-test="operational-health"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="operational-health-unavailable"]').text())
      .toContain('payroll.dashboard.operational_health.unavailable')
    m.operationalHealth.mockResolvedValueOnce(healthFixture())
    await wrapper.get('[data-test="operational-health-retry"]').trigger('click')
    await flushPromises()

    expect(m.operationalHealth).toHaveBeenCalledTimes(2)
    expect(wrapper.find('[data-test="operational-health-unavailable"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="operational-health"]').exists()).toBe(true)
  })
})
