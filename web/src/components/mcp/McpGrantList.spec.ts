import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import McpGrantList from './McpGrantList.vue'

const active = { id: 1, client_name: 'Synthetic active', is_active: true }
const inactive = { id: 2, client_name: 'Synthetic inactive', is_active: false }

describe('shared MCP grant list', () => {
  it('offers revocation only for active grants and emits the selected grant', async () => {
    const wrapper = mount(McpGrantList, {
      props: { grants: [active, inactive], revokingId: null, revokeLabel: 'Revoke' },
      slots: { details: '<p class="details">details</p>' },
    })
    expect(wrapper.text()).toContain('Synthetic inactive')
    expect(wrapper.findAll('.details')).toHaveLength(2)
    const buttons = wrapper.findAll('button')
    expect(buttons).toHaveLength(1)
    expect(buttons[0].classes()).toContain('whitespace-nowrap')
    await buttons[0].trigger('click')
    expect(wrapper.emitted('revoke')).toEqual([[active]])
    wrapper.unmount()
  })

  it('blocks a second revocation while one is running', () => {
    const wrapper = mount(McpGrantList, {
      props: { grants: [active, { ...active, id: 3 }], revokingId: 1, revokeLabel: 'Revoke' },
    })
    expect(wrapper.findAll('button').every(button => button.attributes('disabled') !== undefined)).toBe(true)
    wrapper.unmount()
  })
})
