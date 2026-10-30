export type Point = [number, number];
export type Chunk = {
  id: string;
  points: Point[];
  color: string;
  width: number;
};
export type Guess = {
  round_number: number;
  id: number;
  text: string;
  status: 'pending' | 'accepted' | 'rejected';
  judged_by: 'system' | 'evaluator' | null;
  created_at: string;
  judged_at: string | null;
};
export type RoundSummary = {number: number; secret: string; result: string; finished_at: string; points_each: number};
export type RoundDetail = RoundSummary & {participants: State['participants']; drawing: Chunk[]; proximity: number; started_at: string; ends_at: string; guesses: Guess[]};
export type Competition = {ended_at: string | null; organizer_id: number | null; organizer_name: string | null; is_organizer: boolean; code: string; started: boolean; team_count: number; teams: {id: number; names: string[]; status: string; round_number: number; ready: number; players: number; online: number; points: number}[]};
export type State = {
  competition?: Competition;
  final_window_started_at?: string | null;
  round_number: number;
  next_ready: number[];
  rounds?: RoundSummary[];
  scores?: {id: number; name: string; points: number}[];
  id: number;
  code: string;
  status: 'waiting' | 'playing' | 'finished' | 'closed';
  close_reason?: 'removed' | 'competition_ended' | null;
  closed_at: string | null;
  closed_by: number | null;
  version: number;
  revision: number;
  you: {
    id: number;
    name: string;
    role: 'drawer' | 'evaluator';
  };
  participants: {
    id: number;
    name: string;
    role: string;
    is_online?: boolean;
    left_at?: string | null;
  }[];
  temperature: string;
  server_now: string;
  ends_at: string | null;
  result: string | null;
  secret?: string | null;
  proximity?: number;
  drawing?: Chunk[];
  guesses?: Guess[];
};
export type Operation = {
  round_number: number;
  id: string;
  kind: 'stroke' | 'clear';
  points?: Point[];
  color?: string;
  width?: number;
  base_revision?: number;
};
export type Update = {
  state: State;
  guess_update?: Guess | null;
  delta?: {
    kind: 'stroke' | 'clear';
    chunk: Chunk | null;
    revision: number;
  } | null;
  correct?: boolean | null;
};
