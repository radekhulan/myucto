import assert from 'node:assert/strict';
import test from 'node:test';
import { TOOLS_BY_NAME } from '../src/tools.mjs';
import { validateToolArguments } from '../src/tool-args.mjs';
test('opening catalog remains a bounded curated read without mutation capability', async () => {
 const tool=TOOLS_BY_NAME.get('list_property_openings'); assert.equal(tool.write,false); assert.equal(tool.inputSchema.additionalProperties,false);
 const expected={items:[],next_cursor:null,complete_financial_position:false};let request;
 const result=await tool.run({get:async(path,query)=>{request={path,query};return expected}},validateToolArguments(tool,{after_id:3,limit:25}),'list_property_openings');
 assert.equal(result,expected);assert.deepEqual(request,{path:'/property/catalog/openings',query:{after_id:3,limit:25}});
 for(const bad of [{limit:101},{after_id:-1},{request:{}},{supplier_id:2}])assert.throws(()=>validateToolArguments(tool,bad));
});
