import { useEffect, useState } from 'react';
import { api } from '../api';
import type { RoundDetail, RoundSummary, State } from '../types';
import DrawingCanvas from './DrawingCanvas';

type Props = {
  token: string;
  rounds: RoundSummary[];
  scores: NonNullable<State['scores']>;
};

function roundResult(result: string): string {
  if (result === 'guessed') return 'Acerto';
  if (result === 'abandoned') return 'Abandono';
  return 'Tempo esgotado';
}

export default function RoundHistory({ token, rounds, scores }: Props) {
  const [selected, setSelected] = useState<number | null>(null);
  const [detail, setDetail] = useState<RoundDetail | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    if (selected === null) {
      setDetail(null);
      return;
    }

    let active = true;
    setDetail(null);
    setError('');
    api<RoundDetail>(`rounds/${selected}`, token)
      .then(result => {
        if (active) setDetail(result);
      })
      .catch(() => {
        if (active) setError('Não foi possível carregar a rodada. Feche e tente novamente.');
      });
    return () => {
      active = false;
    };
  }, [selected, token]);

  return (
    <section className="card round-history" aria-labelledby="round-history-heading">
      <div className="round-history-heading">
        <h2 id="round-history-heading">Placar e rodadas</h2>
        <div className="scores">
          {scores.map(player => (
            <span key={player.id}>
              {player.name}: <b>{player.points} {player.points === 1 ? 'ponto' : 'pontos'}</b>
            </span>
          ))}
        </div>
      </div>
      <p>Jogo cooperativo: cada acerto vale 1 ponto para cada pessoa. Tempo esgotado ou abandono vale 0.</p>

      {rounds.length === 0 ? (
        <p>Nenhuma rodada encerrada ainda.</p>
      ) : (
        <ol className="round-list">
          {[...rounds].reverse().map(round => (
            <li key={round.number}>
              <span>
                <b>Rodada {round.number}</b> · {round.secret} · {roundResult(round.result)}
                {' · '}+{round.points_each} por pessoa
              </span>
              <button
                className="tool"
                aria-expanded={selected === round.number}
                onClick={() => setSelected(selected === round.number ? null : round.number)}
              >
                Ver rodada {round.number}
              </button>
            </li>
          ))}
        </ol>
      )}

      {selected !== null && (
        <section className="round-detail" aria-label={`Detalhes da rodada ${selected}`}>
          <button className="tool" onClick={() => setSelected(null)}>Fechar detalhes</button>
          {error ? (
            <p role="alert">{error}</p>
          ) : !detail ? (
            <p>Carregando rodada…</p>
          ) : (
            <>
              <h3>Rodada {detail.number} · {detail.secret}</h3>
              <p>
                {detail.participants
                  .map(participant => `${participant.name}: ${participant.role === 'drawer' ? 'desenhista' : 'avaliador'}`)
                  .join(' · ')}
              </p>
              <div className="archive-canvas">
                <DrawingCanvas
                  key={detail.number}
                  chunks={detail.drawing}
                  enabled={false}
                  color="#18252b"
                  width={5}
                  onChunk={() => {}}
                />
              </div>
              <h3>Palpites dessa rodada</h3>
              <ol>
                {detail.guesses.map(guess => (
                  <li key={guess.id}>
                    {guess.text} · {guess.status === 'accepted'
                      ? 'Aceito'
                      : guess.status === 'rejected' ? 'Rejeitado' : 'Sem avaliação'}
                  </li>
                ))}
              </ol>
            </>
          )}
        </section>
      )}
    </section>
  );
}
