import { mount } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import { describe, expect, it } from 'vitest'
import Settlement from '../MembershipSettlement.vue'
import type { ClaimSettlement } from '@/api/nonprofitMembership'
import cs from '@/i18n/cs.json'
import en from '@/i18n/en.json'

const settlement = (evidence: string): ClaimSettlement => ({
  status: 'ambiguous', paid_cents: null, open_cents: null, pairing_id: 7, evidence,
})

describe('MembershipSettlement', () => {
  it.each([['cs', cs], ['en', en]] as const)('společná částečná úhrada vykreslí důvod i radu (%s)', (locale, messages) => {
    const i18n = createI18n({ legacy: false, locale, messages: { [locale]: messages } })
    const wrapper = mount(Settlement, { props: { settlement: settlement('collective_partial_payment') }, global: { plugins: [i18n] } })
    expect(wrapper.text()).toContain(messages.nonprofitMembership.evidence_collective_partial_payment)
    expect(wrapper.text()).not.toContain(messages.nonprofitMembership.evidence_unknown)
  })
})
