import { endpoint } from './endpoint-tool.mjs';
import { pick } from './tool-schema.mjs';

export const API_DOCUMENTS_TOOLS = [
  endpoint('rename_document_folder', 'Přejmenování složky',
    'Přejmenování složky.',
    '/document-folders/{id}', {
    method: 'PATCH',
    params: { id: { type: 'integer', description: 'ID záznamu.', minimum: 1 } },
    body: { name: { type: 'string', description: 'Název.', maxLength: 255 } },
    required: ['name'],
  }),
  endpoint('trash_document_folder', 'Přesun složky, podsložek a dokumentů do koše',
    'Přesun složky, podsložek a dokumentů do koše. Pro provedení je potřeba confirm: true.',
    '/document-folders/{id}', {
    method: 'DELETE',
    confirm: true,
    preview: async (c, a, tool) => {
      const { folders } = await c.get('/document-folders', null, tool);
      const root = folders.find((folder) => Number(folder.id) === a.id);
      if (!root) return { folder: null, count: 0, items: [] };
      const children = new Map();
      for (const folder of folders) {
        const parentId = Number(folder.parent_id);
        if (!children.has(parentId)) children.set(parentId, []);
        children.get(parentId).push(folder);
      }
      const subtree = [root];
      const items = [];
      for (const folder of subtree) {
        subtree.push(...(children.get(Number(folder.id)) ?? []));
        items.push({ type: 'folder', ...pick(folder, ['id', 'name', 'parent_id']) });
        const { documents } = await c.get('/documents', { folder_id: folder.id }, tool);
        for (const record of documents) {
          items.push({ type: 'document', ...pick(record, ['id', 'title', 'name', 'filename', 'folder_id']) });
        }
      }
      return { count: items.length, items: items.slice(0, 50), shown: Math.min(items.length, 50) };
    },
    params: { id: { type: 'integer', description: 'ID záznamu.', minimum: 1 } },
  }),
  endpoint('move_document_folder', 'Přesun složky pod jinou nadřazenou složku',
    'Přesune složku do jiné složky nebo do kořene. Cílem nemůže být její vlastní podsložka ani složka sama. Pro provedení je potřeba confirm: true.',
    '/document-folders/{id}/move', {
    method: 'POST',
    confirm: true,
    preview: async (c, a, tool) => {
      const { folders } = await c.get('/document-folders', null, tool);
      const folder = folders.find((row) => Number(row.id) === a.id) ?? null;
      const parent = folders.find((row) => Number(row.id) === a.parent_id) ?? null;
      return { folder, parent };
    },
    params: { id: { type: 'integer', description: 'ID záznamu.', minimum: 1 } },
    body: { parent_id: { type: ['integer', 'null'], description: 'NULL = přesun do kořene', minimum: 1 } },
    required: ['parent_id'],
  }),
  endpoint('restore_document_folder', 'Obnovení složky z koše',
    'Obnovení složky z koše.',
    '/document-folders/{id}/restore', {
    method: 'POST',
    params: { id: { type: 'integer', description: 'ID záznamu.', minimum: 1 } },
  }),
  endpoint('delete_document_request', 'Zruší požadavek na doklad',
    'Zruší požadavek na doklad. Pro provedení je potřeba confirm: true.',
    '/document-requests/{id}', {
    method: 'DELETE',
    confirm: true,
    preview: '/document-requests/{id}',
    params: { id: { type: 'integer', description: 'ID záznamu.', minimum: 1 } },
  }),
  endpoint('list_trashed_documents', 'Obsah koše dokumentů a složek',
    'Obsah koše dokumentů a složek.',
    '/documents/trash'),
  endpoint('trash_document', 'Přesun dokumentu do koše',
    'Přesun dokumentu do koše. Pro provedení je potřeba confirm: true.',
    '/documents/{id}', {
    method: 'DELETE',
    confirm: true,
    preview: '/documents/{id}',
    params: { id: { type: 'integer', description: 'ID záznamu.', minimum: 1 } },
  }),
  endpoint('update_document_file', 'Nastavit hlavní soubor nebo pořadí souboru',
    'Změní pořadí nebo nastaví soubor jako hlavní. Dosavadní hlavní soubor se pak přesune mezi přílohy.',
    '/documents/{id}/files/{file_id}', {
    method: 'PATCH',
    params: {
      id: { type: 'integer', description: 'ID záznamu.', minimum: 1 },
      file_id: { type: 'integer', description: 'ID souboru.', minimum: 1 },
    },
    body: {
      role: { type: 'string', description: 'Role souboru v dokumentu: hlavní soubor (`primary`) nebo příloha (`attachment`).', enum: ['primary', 'attachment'] },
      sort_order: { type: 'integer', description: 'Pořadí souboru mezi ostatními soubory dokumentu.' },
    },
  }),
  endpoint('download_document_file', 'Stažení vybraného souboru dokumentu',
    'Stažení vybraného souboru dokumentu.',
    '/documents/{id}/files/{file_id}/download', {
    file: true,
    params: {
      id: { type: 'integer', description: 'ID záznamu.', minimum: 1 },
      file_id: { type: 'integer', description: 'ID souboru.', minimum: 1 },
    },
  }),
  endpoint('restore_document', 'Obnovení dokumentu z koše',
    'Obnovení dokumentu z koše.',
    '/documents/{id}/restore', {
    method: 'POST',
    params: { id: { type: 'integer', description: 'ID záznamu.', minimum: 1 } },
  }),
];
