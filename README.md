# Quente ou Frio por Desenho

**Retomada do projeto:** consulte [STATUS.md](STATUS.md) para o histórico dos incrementos, validações registradas e próximas tarefas.

Jogo web para **uma dupla por sala**, com avaliação humana. Uma pessoa desenha sem conhecer o objeto; a outra acompanha o canvas e informa a proximidade visual. O servidor escolhe o objeto, valida os palpites e encerra a rodada no acerto ou após 180 segundos.

## Partidas com várias duplas

Além de salas independentes, agora é possível reunir **2 a 8 duplas** em uma partida competitiva. Cada dupla continua em sua própria sala, com desenhos, palpites, credenciais e canais privados separados.

Após atualizar o projeto, execute `php artisan migrate` e `npm run build`.

1. Em **Criar sala**, marque **Criar partida com várias duplas**. Convide seu parceiro pelo código da sala.
2. Compartilhe o **código da partida**, exibido na classificação, com quem criará as outras duplas.
3. Cada outra dupla usa **Criar sala**, informa o código da partida no campo opcional e convida seu parceiro pelo novo código de sala. O campo **Entrar com código** continua sendo exclusivo para entrar na sala do parceiro.
4. Quando todas as duplas abertas estiverem completas e conectadas, o organizador pode clicar em **Começar para todas as duplas**, mesmo se for desenhista. A partida exige pelo menos duas duplas. Novas admissões ficam bloqueadas após o início.
5. Todas recebem o mesmo objeto e o mesmo prazo. Cada dupla encerra sua rodada ao acertar ou esgotar o tempo; as outras entram na janela final de até 30 segundos, respeitando o prazo original.
6. Com N duplas na largada, a primeira a acertar recebe N pontos, a segunda N−1, até 1. Tempo esgotado e abandono valem zero. A ordem é a dos acertos aceitos pelo servidor, inclusive para aceitação manual; IDs do arquivo de resultados desempateiam acertos no mesmo segundo. A classificação soma as rodadas e atribui a mesma posição a totais iguais.
7. Todos os participantes das salas ainda abertas precisam confirmar a próxima rodada. A transição inverte os papéis e começa junto, com novo objeto e prazo comum. Desconexão continua removendo a confirmação; saída encerra apenas a sala daquela dupla. Se restar uma única dupla, ela pode continuar as rodadas.

### Janela final de 30 segundos

Quando a primeira dupla acerta numa rodada competitiva, o servidor reduz o prazo das demais para **o menor entre o prazo original e 30 segundos após o acerto**. A tela destaca **“Uma dupla acertou! Faltam no máximo 30 segundos.”** e exibe a contagem regressiva com o tempo real restante. Se restavam 10 segundos, continuam sendo 10; a rodada nunca ganha tempo extra.

Acertos automáticos e aceites humanos disparam a mesma regra. Novos acertos não renovam a janela. O aviso vai imediatamente pelos canais privados, sem compartilhar o palpite nem o desenho da dupla vencedora. A consulta de estado recupera o prazo e o aviso após recarga ou perda de conexão. O servidor aplica o novo prazo a palpites, julgamentos e expiração pelo agendador; o relógio do navegador é apenas visual.

Na próxima rodada, o aviso é removido e todas as duplas recebem novamente os 180 segundos normais. Salas independentes mantêm seu funcionamento atual. Se só restar a dupla vencedora na partida, não há adversário para receber a janela final. A atualização exige `php artisan migrate` e um novo build do frontend.

### Organizador e classificação final

Quem cria uma nova partida é seu **organizador**, independentemente do papel de desenhista ou avaliador. Somente ele inicia a partida, remove duplas incompletas do lobby e encerra a sessão. As salas independentes continuam sendo iniciadas pelo avaliador.

No lobby, **Remover dupla** abre uma confirmação e só está disponível para outras salas com um participante. O servidor rejeita remoção da própria sala, de duplas completas, de salas de outra partida ou após o início. A sala removida fica encerrada, preserva o acesso de leitura e libera uma vaga no limite de oito duplas. Ela não aparece na classificação. Duplas completas desconectadas continuam com a vaga reservada.

