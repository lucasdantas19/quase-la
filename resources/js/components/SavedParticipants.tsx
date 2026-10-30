import { useEffect, useState } from 'react';
import {
  forgetParticipant,
  readSavedParticipants,
  type SavedParticipant
} from '../savedParticipants';

type Props = {
  busy: boolean;
  onResume: (saved: SavedParticipant) => Promise<void>;
};

export default function SavedParticipants({ busy, onResume }: Props) {
  const [saved, setSaved] = useState(() => readSavedParticipants());

  useEffect(() => {
    const refresh = () => setSaved(readSavedParticipants());
    window.addEventListener('storage', refresh);
    return () => window.removeEventListener('storage', refresh);
  }, []);

  if (saved.length === 0) return null;

  return (
    <section className="saved-participants" aria-label="Participações salvas neste navegador">
      <h3>Voltar para uma sala</h3>
      <p>Escolha sua participação para retomar após fechar a aba. Cada pessoa deve usar seu próprio nome.</p>
      <ul>
        {saved.map(item => (
          <li key={item.token}>
            <span>
              <strong>{item.name}</strong>
              <small>Sala {item.code}</small>
            </span>
            <button
              type="button"
              className="tool"
              disabled={busy}
              aria-label={`Retomar ${item.name} na sala ${item.code}`}
              onClick={async () => {
                await onResume(item);
                setSaved(readSavedParticipants());
              }}
            >
              Retomar
            </button>
            <button
              type="button"
              className="forget-participant"
              disabled={busy}
              aria-label={`Esquecer acesso de ${item.name} na sala ${item.code}`}
              onClick={() => {
                forgetParticipant(item.token);
                setSaved(readSavedParticipants());
              }}
            >
              Esquecer acesso
            </button>
          </li>
        ))}
      </ul>
      <p>Esquecer acesso remove apenas o atalho deste navegador; não encerra a sala.</p>
    </section>
  );
}
