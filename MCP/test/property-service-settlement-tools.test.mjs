import assert from 'node:assert/strict';
import test from 'node:test';
import { TOOLS_BY_NAME } from '../src/tools.mjs';
import { validateToolArguments } from '../src/tool-args.mjs';
test('S8 tools call only bounded curated public reads and reject write/tenant proof fields', async () => {
 const cases=[['list_property_service_settlements',{after_id:3,limit:25,unit_id:7,service_id:9},'/property/catalog/service-settlements',{after_id:3,limit:25,unit_id:7,service_id:9}],['get_property_service_settlement',{id:4},'/property/catalog/service-settlements/4',null],['property_service_settlement_current',{id:4},'/property/catalog/service-settlements/4/current',null]];
 for(const[name,args,path,query]of cases){const tool=TOOLS_BY_NAME.get(name);assert.equal(tool.write,false);assert.equal(tool.inputSchema.additionalProperties,false);let called;const expected={scope:'recorded_s8_documents',complete_financial_position:false};const actual=await tool.run({get:async(p,q)=>{called={path:p,query:q};return expected;}},validateToolArguments(tool,args),name);assert.equal(actual,expected);assert.deepEqual(called,{path,query});for(const extra of [{supplier_id:2},{request:{}},{preview_hash:'a'.repeat(64)},{write:true}])assert.throws(()=>validateToolArguments(tool,{...args,...extra}));}
 const list=TOOLS_BY_NAME.get('list_property_service_settlements');for(const bad of [{limit:101},{after_id:-1}])assert.throws(()=>validateToolArguments(list,bad));for(const name of ['get_property_service_settlement','property_service_settlement_current'])for(const id of [0,-1,'../apply','1/../../admin'])assert.throws(()=>validateToolArguments(TOOLS_BY_NAME.get(name),{id}));
});