**Encerrar partida** pede confirmação. Sob o bloqueio da partida, o servidor encerra todas as salas, preserva os acertos já registrados e arquiva rodadas em andamento como abandonadas, sem pontos. Caso o prazo já tenha vencido, registra tempo esgotado. Nenhuma rodada é inventada se a sessão for cancelada ainda no lobby. A classificação final fica salva no banco, é enviada nos canais privados e continua igual após recarregar, retomar ou sair. Empates na liderança são exibidos; se não houver pontos, não há vencedor. Reenvios do encerramento são idempotentes; ações de outra rodada recebem HTTP 409.

Se o organizador sair, ou seu parceiro sair encerrando a sala dele, a organização passa automaticamente para o participante mais antigo que ainda esteja em uma sala aberta. Desconexão temporária não transfere esse controle. O nome do organizador e os controles disponíveis são recuperados pelas consultas de estado. Se ninguém permanecer em uma sala aberta, não há sucessor.

Execute `php artisan migrate` para os novos campos e `npm run build` para a interface. A migração mantém os dados existentes e atribui a organização das partidas antigas ao participante ativo mais antigo em uma sala aberta. Não há contas nem credencial administrativa separada: os controles usam o token privado da participação.

A classificação mostra nomes, presença no lobby, situação e pontos, sem expor códigos das salas adversárias, traços, palpites ou objetos. Atualiza por consulta autenticada a cada cinco segundos; os deltas de desenho continuam restritos à própria dupla. O placar cooperativo do histórico da sala (1 por acerto por pessoa) permanece separado da classificação competitiva.

As mutações adquirem o bloqueio da partida antes do bloqueio da sala, serializando início, confirmações, expiração e ordem de acerto em MySQL. Os testes automatizados usam SQLite e não comprovam concorrência real em MySQL. O novo fluxo de quatro ou mais abas e sua transmissão Reverb ainda precisam de validação no ambiente local.

## Instalação

Requisitos: PHP **8.3+**, Composer 2, Node **22+**, npm e MySQL 8+. Extensões PHP usuais do Laravel, incluindo `pdo_mysql`, `mbstring`, `openssl`, `dom`, `xml`, `curl` e `fileinfo`; `pdo_sqlite` para os testes padrão. Não é necessário Redis, conta de usuário nem serviço externo de IA.

```bash
composer install
npm ci
cp .env.example .env
php artisan key:generate
```

Crie um banco e usuário no seu MySQL, com uma conta administrativa (ajuste a senha):

```sql
CREATE DATABASE quente_frio CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'quente_frio'@'127.0.0.1' IDENTIFIED BY 'secret';
GRANT ALL PRIVILEGES ON quente_frio.* TO 'quente_frio'@'127.0.0.1';
```

Ajuste `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` e `DB_PASSWORD` no `.env`. Os valores de exemplo já correspondem ao SQL acima. Depois:

```bash
php artisan migrate
npm run build
composer run dev
```

Abra **http://127.0.0.1:8000**. O comando `dev` inicia quatro processos: servidor HTTP, Reverb em `127.0.0.1:8080`, agendador e Vite. Encerre com Ctrl+C. Em terminais separados, a alternativa é:

```bash
php artisan serve --host=127.0.0.1 --port=8000
php artisan reverb:start --host=127.0.0.1 --port=8080
php artisan schedule:work
npm run dev
```

Cada comando acima fica em seu próprio terminal. Não é necessário iniciar worker de filas: os eventos usam `ShouldBroadcastNow` e são enviados após o commit. O agendador executa `game:expire` a cada segundo; consultas e ações também verificam o prazo.

As credenciais Reverb do exemplo são exclusivamente para desenvolvimento. `REVERB_APP_KEY` é pública; `REVERB_APP_SECRET` fica somente no backend. Ao alterar variáveis `VITE_*`, reinicie o Vite ou refaça o build. Se configurar cache do Laravel, execute `php artisan config:clear` após mudar o `.env`.

### Partida em duas abas

