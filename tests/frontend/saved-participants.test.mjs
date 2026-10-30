import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readSavedParticipants, rememberParticipant, forgetParticipant } from '../../resources/js/savedParticipants.ts';

function memoryStorage() {
  const data=new Map();
  return {getItem:key=>data.get(key)??null,setItem:(key,value)=>data.set(key,value)};
}
const a='a'.repeat(64), b='b'.repeat(64);
const ana={code:'ABC123',you:{id:1,name:'Ana'}};
const bia={code:'ABC123',you:{id:2,name:'Bia'}};

test('saves both participants independently and restores them after the active tab is gone',()=>{
  const storage=memoryStorage();
  rememberParticipant(a,ana,storage);
  rememberParticipant(b,bia,storage);
  const recovered=readSavedParticipants(storage);
  assert.equal(recovered.length,2);
  assert.deepEqual(recovered.find(item=>item.token===a),{token:a,name:'Ana',code:'ABC123',participantId:1});
  assert.equal(recovered.find(item=>item.token===b).name,'Bia');
});
test('updates a saved participant without duplicates and removes only the participant who leaves',()=>{
  const storage=memoryStorage();
  rememberParticipant(a,ana,storage);
  rememberParticipant(b,bia,storage);
  rememberParticipant(a,ana,storage);
  assert.equal(readSavedParticipants(storage).length,2);
  forgetParticipant(a,storage);
  assert.deepEqual(readSavedParticipants(storage).map(item=>item.token),[b]);
});
test('handles unavailable or corrupted storage without breaking gameplay',()=>{
  const unavailable={getItem:()=>{throw new Error('blocked');},setItem:()=>{throw new Error('blocked');}};
  assert.deepEqual(readSavedParticipants(unavailable),[]);
  assert.equal(rememberParticipant(a,ana,unavailable),false);
  assert.doesNotThrow(()=>forgetParticipant(a,unavailable));
  const storage=memoryStorage();
  storage.setItem('quente-frio-saved-participants-v1','invalid JSON');
  assert.deepEqual(readSavedParticipants(storage),[]);
});
