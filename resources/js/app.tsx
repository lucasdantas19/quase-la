import { useEffect, useRef, useState, type FormEvent } from 'react';
import { createRoot } from 'react-dom/client';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import WelcomeScreen from './components/WelcomeScreen';
import GameScreen from './components/GameScreen';
import { rememberParticipant, forgetParticipant, type SavedParticipant } from './savedParticipants';
import { mergeRoomUpdate } from './roundUpdates';
import { api, ApiError } from './api';
import type { State, Chunk, Operation, Update } from './types';
import '../css/app.css';

const credentialKey = 'quente-frio-participant';

function App() {
  const [token, setToken] = useState<string | null>(() => sessionStorage.getItem(credentialKey));
  const sessionEpoch = useRef(0);
  const activeToken = useRef(token);
  const [competitionConfirm, setCompetitionConfirm] = useState<{action: 'end' | 'remove'; roomId?: number; names?: string} | null>(null);
  const [leaveConfirm, setLeaveConfirm] = useState(false);
  const [state, setState] = useState<State | null>(null);
  const stateRef = useRef<State | null>(null);
  const [drawing, setDrawing] = useState<Chunk[]>([]);
  const canonical = useRef<Chunk[]>([]);
  const revision = useRef(0);
  const [pending, setPending] = useState<Operation[]>([]);
  const queue = useRef<Operation[]>([]);
  const processing = useRef(false);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const [connection, setConnection] = useState('Conectando');
  const [name, setName] = useState('');
  const [code, setCode] = useState('');
  const [role, setRole] = useState('drawer');
  const [competitive, setCompetitive] = useState(false);
  const [competitionCode, setCompetitionCode] = useState('');
  const [joining, setJoining] = useState(false);
  const [guess, setGuess] = useState('');
  const [guessResult, setGuessResult] = useState('');
  const [color, setColor] = useState('#18252b');
  const [width, setWidth] = useState(5);
  const [score, setScore] = useState(0);
  const [tick, setTick] = useState(Date.now());
  const offset = useRef(0);
  const feedbackTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const syncRef = useRef<() => Promise<void>>(async () => {});
  const alive = useRef(true);
  const renderDrawing = () => {
    let all = [...canonical.current];
    for (const op of queue.current) {
      if (op.kind === 'clear') {
        all = [];
      } else {
        all.push(op as Chunk);
      }
    }
    setDrawing(all);
    setPending([...queue.current]);
  };
  const accept = (update: Update) => {
    const next = update.state;
    const current = stateRef.current;
    const merged = mergeRoomUOkpdate(current, update);
    if (!merged) return;
    const changed = !!current && next.round_number !== current.round_number;
    if (next.competition?.ended_at) setCompetitionConfirm(null);
    if (next.status === 'closed') {
      setCompetitionConfirm(null);
      queue.current = [];
      if (feedbackTimer.current) clearTimeout(feedbackTimer.current);
      if (current?.status !== 'closed') setLeaveConfirm(false);
    }
    if (changed) {
      queue.current = [];
      canonical.current = [];
      revision.current = 0;
      if (feedbackTimer.current) clearTimeout(feedbackTimer.current);
      setGuess('');
      setGuessResult('');
      setScore(0);
      setError('');
    }
    offset.current = Date.parse(next.server_now) - Date.now();
    if (next.drawing) {
      canonical.current = next.drawing;
      revision.current = next.revision;
    } else if (update.delta && update.delta.revision === revision.current + 1) {
      canonical.current = update.delta.kind === 'clear' ? [] : [...canonical.current, update.delta.chunk!];
      revision.current = update.delta.revision;
    } else if (next.revision > revision.current) {
      void syncRef.current();
    }
    stateRef.current = merged;
    setState(merged);
    renderDrawing();
    if (next.proximity !== undefined) setScore(next.proximity);
    if ((changed || current?.status !== next.status) && !next.rounds) {
      void syncRef.current();
    }
  };
  const acceptRef = useRef(accept);
  acceptRef.current = accept;
  useEffect(() => {
    if (!token) return;
    alive.current = true;
    let stopped = false;
    let syncing = false;
    let syncAgain = false;
    const sync = async () => {
      if (stopped) return;
      if (syncing) {
        syncAgain = true;
        return;
      }
      syncing = true;
      try {
        const next = await api<State>('room', token);
        if (!stopped && activeToken.current === token) {
          if (!stateRef.current) rememberParticipant(token, next);
          acceptRef.current({
            state: next
          });
          setError(previous => previous === 'Sem conexão com o servidor.' ? '' : previous);
        }
      } catch (e) {
        if (!stopped) {
          setError(e instanceof ApiError ? e.message : 'Sem conexão com o servidor.');
          if (e instanceof ApiError && e.status === 401) {
            forgetParticipant(token);
            resetSession();
            setError('Esta participação foi encerrada ou não está mais disponível.');
          }
        }
      } finally {
        syncing = false;
        if (syncAgain) {
          syncAgain = false;
          void sync();
        }
      }
    };
    syncRef.current = sync;
    void sync();
    const interval = setInterval(() => void sync(), 5000);
    const wake = () => void sync();
    window.addEventListener('online', wake);
    document.addEventListener('visibilitychange', wake);
    return () => {
      stopped = true;
      alive.current = false;
      clearInterval(interval);
      window.removeEventListener('online', wake);
      document.removeEventListener('visibilitychange', wake);
    };
  }, [token]);
  useEffect(() => {f
    if (!token || !state?.you.id) return;
    const echo = new Echo({
      broadcaster: 'reverb',
      client: new Pusher(import.meta.env.VITE_REVERB_APP_KEY, {
        wsHost: import.meta.env.VITE_REVERB_HOST || location.hostname,
        wsPort: Number(import.meta.env.VITE_REVERB_PORT || 8080),
        wssPort: Number(import.meta.env.VITE_REVERB_PORT || 8080),
        forceTLS: import.meta.env.VITE_REVERB_SCHEME === 'https',
        enabledTransports: ['ws', 'wss'],
        cluster: '',
        channelAuthorization: {
          endpoint: '/api/broadcasting/auth',
          transport: 'ajax',
          headers: {
            Authorization: `Bearer ${token}`,
            Accept: 'application/json'
          }
        }
      })
    });
    echo.private(`participants.${state.you.id}`)
      .listen('.game.updated', (event: Update) => {
        if (activeToken.current === token) acceptRef.current(event);
      })
      .subscribed(() => {
        setConnection('Ao vivo');
        void syncRef.current();
      })
      .error(() => setConnection('Reconectando · resgate a cada 5s'));
    const pusher = echo.connector.pusher;
    pusher.connection.bind('state_change', ({
      current
    }: {
      current: string;
    }) => {
      if (current !== 'connected') setConnection('Reconectando · resgate a cada 5s');
    });
    return () => echo.disconnect();
  }, [token, state?.you.id]);
  useEffect(() => {
    const timer = setInterval(() => setTick(Date.now()), 250);
    return () => clearInterval(timer);
  }, []);
  const remaining = state?.ends_at ? Math.max(0, Math.ceil((Date.parse(state.ends_at) - tick - offset.current) / 1000)) : 180;
  useEffect(() => {
    if (state?.status === 'playing' && remaining === 0) void syncRef.current();
  }, [remaining, state?.status]);
  const run = async (action: string, data: Record<string, unknown> = {}) => {
    setError('');
    const epoch = sessionEpoch.current;
    const result = await api<Update>(`room/${action}`, token, {round_number: stateRef.current?.round_number, ...data});
    if (epoch !== sessionEpoch.current) return;
    acceptRef.current(result);
    return result;
  };
  const drain = async () => {
    if (processing.current) return;
    processing.current = true;
    const epoch = sessionEpoch.current;
    try {
      while (queue.current.length && alive.current && epoch === sessionEpoch.current) {
        const op = queue.current[0];
        if (op.base_revision === undefined) op.base_revision = revision.current;
        try {
          const result = await api<Update>('room/draw', token, op);
          if (epoch !== sessionEpoch.current) break;
          if (queue.current[0] === op) queue.current.shift();
          acceptRef.current(result);
          renderDrawing();
        } catch (e) {
          if (epoch !== sessionEpoch.current) break;
          if (op.round_number !== stateRef.current?.round_number) continue;
          if (e instanceof ApiError && e.status < 500 && e.status !== 429) {
            queue.current = [];
            renderDrawing();
            setError(e.message);
            await syncRef.current();
            break;
          }
          setError('Conexão interrompida. Seus traços aguardam reenvio.');
          await new Promise(resolve => setTimeout(resolve, 1500));
        }
      }
    } finally {
      if (epoch === sessionEpoch.current) processing.current = false;
    }
  };
  const enqueue = (op: Omit<Operation, 'round_number'>) => {
    if (!stateRef.current) return;
    queue.current.push({...op, round_number: stateRef.current.round_number});
    renderDrawing();
    void drain();
  };
  const enter = async (e: FormEvent) => {
    e.preventDefault();
    setBusy(true);
    setError('');
    try {
      const result = await api<{
        token: string;
        state: State;
      }>(joining ? 'rooms/join' : 'rooms', null, {
        name: name.trim(),
        ...(joining ? {
          code: code.toUpperCase()
        } : {
          role, competitive, competition_code: competitionCode.trim().toUpperCase() || null
        })
      });
      resetSession();
      activeToken.current = result.token;
      rememberParticipant(result.token, result.state);
      sessionStorage.setItem(credentialKey, result.token);
      acceptRef.current({
        state: result.state
      });
      setToken(result.token);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Não foi possível entrar.');
    } finally {
      setBusy(false);
    }
  };
  const resetSession = () => {
    sessionEpoch.current++;
    activeToken.current = null;
    alive.current = false;
    processing.current = false;
    queue.current = [];
    canonical.current = [];
    revision.current = 0;
    stateRef.current = null;
    if (feedbackTimer.current) clearTimeout(feedbackTimer.current);
    sessionStorage.removeItem(credentialKey);
    setCompetitionConfirm(null);
    setState(null);
    setDrawing([]);
    setPending([]);
    setToken(null);
    setLeaveConfirm(false);
    setBusy(false);
    setGuess('');
    setGuessResult('');
    setConnection('Conectando');
  };
  const resume = async (saved: SavedParticipant) => {
    setBusy(true);
    setError('');
    try {
      const next = await api<State>('room', saved.token);
      resetSession();
      activeToken.current = saved.token;
      sessionStorage.setItem(credentialKey, saved.token);
      rememberParticipant(saved.token, next);
      acceptRef.current({state: next});
      setToken(saved.token);
    } catch (e) {
      if (e instanceof ApiError && e.status === 401) forgetParticipant(saved.token);
      setError(e instanceof ApiError ? e.message : 'Não foi possível reconectar. Tente novamente.');
    } finally {
      setBusy(false);
    }
  };
  const leave = async () => {
    if (!token) return;
    setBusy(true);
    setError('');
    try {
      await api<State>('room/leave', token, {});
      forgetParticipant(token);
      resetSession();
    } catch (e) {
      if (e instanceof ApiError && e.status === 401) {
        forgetParticipant(token);
        resetSession();
      } else {
        setError('Não foi possível confirmar a saída. Verifique sua conexão e tente novamente.');
      }
    } finally {
      setBusy(false);
    }
  };
  const action = async (kind: string, data: Record<string, unknown> = {}) => {
    const epoch = sessionEpoch.current;
    setBusy(true);
    try {
      return await run(kind, data);
    } catch (e) {
      if (epoch !== sessionEpoch.current) return;
      if (e instanceof ApiError && e.status === 409) void syncRef.current();
      setError(e instanceof Error ? e.message : 'Falha de conexão.');
    } finally {
      if (epoch === sessionEpoch.current) setBusy(false);
    }
  };
  const manageCompetition = async () => {
    if (!competitionConfirm || !token || !state) return;
    const epoch = sessionEpoch.current;
    setBusy(true);
    setError('');
    try {
      const next = await api<State>(`competition/${competitionConfirm.action}`, token, {round_number: state.round_number, room_id: competitionConfirm.roomId});
      if (epoch !== sessionEpoch.current) return;
      acceptRef.current({state: next});
      setCompetitionConfirm(null);
    } catch (e) {
      if (epoch !== sessionEpoch.current) return;
      setError(e instanceof Error ? e.message : 'Não foi possível atualizar a partida.');
      void syncRef.current();
    } finally {
      if (epoch === sessionEpoch.current) setBusy(false);
    }
  };
  const sendGuess = async (e: FormEvent) => {
    e.preventDefault();
    const result = await action('guess', {
      guess: guess.trim()
    });
    if (result && result.state.round_number === stateRef.current?.round_number) {
      setGuessResult(result.correct ? 'Acertou!' : 'Ainda não foi reconhecido. O avaliador pode aceitar; continue tentando!');
      setGuess('');
    }
  };
  const changeScore = (value: number) => {
    setScore(value);
    if (feedbackTimer.current) clearTimeout(feedbackTimer.current);
    const roundNumber = stateRef.current?.round_number;
    feedbackTimer.current = setTimeout(() => {
      void action('feedback', {
        score: value, round_number: roundNumber
      });
    }, 150);
  };
  useEffect(() => () => {
    if (feedbackTimer.current) clearTimeout(feedbackTimer.current);
  }, []);
  const closed = state?.status === 'closed';
  return (
    <div className="app-shell">
      <header>
        <a className="brand" href="/" aria-label="Quente ou Frio, início">
          <span className="brand-mark">≈</span>
          <span>quente<span className="brand-light">ou</span>frio<small>POR DESENHO</small></span>
        </a>
        <span className="edition">UM DESENHO. MUITAS TENTATIVAS.</span>
        {state && <span className="live"><i />{connection}</span>}
        {state && (
          <button className="tool leave-control" onClick={() => setLeaveConfirm(true)}>
            Sair da sala
          </button>
        )}
      </header>

      {!state ? (
        token ? (
          <main className="loading-session">
            <p role="status">Reconectando à sua sala…</p>
          </main>
        ) : (
          <WelcomeScreen
            busy={busy}
            name={name}
            code={code}
            role={role}
            competitive={competitive}
            competitionCode={competitionCode}
            joining={joining}
            onNameChange={setName}
            onCodeChange={setCode}
            onRoleChange={setRole}
            onCompetitiveChange={setCompetitive}
            onCompetitionCodeChange={setCompetitionCode}
            onJoiningChange={setJoining}
            onEnter={enter}
            onResume={resume}
          />
        )
      ) : (
        <GameScreen
          state={state}
          token={token!}
          remaining={remaining}
          drawing={drawing}
          pending={pending}
          busy={busy}
          color={color}
          width={width}
          guess={guess}
          guessResult={guessResult}
          score={score}
          onCompetitionConfirm={setCompetitionConfirm}
          onLeaveConfirm={setLeaveConfirm}
          onError={setError}
          onAction={action}
          onEnqueue={enqueue}
          onColorChange={setColor}
          onWidthChange={setWidth}
          onGuessChange={setGuess}
          onScoreChange={changeScore}
          onSendGuess={sendGuess}
        />
      )}

      {competitionConfirm && (
        <div className="leave-backdrop">
          <section
            role="dialog"
            aria-modal="true"
            aria-labelledby="competition-confirm-heading"
            className="card leave-dialog"
          >
            <h2 id="competition-confirm-heading">
              {competitionConfirm.action === 'end' ? 'Encerrar partida?' : 'Remover dupla incompleta?'}
            </h2>
            <p>
              {competitionConfirm.action === 'end'
                ? 'As rodadas em andamento serão interrompidas, sem pontos. Os acertos já registrados serão mantidos e a classificação ficará definitiva.'
                : `A sala de ${competitionConfirm.names} será encerrada e sua vaga no lobby será liberada.`}
            </p>
            <button className="primary" disabled={busy} onClick={() => void manageCompetition()}>
              {competitionConfirm.action === 'end' ? 'Confirmar encerramento' : 'Confirmar remoção'}
            </button>
            <button className="tool" disabled={busy} onClick={() => setCompetitionConfirm(null)}>
              Cancelar
            </button>
          </section>
        </div>
      )}

      {leaveConfirm && (
        <div className="leave-backdrop">
          <section role="dialog" aria-modal="true" aria-labelledby="leave-heading" className="card leave-dialog">
            <h2 id="leave-heading">Sair da sala?</h2>
            <p>
              {closed
                ? 'Você encerrará sua participação. O histórico continuará disponível ao parceiro.'
                : 'Sair encerra a sala para os dois. A rodada em andamento será registrada como abandonada, sem pontos.'}
            </p>
            <button className="primary" disabled={busy} onClick={() => void leave()}>
              Sair e encerrar participação
            </button>
            <button className="tool" disabled={busy} onClick={() => setLeaveConfirm(false)}>
              Continuar na sala
            </button>
          </section>
        </div>
      )}

      {error && (
        <div className="error" role="alert">
          {error}
          <button aria-label="Fechar aviso" onClick={() => setError('')}>×</button>
        </div>
      )}
      <footer>
        <span>Feito de tentativas e boas ideias.</span>
        <span>QUENTE OU FRIO <span className="footer-dot">●</span> POR DESENHO</span>
      </footer>
    </div>
  );
}
createRoot(document.getElementById('root')!).render(<App />);