1. Abra o endereço, informe seu nome, escolha **Desenhista** ou **Avaliador** e crie a sala.
2. Copie o código. Abra **uma nova aba pelo endereço**, ou outro navegador. Escolha **Entrar com código**, informe outro nome e o código. O papel será complementar.
3. Quando houver duas pessoas conectadas, o avaliador clica em **Começar rodada**. Apenas ele vê o objeto escolhido pelo sistema.
4. O desenhista desenha, apaga com a borracha, limpa o canvas e envia palpites. O avaliador ajusta o slider; o desenhista vê somente a temperatura.
5. Os palpites aparecem no histórico dos dois participantes. O avaliador pode **aceitar como acerto** ou **rejeitar** os que o sistema não reconhecer. Aceitar encerra a rodada; rejeitar mantém o jogo sem penalidade. Um palpite reconhecido pelo sistema encerra automaticamente a rodada. Aos três minutos, o tempo se esgota. Ambos veem o objeto no resultado.
6. No resultado, ambos clicam em **Pronto para a próxima rodada**. A segunda confirmação inicia a próxima rodada na mesma sala, com os papéis invertidos, novo objeto e mais três minutos.
7. Consulte **Placar e rodadas** para ver os pontos e abrir o desenho, os palpites e os papéis de uma rodada encerrada. Não é preciso criar outra sala.
8. Se fechar uma aba, reabra o endereço e use **Voltar para uma sala**. Para sair de forma definitiva, use **Sair da sala** e confirme: isso encerra a sala para os dois.

A participação ativa fica em `sessionStorage`, separada por aba. Um acesso de retomada também é salvo em `localStorage`, na lista **Voltar para uma sala** da tela inicial. Fechar e reabrir uma aba permite escolher seu nome nessa lista e recuperar a mesma participação, com o papel e a rodada atuais. Não há retomada apenas pelo nome/código nem substituição de jogador. **Duplicar uma aba pode copiar seu `sessionStorage`**; abra uma aba nova pelo endereço para representar a segunda pessoa. Em duas abas no mesmo navegador, os dois acessos estarão na lista: cada pessoa deve selecionar o próprio nome. O acesso salvo permite controlar essa participação; não o compartilhe.

Para duas máquinas na mesma rede, configure `APP_URL` e `VITE_REVERB_HOST` com o IP acessível do servidor; faça HTTP, Reverb e Vite escutarem em `0.0.0.0`, ajuste o HMR do Vite e libere as portas. Os exemplos prontos priorizam duas abas locais. HTTPS e proxy WebSocket devem ser configurados antes de hospedar publicamente.

### Alternativa rápida sem MySQL

O alvo de persistência é MySQL. Para experimentar localmente, é possível usar SQLite:

```bash
touch database/database.sqlite
```

No `.env`, configure `DB_CONNECTION=sqlite` e remova `DB_DATABASE` para usar o caminho padrão do Laravel. Execute `php artisan config:clear` e `php artisan migrate`. SQLite atende a demonstração local; a concorrência com bloqueio por linha deve ser validada em MySQL.

## Verificação

```bash
./vendor/bin/pest
npm run build
npm run test:frontend
./vendor/bin/pint --test
```

Os testes Pest usam SQLite em memória, sem tocar no banco local. Cobrem papéis complementares, limite de duas pessoas, sigilo em HTTP e broadcast, autenticação e canais privados, faixas de temperatura, validação de entrada, aliases e normalização dos palpites, tentativas sem penalidade, acerto antes do prazo, rejeição no instante limite, expiração pelo agendador, desenho persistido e isolado, revisões, reenvio idempotente, limpeza e limites de armazenamento. Também cobrem histórico persistido, aprovação automática, aceitação/rejeição pelo avaliador, isolamento entre salas, decisões imutáveis e bloqueio de julgamentos no prazo final.

Teste de navegador com a aplicação e o Reverb em execução:

```bash
npx playwright install chromium
npm run test:e2e
```

