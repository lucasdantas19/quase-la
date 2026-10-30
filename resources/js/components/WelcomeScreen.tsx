import type { FormEvent } from 'react';
import SavedParticipants from './SavedParticipants';
import type { SavedParticipant } from '../savedParticipants';

type Props = {
  busy: boolean;
  name: string;
  code: string;
  role: string;
  competitive: boolean;
  competitionCode: string;
  joining: boolean;
  onNameChange: (name: string) => void;
  onCodeChange: (code: string) => void;
  onRoleChange: (role: string) => void;
  onCompetitiveChange: (competitive: boolean) => void;
  onCompetitionCodeChange: (code: string) => void;
  onJoiningChange: (joining: boolean) => void;
  onEnter: (event: FormEvent) => void;
  onResume: (saved: SavedParticipant) => Promise<void>;
};

export default function WelcomeScreen({
  busy,
  name,
  code,
  role,
  competitive,
  competitionCode,
  joining,
  onNameChange,
  onCodeChange,
  onRoleChange,
  onCompetitiveChange,
  onCompetitionCodeChange,
  onJoiningChange,
  onEnter,
  onResume
}: Props) {
  return (
    <main className="welcome">
      <section className="intro">
        <span className="eyebrow">UM JOGO PARA JOGAR EM DUPLAS</span>
        <h1>Siga o traço.<br />Sinta o <em>calor.</em></h1>
        <p>
          Uma pessoa conhece o objeto. A outra desenha para descobrir. Entre uma tentativa e outra,
          a temperatura guia o caminho.
        </p>
        <div className="sketch-card">
          <svg viewBox="0 0 400 220" aria-hidden="true">
            <path d="M90 145 Q120 45 195 65 Q265 43 303 145 Q271 117 242 145 Q219 119 194 145 Q166 119 141 145 Q116 121 90 145 M195 66 L195 183 Q194 209 172 198 Q160 194 165 182" />
            <path className="sun" d="M300 43l12-13m-1 29 19-2m-38-25 2-18" />
          </svg>
          <span className="sketch-label">será que está esquentando?</span>
          <span className="floating-temp">☀ morno</span>
        </div>
      </section>

      <section className="entry card">
        <span className="eyebrow">BORA JOGAR?</span>
        <h2>Sua próxima boa ideia<br />começa aqui.</h2>
        <div className="tabs">
          <button type="button" className={!joining ? 'selected' : ''} onClick={() => onJoiningChange(false)}>
            Criar sala
          </button>
          <button type="button" className={joining ? 'selected' : ''} onClick={() => onJoiningChange(true)}>
            Entrar com código
          </button>
        </div>

        <form onSubmit={onEnter}>
          <label>
            Como podemos te chamar?
            <input
              value={name}
              onChange={event => onNameChange(event.target.value)}
              maxLength={30}
              required
              placeholder="Seu nome"
              autoComplete="nickname"
            />
          </label>

          {joining ? (
            <label>
              Código da sala
              <input
                className="code-input"
                value={code}
                onChange={event => onCodeChange(event.target.value.toUpperCase())}
                minLength={6}
                maxLength={6}
                required
                placeholder="ABC123"
                autoComplete="off"
              />
            </label>
          ) : (
            <fieldset>
              <legend>Qual será seu papel?</legend>
              <label className={`role-option ${role === 'drawer' ? 'chosen' : ''}`}>
                <input
                  type="radio"
                  name="role"
                  value="drawer"
                  checked={role === 'drawer'}
                  onChange={() => onRoleChange('drawer')}
                />
                <span>
                  <strong>✎ Desenhista</strong>
                  <small>Desenhe, experimente e dê seus palpites.</small>
                </span>
              </label>
              <label className={`role-option ${role === 'evaluator' ? 'chosen' : ''}`}>
                <input
                  type="radio"
                  name="role"
                  value="evaluator"
                  checked={role === 'evaluator'}
                  onChange={() => onRoleChange('evaluator')}
                />
                <span>
                  <strong>☀ Avaliador</strong>
                  <small>Conheça o objeto e indique a temperatura.</small>
                </span>
              </label>
            </fieldset>
          )}

          {!joining && (
            <>
              <label>
                <input
                  type="checkbox"
                  checked={competitive}
                  onChange={event => onCompetitiveChange(event.target.checked)}
                />{' '}
                Criar partida com várias duplas
              </label>
              <label>
                Código de uma partida existente (opcional)
                <input
                  value={competitionCode}
                  onChange={event => onCompetitionCodeChange(event.target.value.toUpperCase())}
                  maxLength={6}
                  placeholder="Para criar sua dupla nessa partida"
                />
              </label>
            </>
          )}

          <button className="primary" disabled={busy || !name.trim()}>
            {busy ? 'Preparando…' : joining ? 'Entrar na sala →' : 'Criar minha sala →'}
          </button>
        </form>
        <p className="fine-print">Sem cadastro. Compartilhe o código e jogue em dupla.</p>
        <SavedParticipants busy={busy} onResume={onResume} />
      </section>
    </main>
  );
}
