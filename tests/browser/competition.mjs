import assert from 'node:assert/strict';
import { mkdir } from 'node:fs/promises';
import { chromium } from 'playwright';

const baseURL = process.env.GAME_URL || 'http://127.0.0.1:8000';
const browser = await chromium.launch({headless: true});
const context = await browser.newContext({viewport: {width: 1440, height: 1000}});
const pages = await Promise.all(Array.from({length: 4}, () => context.newPage()));
const errors = [];
const frames = pages.map(() => []);
const tokens = [];
for (const [index, page] of pages.entries()) {
  page.on('pageerror', error => errors.push(error.message));
  page.on('websocket', socket => socket.on('framereceived', frame => {
    const envelope = JSON.parse(String(frame.payload));
    if (envelope.event === 'game.updated') {
      frames[index].push(typeof envelope.data === 'string' ? JSON.parse(envelope.data) : envelope.data);
    }
  }));
}
async function waitFor(check, message, timeout = 15_000) {
  const deadline = Date.now() + timeout;
  while (Date.now() < deadline) {
    if (await check()) return;
    await new Promise(resolve => setTimeout(resolve, 750));
  }
  throw new Error(message);
}
async function state(page) {
  return page.evaluate(async () => {
    const token = sessionStorage.getItem('quente-frio-participant');
    const response = await fetch('/api/room', {headers: {Accept: 'application/json', Authorization: `Bearer ${token}`}});
    if (!response.ok) throw new Error(`State: HTTP ${response.status}`);
    return response.json();
  });
}
async function rememberToken(page) {
  tokens.push(await page.evaluate(() => sessionStorage.getItem('quente-frio-participant')));
}
async function create(page, name, {competitive = false, code = ''} = {}) {
  await page.goto(baseURL);
  await page.getByLabel('Como podemos te chamar?').fill(name);
  if (competitive) await page.getByLabel('Criar partida com várias duplas').check();
  if (code) await page.getByLabel('Código de uma partida existente').fill(code);
  await page.getByRole('button', {name: 'Criar minha sala'}).click();
  await page.getByRole('heading', {name: 'Falta só sua dupla chegar.'}).waitFor();
  await rememberToken(page);
  return state(page);
}
async function join(page, name, code) {
  await page.goto(baseURL);
  await page.getByRole('button', {name: 'Entrar com código'}).click();
  await page.getByLabel('Como podemos te chamar?').fill(name);
  await page.getByLabel('Código da sala', {exact: true}).fill(code);
  await page.getByRole('button', {name: 'Entrar na sala'}).click();
  await page.getByRole('heading', {name: 'Sua dupla está pronta!'}).waitFor();
  await rememberToken(page);
}
async function guess(page, text) {
  await page.getByLabel('Seu palpite').fill(text);
  await page.getByRole('button', {name: 'Enviar palpite'}).click();
}
async function ink(page) {
  return page.locator('canvas').first().evaluate(canvas => {
    const pixels = canvas.getContext('2d').getImageData(0, 0, canvas.width, canvas.height).data;
    return pixels.some((value, index) => index % 4 === 3 && value > 0);
  });
}
try {
  const a = await create(pages[0], 'Ana', {competitive: true});
  await join(pages[1], 'Bia', a.code);
  const c = await create(pages[2], 'Caio', {code: a.competition.code});
  await join(pages[3], 'Dani', c.code);
  assert.equal(new Set(tokens).size, 4);
  const pendingPage = await context.newPage();
  pendingPage.on('pageerror', error => errors.push(error.message));
  await create(pendingPage, 'Eva', {code: a.competition.code});
  const remove = pages[0].getByRole('button', {name: 'Remover dupla: Eva', exact: true});
  await remove.click();
  await pages[0].getByRole('button', {name: 'Cancelar', exact: true}).click();
  assert.equal((await state(pendingPage)).status, 'waiting');
  await remove.click();
  await pages[0].getByRole('button', {name: 'Confirmar remoção', exact: true}).click();
  await pendingPage.getByRole('heading', {name: 'Sala encerrada', exact: true}).waitFor();
  assert.equal((await state(pendingPage)).close_reason, 'removed');

  for (const page of pages) {
    await waitFor(async () => (await page.locator('.live').textContent()).includes('Ao vivo'), 'Reverb não conectou em todas as abas');
    await waitFor(async () => await page.locator('.competition-panel tbody tr').count() === 2, 'Lobby não mostrou as duas duplas');
  }
  await pages[0].getByRole('button', {name: 'Começar para todas as duplas'}).click();
  for (const [index, page] of pages.entries()) {
    await page.getByRole('heading', {name: index % 2 === 0 ? 'O que será que é?' : 'Guie pelo calor.'}).waitFor();
  }
  const initial = await Promise.all(pages.map(state));
  assert.equal(initial[1].secret, initial[3].secret);
  assert.equal(initial[0].ends_at, initial[2].ends_at);
  for (const index of [0, 2]) {
    assert.equal('secret' in initial[index], false);
    assert.equal('proximity' in initial[index], false);
  }
  for (const team of initial[0].competition.teams) {
    for (const field of ['code', 'secret', 'aliases', 'drawing', 'guesses']) assert.equal(field in team, false);
  }
  const box = await pages[0].locator('canvas').boundingBox();
  await pages[0].mouse.move(box.x + 50, box.y + 50);
  await pages[0].mouse.down();
  await pages[0].mouse.move(box.x + 220, box.y + 120, {steps: 12});
  await pages[0].mouse.up();
  await waitFor(() => frames[1].some(frame => frame.delta?.kind === 'stroke'), 'Desenho não chegou por Reverb ao parceiro');
  await waitFor(() => ink(pages[1]), 'Canvas do parceiro ficou vazio');
  assert.equal(frames[2].some(frame => frame.delta?.kind === 'stroke'), false);
  assert.equal(frames[3].some(frame => frame.delta?.kind === 'stroke'), false);
  assert.deepEqual((await state(pages[2])).drawing, []);
  await pages[1].reload();
  await pages[1].getByRole('heading', {name: 'Guie pelo calor.'}).waitFor();
  await waitFor(() => ink(pages[1]), 'Reload não recuperou desenho');

  // The second team wins first, the first team gets a human-accepted synonym.
  await guess(pages[2], initial[3].secret);
  await pages[2].getByRole('heading', {name: 'Vocês acertaram!'}).waitFor();
  assert.equal((await state(pages[0])).status, 'playing');
  await pages[0].locator('.final-window-notice').waitFor();
  const finalWindow = await state(pages[0]);
  assert.ok(finalWindow.final_window_started_at);
  assert.ok(Date.parse(finalWindow.ends_at) - Date.parse(finalWindow.final_window_started_at) <= 30_000);
  assert.ok(Date.parse(finalWindow.ends_at) <= Date.parse(initial[0].ends_at));
  assert.equal('secret' in finalWindow, false);
  await waitFor(() => frames[0].some(frame => frame.state.final_window_started_at && !frame.guess_update && !('secret' in frame.state)), 'Aviso de reta final não chegou por Reverb');
  await guess(pages[0], 'sinônimo aceito manualmente');
  await pages[1].getByRole('button', {name: 'Aceitar como acerto: sinônimo aceito manualmente', exact: true}).click();
  await pages[0].getByRole('heading', {name: 'Vocês acertaram!'}).waitFor();
  const ranked = await state(pages[0]);
  assert.equal(ranked.ends_at, finalWindow.ends_at);
  assert.deepEqual(ranked.competition.teams.map(team => team.points), [2, 1]);
  assert.equal(ranked.competition.teams[0].id, c.id);
  for (const page of pages) {
    await waitFor(async () => (await page.locator('.competition-panel tbody tr').first().textContent()).includes('Caio + Dani'), 'Classificação visual não atualizou');
  }
  await Promise.all(pages.slice(0, 3).map(page => page.getByRole('button', {name: 'Pronto para a próxima rodada'}).click()));
  assert.equal((await state(pages[0])).round_number, 1);
  await pages[3].getByRole('button', {name: 'Pronto para a próxima rodada'}).click();
  for (const [index, page] of pages.entries()) {
    await page.getByRole('heading', {name: index % 2 === 0 ? 'Guie pelo calor.' : 'O que será que é?'}).waitFor();
  }
  const next = await Promise.all(pages.map(state));
  assert.ok(next.every(s => s.round_number === 2));
  assert.ok(next.every(s => s.final_window_started_at === null));
  assert.equal(await pages[0].locator('.final-window-notice').count(), 0);
  assert.equal(next[0].ends_at, next[2].ends_at);
  for (const index of [1, 3]) {
    assert.equal('secret' in next[index], false);
    assert.deepEqual(next[index].drawing, []);
    assert.deepEqual(next[index].guesses, []);
  }
  await pages[1].getByRole('button', {name: 'Ver rodada 1', exact: true}).click();
  await pages[1].getByRole('region', {name: 'Detalhes da rodada 1'}).waitFor();
  await pages[1].getByRole('heading', {name: `Rodada 1 · ${initial[1].secret}`, exact: true}).waitFor();
  await pages[1].getByRole('button', {name: 'Fechar detalhes'}).click();

  // Close without leaving, recover the same participant, then leave explicitly.
  await pages[2].close();
  await waitFor(async () => (await pages[3].locator('.presence-bar').textContent()).includes('desconectado'), 'Ausência não foi detectada', 35_000);
  const resumed = await context.newPage();
  resumed.on('pageerror', error => errors.push(error.message));
  await resumed.goto(baseURL);
  await resumed.getByRole('button', {name: `Retomar Caio na sala ${c.code}`, exact: true}).click();
  await resumed.getByRole('heading', {name: 'Guie pelo calor.'}).waitFor();
  assert.equal(await resumed.evaluate(() => sessionStorage.getItem('quente-frio-participant')), tokens[2]);
  await resumed.getByRole('button', {name: 'Sair da sala', exact: true}).click();
  await resumed.getByRole('button', {name: 'Sair e encerrar participação', exact: true}).click();
  await pages[3].getByRole('heading', {name: 'Sala encerrada', exact: true}).waitFor();
  assert.equal((await state(pages[0])).status, 'playing');
  await guess(pages[1], next[0].secret);
  await pages[1].getByRole('heading', {name: 'Vocês acertaram!'}).waitFor();
  await Promise.all(pages.slice(0, 2).map(page => page.getByRole('button', {name: 'Pronto para a próxima rodada'}).click()));
  await pages[0].getByRole('heading', {name: 'O que será que é?'}).waitFor();
  assert.equal((await state(pages[0])).round_number, 3);
  await pages[0].getByRole('button', {name: 'Encerrar partida', exact: true}).click();
  await pages[0].getByRole('button', {name: 'Cancelar', exact: true}).click();
  assert.equal((await state(pages[0])).status, 'playing');
  await pages[0].getByRole('button', {name: 'Encerrar partida', exact: true}).click();
  await pages[0].getByRole('button', {name: 'Confirmar encerramento', exact: true}).click();
  for (const page of [pages[0], pages[1], pages[3]]) {
    await page.getByRole('heading', {name: 'Classificação final', exact: true}).waitFor();
  }
  const final = (await state(pages[0])).competition;
  assert.ok(final.ended_at);
  await pages[1].reload();
  await pages[1].getByRole('heading', {name: 'Classificação final', exact: true}).waitFor();
  assert.deepEqual((await state(pages[1])).competition.teams, final.teams);
  assert.equal(await pages[0].getByRole('button', {name: 'Encerrar partida', exact: true}).count(), 0);
  assert.deepEqual(errors, []);
  await mkdir('tests/browser/artifacts', {recursive: true});
  await pages[0].screenshot({path: 'tests/browser/artifacts/competition.png', fullPage: true});
  console.log('PASS: quatro abas, Reverb privado, início sincronizado, ranking, aceite humano, próxima rodada, troca de papéis, reconexão, abandono isolado, remoção de dupla incompleta e encerramento com classificação final.');
} finally {
  // Close only participations created by this run; keep their normal archived history.
  for (const token of tokens) {
    await context.request.post(`${baseURL}/api/room/leave`, {headers: {Accept: 'application/json', Authorization: `Bearer ${token}`}}).catch(() => {});
  }
  await context.close();
  await browser.close();
}