Opcionalmente use `GAME_URL=http://127.0.0.1:8000 npm run test:e2e`. O script abre duas abas no mesmo contexto e verifica criação/entrada, credenciais distintas, transmissão de traços por WebSocket, recarga com recuperação do canvas, slider, histórico após recarregar, rejeição de palpite, limpeza, acerto automático e acerto aceito pelo avaliador, três rodadas na mesma sala, confirmações simultâneas, troca de papéis, placar consulta ao histórico, fechamento de aba, detecção de ausência, retomada do acesso e saída explícita. Salva uma captura em `tests/browser/artifacts/presence-and-departure.png`. Cria uma sala com três rodadas e encerra a última por abandono no banco configurado; execute em ambiente de desenvolvimento.

**Validação neste ambiente:** os incrementos anteriores foram validados manualmente pelo usuário. Neste incremento, Pest, testes do cliente e build TypeScript/Vite foram executados. Os testes de backend usam SQLite; eles não substituem uma prova de concorrência real em MySQL. A instalação foi realizada usando caches locais, pois o DNS dos registros estava indisponível. A sandbox bloqueia sockets TCP/Unix e a inicialização do Chromium: não foi possível iniciar HTTP/Reverb, acessar MySQL nem executar o teste de navegador aqui. A integração real e a concorrência com MySQL permanecem como validações locais necessárias; não foram consideradas aprovadas por inferência.

### Validação de partidas competitivas

O teste de quatro abas usa a aplicação, o Reverb e o agendador em execução (por exemplo, `composer run dev` em outro terminal). Antes, aplique as migrações e atualize o build. Execute:

```bash
npm run test:e2e:competition
```

Aceita `GAME_URL` como o teste anterior. Cria duas salas na mesma partida e verifica início sincronizado, canais privados, traços por WebSocket, recuperação após recarga, ranking visual, acerto automático e humano, espera pelas quatro confirmações, troca de papéis, consulta de histórico, fechamento/retomada de aba, saída sem encerrar a dupla adversária, remoção de dupla incompleta com confirmação e encerramento com classificação final persistida. Salva `tests/browser/artifacts/competition.png`. Ao terminar (inclusive em falhas), tenta encerrar somente as participações criadas pelo teste; os históricos normais permanecem no banco de desenvolvimento.

O teste de concorrência usa diretamente o MySQL/InnoDB configurado no `.env`, sem servidor HTTP, Reverb ou Chromium:

```bash
npm run test:mysql:concurrency
```

Ele cria sua própria partida, inicia processos PHP separados, segura o bloqueio da partida até todos chegarem a uma barreira e libera ações concorrentes. Verifica início único, acertos simultâneos com ranking estável, confirmações duplicadas avançando somente uma rodada, troca de papéis sem vazamento, rejeição de ações antigas e disputa entre acerto e saída e encerramentos simultâneos idempotentes pelo organizador. Usa migrações já aplicadas; não executa `migrate:fresh`. No encerramento remove apenas a partida e as salas criadas por essa execução. SQLite é recusado explicitamente porque não comprova bloqueios por linha.

**Estado desta etapa:** a suíte Pest em SQLite, testes do cliente, sintaxe dos novos scripts, formatação e build podem ser verificados nesta sandbox. A abertura de porta HTTP falhou, MySQL retornou erro de conexão e o Chromium encerrou com “Operação não permitida”. Portanto, os dois novos testes de integração **ainda não foram aprovados em execução real**. A revisão também moveu a identificação da partida para fora da transação, de forma que seu bloqueio seja adquirido antes da primeira leitura consistente sob o isolamento padrão do MySQL.

## Estrutura e decisões

