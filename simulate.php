<?php
// =====================================================================
// simulate.php – populate the DB with placeholder test data
//
// Visit ?confirm=YES to run. Wipes every team/player/match/result then
// generates:
//   * 8 Premier Division teams + 7 A Division teams (placeholder names)
//   * 6 randomly-named players per team (90 total)
//   * A complete round-robin (every team plays every other team once
//     within its division) with realistic random singles, doubles,
//     180s and high finishes for each match
//   * A full knockout cup for each division, with the bracket drawn
//     and every round simulated through to a champion
//
// Captain logins are username "prem1".."prem8" and "adiv1".."adiv7",
// password "test1234" for all of them.
//
// Designed for QA. Delete this file from the host after use – it
// exposes the DB credentials.
// =====================================================================

ini_set('display_errors', '0');
ini_set('log_errors', '1');
header('Content-Type: text/html; charset=utf-8');

$db_host = 'localhost';
$db_name = 'darts_league';
$db_user = 'darts_league';
$db_pass = 'M0l3y1993#cdl';

echo '<!doctype html><html><head><meta charset="utf-8"><title>CDL – Simulation</title>';
echo '<style>body{font-family:Segoe UI,Tahoma,sans-serif;max-width:960px;margin:20px auto;padding:0 20px;color:#222;line-height:1.5}';
echo 'h1,h2{color:#1a237e}.ok{color:#2e7d32}.err{color:#c62828}.note{color:#757575}';
echo '.box{background:#f5f5f5;padding:15px 20px;border-left:4px solid #1a237e;border-radius:4px;margin:15px 0}';
echo '.warn{background:#fff3e0;border-left-color:#e65100}.bad{background:#ffebee;border-left-color:#c62828}';
echo 'code{background:#fff;padding:2px 6px;border-radius:3px;border:1px solid #ddd}pre{background:#fff;padding:10px;border-radius:4px;overflow:auto}';
echo 'table{border-collapse:collapse;margin:10px 0}th,td{padding:6px 12px;border:1px solid #ccc;text-align:left}th{background:#1a237e;color:white}</style>';
echo '</head><body><h1>Darts League – Test Data Simulation</h1>';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Exception $e) {
    echo '<div class="box bad"><strong>Cannot connect to the database:</strong> ' . htmlspecialchars($e->getMessage()) . '</div></body></html>';
    exit;
}

if (!isset($_GET['confirm']) || $_GET['confirm'] !== 'YES') {
    echo '<div class="box warn"><strong>This script will:</strong><ol>';
    echo '<li>Wipe every team, captain login, player, match, singles/doubles result, 180, high finish, league standing, player request and cup bracket.</li>';
    echo '<li>Create 8 Premier and 7 A Division placeholder teams.</li>';
    echo '<li>Create 6 randomly-named players per team (90 total).</li>';
    echo '<li>Play a complete round-robin season – every team plays every other team in its division once.</li>';
    echo '<li>Draw and play a full knockout cup for both divisions.</li>';
    echo '</ol>';
    echo '<p><strong>If you actually have live season data, do NOT run this.</strong> There is no undo.</p>';
    echo '<p>To run: <a href="?confirm=YES"><strong>simulate.php?confirm=YES</strong></a></p></div>';
    echo '<div class="box bad"><strong>For security, delete simulate.php from the host after running it.</strong> It contains the DB password in source.</div>';
    echo '</body></html>';
    exit;
}

