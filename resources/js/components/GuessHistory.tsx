import type { Guess } from '../types';

type Props = {
  guesses: Guess[];
  canJudge: boolean;
  finished: boolean;
  busy: boolean;
  onJudge: (id: number, decision: 'accepted' | 'rejected') => void;
};

function verdict(guess: Guess, finished: boolean): string {
  if (guess.status === 'accepted') {
    return guess.judged_by === 'system' ? 'Acerto automático' : 'Aceito pelo avaliador';
  }
  if (guess.status === 'rejected') return 'Rejeitado pelo avaliador';
  return finished ? 'Sem avaliação' : 'Aguardando avaliador';
}

export default function GuessHistory({ guesses, canJudge, finished, busy, onJudge }: Props) {
  return (
    <section className="card guess-history" aria-labelledby="guess-history-heading">
      <h2 id="guess-history-heading">Histórico de palpites</h2>
      <p>
        {canJudge
          ? 'Aceitar um palpite encerra a rodada. Rejeitar permite continuar tentando.'
          : 'Suas tentativas e o julgamento de cada palpite ficam aqui.'}
      </p>
      {guesses.length === 0 ? (
        <p className="history-empty">Nenhum palpite ainda.</p>
      ) : (
        <ol className="guess-list" aria-label="Palpites da rodada" aria-live="polite">
          {[...guesses].reverse().map(guess => (
            <li key={guess.id} className={`history-item history-${guess.status}`}>
              <div className="history-heading">
                <strong>{guess.text}</strong>
                <time dateTime={guess.created_at}>
                  {new Date(guess.created_at).toLocaleTimeString('pt-BR', {
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit'
                  })}
                </time>
              </div>
              <span className="guess-verdict">{verdict(guess, finished)}</span>
              {canJudge && guess.status === 'pending' && (
                <div className="judgment-actions">
                  <button
                    className="accept-guess"
                    disabled={busy}
                    onClick={() => onJudge(guess.id, 'accepted')}
                    aria-label={`Aceitar como acerto: ${guess.text}`}
                  >
                    Aceitar como acerto
                  </button>
                  <button
                    className="reject-guess"
                    disabled={busy}
                    onClick={() => onJudge(guess.id, 'rejected')}
                    aria-label={`Rejeitar palpite: ${guess.text}`}
                  >
                    Rejeitar
                  </button>
                </div>
              )}
            </li>
          ))}
        </ol>
      )}
    </section>
  );
}