- `app/Game/Game.php`: regras de sala, início, desenho, feedback, validação dos palpites e encerramento. Transações e `lockForUpdate` serializam alterações na sala em MySQL. O banco mantém uma restrição única por papel/sala.
- `app/Models/CompletedRound.php`: snapshots das rodadas encerradas, com chave única por sala/número.
- `app/Game/RoomState.php`: lista explícita de campos por destinatário. Objeto e aliases nunca são serializados diretamente do model. Antes do encerramento, somente o avaliador recebe o objeto. O desenhista não recebe o valor numérico do slider.
- `app/Game/Temperature.php`: conversão independente da origem da avaliação: 0–19 muito frio, 20–39 frio, 40–59 morno, 60–79 quente, 80–100 muito quente.
- `config/game.php`: catálogo no servidor, aliases aceitos e duração. Palpites ignoram caixa, acentos, pontuação e espaços repetidos; a comparação é exata após normalização. Não há comparação aproximada nem limites de tentativas por rodada.
- `app/Events/GameUpdated.php`: eventos privados por participante, com payload filtrado. `routes/channels.php` só autoriza o próprio canal. Os tokens são aleatórios (64 caracteres), armazenados como SHA-256 no banco e enviados via Bearer; o código da sala permite admissão, mas não acesso aos canais.
- `resources/js/`: React/TypeScript com canvas nativo e Echo/Pusher para Reverb. A página e a API usam a mesma origem. As rotas `/api` são sem sessão/cookie e exigem Bearer para leitura e ações; não dependem de CSRF com autenticação por cookie.

### Presença, retomada e saída

Execute `php artisan migrate` para acrescentar presença e encerramento explícito; a migração não apaga salas, rodadas nem acessos existentes. Depois atualize o frontend (`npm run build`, ou Vite) e recarregue as abas ativas para salvar o acesso de retomada deste navegador.

O estado exibe se o parceiro está conectado, desconectado ou saiu. Consultas autenticadas ao estado (a cada cinco segundos) e ações do jogo atualizam `last_seen_at`. Após 20 segundos sem atividade, o participante fica desconectado. Essa verificação ocorre nas consultas/ações e em `game:expire`, já executado pelo agendador; não exige Redis nem canais de presença. O indicador representa comunicação com o servidor HTTP, não apenas o estado do WebSocket. Abas em segundo plano podem ser marcadas como ausentes se o navegador suspender seus timers; uma nova consulta restaura a presença.

**Desconexão não é saída:** a vaga e o token permanecem reservados e o cronômetro continua correndo até o limite original. O participante pode retomar o mesmo papel e a rodada atual; nenhum novo objeto é revelado indevidamente. Se alguém ficar desconectado após confirmar a próxima rodada, sua confirmação é removida: ao voltar, deve confirmar novamente. O início exige ambos conectados.

A lista **Voltar para uma sala** mantém acessos separados para os participantes deste navegador. Ela não retoma automaticamente uma pessoa em uma aba nova, preservando o teste com duas abas. Ao selecionar **Retomar**, o servidor valida o acesso e devolve o estado atual. **Esquecer acesso** remove apenas esse atalho, sem sair da sala. Apagar dados do navegador, usar outro navegador ou fechar uma sessão privada pode perder essa recuperação; não há contas nem recuperação por nome. Salas fechadas ainda podem ser consultadas pelo participante que não saiu.

**Saída explícita:** o botão **Sair da sala** pede confirmação. A transação encerra a sala (`closed`), registra quem saiu e revoga seu token para API e novas assinaturas de canais privados. Se a rodada estiver em andamento, ela é arquivada como `abandoned`, valendo zero pontos; se já tiver acabado por acerto ou tempo, o resultado e os pontos permanecem. O limite de tempo vence uma saída no instante exato do prazo. O parceiro recebe o encerramento em tempo real, pode consultar o histórico e sair ou criar outra sala. Não há substituição de participante nem reabertura de sala encerrada. Os registros continuam no banco.

Não enviamos saída automática ao fechar a página: isso confundiria fechamento com recarga ou uma queda temporária. A detecção usa ausência de heartbeat. O armazenamento local não guarda o objeto secreto. Ao sair, o frontend remove somente o acesso daquela participação e ignora respostas HTTP/eventos de sessões anteriores; os acessos de outros jogadores do mesmo navegador são preservados.

Roteiro manual: inicie uma rodada em duas abas; feche uma delas, aguarde cerca de 20 segundos e confira a ausência; abra uma aba nova, retome pelo nome e verifique desenho/papel/prazo. Depois use **Sair da sala**, cancele uma vez, confirme e verifique encerramento no parceiro, histórico com abandono, zero pontos adicionais e impossibilidade de retomar o acesso encerrado. O teste de navegador cobre esse fluxo com espera de até 35 segundos para detecção da ausência.

