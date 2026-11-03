<?php
// cups.php - Individual cup competitions (3-2-1, Singles, Pairs, Good
// Friday, Paul/Terry, Memorial and Cap/Sec Cups): random knockout draws
// with first-round byes, an optional losers bracket for first-round
// losers, and admin results. api.php requires this file and routes the
// comp-draws and admin-comp-* endpoints here. Only functions live below.
if (!defined('CDL_API')) { http_response_code(404); exit; }

// =====================================================================
// Cups, divisions and formats
// =====================================================================

// The cups in display order. The slug is what comp_draws.cup_slug holds.
function compCups() {
    return [
        ['slug' => '321-cup',         'name' => '3-2-1 Cup',       'page' => '321cup.html'],
        ['slug' => 'singles-cup',     'name' => 'Singles Cup',     'page' => 'singlescup.html'],
        ['slug' => 'pairs-cup',       'name' => 'Pairs Cup',       'page' => 'pairscup.html'],
        ['slug' => 'good-friday-cup', 'name' => 'Good Friday Cup', 'page' => 'goodfridaycup.html'],
        ['slug' => 'paul-terry-cup',  'name' => 'Paul/Terry Cup',  'page' => 'paulterrycup.html'],
        ['slug' => 'memorial-cup',    'name' => 'Memorial Cup',    'page' => 'memorialcup.html'],
        ['slug' => 'cap-sec-cup',     'name' => 'Cap/Sec Cup',     'page' => 'capseccup.html'],
    ];
}

// A cup's display name, or null for an unknown slug.
function compCupName($slug) {
    foreach (compCups() as $cup) {
        if ($cup['slug'] === $slug) return $cup['name'];
    }
    return null;
}

// Division keys in display order, with their labels for messages.
function compDivisions() {
    return ['premier' => 'Premier Division', 'a' => 'A Division', 'combined' => 'Combined'];
}

// Players in one entry, by format.
function compFormats() {
    return ['singles' => 1, 'pairs' => 2, 'trebles' => 3];
}

// =====================================================================
// Bracket planning: pure functions, no database access
// =====================================================================

// Smallest power of two that is at least $n.
function compNextPow2($n) {
    $size = 1;
    while ($size < $n) $size *= 2;
    return $size;
}

// log2 of a power of two: the rounds in a bracket of $size slots, or the
// bits needed to number $size first-round matches.
function compLog2($size) {
    $bits = 0;
    while ((1 << $bits) < $size) $bits++;
    return $bits;
}

// Round name from the entrants left in it: a bracket of $size slots has
// $size / 2^(round - 1) left in round $roundNo.
function compRoundName($size, $roundNo) {
    $left = intdiv($size, 1 << ($roundNo - 1));
    if ($left === 2) return 'Final';
    if ($left === 4) return 'Semi Final';
    if ($left === 8) return 'Quarter Final';
    return 'Last ' . $left;
}

// $i with its lowest $bits bits in reverse order (3 bits: 001 -> 100).
function compBitReverse($i, $bits) {
    $out = 0;
    for ($b = 0; $b < $bits; $b++) {
        $out = ($out << 1) | ($i & 1);
        $i >>= 1;
    }
    return $out;
}

// 0-based indexes of the round 1 matches (out of $matchCount, a power of
// two) that are byes: the first $byeCount in bit-reversal order, which for
// 8 matches is 0, 4, 2, 6, 1, 5, 3, 7. Each bye lands in the other half
// from the one before, then in a different quarter, so byes spread evenly.
function compByeIndexes($matchCount, $byeCount) {
    $bits = compLog2($matchCount);
    $indexes = [];
    for ($k = 0; $k < $byeCount; $k++) $indexes[] = compBitReverse($k, $bits);
    return $indexes;
}

// A match with no entrants yet. Plans key matches "bracket:round:match";
// match k of a round feeds match ceil(k/2) of the next, home if k is odd.
function compPlanMatch($bracket, $roundNo, $matchNo, $rounds) {
    $isLast = $roundNo === $rounds;
    return [
        'bracket'         => $bracket,
        'round_no'        => $roundNo,
        'match_no'        => $matchNo,
        'home'            => null,
        'away'            => null,
        'winner'          => null,
        'is_bye'          => false,
        'next'            => $isLast ? null : $bracket . ':' . ($roundNo + 1) . ':' . intdiv($matchNo + 1, 2),
        'next_slot'       => $isLast ? null : ($matchNo % 2 === 1 ? 'home' : 'away'),
        'loser_next'      => null,
        'loser_next_slot' => null,
    ];
}

