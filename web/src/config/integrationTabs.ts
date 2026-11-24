export type IntegrationTab = 'ai' | 'webhooks' | 'fakturoid' | 'idoklad'

export function resolveIntegrationTab(requestedTab: unknown, canManageWebhooks: boolean): IntegrationTab {
  if (requestedTab === 'ai' || requestedTab === 'fakturoid' || requestedTab === 'idoklad') return requestedTab
  return canManageWebhooks ? 'webhooks' : 'idoklad'
}
