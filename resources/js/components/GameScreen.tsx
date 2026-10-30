import type { FormEvent } from 'react';
import CompetitionPanel from './CompetitionPanel';
import DrawingCanvas from './DrawingCanvas';
import GuessHistory from './GuessHistory';
import RoundHistory from './RoundHistory';
import type { Chunk, Operation, State } from '../types';

type Props = {
  state: State;
  token: string;
  remaining: number;
  drawing: Chunk[];
  pending: Operation[];
  busy: boolean;
  color: string;
  width: number;
  guess: string;
  guessResult: string;
  score: number;
  onCompetitionConfirm: (confirm: { action: 'end' | 'remove'; roomId?: number; names?: string }) => void;
  onLeaveConfirm: (confirm: boolean) => void;
  onError: (message: string) => void;
  onAction: (kind: string, data?: Record<string, unknown>) => Promise<unknown>;
  onEnqueue: (operation: Omit<Operation, 'round_number'>) => void;
  onColorChange: (color: string) => void;
  onWidthChange: (width: number) => void;
  onGuessChange: (guess: string) => void;
  onScoreChange: (score: number) => void;
  onSendGuess: (event: FormEvent) => Promise<void>;
};

export default function GameScreen({
  state,
  token,
  remaining,
  drawing,
  pending,
  busy,
  color,
  width,
  guess,
  guessResult,
  score,
  onCompetitionConfirm,
  onLeaveConfirm,
  onError,
  onAction,
  onEnqueue,
  onColorChange,
  onWidthChange,
  onGuessChange,
  onScoreChange,
  onSendGuess
}: Props) {
  const playing = state.status === 'playing';
  const drawer = state.you.role === 'drawer';
  const closed = state.status === 'closed';
  const partner = state.participants.find(participant => participant.id !== state.you.id);
  const ready = state.next_ready.includes(state.you.id);
  const activeTeams = state.competition?.teams.filter(team => team.status !== 'closed') ?? [];
  const canStart = state.participants.length === 2
    && Boolean(partner?.is_online)
    && !busy
    && (!state.competition || (
      activeTeams.length >= 2
      && activeTeams.every(team => team.players === 2 && team.online === 2)
    ));

  let heading = 'Guie pelo calor.';
  if (state.status === 'waiting') heading = 'Uma dupla, muitas ideias.';
  else if (closed) heading = 'Sala encerrada.';
  else if (state.status === 'finished') heading = 'Cada traço valeu a tentativa.';
  else if (drawer) heading = 'O que será que é?';

  let resultTitle = state.result === 'guessed' ? 'Vocês acertaram!' : 'O tempo acabou.';
  let resultMessage = state.result === 'guessed'
    ? 'Boa sintonia entre traços e temperatura.'
    : 'A próxima ideia pode estar mais perto.';
  if (closed) {
    resultTitle = state.close_reason === 'competition_ended' ? 'Partida encerrada' : 'Sala encerrada';
    if (state.close_reason === 'competition_ended') {
      resultMessage = 'O organizador encerrou a partida. A classificação final e o histórico foram preservados.';
    } else if (state.close_reason === 'removed') {
      resultMessage = 'O organizador removeu esta dupla incompleta do lobby. Crie outra sala para jogar.';
    } else {
      resultMessage = 'Um participante saiu. O histórico foi preservado; para jogar novamente, crie outra sala.';
    }
  }

  return (
    <main className="game">
      <div className="room-heading">
        <div>
          <span className="eyebrow">SALA <b>{state.code}</b> · RODADA {state.round_number}</span>
          <h1>{heading}</h1>
        </div>
        <div className={`timer ${remaining <= 30 && playing ? 'urgent' : ''}`}>
          <small>TEMPO DA RODADA</small>
          <strong>{Math.floor(remaining / 60)}:{String(remaining % 60).padStart(2, '0')}</strong>
        </div>
      </div>

      {state.final_window_started_at && !closed && remaining > 0 && (
        <section className="final-window-notice" aria-labelledby="final-window-heading">
          <div>
            <strong id="final-window-heading" role="status">
              Uma dupla acertou! Faltam no máximo 30 segundos.
            </strong>
            <p>
              {state.result === 'guessed'
                ? 'Vocês já pontuaram. As demais duplas estão na reta final.'
                : 'Reta final! Envie seus palpites antes de o tempo acabar.'}
            </p>
          </div>
          <span role="timer" aria-live="off">{remaining}s restantes</span>
        </section>
      )}

      {state.competition && (
        <CompetitionPanel
          competition={state.competition}
          roomId={state.id}
          busy={busy}
          onEnd={() => onCompetitionConfirm({ action: 'end' })}
          onRemove={(roomId, names) => onCompetitionConfirm({ action: 'remove', roomId, names })}
        />
      )}

      <div className="presence-bar" role="status">
        {partner ? (
          <>
            <span className={partner.is_online ? 'online-dot' : 'offline-dot'} />
            {partner.name}: {partner.left_at
              ? 'saiu da sala'
              : partner.is_online ? 'conectado' : 'desconectado · vaga reservada'}
          </>
        ) : 'Aguardando seu parceiro entrar.'}
        {partner && !partner.is_online && !partner.left_at && playing && (
          <small>O cronômetro continua correndo. Seu parceiro pode retomar a participação.</small>
        )}
      </div>

      {state.status === 'waiting' ? (
        <div className="lobby card">
          <span className="lobby-icon">✎ + ☀</span>
          <h2>{state.participants.length === 2 ? 'Sua dupla está pronta!' : 'Falta só sua dupla chegar.'}</h2>
          <p>Compartilhe o código abaixo. A outra pessoa entra com o papel complementar.</p>
          <button
            className="room-code"
            onClick={() => navigator.clipboard?.writeText(state.code).catch(() => onError('Copie o código exibido para compartilhar.'))}
            title="Copiar código"
          >
            {state.code} <small>copiar</small>
          </button>
          <div className="players">
            {state.participants.map(participant => (
              <div key={participant.id}>
                <span className="avatar">{participant.name[0].toUpperCase()}</span>
                <strong>{participant.name}</strong>
                <span>
                  {participant.role === 'drawer' ? 'Desenhista' : 'Avaliador'}{' · '}
                  {participant.is_online ? 'conectado' : 'desconectado'}
                </span>
              </div>
            ))}
            {state.participants.length === 1 && (
              <div className="waiting-player">Aguardando participante…</div>
            )}
          </div>
          {(state.competition ? state.competition.is_organizer : !drawer) ? (
            <button className="primary" disabled={!canStart} onClick={() => void onAction('start')}>
              {state.competition ? 'Começar para todas as duplas →' : 'Começar rodada →'}
            </button>
          ) : (
            <p className="fine-print">
              {state.competition
                ? 'O organizador inicia quando todas as duplas estiverem prontas.'
                : 'O avaliador inicia quando os dois estiverem aqui.'}
            </p>
          )}
        </div>
      ) : (
        <div className="play-layout">
          <section className="drawing-panel card">
            <div className="canvas-heading">
              <span>
                <b>{drawer ? 'Seu desenho' : 'Desenho do parceiro'}</b>
                <small>
                  {drawer ? 'Experimente. Apague. Tente de novo.' : 'Observe os traços e ajuste a proximidade.'}
                </small>
              </span>
              <span className="paper-tag">
                {drawer ? '✎' : '◉'} {state.you.name} · {drawer ? 'desenhista' : 'avaliador'}
              </span>
            </div>
            <div className="canvas-wrap">
              <DrawingCanvas
                key={state.round_number}
                chunks={drawing}
                enabled={playing && drawer && remaining > 0}
                color={color}
                width={width}
                onChunk={chunk => onEnqueue({ ...chunk, kind: 'stroke' })}
              />
              {drawing.length === 0 && (
                <div className="canvas-hint">
                  {drawer ? 'A primeira ideia começa com um traço.' : 'O desenho aparecerá aqui, ao vivo.'}
                </div>
              )}
            </div>
            {drawer && playing && (
              <div className="tools">
                <div className="colors">
                  {['#18252b', '#ef6351', '#3286b8', '#e7ad35'].map(buttonColor => (
                    <button
                      key={buttonColor}
                      aria-label={`Cor ${buttonColor}`}
                      aria-pressed={color === buttonColor}
                      className={color === buttonColor ? 'active' : ''}
                      style={{ background: buttonColor }}
                      onClick={() => onColorChange(buttonColor)}
                    />
                  ))}
                </div>
                <button
                  className={color === '#ffffff' ? 'tool active' : 'tool'}
                  onClick={() => onColorChange('#ffffff')}
                >
                  Borracha
                </button>
                <label className="size">
                  Traço
                  <input
                    aria-label="Espessura do traço"
                    type="range"
                    min="2"
                    max="30"
                    value={width}
                    onChange={event => onWidthChange(Number(event.target.value))}
                  />
                </label>
                <button
                  className="tool clear"
                  disabled={pending.length > 0 || remaining === 0}
                  onClick={() => onEnqueue({ id: crypto.randomUUID(), kind: 'clear' })}
                >
                  Limpar
                </button>
                <small>{pending.length ? 'Sincronizando…' : 'Salvo'}</small>
              </div>
            )}
          </section>

          <aside>
            {state.status === 'finished' || closed ? (
              <section className="card result">
                <span className="eyebrow">RODADA ENCERRADA</span>
                <span className="result-icon">{state.result === 'guessed' ? '☀' : '◷'}</span>
                <h2>{resultTitle}</h2>
                {state.secret && (
                  <>
                    <p>O objeto secreto era</p>
                    <strong className="revealed">{state.secret}</strong>
                  </>
                )}
                <p>{resultMessage}</p>
                {!closed && (
                  <>
                    <button
                      className="primary next-round"
                      disabled={busy || ready}
                      onClick={() => void onAction('next')}
                    >
                      {ready
                        ? (state.competition ? 'Aguardando as duplas…' : 'Aguardando seu parceiro…')
                        : 'Pronto para a próxima rodada →'}
                    </button>
                    <p className="fine-print">
                      {state.next_ready.length}/2 prontos · Os papéis serão invertidos.
                      {state.competition && ' A próxima rodada começa quando todos estiverem prontos.'}
                    </p>
                  </>
                )}
                <button className="tool leave-room" onClick={() => onLeaveConfirm(true)}>
                  Criar ou entrar em outra sala →
                </button>
              </section>
            ) : (
              <>
                <section className="card feedback">
                  <span className="eyebrow">{drawer ? 'SIGA A TEMPERATURA' : 'OBJETO SECRETO'}</span>
                  {!drawer && (
                    <>
                      <h2 className="secret">{state.secret}</h2>
                      <p>Só você conhece o objeto. Use o slider para guiar sua dupla, sem dicas por texto.</p>
                    </>
                  )}
                  <div className={`temperature temp-${state.temperature.replaceAll(' ', '-')}`}>
                    <span>{['quente', 'muito quente'].includes(state.temperature) ? '☀' : '❋'}</span>
                    <h2>{state.temperature}</h2>
                    <small>
                      {drawer ? 'Seu parceiro está avaliando o desenho.' : `Proximidade visual: ${score}%`}
                    </small>
                  </div>
                  <div className="heat-scale"><i /><i /><i /><i /><i /></div>
                  {!drawer && (
                    <label className="slider-label">
                      Proximidade visual
                      <input
                        aria-label="Proximidade visual"
                        type="range"
                        min="0"
                        max="100"
                        value={score}
                        disabled={remaining === 0}
                        onChange={event => onScoreChange(Number(event.target.value))}
                      />
                      <span><small>0 · Muito frio</small><small>Muito quente · 100</small></span>
                    </label>
                  )}
                </section>
                {drawer && (
                  <section className="card guess">
                    <h2>Já tem um palpite?</h2>
                    <p>Tente quantas vezes quiser. Sem penalidade.</p>
                    <form onSubmit={onSendGuess}>
                      <label className="sr-only" htmlFor="guess">Seu palpite</label>
                      <input
                        id="guess"
                        value={guess}
                        onChange={event => onGuessChange(event.target.value)}
                        maxLength={100}
                        placeholder="Acho que é…"
                        required
                        disabled={remaining === 0}
                      />
                      <button className="primary" disabled={busy || !guess.trim() || remaining === 0}>
                        Enviar palpite →
                      </button>
                    </form>
                    <p role="status" className="guess-message">{guessResult}</p>
                  </section>
                )}
                <div className="rule-note">
                  <b>3 minutos para descobrir</b>
                  <p>
                    {drawer
                      ? 'A temperatura fala sobre a aparência do seu desenho. Mude de ideia e tente à vontade.'
                      : 'Seu parceiro pode testar desenhos e palpites livremente. Avalie apenas a proximidade visual.'}
                  </p>
                </div>
              </>
            )}
            <GuessHistory
              guesses={state.guesses ?? []}
              canJudge={!drawer && playing && remaining > 0}
              finished={state.status === 'finished' || closed}
              busy={busy}
              onJudge={(guessId, decision) => void onAction('judge', { guess_id: guessId, decision })}
            />
          </aside>
        </div>
      )}
      <RoundHistory token={token} rounds={state.rounds ?? []} scores={state.scores ?? []} />
    </main>
  );
}