// Every match of a new draw of $entryCount entries (2 to 256), keyed as in
// compPlanMatch() and in insert order: the main bracket round by round,
// then the losers bracket. home/away/winner are 0-based positions in the
// draw order. Round 1 byes come already decided, with their entrant
// already in round 2. The losers bracket is built when $withPlate is on
// and at least two round 1 matches are played; it starts empty, and each
// of its byes takes one loser in the home slot and never has its away
// slot fed.
function compPlanBracket($entryCount, $withPlate) {
    $size = compNextPow2($entryCount);
    $rounds = compLog2($size);
    $plan = [];
    for ($r = 1; $r <= $rounds; $r++) {
        for ($k = 1; $k <= ($size >> $r); $k++) $plan["main:$r:$k"] = compPlanMatch('main', $r, $k, $rounds);
    }

    // Round 1 in draw order: the next entry home, then away unless a bye.
    $byes = array_flip(compByeIndexes($size / 2, $size - $entryCount));
    $played = [];    // keys of the round 1 matches that are not byes
    $pos = 0;
    for ($i = 0; $i < $size / 2; $i++) {
        $key = 'main:1:' . ($i + 1);
        $plan[$key]['home'] = $pos++;
        if (isset($byes[$i])) {
            $plan[$key]['is_bye'] = true;
            $plan[$key]['winner'] = $plan[$key]['home'];
            $plan[$plan[$key]['next']][$plan[$key]['next_slot']] = $plan[$key]['home'];
        } else {
            $plan[$key]['away'] = $pos++;
            $played[] = $key;
        }
    }

    // Losers bracket: the played matches' losers, in match order, fill its
    // round 1 slots in order (home, then away unless that match is a bye).
    $losers = count($played);
    if (!$withPlate || $losers < 2) return $plan;
    $plateSize = compNextPow2($losers);
    $plateRounds = compLog2($plateSize);
    for ($r = 1; $r <= $plateRounds; $r++) {
        for ($k = 1; $k <= ($plateSize >> $r); $k++) $plan["plate:$r:$k"] = compPlanMatch('plate', $r, $k, $plateRounds);
    }
    $plateByes = array_flip(compByeIndexes($plateSize / 2, $plateSize - $losers));
    $feeder = 0;
    for ($i = 0; $i < $plateSize / 2; $i++) {
        $key = 'plate:1:' . ($i + 1);
        $plan[$key]['is_bye'] = isset($plateByes[$i]);
        foreach ($plan[$key]['is_bye'] ? ['home'] : ['home', 'away'] as $slot) {
            $plan[$played[$feeder]]['loser_next'] = $key;
            $plan[$played[$feeder]]['loser_next_slot'] = $slot;
            $feeder++;
        }
    }
    return $plan;
}

// =====================================================================
// Input checks and names: pure functions. A problem the admin can fix is
// thrown as a DomainException carrying the message to show them.
// =====================================================================

// $v as an int if it is a whole number or a string of digits, else null.
function compInt($v) {
    if (is_int($v)) return $v;
    if (is_string($v) && preg_match('/^\d{1,9}$/', $v)) return (int)$v;
    return null;
}

function compIntOrNull($v) {
    return $v === null ? null : (int)$v;
}

// A JSON flag. Only true (or 1, "1", "true") counts, so a stray value
// never replaces a draw by accident.
function compBool($v) {
    return $v === true || $v === 1 || $v === '1' || $v === 'true';
}

// Characters, not bytes, in a UTF-8 string (mbstring may not be installed).
function compCharCount($s) {
    return (int)preg_match_all('/./su', $s);
}

// Lower case for comparing names (mbstring may not be installed).
function compLower($s) {
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

// $s cut to at most $maxBytes bytes without splitting a character, so a
// long name still fits its column.
function compClip($s, $maxBytes) {
    if (strlen($s) <= $maxBytes) return $s;
    $s = substr($s, 0, $maxBytes);
    while ($s !== '' && !preg_match('//u', $s)) $s = substr($s, 0, -1);
    return $s;
}

// An entry's name from its players' names: "A", "A & B" or "A, B & C".
function compEntryName($names) {
    $last = array_pop($names);
    return compClip($names ? implode(', ', $names) . ' & ' . $last : $last, 255);
}

// The distinct teams of an entry's registered players, in player order,
// joined with " / "; null when every player is a guest.
function compEntryTeam($teams) {
    $distinct = [];
    foreach ($teams as $team) {
        if ($team !== null && $team !== '' && !in_array($team, $distinct, true)) $distinct[] = $team;
    }
    return $distinct ? compClip(implode(' / ', $distinct), 255) : null;
}

// One entrant from the request: ['player_id' => int] for a registered
// player or ['guest' => name] for a guest typed in by name.
function compParseRef($ref) {
    $id = is_array($ref) ? ($ref['player_id'] ?? null) : null;
    $guest = is_array($ref) ? ($ref['guest'] ?? null) : null;
    if ($id !== null && $guest === null) {
        $id = compInt($id);
        if ($id === null || $id < 1) throw new DomainException('A ticked player is not valid. Refresh the page and try again.');
        return ['player_id' => $id];
    }
    if ($guest !== null && $id === null && is_string($guest)) {
        $name = trim($guest);
        if ($name === '') throw new DomainException('A guest name is blank: type a name or remove the guest.');
        if (compCharCount($name) > 100) throw new DomainException('Guest names can be at most 100 characters.');
        return ['guest' => $name];
    }
    throw new DomainException('Each entrant must be a registered player or a guest name.');
}

// Checks an admin-comp-draw body and returns the draw settings plus
// 'entries': one list of compParseRef() refs per entry. Random partners
// are shuffled into their pairs or trebles here.
function compParseDrawRequest($d) {
    $cup = $d['cup'] ?? '';
    if (!is_string($cup) || compCupName($cup) === null) throw new DomainException('Unknown cup');
    $division = $d['division'] ?? '';
    if (!is_string($division) || !array_key_exists($division, compDivisions())) {
        throw new DomainException('Choose a division: Premier Division, A Division or Combined.');
    }
    $format = $d['format'] ?? '';
    $sizes = compFormats();
    if (!is_string($format) || !array_key_exists($format, $sizes)) {
        throw new DomainException('Choose a format: singles, pairs or trebles.');
    }
    $size = $sizes[$format];
    $bestOf = compInt($d['best_of'] ?? null);
    if ($bestOf !== 3 && $bestOf !== 5) throw new DomainException('Matches must be best of 3 or best of 5 legs.');
    $mode = $size === 1 ? 'fixed' : ($d['partner_mode'] ?? 'fixed');
    if ($mode !== 'fixed' && $mode !== 'random') throw new DomainException('Choose fixed or random partners.');

    $date = $d['draw_date'] ?? null;
    if ($date === null || $date === '') {
        $date = date('Y-m-d');
    } elseif (!is_string($date) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts)
              || !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1]) || (int)$parts[1] < 1000) {
        throw new DomainException('The draw date must be a real date, written YYYY-MM-DD.');
    }

    // Singles and random partners use the ticked players; fixed pairs and
    // trebles send one list of players per entry. Counts are checked
    // before anything else so a huge request is turned away cheaply.
    $fromPlayers = $size === 1 || $mode === 'random';
    $list = $d[$fromPlayers ? 'players' : 'entries'] ?? null;
    $list = is_array($list) ? array_values($list) : [];
    $count = count($list);
    if ($fromPlayers && $count % $size !== 0) {
        throw new DomainException($size === 2
            ? "Random pairs need an even number of players: you ticked $count."
            : "Random trebles need a multiple of 3 players: you ticked $count.");
    }
    $n = $fromPlayers ? intdiv($count, $size) : $count;
    if ($n < 2) throw new DomainException("A draw needs at least 2 entries: you have $n.");
    if ($n > 256) throw new DomainException("A draw can have at most 256 entries: you have $n.");

    if ($fromPlayers) {
        $refs = array_map('compParseRef', $list);
        if ($mode === 'random') shuffle($refs);
        $entries = array_chunk($refs, $size);
    } else {
        $noun = $format === 'pairs' ? 'Pair' : 'Treble';
        $entries = [];
        foreach ($list as $i => $group) {
            if (!is_array($group) || count($group) !== $size) {
                throw new DomainException("$noun " . ($i + 1) . " needs exactly $size players.");
            }
            $entries[] = array_map('compParseRef', array_values($group));
        }
    }

    return [
        'cup'          => $cup,
        'division'     => $division,
        'format'       => $format,
        'best_of'      => $bestOf,
        'has_plate'    => compBool($d['has_plate'] ?? false),
        'partner_mode' => $mode,
        'draw_date'    => $date,
        'replace'      => compBool($d['replace'] ?? false),
        'entries'      => $entries,
    ];
}