// =====================================================================
// 1. WIPE
// =====================================================================
echo '<h2>1. Wipe existing data</h2>';
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['doubles_results','singles_results','high_finishes','one_eighties','league_standings','player_requests','matches','team_captains','players','teams'] as $t) {
    $pdo->exec("TRUNCATE TABLE `$t`");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
echo '<p class="ok">&#10003; 10 tables truncated.</p>';

// =====================================================================
// 2. TEAMS
// =====================================================================
echo '<h2>2. Create teams</h2>';
$premierNames = ['Alpha Arrows','Bravo Bulls','Charlie Chargers','Delta Daggers','Echo Eagles','Foxtrot Foxes','Golf Gunners','Hotel Hawks'];
$aNames       = ['India Imps','Juliet Jets','Kilo Kings','Lima Lions','Mike Mustangs','November Nomads','Oscar Owls'];

$insertTeam = $pdo->prepare('INSERT INTO teams (team_name, division) VALUES (?, ?)');
$insertCap  = $pdo->prepare('INSERT INTO team_captains (team_id, username, password_hash) VALUES (?, ?, ?)');
$capHash    = password_hash('test1234', PASSWORD_BCRYPT);

$teamsById = [];
foreach ($premierNames as $i => $name) {
    $insertTeam->execute([$name, 'premier']);
    $tid = (int)$pdo->lastInsertId();
    $u = 'prem' . ($i + 1);
    $insertCap->execute([$tid, $u, $capHash]);
    $teamsById[$tid] = ['name' => $name, 'division' => 'premier', 'username' => $u];
}
foreach ($aNames as $i => $name) {
    $insertTeam->execute([$name, 'a']);
    $tid = (int)$pdo->lastInsertId();
    $u = 'adiv' . ($i + 1);
    $insertCap->execute([$tid, $u, $capHash]);
    $teamsById[$tid] = ['name' => $name, 'division' => 'a', 'username' => $u];
}
echo '<p class="ok">&#10003; 15 teams + captain logins (username <code>prem1..prem8</code> / <code>adiv1..adiv7</code>, password <code>test1234</code>).</p>';

// =====================================================================
// 3. PLAYERS
// =====================================================================
echo '<h2>3. Create players</h2>';
$first = ['James','John','Robert','Michael','William','David','Richard','Joseph','Thomas','Charles','Christopher','Daniel','Matthew','Anthony','Mark','Donald','Steven','Paul','Andrew','Joshua','Kenneth','Kevin','Brian','George','Edward','Ronald','Timothy','Jason','Jeffrey','Ryan','Jacob','Gary','Nicholas','Eric','Jonathan','Stephen','Larry','Justin','Scott','Brandon','Benjamin','Samuel','Gregory','Frank','Alexander','Raymond','Patrick','Jack','Dennis','Jerry','Tyler','Aaron','Henry','Douglas','Peter','Adam','Nathan','Zachary','Walter','Kyle','Harold','Carl','Jeremy','Keith','Roger','Gerald','Ethan','Arthur','Terry','Sean','Christian','Austin','Lawrence','Joe','Albert','Wayne','Bruce','Eugene','Russell','Bobby','Louis','Roy','Ralph','Vincent','Bryan','Howard','Philip','Phillip','Russell'];
$last  = ['Smith','Johnson','Williams','Brown','Jones','Garcia','Miller','Davis','Rodriguez','Martinez','Hernandez','Lopez','Gonzalez','Wilson','Anderson','Thomas','Taylor','Moore','Jackson','Martin','Lee','Perez','Thompson','White','Harris','Sanchez','Clark','Ramirez','Lewis','Robinson','Walker','Young','Allen','King','Wright','Scott','Torres','Nguyen','Hill','Flores','Green','Adams','Nelson','Baker','Hall','Rivera','Campbell','Mitchell','Carter','Roberts','Gomez','Phillips','Evans','Turner','Diaz','Parker','Cruz','Edwards','Collins','Reyes','Stewart','Morris','Morales','Murphy','Cook','Rogers','Gutierrez','Ortiz','Morgan'];

$insertPlayer = $pdo->prepare('INSERT INTO players (player_name, team_id) VALUES (?, ?)');
$playersByTeam = [];
$globalUsed = [];
foreach (array_keys($teamsById) as $tid) {
    $playersByTeam[$tid] = [];
    for ($i = 0; $i < 6; $i++) {
        do {
            $name = $first[array_rand($first)] . ' ' . $last[array_rand($last)];
        } while (isset($globalUsed[$name]));
        $globalUsed[$name] = true;
        $insertPlayer->execute([$name, $tid]);
        $playersByTeam[$tid][] = ['id' => (int)$pdo->lastInsertId(), 'name' => $name];
    }
}
echo '<p class="ok">&#10003; 6 players per team (90 total, all unique names).</p>';

// =====================================================================
// HELPERS for match simulation
// =====================================================================

// Pick a random singles/doubles game score given the max games to win
// (3 for Premier singles, 2 for everything else). Bias toward closer
// scores so the data feels realistic.
function pickGameScore($firstTo) {
    $homeWins = (mt_rand(0, 1) === 1);
    if ($homeWins) {
        $loser = mt_rand(0, $firstTo - 1);
        return [$firstTo, $loser];
    } else {
        $loser = mt_rand(0, $firstTo - 1);
        return [$loser, $firstTo];
    }
}

// Simulate one match between two teams. Inserts the match row, doubles
// + singles results, a few 180s, an occasional high finish. For league
// matches the aggregated home/away score is the sum of game points (a
// "win = 1, loss = 0" weighting per game). For cup matches we use the
// same aggregation since the API tallies that way too. Returns the
// match_id.
function simulateMatchInsert($pdo, $homeId, $awayId, $homePlayers, $awayPlayers, $division, $matchType, $cupRound = null, $matchDate = null) {
    $matchDate = $matchDate ?: date('Y-m-d');
    $insertMatch = $pdo->prepare("INSERT INTO matches (home_team_id, away_team_id, match_date, match_type, division, status, cup_round) VALUES (?, ?, ?, ?, ?, 'completed', ?)");
    $insertMatch->execute([$homeId, $awayId, $matchDate, $matchType, $division, $cupRound]);
    $matchId = (int)$pdo->lastInsertId();

    $singlesFirstTo = ($division === 'premier') ? 3 : 2;
    $doublesFirstTo = 2;

    $homeScore = 0; $awayScore = 0;
    $playedHomeDbl = []; $playedAwayDbl = []; // exclude reused across doubles
    $playedHomeSng = []; $playedAwaySng = [];

    $insertDoubles = $pdo->prepare('INSERT INTO doubles_results (match_id, home_player1_id, home_player2_id, away_player1_id, away_player2_id, home_score, away_score) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $insertSingles = $pdo->prepare('INSERT INTO singles_results (match_id, home_player_id, away_player_id, home_score, away_score) VALUES (?, ?, ?, ?, ?)');

    // 3 doubles games
    for ($g = 0; $g < 3; $g++) {
        // pick two home + two away players not used in previous doubles
        $hChoice = pickTwoExcluding($homePlayers, $playedHomeDbl);
        $aChoice = pickTwoExcluding($awayPlayers, $playedAwayDbl);
        if (!$hChoice || !$aChoice) break;
        $playedHomeDbl = array_merge($playedHomeDbl, [$hChoice[0]['id'], $hChoice[1]['id']]);
        $playedAwayDbl = array_merge($playedAwayDbl, [$aChoice[0]['id'], $aChoice[1]['id']]);
        list($hs, $as) = pickGameScore($doublesFirstTo);
        $insertDoubles->execute([$matchId, $hChoice[0]['id'], $hChoice[1]['id'], $aChoice[0]['id'], $aChoice[1]['id'], $hs, $as]);
        if ($hs > $as) $homeScore++; else $awayScore++;
    }

    // 6 singles games – each player ideally plays once
    $hSinglesPool = $homePlayers; shuffle($hSinglesPool);
    $aSinglesPool = $awayPlayers; shuffle($aSinglesPool);
    for ($g = 0; $g < 6; $g++) {
        $h = $hSinglesPool[$g % count($hSinglesPool)];
        $a = $aSinglesPool[$g % count($aSinglesPool)];
        list($hs, $as) = pickGameScore($singlesFirstTo);
        $insertSingles->execute([$matchId, $h['id'], $a['id'], $hs, $as]);
        if ($hs > $as) $homeScore++; else $awayScore++;
    }

    // 180s – ~40% of matches have 1, ~15% have 2
    $insert180 = $pdo->prepare('INSERT INTO one_eighties (match_id, player_id, count) VALUES (?, ?, ?)');
    $r = mt_rand(0, 100);
    $numHundredEighties = $r < 40 ? 1 : ($r < 55 ? 2 : 0);
    for ($k = 0; $k < $numHundredEighties; $k++) {
        $fromHome = mt_rand(0, 1) === 1;
        $pool = $fromHome ? $homePlayers : $awayPlayers;
        $p = $pool[array_rand($pool)];
        $insert180->execute([$matchId, $p['id'], 1]);
    }

    // High finishes – ~12% of matches have 1
    if (mt_rand(0, 100) < 12) {
        $insertHF = $pdo->prepare('INSERT INTO high_finishes (match_id, player_id, finish_value) VALUES (?, ?, ?)');
        $fromHome = mt_rand(0, 1) === 1;
        $pool = $fromHome ? $homePlayers : $awayPlayers;
        $p = $pool[array_rand($pool)];
        $val = mt_rand(105, 170);
        $insertHF->execute([$matchId, $p['id'], $val]);
    }

    // Update match aggregated score.
    $pdo->prepare('UPDATE matches SET home_score = ?, away_score = ? WHERE match_id = ?')->execute([$homeScore, $awayScore, $matchId]);

    return [$matchId, $homeScore, $awayScore];
}

function pickTwoExcluding($pool, $exclude) {
    $available = array_values(array_filter($pool, function($p) use ($exclude) { return !in_array($p['id'], $exclude, true); }));
    if (count($available) < 2) return null;
    shuffle($available);
    return [$available[0], $available[1]];
}

// =====================================================================
// 4. LEAGUE ROUND-ROBIN
// =====================================================================
echo '<h2>4. League round-robin</h2>';

$premIds = array_keys(array_filter($teamsById, function($t) { return $t['division'] === 'premier'; }));
$aIds    = array_keys(array_filter($teamsById, function($t) { return $t['division'] === 'a'; }));

$leagueMatches = 0;
$startDate = strtotime('2025-10-03');
$day = 0;
function nextLeagueDate($startDate, &$day) {
    $d = strtotime('+' . ($day * 7) . ' days', $startDate);
    return date('Y-m-d', $d);
}

// Round-robin schedule each division (every pair plays once).
foreach ([['premier', $premIds], ['a', $aIds]] as $pair) {
    list($div, $ids) = $pair;
    $localDay = 0;
    for ($i = 0; $i < count($ids); $i++) {
        for ($j = $i + 1; $j < count($ids); $j++) {
            $home = $ids[$i]; $away = $ids[$j];
            simulateMatchInsert($pdo, $home, $away, $playersByTeam[$home], $playersByTeam[$away], $div, 'league', null, nextLeagueDate($startDate, $localDay));
            $localDay++;
            $leagueMatches++;
        }
    }
}
echo '<p class="ok">&#10003; ' . $leagueMatches . ' league matches simulated (8&middot;7/2 = 28 Premier + 7&middot;6/2 = 21 A = 49).</p>';

// Refresh league standings.
try { $pdo->exec('CALL update_league_standings()'); echo '<p class="ok">&#10003; League standings recomputed.</p>'; }
catch (Exception $e) { echo '<p class="err">&#10007; Standings refresh failed: ' . htmlspecialchars($e->getMessage()) . '</p>'; }

// =====================================================================
// 5. CUP – draw + simulate
// =====================================================================
echo '<h2>5. Knockout cup draw + simulation</h2>';

// Inline copy of the bracket layout helper from api.php.
function cupBracketLayoutLocal($teamCount) {
    if ($teamCount < 2) return [0, null];
    $size = 1;
    while ($size < $teamCount) $size *= 2;
    $layouts = [
        2  => ['Final'],
        4  => ['Semi Final', 'Final'],
        8  => ['Quarter Final', 'Semi Final', 'Final'],
        16 => ['Last 16', 'Quarter Final', 'Semi Final', 'Final'],
    ];
    if (!isset($layouts[$size])) return [0, null];
    return [$size, $layouts[$size]];
}

// Inline copy of advanceCupWinner from api.php.
function advanceCupWinnerLocal($pdo, $matchId) {
    $stmt = $pdo->prepare('SELECT match_type, home_team_id, away_team_id, home_score, away_score, next_match_id, next_match_slot FROM matches WHERE match_id = ?');
    $stmt->execute([$matchId]);
    $m = $stmt->fetch();
    if (!$m || $m['match_type'] !== 'cup' || empty($m['next_match_id'])) return;
    $winner = null;
    if ((int)$m['home_score'] > (int)$m['away_score']) $winner = (int)$m['home_team_id'];
    elseif ((int)$m['away_score'] > (int)$m['home_score']) $winner = (int)$m['away_team_id'];
    if (!$winner) return;
    $col = $m['next_match_slot'] === 'away' ? 'away_team_id' : 'home_team_id';
    $pdo->prepare("UPDATE matches SET $col = ? WHERE match_id = ?")->execute([$winner, (int)$m['next_match_id']]);
    $stmt = $pdo->prepare('SELECT home_team_id, away_team_id, status FROM matches WHERE match_id = ?');
    $stmt->execute([(int)$m['next_match_id']]);
    $next = $stmt->fetch();
    if ($next && $next['home_team_id'] && $next['away_team_id'] && $next['status'] === 'pending') {
        $pdo->prepare("UPDATE matches SET status = 'scheduled' WHERE match_id = ?")->execute([(int)$m['next_match_id']]);
    }
}

function drawAndPlayCup($pdo, $division, $teamIds, $teamsById, $playersByTeam, &$report) {
    list($size, $rounds) = cupBracketLayoutLocal(count($teamIds));
    if (!$rounds) { $report[] = "$division: unsupported team count " . count($teamIds); return; }

    shuffle($teamIds);
    $padded = $teamIds;
    while (count($padded) < $size) $padded[] = null; // NULL pad => bye

    $baseDate = strtotime('2026-01-09');
    $dates = [];
    for ($r = 0; $r < count($rounds); $r++) $dates[] = date('Y-m-d', strtotime('+' . ($r * 7) . ' days', $baseDate));

    $insScheduled = $pdo->prepare("INSERT INTO matches (home_team_id, away_team_id, match_date, match_type, division, status, cup_round) VALUES (?, ?, ?, 'cup', ?, 'scheduled', ?)");
    $insCompleted = $pdo->prepare("INSERT INTO matches (home_team_id, away_team_id, match_date, match_type, division, status, cup_round, home_score, away_score) VALUES (?, ?, ?, 'cup', ?, 'completed', ?, ?, ?)");
    $insPending   = $pdo->prepare("INSERT INTO matches (home_team_id, away_team_id, match_date, match_type, division, status, cup_round) VALUES (NULL, NULL, ?, 'cup', ?, 'pending', ?)");
    $updNext      = $pdo->prepare('UPDATE matches SET next_match_id = ?, next_match_slot = ? WHERE match_id = ?');

    // Build round 1 (scheduled or auto-bye).
    $round1Ids = [];
    $autoAdvanced = [];
    $matchCount = $size / 2;
    for ($i = 0; $i < $matchCount; $i++) {
        $h = $padded[$i * 2]; $a = $padded[$i * 2 + 1];
        if ($h && $a) {
            $insScheduled->execute([$h, $a, $dates[0], $division, $rounds[0]]);
        } elseif ($h && !$a) {
            $insCompleted->execute([$h, null, $dates[0], $division, $rounds[0], 1, 0]);
            $autoAdvanced[(int)$pdo->lastInsertId()] = true;
        } elseif (!$h && $a) {
            $insCompleted->execute([null, $a, $dates[0], $division, $rounds[0], 0, 1]);
            $autoAdvanced[(int)$pdo->lastInsertId()] = true;
        } else {
            $insCompleted->execute([null, null, $dates[0], $division, $rounds[0], 0, 0]);
        }
        $round1Ids[] = (int)$pdo->lastInsertId();
    }

    // Empty pending rounds 2..N
    $prev = $round1Ids;
    for ($r = 1; $r < count($rounds); $r++) {
        $cur = [];
        $count = $size / pow(2, $r + 1);
        for ($i = 0; $i < $count; $i++) {
            $insPending->execute([$dates[$r], $division, $rounds[$r]]);
            $cur[] = (int)$pdo->lastInsertId();
        }
        for ($i = 0; $i < count($prev); $i++) {
            $next = $cur[intdiv($i, 2)];
            $slot = ($i % 2 === 0) ? 'home' : 'away';
            $updNext->execute([$next, $slot, $prev[$i]]);
        }
        $prev = $cur;
    }

    // Propagate auto-byes.
    foreach (array_keys($autoAdvanced) as $mid) advanceCupWinnerLocal($pdo, $mid);

    // Now repeatedly find scheduled cup matches, simulate them, advance.
    $playedCount = 0;
    while (true) {
        $row = $pdo->query("SELECT m.match_id, m.home_team_id, m.away_team_id, m.cup_round, m.match_date FROM matches m WHERE m.match_type='cup' AND m.division=" . $pdo->quote($division) . " AND m.status='scheduled' ORDER BY m.match_id ASC LIMIT 1")->fetch();
        if (!$row) break;

        $hId = (int)$row['home_team_id']; $aId = (int)$row['away_team_id'];
        // Re-use the league helper but UPDATE the existing row.
        $singlesFirstTo = ($division === 'premier') ? 3 : 2;
        $doublesFirstTo = 2;
        $homeScore = 0; $awayScore = 0;
        $playedHomeDbl = []; $playedAwayDbl = [];

        $insertDoubles = $pdo->prepare('INSERT INTO doubles_results (match_id, home_player1_id, home_player2_id, away_player1_id, away_player2_id, home_score, away_score) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $insertSingles = $pdo->prepare('INSERT INTO singles_results (match_id, home_player_id, away_player_id, home_score, away_score) VALUES (?, ?, ?, ?, ?)');

        $hPlayers = $playersByTeam[$hId];
        $aPlayers = $playersByTeam[$aId];

        for ($g = 0; $g < 3; $g++) {
            $hc = pickTwoExcluding($hPlayers, $playedHomeDbl);
            $ac = pickTwoExcluding($aPlayers, $playedAwayDbl);
            if (!$hc || !$ac) break;
            $playedHomeDbl = array_merge($playedHomeDbl, [$hc[0]['id'], $hc[1]['id']]);
            $playedAwayDbl = array_merge($playedAwayDbl, [$ac[0]['id'], $ac[1]['id']]);
            list($hs, $as) = pickGameScore($doublesFirstTo);
            $insertDoubles->execute([$row['match_id'], $hc[0]['id'], $hc[1]['id'], $ac[0]['id'], $ac[1]['id'], $hs, $as]);
            if ($hs > $as) $homeScore++; else $awayScore++;
        }
        $hSP = $hPlayers; shuffle($hSP);
        $aSP = $aPlayers; shuffle($aSP);
        for ($g = 0; $g < 6; $g++) {
            $h = $hSP[$g % count($hSP)];
            $a = $aSP[$g % count($aSP)];
            list($hs, $as) = pickGameScore($singlesFirstTo);
            $insertSingles->execute([$row['match_id'], $h['id'], $a['id'], $hs, $as]);
            if ($hs > $as) $homeScore++; else $awayScore++;
        }
        // 180s / high finishes (cup matches contribute to those leaderboards)
        $rr = mt_rand(0, 100);
        $count180 = $rr < 50 ? 1 : ($rr < 70 ? 2 : 0);
        if ($count180) {
            $ins180 = $pdo->prepare('INSERT INTO one_eighties (match_id, player_id, count) VALUES (?, ?, ?)');
            for ($k = 0; $k < $count180; $k++) {
                $fromHome = mt_rand(0, 1) === 1;
                $pool = $fromHome ? $hPlayers : $aPlayers;
                $p = $pool[array_rand($pool)];
                $ins180->execute([$row['match_id'], $p['id'], 1]);
            }
        }
        if (mt_rand(0, 100) < 18) {
            $insHF = $pdo->prepare('INSERT INTO high_finishes (match_id, player_id, finish_value) VALUES (?, ?, ?)');
            $fromHome = mt_rand(0, 1) === 1;
            $pool = $fromHome ? $hPlayers : $aPlayers;
            $p = $pool[array_rand($pool)];
            $insHF->execute([$row['match_id'], $p['id'], mt_rand(105, 170)]);
        }

        // Cup ties can't be tied; if they came out equal, flip one.
        if ($homeScore === $awayScore) {
            if (mt_rand(0, 1) === 1) $homeScore++; else $awayScore++;
        }
        $pdo->prepare("UPDATE matches SET home_score = ?, away_score = ?, status='completed' WHERE match_id = ?")->execute([$homeScore, $awayScore, $row['match_id']]);
        advanceCupWinnerLocal($pdo, (int)$row['match_id']);
        $playedCount++;
        if ($playedCount > 100) break; // safety guard
    }
    $report[] = "$division: $playedCount cup matches played, bracket size $size, rounds: " . implode(' &rarr; ', $rounds);
}

$cupReport = [];
drawAndPlayCup($pdo, 'premier', $premIds, $teamsById, $playersByTeam, $cupReport);
drawAndPlayCup($pdo, 'a',       $aIds,    $teamsById, $playersByTeam, $cupReport);
foreach ($cupReport as $r) echo '<p class="ok">&#10003; ' . $r . '</p>';

// =====================================================================
// 6. VERIFICATION
// =====================================================================
echo '<h2>6. Verification</h2>';

// League standings sanity: total wins == total losses across the division.
foreach (['premier','a'] as $div) {
    $row = $pdo->prepare('SELECT SUM(won) w, SUM(lost) l, SUM(drawn) d, SUM(played) p FROM league_standings WHERE division = ?');
    $row->execute([$div]); $r = $row->fetch();
    $expectedPlayed = $div === 'premier' ? 8 * 7 : 7 * 6; // each team's "played" sums to teams * (teams-1) because both sides count
    $cls = ((int)$r['w'] === (int)$r['l']) && ((int)$r['p'] === $expectedPlayed) ? 'ok' : 'err';
    echo '<p class="' . $cls . '">' . strtoupper($div) . ': Played=' . (int)$r['p'] . ' Won=' . (int)$r['w'] . ' Lost=' . (int)$r['l'] . ' Drawn=' . (int)$r['d'] . ' (expected played=' . $expectedPlayed . ', wins==losses)</p>';
}

// Per-team played count
echo '<h3>League standings</h3>';
$ls = $pdo->query("SELECT t.team_name, t.division, ls.played, ls.won, ls.lost, ls.points FROM league_standings ls JOIN teams t ON ls.team_id = t.team_id ORDER BY t.division, ls.points DESC")->fetchAll();
echo '<table><thead><tr><th>Team</th><th>Div</th><th>P</th><th>W</th><th>L</th><th>Pts</th></tr></thead><tbody>';
foreach ($ls as $r) echo '<tr><td>' . htmlspecialchars($r['team_name']) . '</td><td>' . strtoupper($r['division']) . '</td><td>' . $r['played'] . '</td><td>' . $r['won'] . '</td><td>' . $r['lost'] . '</td><td>' . $r['points'] . '</td></tr>';
echo '</tbody></table>';

// Total counts
$counts = [];
foreach (['matches','singles_results','doubles_results','one_eighties','high_finishes'] as $t) {
    $counts[$t] = (int)$pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
}
echo '<h3>Database totals</h3><pre>' . htmlspecialchars(json_encode($counts, JSON_PRETTY_PRINT)) . '</pre>';

// Cup integrity: every cup match either completed or the final.
$cupStats = $pdo->query("SELECT cup_round, status, COUNT(*) c FROM matches WHERE match_type='cup' GROUP BY cup_round, status ORDER BY cup_round, status")->fetchAll();
echo '<h3>Cup match status breakdown</h3><table><tr><th>Round</th><th>Status</th><th>Count</th></tr>';
foreach ($cupStats as $r) echo '<tr><td>' . htmlspecialchars($r['cup_round']) . '</td><td>' . htmlspecialchars($r['status']) . '</td><td>' . $r['c'] . '</td></tr>';
echo '</table>';

echo '<div class="box bad"><strong>Reminder:</strong> delete <code>simulate.php</code> from your host now – it contains the DB password in source.</div>';

echo '<h2>What to verify in the browser</h2><ul>';
echo '<li><a href="leaguetable.html">leaguetable.html</a> – both division standings should reflect the simulated results.</li>';
echo '<li><a href="leagueresults.html">leagueresults.html</a> – 49 league matches across both divisions.</li>';
echo '<li><a href="playerrankings.html">playerrankings.html</a> – every player should appear; played &gt; 0 for those who featured.</li>';
echo '<li><a href="180sfinishes.html">180sfinishes.html</a> – should list 180 and high finish counts including cup contributions.</li>';
echo '<li><a href="knockoutcup.html">knockoutcup.html</a> – both brackets fully populated with completed matches; click a completed one to see games inside.</li>';
echo '<li><a href="admin-login.html">admin-login.html</a> &rarr; Cup Draw tab – bracket status should show 0 pending in both divisions.</li>';
echo '</ul>';

echo '</body></html>';
