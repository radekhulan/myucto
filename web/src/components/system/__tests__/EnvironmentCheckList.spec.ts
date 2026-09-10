import { ref } from 'vue'
import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import type { DiagnosticCheck } from '@/api/diagnostics'

// Popisky se skládají z i18n klíčů; tady stačí, že se klíč vrátí zpátky —
// testujeme řazení, filtr a zvýraznění nálezu, ne překlady.
const TRANSLATED = new Set([
  'diagnostics.checks.php_extensions.actual_label',
  'diagnostics.checks.php_extensions.expected_label',
  'diagnostics.checks.app_url.values.app_url_invalid_origin',
  'diagnostics.checks.cron_health.variants.dispatcher_down.label',
  'diagnostics.checks.cron_health.variants.dispatcher_down.fix',
  'diagnostics.checks.cron_health.affected_label',
  'diagnostics.cron_inactive.not_in_use',
  'diagnostics.cron_inactive.feature_off',
  'diagnostics.cron_inactive.not_in_use_by_script.cron-epo-status',
  'diagnostics.schema_findings.table_leftover',
])

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    locale: ref('cs-CZ'),
    t: (key: string) => key,
    te: (key: string) => TRANSLATED.has(key),
  }),
}))

import EnvironmentCheckList from '@/components/system/EnvironmentCheckList.vue'

function check(partial: Partial<DiagnosticCheck> & { id: string; status: DiagnosticCheck['status'] }): DiagnosticCheck {
  return { actual: '', expected: '', manual: '', ...partial }
}

describe('EnvironmentCheckList', () => {
  const CHECKS: DiagnosticCheck[] = [
    check({ id: 'opcache', status: 'ok', actual: 'zapnuto' }),
    check({ id: 'php_extensions_optional', status: 'warn', actual: 'intl' }),
    check({ id: 'db_version', status: 'fail', actual: 'nedostupná' }),
    check({ id: 'redis', status: 'skip', actual: 'vypnutý' }),
  ]

  it('řadí problémy nahoru', () => {
    const wrapper = mount(EnvironmentCheckList, { props: { checks: CHECKS } })
    const ids = wrapper.findAll('li').map((li) => li.text())

    expect(ids).toHaveLength(4)
    expect(ids[0]).toContain('db_version')
    expect(ids[1]).toContain('php_extensions_optional')
  })

  it('v režimu problems-only skryje ok i skip', () => {
    const wrapper = mount(EnvironmentCheckList, { props: { checks: CHECKS, problemsOnly: true } })
    const text = wrapper.text()

    expect(wrapper.findAll('li')).toHaveLength(2)
    expect(text).not.toContain('diagnostics.checks.redis.label')
    expect(text).not.toContain('diagnostics.checks.opcache.label')
  })

  it('naměřenou hodnotu u nálezu zvýrazní červeně, u pořádku ne', () => {
    const wrapper = mount(EnvironmentCheckList, { props: { checks: CHECKS } })
    const values = wrapper.findAll('dd')

    const failing = values.filter((dd) => dd.text() === 'nedostupná')
    const passing = values.filter((dd) => dd.text() === 'zapnuto')
    expect(failing[0].classes()).toContain('text-danger-600')
    expect(passing[0].classes()).not.toContain('text-danger-600')
  })

  it('informaci ukáže i u kontroly s nálezem, ale nezvýrazní ji jako problém', () => {
    const wrapper = mount(EnvironmentCheckList, {
      props: {
        checks: [check({ id: 'cron_health', status: 'fail', actual: 'cron-backup', info: 'cron-bank-scan' })],
      },
    })

    const info = wrapper.findAll('dd').filter((dd) => dd.text() === 'cron-bank-scan')
    expect(info).toHaveLength(1)
    expect(info[0].classes()).not.toContain('text-danger-600')
    expect(wrapper.findAll('dt').map((dt) => dt.text())).toContain('diagnostics.info:')
  })

  it('u seznamových kontrol použije vlastní popisek místo „Naměřeno“', () => {
    const wrapper = mount(EnvironmentCheckList, {
      props: { checks: [check({ id: 'php_extensions', status: 'fail', actual: 'gd', expected: 'gd, zip' })] },
    })
    const labels = wrapper.findAll('dt').map((dt) => dt.text())

    expect(labels).toContain('diagnostics.checks.php_extensions.actual_label:')
    expect(labels).not.toContain('diagnostics.actual:')
  })

  it('varianta nahradí popisek i nápravu a zaseklé úlohy ukáže jen jako podrobnost', () => {
    const wrapper = mount(EnvironmentCheckList, {
      props: {
        checks: [check({
          id: 'cron_health',
          status: 'fail',
          actual: 'dispatcher_down',
          variant: 'dispatcher_down',
          meta: { affected: ['cron-dispatch', 'cron-epo-status'] },
        })],
      },
    })
    const text = wrapper.text()

    expect(text).toContain('diagnostics.checks.cron_health.variants.dispatcher_down.label')
    expect(text).toContain('diagnostics.checks.cron_health.variants.dispatcher_down.fix')
    expect(text).not.toContain('diagnostics.checks.cron_health.label')
    const affected = wrapper.findAll('dd').filter((dd) => dd.text() === 'cron-dispatch, cron-epo-status')
    expect(affected).toHaveLength(1)
    expect(affected[0].classes()).not.toContain('text-danger-600')
  })

  it('u neaktivní úlohy ukáže konkrétní důvod, jinak obecný', () => {
    const wrapper = mount(EnvironmentCheckList, {
      props: {
        checks: [check({
          id: 'cron_health',
          status: 'ok',
          info: 'cron-epo-status, cron-ai-worker',
          meta: { inactive: { 'cron-epo-status': 'not_in_use', 'cron-ai-worker': 'feature_off' } },
        })],
      },
    })
    const text = wrapper.text()

    expect(text).toContain('diagnostics.cron_inactive.not_in_use_by_script.cron-epo-status')
    expect(text).toContain('diagnostics.cron_inactive.feature_off')
    expect(text).not.toContain('diagnostics.cron_inactive.not_in_use ')
  })

  it('pozůstatky starší verze ukáže i u kontroly v pořádku, šedě', () => {
    const wrapper = mount(EnvironmentCheckList, {
      props: {
        checks: [check({
          id: 'schema_integrity',
          status: 'ok',
          actual: '0 / 0',
          meta: {
            findings: [{ severity: 'info', code: 'table_leftover', object: 'bank_statement_owners', expected: '', actual: '' }],
            total: 1,
          },
        })],
      },
    })

    const finding = wrapper.findAll('span').filter((s) => s.text() === 'diagnostics.schema_findings.table_leftover')
    expect(finding).toHaveLength(1)
    expect(finding[0].classes()).toContain('text-neutral-600')
    expect(wrapper.text()).toContain('bank_statement_owners')
  })

  it('bezpečný reason code konfigurace přeloží přes slovník kontroly', () => {
    const wrapper = mount(EnvironmentCheckList, {
      props: {
        checks: [check({ id: 'app_url', status: 'fail', actual: 'app_url_invalid_origin' })],
      },
    })

    expect(wrapper.find('dd').text()).toBe(
      'diagnostics.checks.app_url.values.app_url_invalid_origin',
    )
  })
})