### Múltiplas rodadas e alternância de papéis

Atualize o banco com `php artisan migrate`, refaça o build (`npm run build`) ou reinicie o Vite e recarregue **as duas abas**. A nova migração é aditiva: salas antigas continuam na rodada 1; os palpites e operações existentes recebem esse número, e as salas já encerradas têm seu resultado arquivado. Salas ainda em andamento seguem até o encerramento. Não use `migrate:fresh`, pois ele apaga as partidas.

A sala guarda o estado atual e um `round_number` crescente. Ao terminar por acerto automático, julgamento humano ou tempo, um snapshot é inserido em `completed_rounds`, preservando objeto, desenho, temperatura final, horários e participantes nos papéis originais. Os palpites e operações continuam persistidos, separados por número da rodada. A consulta `GET /api/rounds/{number}` exige o token de um participante da própria sala e só retorna rodadas encerradas.

Cada participante confirma o resultado em **Pronto para a próxima rodada** (`POST /api/room/next`). A primeira confirmação fica persistida, inclusive após reconexão; a segunda troca os papéis e inicia a próxima rodada. Não há reinício automático antes da confirmação dos dois. Os tokens, IDs e canais privados permanecem os mesmos. O canvas, revisão, palpites visíveis, temperatura e resultado são reiniciados, e o prazo recebe mais 180 segundos a partir do novo início.

**Pontuação:** como existe uma dupla cooperativa, cada rodada acertada vale 1 ponto para cada participante; tempo esgotado vale 0. O placar é calculado a partir das rodadas encerradas, evitando somas duplicadas em reenvios. O histórico mostra esses pontos e permite consultar o canvas e os palpites de cada rodada. Nas partidas competitivas, a classificação por ordem de acerto é calculada separadamente, conforme a seção de partidas com várias duplas.

A seleção evita os últimos três objetos da sala quando houver opções. Em catálogos menores, libera primeiro os objetos mais antigos; com um único objeto, a repetição é inevitável.

**Concorrência e eventos atrasados:** todas as ações exigem `round_number`. Dentro da transação, `lockForUpdate` serializa as confirmações e compara esse número com o atual; pedidos antigos recebem HTTP 409. Confirmações repetidas da mesma pessoa não contam duas vezes. Uma chave única `(room_id, number)` protege o arquivo de resultados. A troca de papéis usa um valor temporário apenas dentro da transação para preservar a restrição de papéis únicos na sala. Autorizações e projeções consultam os papéis atuais, sem confiar em um model de participante carregado antes da troca.

O cliente descarta eventos/respostas de rodadas anteriores e versões antigas, limpa operações pendentes e timers de feedback na transição e recria o canvas. Traços guardam o número da rodada no momento do envio; respostas antigas não removem operações da fila nova. Eventos de transição disparam recuperação do snapshot, incluindo histórico e placar, sem transmitir desenhos completos por WebSocket. A reconexão recupera rodada, papel e confirmações atuais.

Testes adicionais cobrem múltiplas transições, reenvios de confirmação, ações antigas, papéis atualizados, sigilo nos broadcasts, palpites de outras rodadas, reset do estado, pontuação única, catálogo pequeno, consulta privada e migração de dados existentes. `npm run test:frontend` verifica a rejeição de respostas atrasadas e a limpeza dos campos restritos após trocar de papel. A validação simultânea real em MySQL e o fluxo de navegador com Reverb devem ser executados localmente.

### Histórico e julgamento de palpites

Depois de atualizar o código, execute `php artisan migrate` para criar a tabela `guesses` sem apagar as salas existentes. Use `npm run build` ou mantenha o Vite em execução para atualizar a interface.

Cada palpite fica vinculado à sala e ao número da rodada, com texto, horário e estado: `pending`, `accepted` ou `rejected`. Um acerto por comparação com objeto/aliases recebe aprovação automática (`system`). Os demais ficam disponíveis para julgamento humano (`evaluator`), permitindo aceitar sinônimos ou variações ausentes do catálogo. Ambos os participantes veem as decisões e mantêm o histórico após recarregar e ao fim da rodada. Palpites pendentes após o fim são exibidos como **Sem avaliação**.

