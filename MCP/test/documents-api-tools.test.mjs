import { testEndpoints } from './support/endpoint-cases.mjs';

testEndpoints([
  ['rename_document_folder',
    { id: 1, name: 'Test' }, 'PATCH', '/document-folders/1', { name: 'Test' }],
  ['trash_document_folder',
    { id: 1, confirm: true }, 'DELETE', '/document-folders/1', null],
  ['move_document_folder',
    { id: 1, parent_id: 1, confirm: true }, 'POST', '/document-folders/1/move', { parent_id: 1 }],
  ['restore_document_folder',
    { id: 1 }, 'POST', '/document-folders/1/restore', {}],
  ['delete_document_request',
    { id: 1, confirm: true }, 'DELETE', '/document-requests/1', null],
  ['list_trashed_documents',
    {}, 'GET', '/documents/trash', null],
  ['trash_document',
    { id: 1, confirm: true }, 'DELETE', '/documents/1', null],
  ['update_document_file',
    { id: 1, file_id: 1, role: 'primary', sort_order: 1 }, 'PATCH', '/documents/1/files/1', { role: 'primary', sort_order: 1 }],
  ['download_document_file',
    { id: 1, file_id: 1 }, 'DOWNLOAD', '/documents/1/files/1/download', null],
  ['restore_document',
    { id: 1 }, 'POST', '/documents/1/restore', {}],
]);
