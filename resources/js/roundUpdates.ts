import type { State, Update } from './types';

// Reverb, polling and HTTP responses can arrive in any order.
export function mergeRoomUpdate(current: State | null, update: Update): State | null {
  const next = update.state;
  if (current && (next.round_number < current.round_number || next.version < current.version)) return null;
  if (current && next.version === current.version && next.server_now && current.server_now && Date.parse(next.server_now) < Date.parse(current.server_now)) return null;
  const changed = !!current && next.round_number !== current.round_number;
  let guesses = next.guesses ?? (changed ? [] : current?.guesses ?? []);
  const guess = update.guess_update;
  if (guess && guess.round_number === next.round_number) {
    guesses = [...guesses.filter(item => item.id !== guess.id), guess].sort((a, b) => a.id - b.id);
  }
  // Never spread the previous state: its secret and proximity belonged to the old role.
  return { ...next, competition: next.competition ?? current?.competition, guesses, rounds: next.rounds ?? current?.rounds ?? [], scores: next.scores ?? current?.scores ?? [] };
}
