import type { ProductSetDefinition } from '@/api/eshop'

export type ProductSetSelections = Record<string, Record<string, string[]>>

export interface ActiveProductSetDefinition {
  itemId: number
  definition: ProductSetDefinition
}

export interface ActiveProductSet {
  definitions: ActiveProductSetDefinition[]
  selections: ProductSetSelections
  componentItemIds: number[]
}

/**
 * Resolves the branch which the backend expands: fixed components are always
 * followed and group components only after their option was picked. The visited
 * set and depth bound keep malformed cyclic definitions from expanding forever.
 */
export function resolveActiveProductSet(
  rootItemId: number,
  definitions: Record<string, ProductSetDefinition>,
  selections: ProductSetSelections,
  maxDepth = 64,
): ActiveProductSet {
  const activeDefinitions: ActiveProductSetDefinition[] = []
  const activeSelections: ProductSetSelections = {}
  const componentItemIds = new Set<number>()
  const visited = new Set<number>()

  function visit(itemId: number, depth: number) {
    if (depth > maxDepth || visited.has(itemId)) return

    const definition = definitions[String(itemId)]
    if (!definition) return

    visited.add(itemId)
    activeDefinitions.push({ itemId, definition })

    const children = definition.components.map(component => component.item_id)
    for (const componentId of children) componentItemIds.add(componentId)

    const selectedGroups = selections[String(itemId)] ?? {}
    for (const group of definition.groups) {
      const optionByCode = new Map(group.options.map(option => [option.code, option]))
      const chosenCodes = [...new Set(selectedGroups[group.code] ?? [])]
        .filter(code => optionByCode.has(code))

      if (chosenCodes.length > 0) {
        const perSet = activeSelections[String(itemId)] ?? (activeSelections[String(itemId)] = {})
        perSet[group.code] = chosenCodes
      }

      for (const code of chosenCodes) {
        const option = optionByCode.get(code)!
        componentItemIds.add(option.item_id)
        children.push(option.item_id)
      }
    }

    for (const childId of children) visit(childId, depth + 1)
  }

  visit(rootItemId, 0)

  for (const definition of activeDefinitions) componentItemIds.delete(definition.itemId)

  return {
    definitions: activeDefinitions,
    selections: activeSelections,
    componentItemIds: [...componentItemIds],
  }
}

/** Replaces only the expansion root key while preserving selections of nested sets. */
export function remapRootProductSetSelections(
  selections: ProductSetSelections,
  recipeItemId: number,
  targetItemId: number,
): ProductSetSelections {
  if (recipeItemId === targetItemId) return selections

  const rootSelections = selections[String(recipeItemId)]
  if (!rootSelections) return selections

  const { [String(recipeItemId)]: _removed, ...nestedSelections } = selections
  return { ...nestedSelections, [String(targetItemId)]: rootSelections }
}