Só o avaliador da própria sala pode julgar; decisões não admitem edição nem texto livre. O servidor verifica o prazo sob o mesmo bloqueio da rodada: um julgamento atrasado não reabre a partida. Aceitar encerra como acerto, rejeitar não altera o prazo nem penaliza novas tentativas. O avaliador pode julgar qualquer palpite pendente da rodada, inclusive um anterior ao mais recente.

O snapshot HTTP inclui o histórico completo, em ordem de envio. Eventos e respostas de envio/julgamento levam apenas o registro alterado (`guess_update`), mesclado por ID no cliente. Eventos de desenho/slider não retransmitem o histórico. As tentativas anteriores à instalação desta atualização não foram armazenadas e não podem ser recuperadas.

### Desenho e reconexão

O canvas lógico mede 1000 × 700. O navegador acumula pontos por cerca de 100 ms, com no máximo 40 pontos por operação. Cada lote contém UUID, pontos, cor, espessura e revisão-base. Borracha usa traços brancos; limpar é uma operação explícita. Nunca são transmitidas imagens inteiras.

O servidor persiste o snapshot vetorial em JSON e transmite somente o delta da operação com os metadados da rodada. UUIDs impedem duplicação por reenvio, inclusive quando uma operação antiga chega depois de limpar. Revisões rejeitam escrita sobre um desenho desatualizado. A fila do navegador serializa operações e tenta novamente em falhas transitórias; traços ainda não confirmados estão apenas na memória e podem se perder ao recarregar offline. Um conflito de revisão descarta a fila local e recupera o estado confirmado.

Ao entrar, recarregar, recuperar a conexão ou detectar lacuna de revisão, o cliente consulta o snapshot. Uma consulta adicional a cada cinco segundos corrige eventos perdidos e oferece recuperação se Reverb cair. Relógio visual usa o horário do servidor para compensar diferença entre relógios; nenhum resultado depende do relógio do navegador. Falha de broadcast é registrada e não desfaz uma jogada já confirmada no banco.

Para manter o incremento pequeno, cada sala guarda até 12.000 pontos/2.000 lotes entre limpezas; ao atingir o limite, a interface pede para limpar. Há proteção contra abuso por minuto (20 admissões por IP, 120 consultas/autenticações por participante e 900 ações por participante); isso não limita a quantidade de palpites por rodada nem aplica penalidades. Não há chat e eventos de cliente/whispers do Reverb estão desativados.

### Avaliação por IA no futuro

A origem do feedback é distinta das regras de desenho, palpites, tempo e encerramento. Hoje a entrada `feedback` exige um participante avaliador e persiste uma proximidade 0–100. Uma avaliação automática futura poderá produzir essa mesma proximidade por um serviço/job no servidor, usando as mesmas faixas e projeções por destinatário. Será preciso adicionar a entrada autenticada desse resultado e proteger resultados atrasados por rodada/revisão, sem alterar a mecânica de palpites e tempo. Nenhum SDK, chave, interface genérica de provedores ou chamada de IA foi adicionado.

### Próximos incrementos

Substituição de participantes e avaliação automática ficam para depois. Acerto encerra imediatamente a rodada da dupla; as demais têm até 30 segundos adicionais a partir do primeiro acerto, sempre dentro do prazo original. O placar por pessoa é cooperativo, e a classificação competitiva compara duplas da mesma partida.

Antes de uso público, também será preciso estabelecer retenção/limpeza de salas e tokens, testar carga/concorrência em MySQL e configurar a hospedagem. O snapshot JSON e a emissão síncrona priorizam simplicidade para duas pessoas; filas, Redis e armazenamento incremental de vetores podem ser avaliados quando houver necessidade medida.

Documentação de referência: [Broadcasting do Laravel](https://laravel.com/docs/12.x/broadcasting) e [Laravel Reverb](https://laravel.com/docs/12.x/reverb).
