<?php

// The King's Escape

declare(strict_types=1);
session_start();


  // Database Creation 
const DB_FILE = __DIR__ . '/kings_escape.db';
const TABLE_SQL = <<<SQL
CREATE TABLE IF NOT EXISTS chessboard_positions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    move_number INTEGER NOT NULL,
    piece_type VARCHAR(10) NOT NULL,
    position_x INTEGER NOT NULL,
    position_y INTEGER NOT NULL,
    is_enemy BOOLEAN NOT NULL
);
SQL;

const DEFAULT_BOARD_SIZE = 8;
const DEFAULT_START = [1, 1];   // bottom-left (x1,y1)
const DEFAULT_EXIT  = [2, 2];   // top-right (x2,y2)
const DEFAULT_INITIAL_ENEMIES = 1;
const SPAWN_ENEMY_PROB = 70;    // percent chance that the new spawn is an enemy
// Then 30% chance left is for ally spawn )


 //  Database Helpers
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . DB_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(TABLE_SQL);
    }
    return $pdo;
}

// Insert a piece into the DB for a given move number
function db_insert_piece(int $move, string $type, int $x, int $y, bool $isEnemy): void {
    $pdo = db();
    static $stmt = null;
    if ($stmt === null) {
        $stmt = $pdo->prepare("
            INSERT INTO chessboard_positions (move_number, piece_type, position_x, position_y, is_enemy)
            VALUES (:move, :type, :x, :y, :enemy)
        ");
    }
    $stmt->execute([
        ':move' => $move,
        ':type' => $type,
        ':x'    => $x,
        ':y'    => $y,
        ':enemy'=> $isEnemy ? 1 : 0,
    ]);
}

// Clear all positions from the Database
function db_reset_positions(): void {
    db()->exec("DELETE FROM chessboard_positions");
}

// Fetch all pieces for a given move number
function db_fetch_positions_for_move(int $move): array {
    $stmt = db()->prepare("SELECT piece_type, position_x, position_y, is_enemy FROM chessboard_positions WHERE move_number = :m ORDER BY id ASC");
    $stmt->execute([':m' => $move]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

  //  Game Helpers
 
function in_bounds(int $x, int $y, int $N): bool {
    return $x >= 0 && $y >= 0 && $x < $N && $y < $N;
}

// Return 8-direction neighbors for a square (king/enemy movement) 
function neighbors8(int $x, int $y, int $N): array {
    $out = [];
    for ($dx = -1; $dx <= 1; $dx++) {
        for ($dy = -1; $dy <= 1; $dy++) {
            if ($dx === 0 && $dy === 0) continue;
            $nx = $x + $dx; $ny = $y + $dy;
            if (in_bounds($nx, $ny, $N)) $out[] = [$nx, $ny];
        }
    }
    return $out;
}

// Build key string "x,y" for position
function keyxy(int $x, int $y): string {
    return "{$x},{$y}";
}

// Parse integer safely with fallback 
function int_from($v, int $fallback=0): int {
    return isset($v) && is_numeric($v) ? (int)$v : $fallback;
}
 
   //Session Default State
  
$defaultGame = [
    'N' => DEFAULT_BOARD_SIZE,
    'start' => DEFAULT_START,
    'exit'  => DEFAULT_EXIT,
    'turn' => 0,
    'king' => DEFAULT_START,
    // board pieces state maintained in session (arrays of [x,y])
    'enemies' => [],   // each element: [x,y]
    'allies'  => [],
    'status'  => 'Ready. Press "New Game" to start.',
    'result'  => null, //  'win' | 'lose' | null (ongoing) 
    'initial_enemy_count' => DEFAULT_INITIAL_ENEMIES,
];

if (!isset($_SESSION['game']) || !is_array($_SESSION['game'])) {
    $_SESSION['game'] = $defaultGame;
} else {
    // ensure keys exist
    $_SESSION['game'] = array_merge($defaultGame, $_SESSION['game']);
    foreach (['start','exit','king'] as $k) {
        if (!isset($_SESSION['game'][$k]) || !is_array($_SESSION['game'][$k])) {
            $_SESSION['game'][$k] = $defaultGame[$k];
        }
    }
    if (!isset($_SESSION['game']['enemies']) || !is_array($_SESSION['game']['enemies'])) $_SESSION['game']['enemies'] = [];
    if (!isset($_SESSION['game']['allies'])  || !is_array($_SESSION['game']['allies']))  $_SESSION['game']['allies']  = [];
}


   //Game Engine: helper functions for board state
function board_occupied_map(array $enemies, array $allies, array $king): array {
    $map = [];
    foreach ($enemies as [$x,$y]) $map[keyxy($x,$y)] = 'E';
    foreach ($allies  as [$x,$y]) $map[keyxy($x,$y)] = 'A';
    $map[keyxy($king[0], $king[1])] = 'K';
    return $map;
}

// Return list of empty cells for spawning new pieces
function empty_cells_for_spawn(int $N, array $enemies, array $allies, array $king, array $exit): array {
    $occupied = [];
    foreach ($enemies as [$x,$y]) $occupied[keyxy($x,$y)] = true;
    foreach ($allies  as [$x,$y]) $occupied[keyxy($x,$y)] = true;
    $occupied[keyxy($king[0], $king[1])] = true; // avoid spawning on king
    $occupied[keyxy($exit[0], $exit[1])] = true; // avoid spawning on exit
    $list = [];
    for ($x=0;$x<$N;$x++){
        for ($y=0;$y<$N;$y++){
            $k = keyxy($x,$y);
            if (!isset($occupied[$k])) $list[] = [$x,$y];
        }
    }
    return $list;
}


   //Random spawn helpers 
function spawn_initial_enemies(int $count, int $N, array $king, array $exit): array {
    $enemies = [];
    $attempts = 0;
    while (count($enemies) < $count && $attempts < 500) {
        $attempts++;
        $x = rand(0, $N-1); $y = rand(0, $N-1);
        if ($x === $king[0] && $y === $king[1]) continue;
        if ($x === $exit[0] && $y === $exit[1]) continue;
        $k = keyxy($x,$y);
        $exists = false;
        foreach ($enemies as $e) if (keyxy($e[0],$e[1]) === $k) { $exists=true; break; }
        if ($exists) continue;
        $enemies[] = [$x,$y];
    }
    return $enemies;
}

function spawn_random_piece(int $N, array $enemies, array $allies, array $king, array $exit): ?array {
    // returns ['type'=>'enemy'|'ally','pos'=>[x,y]] or null if no empty cell
    $empties = empty_cells_for_spawn($N, $enemies, $allies, $king, $exit);
    if (empty($empties)) return null;
    $pick = $empties[array_rand($empties)];
    $roll = rand(1,100);
    return ['type' => ($roll <= SPAWN_ENEMY_PROB) ? 'enemy' : 'ally', 'pos' => $pick];
}


  // Enemy movement logic
function move_enemies(int $N, array &$enemies, array &$allies, array $king): array {
    $result = ['captured' => false, 'capturedEnemyIndex' => null, 'ally_removed' => null];
    // Build fast lookup of allies and enemies positions
    $allyMap = [];
    foreach ($allies as $ai => $a) $allyMap[keyxy($a[0],$a[1])] = $ai;
    $enemyMap = [];
    foreach ($enemies as $ei => $e) $enemyMap[keyxy($e[0],$e[1])] = $ei;

    $newEnemies = []; // will store updated positions
    // Repetition through enemies in original order
    for ($i=0;$i<count($enemies);$i++) {
        [$ex,$ey] = $enemies[$i];
        $candidates = neighbors8($ex,$ey,$N);

        // shuffle candidates for randomness
        usort($candidates, function($a,$b){ return rand(-1,1); });

        $moved = false;
        foreach ($candidates as [$nx,$ny]) {
            $kxy = keyxy($nx,$ny);

            // can't move into another enemy's new position
            $blockedByNewEnemy = false;
            foreach ($newEnemies as $ne) {
                if ($ne[0] === $nx && $ne[1] === $ny) { $blockedByNewEnemy = true; break; }
            }
            if ($blockedByNewEnemy) continue;

            // If target is ally -> ally is sacrificed; enemy stays in place (blocked)
            if (isset($allyMap[$kxy])) {
                // remove ally
                $removedIndex = $allyMap[$kxy];
                $result['ally_removed'][] = ['ally_index'=>$removedIndex, 'pos'=>[$nx,$ny]];
                // mark removal
                $allies[$removedIndex] = null;
                unset($allyMap[$kxy]);
                $moved = false; // enemy stays at original place
                break; // stop trying moves for this enemy
            }

            // If target is king -> enemy moves there, capture king
            if ($nx === $king[0] && $ny === $king[1]) {
                $newEnemies[] = [$nx,$ny];
                $result['captured'] = true;
                $result['capturedEnemyIndex'] = $i;
                $moved = true;
                break;
            }

            // If target is empty (not occupied by king or allies or other enemies), move there
            $occupiedByEnemyOriginal = false;
            foreach ($enemies as $ei => $ee) {
                if ($ei === $i) continue;
                if ($ee[0] === $nx && $ee[1] === $ny) { $occupiedByEnemyOriginal = true; break; }
            }
            if ($occupiedByEnemyOriginal) continue;


            // move enemy to new position
            $newEnemies[] = [$nx,$ny];
            $moved = true;
            break;
        }

        if (!$moved) {
            // enemy stays
            $newEnemies[] = [$ex,$ey];
        }
    }

    // Filter out any allies set to null (sacrificed)
    $allies = array_values(array_filter($allies, function($v){ return $v !== null; }));

    // Deduplicate newEnemies (in rare conflicts) by keeping order and unique positions
    $seen = [];
    $dedup = [];
    foreach ($newEnemies as [$x,$y]) {
        $k = keyxy($x,$y);
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $dedup[] = [$x,$y];
    }

    $enemies = $dedup;
    return $result;
}


  // Simulation persistence helper
function persist_turn_state(int $turn, array $king, array $enemies, array $allies): void {

    // insert King first (not enemy)
    db_insert_piece($turn, 'King', $king[0], $king[1], false);
    // insert allies as piece_type 'Ally', is_enemy = 0
    foreach ($allies as [$ax,$ay]) {
        db_insert_piece($turn, 'Ally', $ax, $ay, false);
    }

    // insert enemies as piece_type 'Enemy', is_enemy = 1
    foreach ($enemies as [$ex,$ey]) {
        db_insert_piece($turn, 'Pawn', $ex, $ey, true);
    }
}



  // Actions: new game, move, Reset game / database
  
$action = $_POST['action'] ?? $_GET['action'] ?? '';

function set_status(string $msg, $result = null): void {
    $_SESSION['game']['status'] = $msg;
    $_SESSION['game']['result'] = $result;
}

// Start a new game 
if ($action === 'new') {
    $N = max(2, int_from($_POST['board_size'] ?? $_SESSION['game']['N'], DEFAULT_BOARD_SIZE));
    $sx = int_from($_POST['start_x'] ?? $_SESSION['game']['start'][0], $_SESSION['game']['start'][0]);
    $sy = int_from($_POST['start_y'] ?? $_SESSION['game']['start'][1], $_SESSION['game']['start'][1]);
    $ex = int_from($_POST['exit_x'] ?? $_SESSION['game']['exit'][0], $_SESSION['game']['exit'][0]);
    $ey = int_from($_POST['exit_y'] ?? $_SESSION['game']['exit'][1], $_SESSION['game']['exit'][1]);
    $initEnemies = max(0, int_from($_POST['initial_enemy_count'] ?? $_SESSION['game']['initial_enemy_count'], DEFAULT_INITIAL_ENEMIES));

    // hold coordinates to board size
    $sx = max(0, min($N-1, $sx)); $sy = max(0, min($N-1, $sy));
    $ex = max(0, min($N-1, $ex)); $ey = max(0, min($N-1, $ey));

    $_SESSION['game']['N'] = $N;
    $_SESSION['game']['start'] = [$sx,$sy];
    $_SESSION['game']['exit']  = [$ex,$ey];
    $_SESSION['game']['turn']  = 0;
    $_SESSION['game']['king']  = [$sx,$sy];
    $_SESSION['game']['enemies'] = spawn_initial_enemies($initEnemies, $N, [$sx,$sy], [$ex,$ey]);
    $_SESSION['game']['allies']  = [];
    $_SESSION['game']['status'] = 'Game started. King at start.';
    $_SESSION['game']['result'] = null;
    $_SESSION['game']['initial_enemy_count'] = $initEnemies;

    // clear Database and persist initial state (move 0)
    db_reset_positions();
    persist_turn_state(0, $_SESSION['game']['king'], $_SESSION['game']['enemies'], $_SESSION['game']['allies']);

    // redirect to avoid re-post
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// Clear DB only 
if ($action === 'clear') {
    db_reset_positions();
    set_status('Database cleared.', $_SESSION['game']['result'] ?? null);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// Player move action 
if ($action === 'move' && ($_SESSION['game']['result'] ?? null) === null) {
    $tx = int_from($_POST['tx'] ?? null, -999);
    $ty = int_from($_POST['ty'] ?? null, -999);
    $game = &$_SESSION['game'];
    $N = (int)$game['N'];
    $king = $game['king'];
    $turn = (int)$game['turn'];
    $enemies = $game['enemies'];
    $allies  = $game['allies'];
    $exit = $game['exit'];
    $start =$game['start'];

    // Validate target within bounds
    if (!in_bounds($tx,$ty,$N)) {
        set_status('Invalid move: out of bounds.', null);
    } else {
        // must be a legal king move
        $legal = false;
        foreach (neighbors8($king[0], $king[1], $N) as [$nx,$ny]) {
            if ($nx === $tx && $ny === $ty) { $legal = true; break; }
        }
        if (!$legal) {
            set_status('Invalid move: King can only move one square in any direction.', null);
        } else {
            // target must not be occupied by an enemy or ally
            $occupiedMap = board_occupied_map($enemies, $allies, $king);
            if (isset($occupiedMap[keyxy($tx,$ty)]) && $occupiedMap[keyxy($tx,$ty)] !== 'K') {
                set_status('Invalid move: target tile is occupied.', null);
            } else {
                // Move accepted: advance turn
                $nextTurn = $turn + 1;
                $game['king'] = [$tx,$ty];
                $game['turn'] = $nextTurn;

                // move enemies (they react after the king moved)
                $moveResult = move_enemies($N, $enemies, $allies, $game['king']);
                $game['enemies'] = $enemies;
                $game['allies'] = $allies;

                // If any enemy moved onto king -> captured
                if ($moveResult['captured']) {
                    // persist final positions including capturing enemy
                    persist_turn_state($nextTurn, $game['king'], $game['enemies'], $game['allies']);
                    set_status("An enemy moved onto the King at turn $nextTurn — you were captured. Game over.", 'lose');
                    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
                    exit;
                }

                // After enemies move, spawn one new piece at random (enemy or ally)
                $spawn = spawn_random_piece($N, $game['enemies'], $game['allies'], $game['king'], $exit);
                if ($spawn !== null) {
                    if ($spawn['type'] === 'enemy') {
                        $game['enemies'][] = $spawn['pos'];
                    } else {
                        $game['allies'][] = $spawn['pos'];
                    }
                }

                // Persist entire state for this move number
                persist_turn_state($nextTurn, $game['king'], $game['enemies'], $game['allies']);

                // Check if spawn accidentally landed on king 
                //(I know it won't happen because spawn picks empty squares), but I made sure to double-check:
                foreach ($game['enemies'] as [$exx,$eyy]) {
                    if ($exx === $game['king'][0] && $eyy === $game['king'][1]) {
                        set_status("An enemy spawned on the King at turn $nextTurn — you were captured. Game over.", 'lose');
                        $game['result'] = 'lose';
                        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
                        exit;
                    }
                }

                // Check win condition
                if ($game['king'][0] === $exit[0] && $game['king'][1] === $exit[1]) {
                    set_status("Victory! The King reached the exit at turn $nextTurn.", 'win');
                    $game['result'] = 'win';
                    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
                    exit;
                }

                // If not captured and not won, check if king has any legal safe moves next turn
                $enemyMapNow = [];
                foreach ($game['enemies'] as $e) $enemyMapNow[keyxy($e[0],$e[1])] = true;
                $allyMapNow = [];
                foreach ($game['allies'] as $a) $allyMapNow[keyxy($a[0],$a[1])] = true;

                $hasMove = false;
                foreach (neighbors8($game['king'][0], $game['king'][1], $N) as [$mx,$my]) {
                    $kxy = keyxy($mx,$my);
                    if (isset($enemyMapNow[$kxy]) || isset($allyMapNow[$kxy])) continue;
                    $hasMove = true; break;
                }
                if (!$hasMove) {
                    set_status("No legal moves available after turn $nextTurn — the King is trapped. You lose.", 'lose');
                    $game['result'] = 'lose';
                } else {
                    set_status("Move accepted to ({$tx},{$ty}). Turn $nextTurn complete.", null);
                }

                // update session arrays
                $_SESSION['game'] = $game;

                // redirect to avoid double-post
                header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
                exit;
            }
        }
    }
}


//   Prepare data for rendering

$game = &$_SESSION['game'];
$start = $game['start'];
$N = (int)$game['N'];
$start = $game['start'];
$exit  = $game['exit'];
$king  = $game['king'];
$turn  = (int)$game['turn'];
$enemies = $game['enemies'];
$allies  = $game['allies'];
$status  = $game['status'];
$result  = $game['result'];
$initialEnemyCount = (int)$game['initial_enemy_count'];

// Build map for quick checks in the view 
$occupiedMap = board_occupied_map($enemies, $allies, $king);

// Determine legal moves for current king (make sure he cannot land on an enemy or ally) 
$legalMoves = [];
foreach (neighbors8($king[0], $king[1], $N) as [$mx,$my]) {
    $kxy = keyxy($mx,$my);
    if (!isset($occupiedMap[$kxy])) $legalMoves[$kxy] = true;
}


//  HTML (for User Interface)

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>The King's Escape</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<style>
    :root{
        --bg:#0b1220;
        --panel:#ffffff10;
        --cell-size:56px;
        --gap:4px;
        --light:#f0d9b5;
        --dark:#b58863;
        --enemy:#cc3333;
        --ally:#3b82f6;
        --king:#22c55e;
        --exit-outline:#f59e0b;
        --muted:#9aa9bf;
    }
        * {
            box-sizing: border-box
        }
    
        body {
            margin: 0;
            font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, Arial;
            background: var(--bg);
            color: #e6eef6
        }
    
        header {
            padding: 18px 22px;
            border-bottom: 1px solid rgba(255, 255, 255, .03);
            text-align: center
        }
    
        header h1 {
            margin: 0;
            font-weight: 600
        }
    
        main {
            display: grid;
            grid-template-columns: 420px 1fr;
            gap: 18px;
            padding: 20px;
            align-items: start
        }
    
        .card {
            background: var(--panel);
            border-radius: 12px;
            padding: 18px;
            box-shadow: 0 12px 28px rgba(0, 0, 0, .5)
        }
    
        label {
            display: block;
            color: var(--muted);
            margin-bottom: 6px;
            font-size: 18px
        }
    
        input[type=number],
        input[type=text] {
            width: 100%;
            padding: 8px;
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, .04);
            background: #071022;
            color: #e6eef6
        }
    
        .row {
            display: flex;
            gap: 8px
        }
    
        textarea {
            width: 100%;
            min-height: 80px;
            padding: 8px;
            border-radius: 8px;
            background: #071022;
            color: #e6eef6;
            border: 1px solid rgba(255, 255, 255, .04)
        }
    
        .btn {
            display: inline-block;
            padding: 10px 14px;
            border-radius: 10px;
            background: #ef476f;
            color: white;
            border: 0;
            font-weight: 700;
            cursor: pointer
        }
    
        .btn.secondary {
            background: #334155
        }
    
        .status {
            margin-top: 10px;
            padding: 10px;
            border-radius: 8px;
            background: rgba(255, 255, 255, .02);
            border: 1px solid rgba(255, 255, 255, .02)
        }
    
        .board {
            display: grid;
            grid-template-columns:
                repeat(<?=($N + 1) ?>, var(--cell-size));
            gap: var(--gap);
            background: transparent;
            justify-content: start
        }
    
        .cell {
            width: var(--cell-size);
            height: var(--cell-size);
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            position: relative;
            font-weight: 800
        }
    
        .light {
            background: var(--light);
            color: #222
        }
    
        .dark {
            background: var(--dark);
            color: #fff
        }
    
        .enemy {
            background: var(--enemy) !important;
            color: #fff
        }
    
        .ally {
            background: var(--ally) !important;
            color: #fff
        }
    
        .king {
            background: var(--king) !important;
            color: #062e13
        }
    
        .exit {
            outline: 3px solid var(--exit-outline);
            outline-offset: -6px
        }
    
        .cell form {
            width: 100%;
            height: 100%;
            margin: 0
        }
    
        .start {
            outline: 3px solid black;
            outline-offset: -4px;
        }
    
        .cell button {
            width: 100%;
            height: 100%;
            border: 0;
            background: transparent;
            cursor: pointer
        }
    
        .legend {
            display: flex;
            gap: 8px;
            margin-top: 12px;
            flex-wrap: wrap
        }
    
        .pill {
            background: rgba(255, 255, 255, .02);
            padding: 6px 10px;
            border-radius: 999px;
            border: 1px solid rgba(255, 255, 255, .02)
        }
    
        .muted {
            color: var(--muted)
        }
    
        footer {
            padding: 12px;
            text-align: center;
            color: var(--muted)
        }
    
        table {
            width: 100%;
            border-collapse: collapse;
            color: #e6eef6
        }
    
        th,
        td {
            padding: 6px 8px;
            text-align: left;
            border-bottom: 1px solid rgba(255, 255, 255, .03)
        }
    
        .axis-label {
            width: var(--cell-size);
            height: var(--cell-size);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--muted);
            font-weight: 700;
        }
    
        .db-preview {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid #ccc;
            padding: 6px;
            background: rgba(255, 255, 255, 0.05);
        }
    
        .db-preview table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9em;
        }
    
        .db-preview th,
        .db-preview td {
            border: 1px solid rgba(255, 255, 255, 0.2);
            padding: 4px 6px;
            text-align: center;
        }
</style>
</head>

<body>
<header>
    <h1>♚ The King's Escape</h1>
    <div class="muted" style="margin-top:6px">Move number <?= $turn ?> | King position: (<?= htmlspecialchars((string)$king[0]) ?>,
     <?= htmlspecialchars((string)$king[1]) ?>) | Exit: (<?= htmlspecialchars((string)$exit[0]) ?>, <?= htmlspecialchars((string)$exit[1]) ?>)
    </div>
</header>


<main>
    <!-- Left sidebar: controls & instructions -->
    <section class="card" style="width: 600px; margin-left: 120px;">
        <form method="post" style="margin-bottom:12px ">
            <input type="hidden" name="action" value="new">
            <label>Board size (N × N)</label>
            <input type="number" name="board_size" min="2" max="12" value="<?= htmlspecialchars((string)$N) ?>">

            <div style="display:flex;gap:8px;margin-top:10px">
                <div style="flex:1">
                    <label>Start Position (X , Y)</label>
                    <div class="row">
                        <input type="number" name="start_x" min="0" max="<?= $N - 1 ?>" value="<?= htmlspecialchars((string)$start[0]) ?>">
                        <input type="number" name="start_y" min="0" max="<?= $N - 1 ?>" value="<?= htmlspecialchars((string)$start[1]) ?>">
                    </div>
                </div>

                <div style="flex:1">
                    <label>Exit Position (X , Y)</label>
                    <div class="row">
                        <input type="number" name="exit_x" min="0" max="<?= $N - 1 ?>" value="<?= htmlspecialchars((string)$exit[0]) ?>">
                        <input type="number" name="exit_y" min="0" max="<?= $N - 1 ?>" value="<?= htmlspecialchars((string)$exit[1]) ?>">
                    </div>
                </div>
            </div>

            <div style="margin-top:10px">
                <label>Initial enemy count</label>
                <input type="number" name="initial_enemy_count" min="0" max="<?= $N * $N - 2 ?>" value="<?= $initialEnemyCount ?>">
            </div>

            <div style="margin-top:12px;display:flex;gap:8px">
                <button class="btn" type="submit">New Game</button>
                <button class="btn secondary" type="submit" name="action" value="clear">Clear Database</button>
            </div>

            <div class="status">
                <div><strong>Status:</strong> <?= htmlspecialchars($status) ?></div>
                <div class="muted" style="margin-top:6px">Enemies move after your turn. Allies are sacrificed when enemies try to move into them.</div>
            </div>
        </form>

        <div class="legend">
            <div class="pill">🟩 &#9812 = King (you)  </div>
            <div class="pill">🟥 E = Enemy (moves)</div>
            <div class="pill">🟦 A = Ally (blocks, sacrificed)</div>
            <div class="pill">🟨/🏠 Exit = goal square</div>
            <div class="pill">⬛/📌 Start line</div>
        </div>
    <section class="card" >
        <div class="db-preview" style="margin-top:12px">
            <strong>Database preview (rows for current turn <?= $turn ?>):</strong>
            <div style="margin-top:8px">
                <?php $rows = db_fetch_positions_for_move($turn); ?>
                <?php if (empty($rows)): ?>
                    <div class="muted">No entries for this turn in DB.</div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>X</th>
                                <th>Y</th>
                                <th>is enemy</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $r): ?>
                                <tr>
                                    <td><?= htmlspecialchars((string)$r['piece_type']) ?></td>
                                    <td><?= htmlspecialchars((string)$r['position_x']) ?></td>
                                    <td><?= htmlspecialchars((string)$r['position_y']) ?></td>
                                    <td><?= $r['is_enemy'] ? 'yes' : 'no' ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </section>

    </section>

    <!-- Right: Chessboard -->
    <section class="card" style="margin-left: 350px; margin-right: 400px;">
        <div >
            <div ><strong>Chess Board</strong></div>
            <div class="muted">Enemies: <?= count($enemies) ?> &nbsp; Allies: <?= count($allies) ?></div>
            
                    <?php if ($result !== null): ?>
            <div style="padding:10px;border-radius:8px;background:rgba(255,255,255,.02)">
                <?php if ($result === 'win'): ?>
                    <strong>🎉 You won! <br></strong> <?= htmlspecialchars($status) ?>
                <?php else: ?>
                    <strong>💀 You lost. <br> </strong> <?= htmlspecialchars($status) ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        </div>

        <div class="board" role="grid" aria-label="Chessboard (with axis labels)">
            <!-- top-left empty corner -->
            <div class="axis-label" aria-hidden="true"></div>

            <!-- top axis labels (x = 0..N-1) -->
            <?php for ($x = 0; $x < $N; $x++): ?>
                <div class="axis-label" aria-hidden="true"><?= $x ?></div>
            <?php endfor; ?>

            <!-- board rows with left axis labels and cells -->
            <?php for ($y = $N - 1; $y >= 0; $y--): ?>
                <div class="axis-label" aria-hidden="true"><?= $y ?></div>

                <?php for ($x = 0; $x < $N; $x++):
                    $isLight = (($x + $y) % 2 === 0);
                    $cls = $isLight ? 'light' : 'dark';
                    $kxy = keyxy($x,$y);
                    $content = '';
                    $extraClass = '';
                    if ($x === $king[0] && $y === $king[1]) { $extraClass = 'king'; $content = '&#9812'; }
                    elseif (isset($occupiedMap[$kxy]) && $occupiedMap[$kxy] === 'E') { $extraClass = 'enemy'; $content = 'E'; }
                    elseif (isset($occupiedMap[$kxy]) && $occupiedMap[$kxy] === 'A') { $extraClass = 'ally'; $content = 'A'; }
                    if ($x === $exit[0] && $y === $exit[1]) {
                        $extraClass = trim($extraClass . ' exit'); $content = '🏠'; }
                    if($x==$start[0] && $y==$start[1]) {
                        $extraClass = trim($extraClass . ' start'); $content = '📌';
                    } 
                    $cellClass = trim("cell $cls $extraClass");
                ?>
                    <div class="<?= $cellClass ?>">
                        <?php if (isset($legalMoves[$kxy]) && $result === null): ?>
                            <form method="post" aria-hidden="true">
                                <input type="hidden" name="action" value="move">
                                <input type="hidden" name="tx" value="<?= $x ?>">
                                <input type="hidden" name="ty" value="<?= $y ?>">
                                <button title="Move King to (<?= $x ?>,<?= $y ?>)"></button>
                            </form>
                        <?php else: ?>
                            <?php if ($content !== ''): ?>
                                <div style="font-size:18px"><?= $content ?></div>
                            <?php else: ?>
                                <div style="width:100%;height:100%"></div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endfor; ?>
            <?php endfor; ?>
        </div>
    </section>


</main>

<footer>
   <!-- <div style="padding:12px;text-align:center;color:var(--muted)">Database file: <code><?= htmlspecialchars(basename(DB_FILE)) ?></code> — Table: <code>chessboard_positions</code></div> -->
</footer>
</body>
</html>

<?php
//--------------The END ----------------
// Please note that I used a 24 inch screen to design, the page may different on a laptop
// <----------- By Lebepe M.A --------------->
?>