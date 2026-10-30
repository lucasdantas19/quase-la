import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mergeRoomUpdate } from '../../resources/js/roundUpdates.ts';

const previous = {
  round_number: 1, version: 8, you: {id: 1, role: 'evaluator'},
  status: 'finished', secret: 'violão', proximity: 90,
  guesses: [{id: 1, round_number: 1, text: 'violão', status: 'accepted'}],
  rounds: [{number: 1, secret: 'violão'}], scores: [{id: 1, points: 1}],
};
const transition = {round_number: 2, version: 10, status: 'playing', you: {id: 1, role: 'drawer'}};

test('transition clears the previous secret numeric score and guesses', () => {
  const next = mergeRoomUpdate(previous, {state: transition});
  assert.equal(next.you.role, 'drawer');
  assert.equal('secret' in next, false);
  assert.equal('proximity' in next, false);
  assert.deepEqual(next.guesses, []);
  assert.deepEqual(next.rounds, previous.rounds);
  assert.deepEqual(next.scores, previous.scores);
});
test('an old round cannot overwrite a new round even with a larger version', () => {
  const current = mergeRoomUpdate(previous, {state: transition});
  assert.equal(mergeRoomUpdate(current, {state: {...previous, version: 999}}), null);
  assert.equal(mergeRoomUpdate(current, {state: previous, delta: {kind: 'clear', revision: 999}}), null);
});
test('out-of-order snapshots and same-round updates cannot revert state', () => {
  const current = mergeRoomUpdate(previous, {state: transition});
  assert.equal(mergeRoomUpdate(current, {state: {...transition, version: 9, secret: 'old'}}), null);
});
test('a guess delta from another round cannot contaminate the current history', () => {
  const next = mergeRoomUpdate(previous, {state: transition, guess_update: previous.guesses[0]});
  assert.deepEqual(next.guesses, []);
});
test('reconnect restores the active round history without blending previous guesses', () => {
  const guess = {id: 5, round_number: 2, text: 'novo palpite', status: 'pending'};
  const next = mergeRoomUpdate(previous, {state: {...transition, guesses: [guess], rounds: previous.rounds}});
  assert.deepEqual(next.guesses, [guess]);
  const updated = {...guess, status: 'rejected'};
  assert.deepEqual(mergeRoomUpdate(next, {state: {...transition, version: 11}, guess_update: updated}).guesses, [updated]);
});

test('late playing snapshots cannot reopen a room closed by departure', () => {
  const closed={...transition, status:'closed', version:12, server_now:'2026-10-29T12:00:20Z'};
  assert.equal(mergeRoomUpdate(closed,{state:{...transition,version:11}}),null);
});
test('late heartbeat snapshots at the same version cannot revert a newer presence state', () => {
  const current={...transition,server_now:'2026-10-29T12:00:20Z',participants:[{id:2,is_online:false}]};
  assert.equal(mergeRoomUpdate(current,{state:{...transition,server_now:'2026-10-29T12:00:10Z',participants:[{id:2,is_online:true}]}}),null);
});

test('keeps the competition ranking across private deltas and refreshes it from snapshots', () => {
  const competition = {code: 'MATCH1', started: true, team_count: 2, teams: [{id: 1, points: 2}]};
  const current = {...transition, competition};
  const delta = mergeRoomUpdate(current, {state: {...transition, version: 11}});
  assert.deepEqual(delta.competition, competition);
  const refreshed = {...competition, teams: [{id: 2, points: 4}, {id: 1, points: 2}]};
  const snapshot = mergeRoomUpdate(delta, {state: {...transition, version: 11, competition: refreshed}});
  assert.deepEqual(snapshot.competition, refreshed);
});

test('a final competition snapshot survives delayed round updates and private deltas', () => {
  const final = {code: 'MATCH1', ended_at: '2026-10-29T15:00:00Z', teams: [{id: 2, points: 4}]};
  const current = {...transition, status: 'closed', version: 12, competition: final};
  assert.equal(mergeRoomUpdate(current, {state: {...transition, version: 11}}), null);
  const updated = mergeRoomUpdate(current, {state: {...transition, status: 'closed', version: 13}});
  assert.deepEqual(updated.competition, final);
});

test('late snapshots cannot restore the original deadline after the final window arrives', () => {
  const original = {...transition, ends_at: '2026-10-29T12:03:00Z', final_window_started_at: null};
  const shortened = {...original, version: original.version + 1, ends_at: '2026-10-29T12:00:40Z', final_window_started_at: '2026-10-29T12:00:10Z'};
  const current = mergeRoomUpdate(original, {state: shortened});
  assert.equal(current.ends_at, shortened.ends_at);
  assert.equal(mergeRoomUpdate(current, {state: original}), null);
});

test('the final window notice clears when a new round begins', () => {
  const current = {...transition, final_window_started_at: '2026-10-29T12:00:10Z'};
  const next = mergeRoomUpdate(current, {state: {...transition, version: transition.version + 1, round_number: 3, final_window_started_at: null}});
  assert.equal(next.final_window_started_at, null);
});
