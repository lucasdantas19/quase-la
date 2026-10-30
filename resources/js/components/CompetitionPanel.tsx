import type { Competition } from '../types';

type Props = {
  competition: Competition;
  roomId: number;
  busy: boolean;
  onEnd: () => void;
  onRemove: (roomId: number, names: string) => void;
};

function teamStatus(team: Competition['teams'][number], ended: boolean): string {
  if (ended) return 'Encerrada';
  if (team.status === 'waiting') {
    return `${team.players}/2 participantes · ${team.online}/2 conectados`;
  }
  if (team.status === 'playing') return 'Jogando';
  if (team.status === 'closed') return 'Saiu da partida';
  return `${team.ready}/2 prontos`;
}

export default function CompetitionPanel({ competition, roomId, busy, onEnd, onRemove }: Props) {
  const ended = Boolean(competition.ended_at);
  const best = competition.teams[0]?.points ?? 0;
  const winners = competition.teams.filter(team => team.points === best && best > 0);
  const winnerNames = winners.map(team => team.names.join(' + ')).join(' · ');

  return (
    <section className="card competition-panel" aria-labelledby="competition-heading">
      <span className="eyebrow">PARTIDA {competition.code}</span>
      <h2 id="competition-heading">
        {ended ? 'Classificação final' : 'Classificação das duplas'}
      </h2>

      {ended ? (
        <p role="status">
          {winners.length === 0
            ? 'Nenhuma dupla pontuou nesta partida.'
            : `${winners.length === 1 ? 'Dupla vencedora' : 'Empate na liderança'}: ${winnerNames}.`}
        </p>
      ) : (
        <p>
          Organizador: <b>{competition.organizer_name ?? 'aguardando participante'}</b>
          {competition.is_organizer && ' (você)'}. O organizador inicia e encerra a partida.
        </p>
      )}

      {!ended && !competition.started && (
        <p>
          Para adicionar uma dupla, crie uma sala informando o código da partida <b>{competition.code}</b>.
          Cada parceiro entra pelo código da própria sala. São necessárias de 2 a 8 duplas completas e conectadas.
        </p>
      )}

      <p>
        A primeira dupla a acertar ganha {competition.team_count} pontos; cada posição seguinte recebe um ponto a menos.
        Sem acerto: zero. Empates no total compartilham a posição.
      </p>

      <table>
        <thead>
          <tr>
            <th>Posição</th>
            <th>Dupla</th>
            <th>Pontos</th>
            <th>Situação</th>
          </tr>
        </thead>
        <tbody>
          {competition.teams.map((team, _index, teams) => {
            const names = team.names.join(' + ');
            const canRemove = !ended && !competition.started && competition.is_organizer
              && team.id !== roomId && team.status === 'waiting' && team.players < 2;

            return (
              <tr key={team.id}>
                <td>{teams.findIndex(other => other.points === team.points) + 1}</td>
                <td>{names}{team.id === roomId ? ' (vocês)' : ''}</td>
                <td>{team.points}</td>
                <td>
                  {teamStatus(team, ended)}
                  {canRemove && (
                    <button className="tool" disabled={busy} onClick={() => onRemove(team.id, names)}>
                      Remover dupla: {names}
                    </button>
                  )}
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>

      {!ended && (
        <p className="fine-print">
          A classificação atualiza a cada cinco segundos. Desenhos e palpites ficam dentro de cada dupla.
        </p>
      )}
      {!ended && competition.is_organizer && (
        <button className="tool" disabled={busy} onClick={onEnd}>Encerrar partida</button>
      )}
    </section>
  );
}
