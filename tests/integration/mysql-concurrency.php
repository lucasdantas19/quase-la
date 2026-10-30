<?php

// Standalone integration runner: uses the configured MySQL database, never migrate:fresh.
use App\Game\Game;
use App\Models\Competition;
use App\Models\CompletedRound;
use App\Models\Participant;
use App\Models\Room;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Process\Process;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['broadcasting.default' => 'null', 'cache.default' => 'array']);

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

if (($argv[1] ?? '') === '--worker') {
    $task = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    try {
        file_put_contents($task['barrier'].'/'.$task['index'].'.ready', 'ready');
        $deadline = microtime(true) + 20;
        while (! is_file($task['barrier'].'/go')) {
            check(microtime(true) < $deadline, 'Barrier timeout');
            usleep(10000);
        }
        $game = app(Game::class);
        $actor = Participant::findOrFail($task['participant']);
        if ($task['action'] === 'end') {
            $game->manageCompetition($actor, 'end', $task['data']);
        } elseif ($task['action'] === 'leave') {
            $game->leave($actor);
        } else {
            $game->act($actor, $task['action'], $task['data']);
        }
        echo json_encode(['status' => 200], JSON_THROW_ON_ERROR);
    } catch (ValidationException $e) {
        echo json_encode(['status' => 422], JSON_THROW_ON_ERROR);
    } catch (HttpExceptionInterface $e) {
        echo json_encode(['status' => $e->getStatusCode()], JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        fwrite(STDERR, get_class($e).': '.$e->getMessage().PHP_EOL);
        exit(1);
    }
    exit;
}

function race(array $tasks, int $competitionId): array
{
    $directory = sys_get_temp_dir().'/quente-frio-race-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $processes = [];
    try {
        // Hold the real InnoDB parent lock until all workers have reached the barrier.
        DB::beginTransaction();
        Competition::whereKey($competitionId)->lockForUpdate()->firstOrFail();
        foreach ($tasks as $i => $task) {
            $process = new Process([PHP_BINARY, __FILE__, '--worker'], dirname(__DIR__, 2));
            $process->setInput(json_encode([...$task, 'barrier' => $directory, 'index' => $i], JSON_THROW_ON_ERROR));
            $process->setTimeout(30);
            $process->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 15;
        foreach ($processes as $i => $process) {
            while (! is_file($directory.'/'.$i.'.ready')) {
                check($process->isRunning(), 'Worker failed before barrier: '.$process->getErrorOutput());
                check(microtime(true) < $deadline, 'Workers did not reach barrier');
                usleep(10000);
            }
        }
        file_put_contents($directory.'/go', 'go');
        usleep(250000);
        foreach ($processes as $process) {
            check($process->isRunning(), 'Mutation bypassed the competition lock: '.$process->getErrorOutput());
        }
        DB::commit();
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            check($process->isSuccessful(), 'Concurrent worker failed: '.$process->getErrorOutput());
            $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['status'];
        }

        return $results;
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        foreach (glob($directory.'/*') as $path) {
            unlink($path);
        }
        rmdir($directory);
    }
}

$competitionId = null;
try {
    check(DB::connection()->getDriverName() === 'mysql', 'This check requires MySQL; SQLite does not validate row locks.');
    DB::connection()->getPdo();
    check(DB::getSchemaBuilder()->hasColumn('rooms', 'competition_id'), 'Run php artisan migrate before this check.');
    $engines = DB::table('information_schema.tables')->where('table_schema', DB::connection()->getDatabaseName())
        ->whereIn('table_name', ['competitions', 'rooms', 'participants', 'completed_rounds'])->pluck('engine');
    check($engines->count() === 4 && $engines->every(fn ($engine) => strtoupper($engine) === 'INNODB'), 'Concurrency checks require InnoDB tables.');
    $game = app(Game::class);
    $a = $game->create('Race A', 'drawer', true);
    $competitionId = Room::findOrFail($a['state']['id'])->competition_id;
    $code = $a['state']['competition']['code'];
    $b = $game->join($a['state']['code'], 'Race B');
    $c = $game->create('Race C', 'drawer', false, $code);
    $d = $game->join($c['state']['code'], 'Race D');
    $players = collect([$a, $b, $c, $d])->map(fn ($a) => $a['state']['you']['id'])->all();
    $task = fn ($id, $action, $data = []) => ['participant' => $id, 'action' => $action, 'data' => ['round_number' => 1, ...$data]];

    $starts = race([$task($players[0], 'start'), $task($players[0], 'start')], $competitionId);
    sort($starts);
    check($starts === [200, 422], 'Exactly one concurrent start must succeed.');
    $rooms = Room::where('competition_id', $competitionId)->orderBy('id')->get();
    check($rooms->pluck('status')->all() === ['playing', 'playing'], 'Both teams must start.');
    check($rooms[0]->secret === $rooms[1]->secret && $rooms[0]->ends_at->eq($rooms[1]->ends_at), 'Start must share secret and deadline.');
    $wins = race([$task($players[0], 'guess', ['guess' => $rooms[0]->secret]), $task($players[2], 'guess', ['guess' => $rooms[0]->secret])], $competitionId);
    check($wins === [200, 200], 'Both simultaneous wins must commit.');
    $afterWins = Room::where('competition_id', $competitionId)->orderBy('id')->get();
    check($afterWins->every(fn ($room) => $room->final_window_started_at !== null), 'First win must persist the final window for every team.');
    check($afterWins[0]->ends_at->eq($afterWins[1]->ends_at), 'Concurrent wins must not renew or diverge the final deadline.');
    check($afterWins[0]->final_window_started_at->diffInSeconds($afterWins[0]->ends_at) <= 30, 'Final window must not exceed thirty seconds.');
    check(CompletedRound::whereIn('room_id', $rooms->pluck('id'))->count() === 2, 'Each result must be archived once.');
    $ranking = $game->state(Participant::findOrFail($players[0]))['competition']['teams'];
    check(array_column($ranking, 'points') === [2, 1], 'Concurrent wins must have distinct stable ranking.');
    check($game->state(Participant::findOrFail($players[0]))['competition']['teams'] === $ranking, 'Ranking must be stable across reads.');

    // Duplicate confirmations race with confirmations from both teams.
    $confirmations = race(array_map(fn ($id) => $task($id, 'next'), [...$players, $players[0], $players[3]]), $competitionId);
    check(count(array_filter($confirmations, fn ($status) => ! in_array($status, [200, 409], true))) === 0, 'Readiness must succeed or report a completed transition.');
    $rooms = Room::where('competition_id', $competitionId)->orderBy('id')->get();
    check($rooms->every(fn ($room) => $room->final_window_started_at === null), 'New round must reset the final window.');
    check($rooms->pluck('round_number')->all() === [2, 2], 'Concurrent readiness must advance exactly once.');
    check($rooms[0]->ends_at->eq($rooms[1]->ends_at), 'Next round deadlines must match.');
    foreach ($players as $i => $id) {
        $state = $game->state(Participant::findOrFail($id));
        check($state['you']['role'] === ($i % 2 === 0 ? 'evaluator' : 'drawer'), 'Roles must swap exactly once.');
        if ($i % 2 !== 0) {
            check(! array_key_exists('secret', $state), 'New drawers must not receive the secret.');
        }
    }
    $stale = race([$task($players[0], 'feedback', ['score' => 99]), $task($players[1], 'guess', ['guess' => 'old round'])], $competitionId);
    check($stale === [409, 409], 'Delayed commands must not affect the next round.');
    check(CompletedRound::whereIn('room_id', $rooms->pluck('id'))->count() === 2, 'Duplicates must not add result snapshots.');
    $departure = race([
        $task($players[2], 'leave'),
        $task($players[3], 'guess', ['round_number' => 2, 'guess' => $rooms[1]->secret]),
    ], $competitionId);
    check($departure[0] === 200 && in_array($departure[1], [200, 422], true), 'Concurrent departure and guess must resolve without database errors.');
    check(Room::findOrFail($rooms[0]->id)->status === 'playing', 'Opponent departure must not close the remaining team.');
    check(Room::findOrFail($rooms[1]->id)->status === 'closed', 'Departing team must be closed.');
    check(CompletedRound::where('room_id', $rooms[1]->id)->where('number', 2)->count() === 1, 'Departure and win must archive exactly once.');
    $endings = race([
        $task($players[0], 'end', ['round_number' => 2]),
        $task($players[0], 'end', ['round_number' => 2]),
    ], $competitionId);
    check($endings === [200, 200], 'Concurrent termination must be idempotent.');
    $final = $game->state(Participant::findOrFail($players[1]))['competition'];
    check($final['ended_at'] !== null && Room::where('competition_id', $competitionId)->whereNull('closed_at')->count() === 0, 'Termination must close every team.');
    check($final['teams'] === Competition::findOrFail($competitionId)->final_standings, 'Final ranking must be persisted.');
    echo 'PASS: MySQL row-lock contention, simultaneous starts/wins/readiness, stable ranking, role secrecy, stale commands and departure/win races.'.PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'FAIL: '.$e->getMessage().PHP_EOL);
    $failed = true;
} finally {
    if ($competitionId !== null) {
        // Only delete records created by this run. No schema or pre-existing data is removed.
        Room::where('competition_id', $competitionId)->delete();
        Competition::whereKey($competitionId)->delete();
    }
}
exit(isset($failed) ? 1 : 0);
