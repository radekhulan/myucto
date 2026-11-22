import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'
import QuoteMarkdownEditor from './QuoteMarkdownEditor.vue'

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

describe('editor textu nabídky', () => {
  it('vizuální text s obrázkem lze upravit a dál otevřít ve skladovém editoru', async () => {
    const original = '## Průběh\n\n![Montáž](quote-file:1)'
    const wrapper = mount(QuoteMarkdownEditor, {
      props: {
        modelValue: original,
        images: [{ id: 1, name: 'Montáž', url: '/api/sales-quotes/2/files/1?version_id=3' }],
        rows: 3,
        disabled: false,
      },
    })
    await nextTick()
    const editable = wrapper.get<HTMLElement>('[contenteditable="true"]')
    expect(editable.find('h2').text()).toBe('Průběh')
    expect(editable.get('img').attributes('src')).toBe('/api/sales-quotes/2/files/1?version_id=3')

    editable.element.appendChild(document.createElement('p')).textContent = 'Příprava'
    await editable.trigger('input')
    const result = wrapper.emitted('update:modelValue')?.[0]?.[0] as string
    expect(result).toBe(original + '\n\nPříprava')
    await wrapper.setProps({ modelValue: result })
    await wrapper.get('button.ml-auto').trigger('click')
    expect(wrapper.get<HTMLTextAreaElement>('textarea').element.value).toBe(result)
  })
})
