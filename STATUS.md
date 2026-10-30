# Ponto de retomada — Quente ou Frio por Desenho

Atualizado em **29/10/2026**. Este arquivo registra o progresso da sessão e o que fazer ao voltar. O [README](README.md) contém instalação, regras e detalhes técnicos.

## Onde paramos

O jogo está implementado para salas independentes e partidas competitivas com várias duplas. O usuário relatou funcionamento local dos incrementos anteriores. A última funcionalidade implementada foi a **janela final de até 30 segundos após o primeiro acerto**, solicitada antes da preparação para publicação.

**Próximo passo:** confirmar localmente esse último incremento e, em seguida, preparar a publicação. Não há hospedagem, domínio ou provedor definidos nesta conversa. Nenhum deploy foi realizado.

## Caminho percorrido

1. **Jogo básico em dupla:** criação/entrada por código, desenhista e avaliador, objeto secreto no servidor, temperatura humana, palpites e prazo de 180 segundos.
2. **Desenho e recuperação:** canvas vetorial sincronizado por Reverb, persistência, reenvio idempotente, borracha, limpeza e recuperação após recarga/reconexão.
3. **Julgamento e histórico:** acertos automáticos por objeto/aliases e aceitação/rejeição pelo avaliador, com histórico persistido e sigilo por participante.
4. **Múltiplas rodadas na sala:** confirmação dos dois, troca de papéis, snapshots das rodadas encerradas e placar cooperativo.
5. **Presença e retomada:** detecção de ausência, acessos salvos por participante, retomada após fechar a aba e saída explícita com revogação do acesso.
6. **Partidas competitivas:** 2 a 8 duplas, cada uma em sua sala privada; início e próximas rodadas sincronizados; mesmo objeto e prazo; classificação por ordem de acerto.
7. **Validação de integração preparada:** testes de navegador com várias abas e testes de concorrência com processos PHP separados disputando bloqueios MySQL. Ajustada a ordem das leituras/bloqueios para evitar snapshots antigos sob REPEATABLE READ.
8. **Organizador e resultado final:** criador controla início/encerramento; pode remover outras duplas incompletas do lobby; transferência automática quando a sala do organizador fecha; encerramento com confirmação e classificação final persistida.
9. **Janela final de 30 segundos:** primeiro acerto automático ou aceito pelo avaliador reduz o prazo das demais; aviso destacado e contagem regressiva; reconexão recupera o estado; nova rodada limpa o aviso.

## Regras que devem ser preservadas

- Uma sala guarda uma dupla. Uma partida competitiva agrupa salas; desenhos, palpites, credenciais e canais continuam privados por dupla.
- O desenhista não recebe o objeto nem a proximidade numérica enquanto sua rodada está em andamento.
- O servidor controla tempo, autorização, pontuação e encerramento. O relógio do navegador é visual.
- Janela final: `min(prazo original, instante do primeiro acerto + 30 segundos)`. Nunca estende o tempo, nem reinicia em acertos seguintes. Se faltavam 10 segundos, continuam sendo 10.
- A janela vale para partidas competitivas com outra dupla ainda jogando. Salas independentes mantêm o funcionamento anterior.
- Cada dupla que acerta encerra sua própria rodada imediatamente; as demais podem continuar até o novo prazo.
- Com N duplas na largada, a classificação concede N pontos ao primeiro acerto, N−1 ao segundo, até 1. Sem acerto: zero. A ordem é a dos resultados aceitos pelo servidor, incluindo julgamentos humanos. Empates no total compartilham posição.
- O placar cooperativo do histórico é separado: cada acerto vale 1 ponto por pessoa.
- A próxima rodada espera todos os participantes das salas ainda abertas, troca os papéis e restaura os 180 segundos.
- Só o organizador inicia a partida competitiva e controla remoção/encerramento. Salas independentes são iniciadas pelo avaliador.
- Encerramento preserva os pontos já conquistados, arquiva rodadas em andamento sem pontos e congela a classificação final. O prazo vencido tem prioridade e produz timeout.
- Mutações competitivas bloqueiam a partida antes da sala. Pedidos de rodadas antigas recebem HTTP 409.

## Validação registrada

Na última execução desta sessão:

- **106 testes de backend aprovados**, com **618 verificações**, usando SQLite.
- **14 testes de frontend aprovados**.
- Build TypeScript/Vite e formatação PHP aprovados.
- Sintaxe dos scripts de navegador e concorrência aprovada.

Esses resultados não comprovam a execução real com WebSocket e MySQL. Nesta sandbox, a porta HTTP não abriu, a conexão MySQL falhou e o Chromium encerrou com “Operação não permitida”. As tentativas de aplicar as migrações no MySQL local também falharam por conexão.

