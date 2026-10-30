import assert from 'node:assert/strict';
import { mkdir } from 'node:fs/promises';
import { chromium } from 'playwright';

const baseURL = process.env.GAME_URL || 'http://127.0.0.1:8000';
const browser = await chromium.launch({ headless: true });
const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
const drawer = await context.newPage();
const evaluator = await context.newPage();
const errors = [];
const frames = [];
for (const page of [drawer, evaluator]) {
  page.on('pageerror', error => errors.push(error.message));
}
evaluator.on('websocket', socket => socket.on('framereceived', frame => frames.push(String(frame.payload))));
async function waitFor(check, message, timeout = 12_000) {
  const end = Date.now() + timeout;
  while (Date.now() < end) {
    if (await check()) return;
    await new Promise(resolve => setTimeout(resolve, 100));
  }
  throw new Error(message);
}
async function state(page) {
  return page.evaluate(async () => {
    const token = sessionStorage.getItem('quente-frio-participant');
    const response = await fetch('/api/room', { headers: { Accept: 'application/json', Authorization: `Bearer ${token}` } });
    if (!response.ok) throw new Error(`State failed: ${response.status}`);
    return response.json();
  });
}
async function hasInk(page) {
  return page.locator('canvas').evaluate(canvas => {
    const pixels = canvas.getContext('2d').getImageData(0, 0, canvas.width, canvas.height).data;
    return pixels.some((value, index) => index % 4 === 3 && value > 0);
  });
}
try {
  await drawer.goto(baseURL);
  await drawer.getByLabel('Como podemos te chamar?').fill('Ana');
  await drawer.getByRole('button', { name: 'Criar minha sala' }).click();
  await drawer.getByRole('heading', { name: 'Falta só sua dupla chegar.' }).waitFor();
  const code = (await state(drawer)).code;
  await evaluator.goto(baseURL);
  await evaluator.getByRole('button', { name: 'Entrar com código' }).click();
  await evaluator.getByLabel('Como podemos te chamar?').fill('Bia');
  await evaluator.getByLabel('Código da sala').fill(code);
  await evaluator.getByRole('button', { name: 'Entrar na sala' }).click();
  await evaluator.getByRole('heading', { name: 'Sua dupla está pronta!' }).waitFor();
  await waitFor(async () => (await evaluator.locator('.live').textContent()).includes('Ao vivo'), 'Reverb não conectou');
  assert.notEqual(await drawer.evaluate(() => sessionStorage.getItem('quente-frio-participant')), await evaluator.evaluate(() => sessionStorage.getItem('quente-frio-participant')));
  await evaluator.getByRole('button', { name: 'Começar rodada' }).click();
  await drawer.getByRole('heading', { name: 'O que será que é?' }).waitFor();
  const secret = (await state(evaluator)).secret;
  assert.ok(secret);
  const drawerState = await state(drawer);
  assert.equal('secret' in drawerState, false);
  assert.equal('proximity' in drawerState, false);
  const box = await drawer.locator('canvas').boundingBox();
  await drawer.mouse.move(box.x + 50, box.y + 50);
  await drawer.mouse.down();
  await drawer.mouse.move(box.x + 250, box.y + 150, { steps: 30 });
  await drawer.mouse.up();
  await waitFor(async () => (await state(evaluator)).drawing.length > 0, 'Traços não persistiram');
  await waitFor(() => frames.some(frame => frame.includes('game.updated') && frame.includes('stroke')), 'Delta não chegou via WebSocket');
  await waitFor(() => hasInk(evaluator), 'Canvas do avaliador ficou vazio');
  await evaluator.reload();
  await evaluator.getByRole('heading', { name: 'Guie pelo calor.' }).waitFor();
  await waitFor(() => hasInk(evaluator), 'Reconexão não restaurou o canvas');
  const slider = evaluator.getByRole('slider', { name: 'Proximidade visual' });
  await slider.focus();
  await slider.press('End');
  await waitFor(async () => (await state(drawer)).temperature === 'muito quente', 'Slider não atualizou temperatura');
  await drawer.getByRole('heading', { name: 'muito quente', exact: true }).waitFor();
  await drawer.getByLabel('Seu palpite').fill('objeto certamente incorreto');
  await drawer.getByRole('button', { name: 'Enviar palpite' }).click();
  await drawer.getByRole('status').filter({ hasText: 'Ainda não' }).waitFor();
  const firstGuess = evaluator.locator('.history-item').filter({ hasText: 'objeto certamente incorreto' });
  await firstGuess.getByRole('button', { name: 'Rejeitar palpite:' }).click();
  await drawer.locator('.history-item').filter({ hasText: 'Rejeitado pelo avaliador' }).waitFor();
  await drawer.reload();
  await drawer.locator('.history-item').filter({ hasText: 'objeto certamente incorreto' }).waitFor();
  assert.equal((await state(drawer)).guesses[0].status, 'rejected');
  await waitFor(async () => !(await drawer.getByRole('button', { name: 'Limpar', exact: true }).isDisabled()), 'Fila de traços não esvaziou');
  await drawer.getByRole('button', { name: 'Limpar', exact: true }).click();
  await waitFor(async () => (await state(evaluator)).drawing.length === 0, 'Limpeza não sincronizou');
  await waitFor(async () => !(await hasInk(evaluator)), 'Canvas não apagou');
  await drawer.getByLabel('Seu palpite').fill(secret);
  await drawer.getByRole('button', { name: 'Enviar palpite' }).click();
  for (const page of [drawer, evaluator]) {
    await page.getByRole('heading', { name: 'Vocês acertaram!' }).waitFor();
    assert.equal((await state(page)).status, 'finished');
    assert.equal((await state(page)).secret, secret);
  }

  // Keep the same credentials and room, then alternate roles twice.
  const tokenA = await drawer.evaluate(() => sessionStorage.getItem('quente-frio-participant'));
  const tokenB = await evaluator.evaluate(() => sessionStorage.getItem('quente-frio-participant'));
  await drawer.getByRole('button', { name: 'Pronto para a próxima rodada' }).click();
  await drawer.getByRole('button', { name: 'Aguardando seu parceiro' }).waitFor();
  assert.equal((await state(drawer)).round_number, 1);
  await evaluator.getByRole('button', { name: 'Pronto para a próxima rodada' }).click();
  await evaluator.getByRole('heading', { name: 'O que será que é?' }).waitFor();
  await drawer.getByRole('heading', { name: 'Guie pelo calor.' }).waitFor();
  const secondA = await state(drawer);
  const secondB = await state(evaluator);
  assert.equal(secondA.code, code);
  assert.equal(secondB.round_number, 2);
  assert.equal(secondB.you.role, 'drawer');
  assert.equal('secret' in secondB, false);
  assert.notEqual(secondA.secret, secret);
  assert.deepEqual(secondB.drawing, []);
  assert.deepEqual(secondB.guesses, []);
  assert.equal(secondB.temperature, 'muito frio');
  assert.deepEqual(secondA.scores.map(player => player.points), [1, 1]);
  assert.equal(await drawer.evaluate(() => sessionStorage.getItem('quente-frio-participant')), tokenA);
  assert.equal(await evaluator.evaluate(() => sessionStorage.getItem('quente-frio-participant')), tokenB);
  await evaluator.reload();
  await evaluator.getByRole('heading', { name: 'O que será que é?' }).waitFor();
  assert.equal((await state(evaluator)).round_number, 2);
  await evaluator.getByRole('button', { name: 'Ver rodada 1', exact: true }).click();
  await evaluator.getByRole('region', { name: 'Detalhes da rodada 1' }).waitFor();
  await evaluator.getByRole('heading', { name: `Rodada 1 · ${secret}`, exact: true }).waitFor();
  await evaluator.getByRole('button', { name: 'Fechar detalhes' }).click();
  await evaluator.getByLabel('Seu palpite').fill('variação aceita pela dupla');
  await evaluator.getByRole('button', { name: 'Enviar palpite' }).click();
  await drawer.getByRole('button', { name: 'Aceitar como acerto: variação aceita pela dupla' }).click();
  for (const page of [drawer, evaluator]) {
    await page.getByRole('heading', { name: 'Vocês acertaram!' }).waitFor();
    const result = await state(page);
    assert.equal(result.guesses[0].status, 'accepted');
    assert.equal(result.guesses[0].judged_by, 'evaluator');
    assert.deepEqual(result.scores.map(player => player.points), [2, 2]);
    await page.getByText('Aceito pelo avaliador', { exact: true }).waitFor();
  }
  await Promise.all([
    drawer.getByRole('button', { name: 'Pronto para a próxima rodada' }).click(),
    evaluator.getByRole('button', { name: 'Pronto para a próxima rodada' }).click(),
  ]);
  await drawer.getByRole('heading', { name: 'O que será que é?' }).waitFor();
  await evaluator.getByRole('heading', { name: 'Guie pelo calor.' }).waitFor();
  assert.equal((await state(drawer)).round_number, 3);
  assert.equal((await state(evaluator)).round_number, 3);
  assert.equal((await state(drawer)).rounds.length, 2);
  // Close a tab without leaving; the same participant can return via local storage.
  await evaluator.close();
  await waitFor(async () => (await drawer.locator('.presence-bar').textContent()).includes('desconectado'), 'Ausência do parceiro não foi detectada', 35_000);
  const resumed = await context.newPage();
  resumed.on('pageerror', error => errors.push(error.message));
  await resumed.goto(baseURL);
  await resumed.getByRole('button', { name: `Retomar Bia na sala ${code}`, exact: true }).click();
  await resumed.getByRole('heading', { name: 'Guie pelo calor.' }).waitFor();
  assert.equal(await resumed.evaluate(() => sessionStorage.getItem('quente-frio-participant')), tokenB);
  assert.equal((await state(resumed)).round_number, 3);
  await waitFor(async () => (await drawer.locator('.presence-bar').textContent()).includes('Bia: conectado'), 'Retorno não foi sincronizado');
  await resumed.getByRole('button', { name: 'Sair da sala', exact: true }).click();
  await resumed.getByRole('button', { name: 'Continuar na sala', exact: true }).click();
  assert.equal((await state(resumed)).status, 'playing');
  await resumed.getByRole('button', { name: 'Sair da sala', exact: true }).click();
  await resumed.getByRole('button', { name: 'Sair e encerrar participação', exact: true }).click();
  await resumed.getByRole('button', { name: 'Criar minha sala' }).waitFor();
  assert.equal(await resumed.getByRole('button', { name: `Retomar Bia na sala ${code}`, exact: true }).count(), 0);
  await drawer.getByRole('heading', { name: 'Sala encerrada', exact: true }).waitFor();
  const finalState = await state(drawer);
  assert.equal(finalState.result, 'abandoned');
  assert.equal(finalState.rounds.length, 3);
  assert.deepEqual(finalState.scores.map(player => player.points), [2, 2]);
  const revoked = await resumed.evaluate(async token => (await fetch('/api/room', {headers:{Accept:'application/json',Authorization:`Bearer ${token}`}})).status, tokenB);
  assert.equal(revoked, 401);
  assert.deepEqual(errors, []);
  await mkdir('tests/browser/artifacts', { recursive: true });
  await drawer.screenshot({ path: 'tests/browser/artifacts/presence-and-departure.png', fullPage: true });
  console.log('PASS: duas abas, canais privados, desenho via Reverb, reconexão, slider, histórico, rejeição, acertos automático/humano, três rodadas, troca de papéis, placar, fechamento de aba, retomada, saída explícita e revogação do acesso.');
} finally {
  await context.close();
  await browser.close();
}
