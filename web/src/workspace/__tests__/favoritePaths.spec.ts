import { describe, expect, it } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { withoutRecordParams } from '@/workspace/favoritePaths'

const stub = { template: '<div />' }
const router = createRouter({
  history: createMemoryHistory(),
  routes: [
    { path: '/', component: stub },
    { path: '/invoices', component: stub },
    { path: '/invoices/:id', component: stub },
    { path: '/invoices/:id/payments/:paymentId', component: stub },
    { path: '/clients/:id/edit', component: stub },
    { path: '/:pathMatch(.*)*', component: stub },
  ],
})

describe('withoutRecordParams', () => {
  it('cestu bez parametrů nechá beze změny vč. dotazu', () => {
    expect(withoutRecordParams(router, '/invoices?status=paid')).toEqual({ path: '/invoices?status=paid', changed: false })
  })

  it('detail záznamu nahradí nadřazeným seznamem', () => {
    expect(withoutRecordParams(router, '/invoices/123')).toEqual({ path: '/invoices', changed: true })
    expect(withoutRecordParams(router, '/invoices/123/payments/9?x=1')).toEqual({ path: '/invoices', changed: true })
  })

  it('bez nadřazeného seznamu skončí na homepage', () => {
    expect(withoutRecordParams(router, '/clients/5/edit')).toEqual({ path: '/', changed: true })
  })
})