O usuário confirmou funcionamento local dos incrementos anteriores, mas não há saída de execução dos testes de integração anexada à conversa. **A aplicação da migração e a validação local da última janela de 30 segundos ainda devem ser conferidas ao retomar.** Não tratar essa pendência como falha de funcionamento já demonstrada.

## Como retomar

1. Ler este arquivo e consultar as seções pertinentes do README.
2. Conferir os arquivos e o estado do Git, se acessível. Nesta sessão o histórico Git não ficou disponível; não há commits ou PRs registrados aqui.
3. Aplicar as migrações pendentes e atualizar a interface:

   ```bash
   php artisan migrate
   npm run build
   ```

4. Em outro terminal, iniciar os serviços locais:

   ```bash
   composer run dev
   ```

   Esse comando inicia HTTP, Reverb, agendador e Vite. Configurações e alternativa com terminais separados estão no README.

5. Executar os testes conforme o escopo:

   ```bash
   ./vendor/bin/pest
   npm run test:frontend
   ./vendor/bin/pint --test
   npm run test:mysql:concurrency
   npm run test:e2e
   npm run test:e2e:competition
   ```

   O teste MySQL exige banco migrado e usa processos separados, sem precisar de HTTP/Reverb. Os testes de navegador exigem os serviços em execução e Chromium instalado (`npx playwright install chromium`). Aceitam `GAME_URL`.

   O teste MySQL remove somente a partida que ele cria. Os testes de navegador criam salas no banco configurado e preservam seu histórico normal. Usar o ambiente de desenvolvimento.

6. Conferir a janela final em várias abas: primeiro acerto cedo, primeiro acerto com menos de 30 segundos restantes, aceite humano, acerto seguinte sem renovar tempo, recarga e reinício na próxima rodada. O aviso não pode revelar o objeto ou o palpite adversário.

## Próximas tarefas, em ordem sugerida

### 1. Fechar a validação do último incremento

- [ ] Confirmar aplicação de `2026_10_29_000006_add_final_window.php` no banco local.
- [ ] Validar aviso/cronômetro em várias abas com Reverb e expiração no novo prazo.
- [ ] Executar os scripts de concorrência e navegador atualizados e registrar os resultados.

### 2. Preparar a publicação

- [ ] Definir hospedagem, domínio e ambiente de produção.
- [ ] Configurar HTTPS, proxy e WebSocket seguro para Reverb.
- [ ] Configurar processos persistentes para HTTP, Reverb e agendador.
- [ ] Definir retenção e implementar limpeza automática de salas, desenhos, históricos e tokens antigos, preservando partidas ativas.
- [ ] Definir backups e recuperação do MySQL; configurar logs e acompanhamento de falhas.
- [ ] Validar carga e concorrência em MySQL no ambiente de destino.
- [ ] Documentar configuração, deploy e recuperação; fazer a publicação após revisão do resultado concreto.

Essas são tarefas planejadas, não implementadas. Redis, filas e mudanças de armazenamento devem ser avaliados conforme necessidade medida.

### 3. Melhorias posteriores

- Substituição de participantes (ainda não existe; hoje desconexão reserva a vaga).
- Avaliação automática por IA como modo opcional, com resultado associado à rodada/revisão e proteção contra respostas atrasadas.
- Ampliação do catálogo de objetos e ajustes de experiência conforme uso real.

As melhorias posteriores são sugestões, sem prioridade ou implementação autorizada além do que for solicitado ao retomar.

## Mapa de arquivos úteis

| Área | Arquivos |
| --- | --- |
| Regras, transações e janela final | `app/Game/Game.php` |
| Estado por destinatário e classificação | `app/Game/RoomState.php` |
| Modelos | `app/Models/Room.php`, `Competition.php`, `Participant.php`, `CompletedRound.php` |
| API | `app/Http/Controllers/GameController.php`, `routes/api.php` |
| Eventos privados | `app/Events/GameUpdated.php`, `routes/channels.php` |
| Interface e aviso final | `resources/js/app.tsx`, `resources/css/app.css` |
| Classificação e controles do organizador | `resources/js/CompetitionPanel.tsx` |
| Eventos atrasados e tipos | `resources/js/roundUpdates.ts`, `resources/js/types.ts` |
| Testes da janela final | `tests/Feature/FinalWindowTest.php`, `tests/Feature/CompetitionBroadcastTest.php` |
| Testes do organizador | `tests/Feature/CompetitionManagementTest.php` |
| Integração real | `tests/browser/competition.mjs`, `tests/integration/mysql-concurrency.php` |
| Últimas migrações | `database/migrations/2026_10_29_000004_add_competitions.php`, `2026_10_29_000005_add_competition_organizer.php`, `2026_10_29_000006_add_final_window.php` |

Atualizar este registro ao concluir a próxima etapa, distinguindo implementação, validação automatizada e confirmação manual.