// The optional leg score from an admin-comp-result body: [home, away] as
// ints, or [null, null] when neither is sent.
function compParseScores($d) {
    $home = $d['home_score'] ?? null;
    $away = $d['away_score'] ?? null;
    $homeBlank = $home === null || $home === '';
    $awayBlank = $away === null || $away === '';
    if ($homeBlank && $awayBlank) return [null, null];
    if ($homeBlank || $awayBlank) throw new DomainException('Enter both leg scores, or neither.');
    $home = compInt($home);
    $away = compInt($away);
    if ($home === null || $away === null) throw new DomainException('Leg scores must be whole numbers.');
    return [$home, $away];
}

// Whether a result can be recorded on match $m (a compMatchRow()): it must
// be a real match with both entrants, and a leg score must fit the format.
function compCheckResult($m, $side, $homeScore, $awayScore, $bestOf) {
    if ($side !== 'home' && $side !== 'away') throw new DomainException('Pick the winner: home or away.');
    if ($m['is_bye']) throw new DomainException('Byes go through automatically, so there is no result to record.');
    if ($m['home_entry_id'] === null || $m['away_entry_id'] === null) {
        throw new DomainException('This match is still waiting for both entrants.');
    }
    if ($homeScore === null) return;
    $legsToWin = intdiv($bestOf, 2) + 1;
    [$winnerLegs, $loserLegs] = $side === 'home' ? [$homeScore, $awayScore] : [$awayScore, $homeScore];
    if ($winnerLegs !== $legsToWin || $loserLegs < 0 || $loserLegs >= $legsToWin) {
        throw new DomainException("In a best of $bestOf match the winner needs exactly $legsToWin legs and the loser fewer.");
    }
}

// =====================================================================
// Database helpers. SQL stays portable between MariaDB and SQLite.
// =====================================================================

// Runs $work inside one transaction. Returns ['ok' => true, 'value' => ...]
// once committed, or ['ok' => false, 'error' => ..., 'code' => ...] once
// rolled back. It never sends the response itself, so callers reply only
// after the transaction has ended. A DomainException carries a message for
// the admin; anything else is unexpected, so it is logged and reported
// after $failPrefix.
function compTransaction(callable $work, $failPrefix) {
    global $pdo;
    try {
        $pdo->beginTransaction();
        $value = $work();
        $pdo->commit();
        return ['ok' => true, 'value' => $value];
    } catch (Throwable $e) {
        try {
            if ($pdo->inTransaction()) $pdo->rollBack();
        } catch (Throwable) {
            // A lost connection rolls back on the server anyway.
        }
        if ($e instanceof DomainException) {
            return ['ok' => false, 'error' => $e->getMessage(), 'code' => $e->getCode()];
        }
        error_log('cups.php: ' . $e->getMessage());
        return ['ok' => false, 'error' => $failPrefix . ': ' . $e->getMessage(), 'code' => 0];
    }
}

