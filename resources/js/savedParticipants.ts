import type { State } from './types';

export type SavedParticipant = { token: string; code: string; name: string; participantId: number };
const savedKey = 'quente-frio-saved-participants-v1';

export function readSavedParticipants(storage?: Storage): SavedParticipant[] {
  try {
    const data: unknown = JSON.parse((storage ?? globalThis.localStorage).getItem(savedKey) ?? '[]');
    if (!Array.isArray(data)) return [];
    return data.filter((item): item is SavedParticipant => !!item && typeof item.token === 'string' && item.token.length === 64
      && typeof item.code === 'string' && typeof item.name === 'string' && Number.isInteger(item.participantId));
  } catch { return []; }
}

export function rememberParticipant(token: string, state: State, storage?: Storage): boolean {
  try {
    const target = storage ?? globalThis.localStorage;
    const saved = readSavedParticipants(target).filter(item => item.token !== token);
    target.setItem(savedKey, JSON.stringify([{token, code: state.code, name: state.you.name, participantId: state.you.id}, ...saved]));
    return true;
  } catch { return false; }
}

export function forgetParticipant(token: string, storage?: Storage): void {
  try {
    const target = storage ?? globalThis.localStorage;
    target.setItem(savedKey, JSON.stringify(readSavedParticipants(target).filter(item => item.token !== token)));
  } catch { /* The active session still works when persistent storage is unavailable. */ }
}