function compRequireSchema() {
    if (!schemaHas('comp_draws')) {
        returnJson(['success' => false, 'error' => 'Database not upgraded yet: run db/2026-add-individual-cups.sql in phpMyAdmin first.']);
    }
}

// Turns each entry's refs into players ({player_id, player_name,
// team_name}, a snapshot of today's name and team) and checks that every
// registered player exists and plays in the draw's division, and that
// nobody is entered twice.
function compResolveEntries($entries, $division) {
    global $pdo;
    $ids = [];
    foreach ($entries as $refs) {
        foreach ($refs as $ref) {
            if (isset($ref['player_id'])) $ids[$ref['player_id']] = true;
        }
    }
    $found = [];
    if ($ids) {
        $ids = array_keys($ids);
        $marks = implode(', ', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT p.player_id, p.player_name, t.team_name, t.division
                               FROM players p LEFT JOIN teams t ON t.team_id = p.team_id
                               WHERE p.player_id IN ($marks)");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $row) $found[(int)$row['player_id']] = $row;
    }

    $labels = compDivisions();
    $seen = [];
    $out = [];
    foreach ($entries as $refs) {
        $players = [];
        foreach ($refs as $ref) {
            if (isset($ref['player_id'])) {
                $row = $found[$ref['player_id']] ?? null;
                if ($row === null) {
                    throw new DomainException('A ticked player was not found: they may have been deleted. Refresh the page and try again.');
                }
                $name = $row['player_name'];
                if ($division !== 'combined' && $row['division'] !== $division) {
                    throw new DomainException($row['division'] === null
                        ? "$name has no team, so can only enter a Combined draw."
                        : "$name plays in the " . ($labels[$row['division']] ?? $row['division']) . ', not the ' . $labels[$division] . '.');
                }
                $key = 'player ' . $ref['player_id'];
                $player = ['player_id' => $ref['player_id'], 'player_name' => $name, 'team_name' => $row['team_name']];
            } else {
                $name = $ref['guest'];
                $key = 'guest ' . compLower($name);
                $player = ['player_id' => null, 'player_name' => $name, 'team_name' => null];
            }
            if (isset($seen[$key])) throw new DomainException("$name is in the draw twice.");
            $seen[$key] = true;
            $players[] = $player;
        }
        $out[] = $players;
    }
    return $out;
}

// The draw_id for a cup and division, or null if there is no draw yet.
function compFindDrawId($cup, $division) {
    global $pdo;
    $stmt = $pdo->prepare('SELECT draw_id FROM comp_draws WHERE cup_slug = ? AND division = ?');
    $stmt->execute([$cup, $division]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int)$id;
}

// Deletes a draw and everything in it, children first, so it never relies
// on the foreign keys' cascades. Callers lock the draw first.
function compDeleteDrawRows($drawId) {
    global $pdo;
    $pdo->prepare('DELETE FROM comp_entry_players WHERE entry_id IN (SELECT entry_id FROM comp_entries WHERE draw_id = ?)')
        ->execute([$drawId]);
    $pdo->prepare('DELETE FROM comp_matches WHERE draw_id = ?')->execute([$drawId]);
    $pdo->prepare('DELETE FROM comp_entries WHERE draw_id = ?')->execute([$drawId]);
    $pdo->prepare('DELETE FROM comp_draws WHERE draw_id = ?')->execute([$drawId]);
}

// Inserts a draw: its settings, the entries in a random draw order and
// every match of its plan. Returns the new draw_id.
function compInsertDraw($req, $entries, $adminId) {
    global $pdo;
    // Flags go in as 0/1: strict mode rejects PDO's '' for false.
    $pdo->prepare('INSERT INTO comp_draws (cup_slug, division, format, best_of, has_plate, partner_mode, draw_date, created_by_admin_id)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$req['cup'], $req['division'], $req['format'], $req['best_of'], $req['has_plate'] ? 1 : 0,
                   $req['partner_mode'], $req['draw_date'], $adminId]);
    $drawId = (int)$pdo->lastInsertId();

    shuffle($entries);
    $insEntry  = $pdo->prepare('INSERT INTO comp_entries (draw_id, draw_order, name, team_name) VALUES (?, ?, ?, ?)');
    $insPlayer = $pdo->prepare('INSERT INTO comp_entry_players (entry_id, player_id, player_name, team_name) VALUES (?, ?, ?, ?)');
    $entryIds = [];
    foreach ($entries as $i => $players) {
        $names = [];
        $teams = [];
        foreach ($players as $p) {
            $names[] = $p['player_name'];
            $teams[] = $p['team_name'];
        }
        $insEntry->execute([$drawId, $i + 1, compEntryName($names), compEntryTeam($teams)]);
        $entryIds[$i] = (int)$pdo->lastInsertId();
        foreach ($players as $p) {
            $insPlayer->execute([$entryIds[$i], $p['player_id'], $p['player_name'], $p['team_name']]);
        }
    }

    // Insert the matches, then link them up once every id is known.
    $plan = compPlanBracket(count($entries), $req['has_plate']);
    $entryAt = fn($pos) => $pos === null ? null : $entryIds[$pos];
    $now = date('Y-m-d H:i:s');
    $insMatch = $pdo->prepare('INSERT INTO comp_matches (draw_id, bracket, round_no, match_no, home_entry_id, away_entry_id,
                                                         winner_entry_id, is_bye, completed_at)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $matchIds = [];
    foreach ($plan as $key => $m) {
        $insMatch->execute([$drawId, $m['bracket'], $m['round_no'], $m['match_no'], $entryAt($m['home']), $entryAt($m['away']),
                            $entryAt($m['winner']), $m['is_bye'] ? 1 : 0, $m['winner'] === null ? null : $now]);
        $matchIds[$key] = (int)$pdo->lastInsertId();
    }
    $link = $pdo->prepare('UPDATE comp_matches SET next_match_id = ?, next_slot = ?, loser_next_match_id = ?, loser_next_slot = ?
                           WHERE match_id = ?');
    foreach ($plan as $key => $m) {
        if ($m['next'] === null && $m['loser_next'] === null) continue;
        $link->execute([$m['next'] === null ? null : $matchIds[$m['next']], $m['next_slot'],
                        $m['loser_next'] === null ? null : $matchIds[$m['loser_next']], $m['loser_next_slot'],
                        $matchIds[$key]]);
    }
    return $drawId;
}

// The comp_matches columns that compMatchRow() reads, for a SELECT.
function compMatchColumns($alias) {
    $cols = ['match_id', 'draw_id', 'bracket', 'round_no', 'match_no', 'home_entry_id', 'away_entry_id',
             'winner_entry_id', 'home_score', 'away_score', 'is_bye', 'next_match_id', 'next_slot',
             'loser_next_match_id', 'loser_next_slot'];
    return implode(', ', array_map(fn($c) => "$alias.$c", $cols));
}

// A comp_matches row with ids and numbers as int (or null) and the bye
// flag as a bool, so values compare with ===.
function compMatchRow($row) {
    return [
        'match_id'            => (int)$row['match_id'],
        'draw_id'             => (int)$row['draw_id'],
        'bracket'             => $row['bracket'],
        'round_no'            => (int)$row['round_no'],
        'match_no'            => (int)$row['match_no'],
        'home_entry_id'       => compIntOrNull($row['home_entry_id']),
        'away_entry_id'       => compIntOrNull($row['away_entry_id']),
        'winner_entry_id'     => compIntOrNull($row['winner_entry_id']),
        'home_score'          => compIntOrNull($row['home_score']),
        'away_score'          => compIntOrNull($row['away_score']),
        'is_bye'              => (bool)(int)$row['is_bye'],
        'next_match_id'       => compIntOrNull($row['next_match_id']),
        'next_slot'           => $row['next_slot'],
        'loser_next_match_id' => compIntOrNull($row['loser_next_match_id']),
        'loser_next_slot'     => $row['loser_next_slot'],
    ];
}

// A match as the API returns it.
function compMatchOut($m, $roundName) {
    return [
        'match_id'            => $m['match_id'],
        'bracket'             => $m['bracket'],
        'round_no'            => $m['round_no'],
        'round_name'          => $roundName,
        'match_no'            => $m['match_no'],
        'home_entry_id'       => $m['home_entry_id'],
        'away_entry_id'       => $m['away_entry_id'],
        'winner_entry_id'     => $m['winner_entry_id'],
        'home_score'          => $m['home_score'],
        'away_score'          => $m['away_score'],
        'is_bye'              => $m['is_bye'],
        'next_match_id'       => $m['next_match_id'],
        'next_slot'           => $m['next_slot'],
        'loser_next_match_id' => $m['loser_next_match_id'],
        'loser_next_slot'     => $m['loser_next_slot'],
    ];
}

// Draws as the API returns them: every draw, one cup's ($cupSlug) or one
// draw ($drawId), ordered by cup, then division. Ids and numbers are ints
// (or null) and flags are bools, because the pages compare with ===.
function compFetchDraws($cupSlug = null, $drawId = null) {
    global $pdo;
    if ($drawId !== null) {
        $where = 'd.draw_id = ?';
        $params = [$drawId];
    } elseif ($cupSlug !== null) {
        $where = 'd.cup_slug = ?';
        $params = [$cupSlug];
    } else {
        $where = '1 = 1';
        $params = [];
    }

    $draws = [];
    $stmt = $pdo->prepare("SELECT d.draw_id, d.cup_slug, d.division, d.format, d.best_of, d.has_plate,
                                  d.partner_mode, d.draw_date, d.created_at
                           FROM comp_draws d WHERE $where");
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $draws[(int)$row['draw_id']] = [
            'draw_id'                 => (int)$row['draw_id'],
            'cup_slug'                => $row['cup_slug'],
            'cup_name'                => compCupName($row['cup_slug']) ?? $row['cup_slug'],
            'division'                => $row['division'],
            'format'                  => $row['format'],
            'best_of'                 => (int)$row['best_of'],
            'has_plate'               => (bool)(int)$row['has_plate'],
            'partner_mode'            => $row['partner_mode'],
            'draw_date'               => $row['draw_date'],
            'created_at'              => $row['created_at'],
            'main_rounds'             => 0,
            'plate_rounds'            => 0,
            'champion_entry_id'       => null,
            'plate_champion_entry_id' => null,
            'complete'                => false,
            'entries'                 => [],
            'matches'                 => [],
        ];
    }
    if (!$draws) return [];

    // Entries in draw order, each with its players in the order entered.
    $entries = [];
    $entryDraw = [];
    $stmt = $pdo->prepare("SELECT e.entry_id, e.draw_id, e.draw_order, e.name, e.team_name
                           FROM comp_entries e JOIN comp_draws d ON d.draw_id = e.draw_id
                           WHERE $where ORDER BY e.draw_order, e.entry_id");
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $id = (int)$row['entry_id'];
        $entryDraw[$id] = (int)$row['draw_id'];
        $entries[$id] = [
            'entry_id'   => $id,
            'draw_order' => (int)$row['draw_order'],
            'name'       => $row['name'],
            'team_name'  => $row['team_name'],
            'players'    => [],
        ];
    }
    $stmt = $pdo->prepare("SELECT ep.entry_id, ep.player_id, ep.player_name, ep.team_name
                           FROM comp_entry_players ep
                           JOIN comp_entries e ON e.entry_id = ep.entry_id
                           JOIN comp_draws d ON d.draw_id = e.draw_id
                           WHERE $where ORDER BY ep.id");
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $id = (int)$row['entry_id'];
        if (!isset($entries[$id])) continue;
        $entries[$id]['players'][] = [
            'player_id'   => compIntOrNull($row['player_id']),
            'player_name' => $row['player_name'],
            'team_name'   => $row['team_name'],
        ];
    }
    foreach ($entries as $id => $entry) {
        if (isset($draws[$entryDraw[$id]])) $draws[$entryDraw[$id]]['entries'][] = $entry;
    }

    // Matches: the main bracket before the losers bracket, each by round
    // and match number.
    $brackets = [];
    $stmt = $pdo->prepare('SELECT ' . compMatchColumns('m') . "
                           FROM comp_matches m JOIN comp_draws d ON d.draw_id = m.draw_id
                           WHERE $where ORDER BY m.round_no, m.match_no");
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $row) {
        $m = compMatchRow($row);
        $brackets[$m['draw_id']][$m['bracket']][] = $m;
    }
    foreach ($draws as $id => $draw) {
        $main = $brackets[$id]['main'] ?? [];
        $plate = $brackets[$id]['plate'] ?? [];
        $mainRounds = $main ? max(array_column($main, 'round_no')) : 0;
        $plateRounds = $plate ? max(array_column($plate, 'round_no')) : 0;
        foreach ($main as $m) {
            $draw['matches'][] = compMatchOut($m, compRoundName(1 << $mainRounds, $m['round_no']));
            if ($m['round_no'] === $mainRounds) $draw['champion_entry_id'] = $m['winner_entry_id'];
        }
        foreach ($plate as $m) {
            $draw['matches'][] = compMatchOut($m, compRoundName(1 << $plateRounds, $m['round_no']));
            if ($m['round_no'] === $plateRounds) $draw['plate_champion_entry_id'] = $m['winner_entry_id'];
        }
        $draw['main_rounds'] = $mainRounds;
        $draw['plate_rounds'] = $plateRounds;
        $draw['complete'] = $draw['champion_entry_id'] !== null
            && ($plateRounds === 0 || $draw['plate_champion_entry_id'] !== null);
        $draws[$id] = $draw;
    }

    $cupOrder = array_flip(array_column(compCups(), 'slug'));
    $divisionOrder = array_flip(array_keys(compDivisions()));
    $sortKey = fn($d) => [$cupOrder[$d['cup_slug']] ?? count($cupOrder), $divisionOrder[$d['division']] ?? 3, $d['draw_id']];
    $list = array_values($draws);
    usort($list, fn($a, $b) => $sortKey($a) <=> $sortKey($b));
    return $list;
}

// One draw as the API returns it, or null if it doesn't exist.
function compFetchDraw($drawId) {
    return compFetchDraws(null, $drawId)[0] ?? null;
}

// A match as a compMatchRow(), or null if it doesn't exist.
function compLoadMatch($matchId) {
    global $pdo;
    $stmt = $pdo->prepare('SELECT ' . compMatchColumns('m') . ' FROM comp_matches m WHERE m.match_id = ?');
    $stmt->execute([$matchId]);
    $row = $stmt->fetch();
    return $row ? compMatchRow($row) : null;
}

// The draw a match belongs to, or null. Read before the transaction
// starts: see compLockDraw().
function compMatchDrawId($matchId) {
    global $pdo;
    if ($matchId === null) return null;
    $stmt = $pdo->prepare('SELECT draw_id FROM comp_matches WHERE match_id = ?');
    $stmt->execute([$matchId]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int)$id;
}

function compDrawBestOf($drawId) {
    global $pdo;
    $stmt = $pdo->prepare('SELECT best_of FROM comp_draws WHERE draw_id = ?');
    $stmt->execute([$drawId]);
    return (int)$stmt->fetchColumn();
}

// Locks draw $drawId until the transaction ends, so two admins can't change
// the same draw at once (the no-op UPDATE takes InnoDB's row lock; SQLite
// locks the whole file for writing anyway). Returns false if the draw no
// longer exists. Take it before reading anything the change depends on:
// InnoDB's snapshot starts at the transaction's first plain read, so a
// read made before waiting here would miss what the other admin committed.
function compLockDraw($drawId) {
    global $pdo;
    $pdo->prepare('UPDATE comp_draws SET best_of = best_of WHERE draw_id = ?')->execute([$drawId]);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM comp_draws WHERE draw_id = ?');
    $stmt->execute([$drawId]);
    return (int)$stmt->fetchColumn() > 0;
}

// Match $matchId of draw $drawId, read after the draw is locked.
function compLockedMatch($drawId, $matchId) {
    if ($matchId === null) throw new DomainException('match_id is required.');
    $m = ($drawId !== null && compLockDraw($drawId)) ? compLoadMatch($matchId) : null;
    if ($m === null || $m['draw_id'] !== $drawId) {
        throw new DomainException('That match no longer exists. Refresh the page and try again.');
    }
    return $m;
}

// Puts $entryId into the home or away slot of match $matchId.
function compSetSlot($matchId, $slot, $entryId) {
    global $pdo;
    $col = $slot === 'away' ? 'away_entry_id' : 'home_entry_id';
    $pdo->prepare("UPDATE comp_matches SET $col = ? WHERE match_id = ?")->execute([$entryId, $matchId]);
}

// Empties a slot of match $matchId, but only while it holds $entryId.
function compClearSlot($matchId, $slot, $entryId) {
    global $pdo;
    $col = $slot === 'away' ? 'away_entry_id' : 'home_entry_id';
    $pdo->prepare("UPDATE comp_matches SET $col = NULL WHERE match_id = ? AND $col = ?")->execute([$matchId, $entryId]);
}

// A losers bracket bye goes through as soon as its one entrant arrives:
// it is decided for that entrant, who moves on to the next round.
function compSettlePlateBye($matchId) {
    global $pdo;
    $bye = compLoadMatch($matchId);
    if ($bye === null || !$bye['is_bye'] || $bye['winner_entry_id'] !== null) return;
    $entrant = $bye['home_entry_id'] ?? $bye['away_entry_id'];
    if ($entrant === null) return;
    $pdo->prepare('UPDATE comp_matches SET winner_entry_id = ?, completed_at = ? WHERE match_id = ?')
        ->execute([$entrant, date('Y-m-d H:i:s'), $matchId]);
    if ($bye['next_match_id'] !== null) compSetSlot($bye['next_match_id'], $bye['next_slot'], $entrant);
}

// Takes match $m's result back out of the brackets: its winner out of the
// next match and its loser out of the losers bracket, undoing a losers
// bracket bye that already went through with that loser. Refuses, before
// changing anything, once a match that depends on the result is played.
function compUnwind($m) {
    global $pdo;
    $next = $m['next_match_id'] !== null ? compLoadMatch($m['next_match_id']) : null;
    if ($next !== null && $next['winner_entry_id'] !== null) {
        throw new DomainException('The next round match has already been played. Undo that result first.');
    }
    $plate = $m['loser_next_match_id'] !== null ? compLoadMatch($m['loser_next_match_id']) : null;
    $plateNext = null;
    if ($plate !== null && $plate['winner_entry_id'] !== null) {
        // Only a bye can already be decided there, and it can only be
        // undone while the match it went through to is unplayed.
        if ($plate['is_bye'] && $plate['next_match_id'] !== null) $plateNext = compLoadMatch($plate['next_match_id']);
        if (!$plate['is_bye'] || ($plateNext !== null && $plateNext['winner_entry_id'] !== null)) {
            throw new DomainException('The loser has already played in the losers bracket. Undo that result first.');
        }
    }

    $winner = $m['winner_entry_id'];
    $loser = $winner === $m['home_entry_id'] ? $m['away_entry_id'] : $m['home_entry_id'];
    if ($next !== null) compClearSlot($next['match_id'], $m['next_slot'], $winner);
    if ($plate !== null) {
        if ($plate['winner_entry_id'] !== null) {
            if ($plateNext !== null) compClearSlot($plateNext['match_id'], $plate['next_slot'], $plate['winner_entry_id']);
            $pdo->prepare('UPDATE comp_matches SET winner_entry_id = NULL, completed_at = NULL WHERE match_id = ?')
                ->execute([$plate['match_id']]);
        }
        compClearSlot($plate['match_id'], $m['loser_next_slot'], $loser);
    }
}

// Records $side ('home' or 'away') as the winner of match $m with an
// optional leg score, then moves the winner into the next match and a
// first-round loser into the losers bracket. The same winner again only
// updates the score; a different winner first unwinds the old result.
function compRecordResult($m, $side, $homeScore, $awayScore) {
    global $pdo;
    $winner = $side === 'home' ? $m['home_entry_id'] : $m['away_entry_id'];
    $loser = $side === 'home' ? $m['away_entry_id'] : $m['home_entry_id'];
    if ($m['winner_entry_id'] === $winner) {
        $pdo->prepare('UPDATE comp_matches SET home_score = ?, away_score = ? WHERE match_id = ?')
            ->execute([$homeScore, $awayScore, $m['match_id']]);
        return;
    }
    if ($m['winner_entry_id'] !== null) compUnwind($m);
    $pdo->prepare('UPDATE comp_matches SET winner_entry_id = ?, home_score = ?, away_score = ?, completed_at = ? WHERE match_id = ?')
        ->execute([$winner, $homeScore, $awayScore, date('Y-m-d H:i:s'), $m['match_id']]);
    if ($m['next_match_id'] !== null) compSetSlot($m['next_match_id'], $m['next_slot'], $winner);
    if ($m['loser_next_match_id'] !== null) {
        compSetSlot($m['loser_next_match_id'], $m['loser_next_slot'], $loser);
        compSettlePlateBye($m['loser_next_match_id']);
    }
}

// Puts match $m back to undecided.
function compClearResult($m) {
    global $pdo;
    if ($m['is_bye']) throw new DomainException('Byes go through automatically and cannot be undone.');
    if ($m['winner_entry_id'] === null) throw new DomainException('This match has no result to undo.');
    compUnwind($m);
    $pdo->prepare('UPDATE comp_matches SET winner_entry_id = NULL, home_score = NULL, away_score = NULL, completed_at = NULL
                   WHERE match_id = ?')
        ->execute([$m['match_id']]);
}

// =====================================================================
// Endpoints
// =====================================================================

// GET comp-draws (public): the cups and their draws, all or one cup's
// (?cup=slug).
function compListDraws() {
    $slug = $_GET['cup'] ?? '';
    if ($slug !== '' && (!is_string($slug) || compCupName($slug) === null)) {
        returnJson(['success' => false, 'error' => 'Unknown cup']);
    }
    if (!schemaHas('comp_draws')) {
        returnJson(['success' => true, 'schema_ready' => false, 'cups' => compCups(), 'draws' => []]);
    }
    try {
        $draws = compFetchDraws($slug === '' ? null : $slug);
    } catch (Throwable $e) {
        error_log('comp-draws failed: ' . $e->getMessage());
        $draws = null;
    }
    if ($draws === null) returnJson(['success' => false, 'error' => 'The cup draws could not be loaded. Please try again.']);
    returnJson(['success' => true, 'schema_ready' => true, 'cups' => compCups(), 'draws' => $draws]);
}

// POST admin-comp-draw: makes the draw for a cup and division, or with
// replace: true remakes it (the old draw and its results are deleted).
function compAdminDraw() {
    $adminId = requireAdmin();
    compRequireSchema();
    $body = readJsonBody();
    if (!is_array($body)) $body = [];
    $out = compTransaction(function () use ($body, $adminId) {
        $req = compParseDrawRequest($body);
        $entries = compResolveEntries($req['entries'], $req['division']);
        $existing = compFindDrawId($req['cup'], $req['division']);
        if ($existing !== null) {
            if (!$req['replace']) {
                // Code 409 marks "already exists" for the response below.
                throw new DomainException('The ' . compCupName($req['cup']) . ' already has a '
                    . compDivisions()[$req['division']] . ' draw. Use Redraw to replace it.', 409);
            }
            // The draw row first, as result changes take it, so a redraw
            // and a result on the same draw queue instead of deadlocking.
            compLockDraw($existing);
            compDeleteDrawRows($existing);
        }
        return compFetchDraw(compInsertDraw($req, $entries, $adminId));
    }, 'Could not make the draw');
    if (!$out['ok']) {
        $res = ['success' => false];
        if ($out['code'] === 409) $res['exists'] = true;
        $res['error'] = $out['error'];
        returnJson($res);
    }
    returnJson(['success' => true, 'draw' => $out['value']]);
}

// POST admin-comp-result {match_id, winner: 'home'|'away', home_score?,
// away_score?}: records or changes a result and returns the whole draw.
function compAdminResult() {
    requireAdmin();
    compRequireSchema();
    $body = readJsonBody();
    if (!is_array($body)) $body = [];
    $matchId = compInt($body['match_id'] ?? null);
    $drawId = compMatchDrawId($matchId);
    $out = compTransaction(function () use ($body, $matchId, $drawId) {
        $m = compLockedMatch($drawId, $matchId);
        $side = $body['winner'] ?? null;
        [$homeScore, $awayScore] = compParseScores($body);
        compCheckResult($m, $side, $homeScore, $awayScore, compDrawBestOf($drawId));
        compRecordResult($m, $side, $homeScore, $awayScore);
        return compFetchDraw($drawId);
    }, 'Could not save the result');
    if (!$out['ok']) returnJson(['success' => false, 'error' => $out['error']]);
    returnJson(['success' => true, 'draw' => $out['value']]);
}

// POST admin-comp-clear-result {match_id}: undoes a result while nothing
// that depends on it has been played, and returns the whole draw.
function compAdminClearResult() {
    requireAdmin();
    compRequireSchema();
    $body = readJsonBody();
    if (!is_array($body)) $body = [];
    $matchId = compInt($body['match_id'] ?? null);
    $drawId = compMatchDrawId($matchId);
    $out = compTransaction(function () use ($matchId, $drawId) {
        compClearResult(compLockedMatch($drawId, $matchId));
        return compFetchDraw($drawId);
    }, 'Could not undo the result');
    if (!$out['ok']) returnJson(['success' => false, 'error' => $out['error']]);
    returnJson(['success' => true, 'draw' => $out['value']]);
}

// POST admin-comp-delete-draw {draw_id}: deletes a draw and all its results.
function compAdminDeleteDraw() {
    requireAdmin();
    compRequireSchema();
    $body = readJsonBody();
    if (!is_array($body)) $body = [];
    $drawId = compInt($body['draw_id'] ?? null);
    $out = compTransaction(function () use ($drawId) {
        if ($drawId === null || !compLockDraw($drawId)) {
            throw new DomainException('That draw no longer exists. Refresh the page and try again.');
        }
        compDeleteDrawRows($drawId);
        return null;
    }, 'Could not delete the draw');
    if (!$out['ok']) returnJson(['success' => false, 'error' => $out['error']]);
    returnJson(['success' => true]);
}
