<?php
// api.php - Backend API for the Darts League website

// Ensure no output before JSON
ob_start();

// Start session for admin / team login state. The site is HTTPS-only
// (HTTP 301s to HTTPS and HSTS is on), so the cookie can be Secure.
// SameSite=Lax stops browsers attaching it to cross-site POSTs.
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Helper used by every safety-net path to emit a JSON error and stop.
// Defined as a closure here so it works before the file-level
// returnJson() function is declared.
$emitJsonError = function($message) {
    while (ob_get_level()) { ob_end_clean(); }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
};

// Global fallback so any uncaught exception (missing table, query syntax,
// etc.) becomes a valid JSON response instead of a half-flushed PHP
// error page that the client parses as "Invalid server response".
set_exception_handler(function($e) use ($emitJsonError) {
    error_log('Uncaught in api.php: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    $emitJsonError('Server error: ' . $e->getMessage());
});

// Fatal errors (parse errors, out-of-memory, undefined function calls in
// PHP 8) cannot be caught by set_error_handler / set_exception_handler.
// They show up here as the *last error* during shutdown – surface them
// to the client as JSON so a 500 with an empty body is impossible.
register_shutdown_function(function() use ($emitJsonError) {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
        error_log('Fatal in api.php: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
        $emitJsonError('Fatal PHP error: ' . $err['message'] . ' (' . basename($err['file']) . ':' . $err['line'] . ')');
    }
});

// Turn on error logging but disable displaying errors
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Set headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Database configuration
// The credentials are not kept in this file because the GitHub repo is
// public. They live in db-config.php (copy db-config.example.php), which
// git ignores. Put it one level above the web root, where Apache can't
// serve it. Next to api.php also works: a request for it just runs it,
// which prints nothing.
$db_host = $db_name = $db_user = $db_pass = null;
foreach ([dirname(__DIR__) . '/db-config.php', __DIR__ . '/db-config.php'] as $dbConfigFile) {
    if (!is_file($dbConfigFile)) {
        continue;
    }
    try {
        $dbConfig = include $dbConfigFile;
    } catch (Throwable $e) {
        // Log only the error type and line: a parse error message can
        // quote part of the password.
        error_log("$dbConfigFile failed to load: " . get_class($e) . ' on line ' . $e->getLine());
        returnJson(['success' => false, 'error' => 'Database connection failed: database config file is invalid']);
    }
    if (!is_array($dbConfig) || !isset($dbConfig['host'], $dbConfig['name'], $dbConfig['user'], $dbConfig['pass'])) {
        error_log("$dbConfigFile must return an array with host, name, user and pass");
        returnJson(['success' => false, 'error' => 'Database connection failed: database config file is invalid']);
    }
    $db_host = (string) $dbConfig['host'];
    $db_name = (string) $dbConfig['name'];
    $db_user = (string) $dbConfig['user'];
    $db_pass = (string) $dbConfig['pass'];
    break;
}
if ($db_host === null) {
    error_log('db-config.php not found one level above the web root or next to api.php');
    returnJson(['success' => false, 'error' => 'Database connection failed: database config file not found']);
}
unset($dbConfig, $dbConfigFile);

// Function to safely return JSON
function returnJson($data) {
    // Clean output buffer
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    // Send JSON headers
    header('Content-Type: application/json');
    
    // Ensure proper encoding
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    returnJson(['success' => false, 'error' => 'Database connection failed: ' . $e->getMessage()]);
}

// Handle OPTIONS request for CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    returnJson([]);
}

// Parse request
$requestMethod = $_SERVER['REQUEST_METHOD'];
$endpoint = isset($_GET['endpoint']) ? $_GET['endpoint'] : '';

// Log request info for debugging
error_log("API Request: $requestMethod to $endpoint");

// CSRF guard for every POST endpoint, including login, submit-match and
// update-match which don't go through requirePost().
if ($requestMethod === 'POST') {
    rejectCrossSitePost();
}

// Individual cup competitions (comp-draws and admin-comp-* endpoints).
define('CDL_API', true);
require_once __DIR__ . '/cups.php';

// Process by endpoint
switch ($endpoint) {
    case 'teams':
        getTeams();
        break;
    
    case 'players':
        getPlayers();
        break;
    
    case 'league-table':
        getLeagueTable();
        break;
    
    case 'singles-ranking':
        getSinglesRanking();
        break;
    
    case 'league-fixtures':
        getLeagueFixtures();
        break;
    
    case 'league-results':
        getLeagueResults();
        break;
    
    case 'cup-fixtures':
        getcupbracket();
        break;
    
    case 'cup-results':
        getknockoutcup();
        break;
    
    case 'one-eighties':
        getOneEighties();
        break;
    
    case 'high-finishes':
        getHighFinishes();
        break;
    
    case 'match-details':
        getMatchDetails();
        break;
    
    case 'login':
        if ($requestMethod === 'POST') {
            handleLogin();
        } else {
            returnJson(['success' => false, 'error' => 'Method not allowed for login']);
        }
        break;
    
    case 'submit-match':
        if ($requestMethod === 'POST') {
            submitMatch();
        } else {
            returnJson(['success' => false, 'error' => 'Method not allowed for submit-match']);
        }
        break;
    
    case 'update-match':
        if ($requestMethod === 'POST') {
            updateMatch();
        } else {
            returnJson(['success' => false, 'error' => 'Method not allowed for update-match']);
        }
        break;

    // -------- new endpoints (admin + team session-aware) --------

    case 'admin-login':       requirePost(); adminLogin(); break;
    case 'admin-logout':      requirePost(); adminLogout(); break;
    case 'admin-me':          adminMe(); break;
    case 'admin-change-password': requirePost(); adminChangePassword(); break;
    case 'admin-list-teams':  adminListTeams(); break;
    case 'add-team':          requirePost(); adminAddTeam(); break;
    case 'update-team':       requirePost(); adminUpdateTeam(); break;
    case 'delete-team':       requirePost(); adminDeleteTeam(); break;
    case 'admin-list-players': adminListPlayers(); break;
    case 'add-player':        requirePost(); adminAddPlayer(); break;
    case 'update-player':     requirePost(); adminUpdatePlayer(); break;
    case 'delete-player':     requirePost(); adminDeletePlayer(); break;
    case 'pending-requests':  adminListPendingRequests(); break;
    case 'approve-request':   requirePost(); adminApproveRequest(); break;
    case 'deny-request':      requirePost(); adminDenyRequest(); break;
    case 'admin-list-matches': adminListMatches(); break;
    case 'admin-delete-match': requirePost(); adminDeleteMatch(); break;
    case 'admin-season-reset': requirePost(); adminSeasonReset(); break;

    case 'points-adjustments':      listPointsAdjustments(); break;
    case 'admin-add-adjustment':    requirePost(); adminAddAdjustment(); break;
    case 'admin-delete-adjustment': requirePost(); adminDeleteAdjustment(); break;
    case 'admin-record-walkover':   requirePost(); adminRecordWalkover(); break;

    case 'announcements':         listAnnouncements(); break;
    case 'add-announcement':      requirePost(); adminAddAnnouncement(); break;
    case 'delete-announcement':   requirePost(); adminDeleteAnnouncement(); break;

    case 'cup-bracket':           getBracket(); break;
    case 'cup-draw-preview':      adminCupDrawPreview(); break;
    case 'admin-cup-draw':        requirePost(); adminCupDraw(); break;
    case 'comp-draws':               compListDraws(); break;
    case 'admin-comp-draw':          requirePost(); compAdminDraw(); break;
    case 'admin-comp-result':        requirePost(); compAdminResult(); break;
    case 'admin-comp-clear-result':  requirePost(); compAdminClearResult(); break;
    case 'admin-comp-delete-draw':   requirePost(); compAdminDeleteDraw(); break;

    case 'settings':              listSettings(); break;
    case 'update-setting':        requirePost(); adminUpdateSetting(); break;

    case 'venues':                listVenues(); break;
    case 'add-venue':             requirePost(); adminAddVenue(); break;
    case 'update-venue':          requirePost(); adminUpdateVenue(); break;
    case 'delete-venue':          requirePost(); adminDeleteVenue(); break;

    case 'contact-info':          getContactInfo(); break;

    case 'team-me':           teamMe(); break;
    case 'team-logout':       requirePost(); teamLogout(); break;
    case 'team-request-player': requirePost(); teamRequestPlayer(); break;
    case 'team-my-requests':  teamMyRequests(); break;
    case 'team-list-my-matches': teamListMyMatches(); break;
    case 'team-delete-match': requirePost(); teamDeleteMatch(); break;
    case 'submit-cup-result': requirePost(); submitCupResult(); break;

    default:
        returnJson(['success' => false, 'error' => 'Invalid endpoint']);
        break;
}

function getTeams() {
    global $pdo;
    $division = isset($_GET['division']) ? $_GET['division'] : 'premier';
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM teams WHERE division = ?");
        $stmt->execute([$division]);
        $teams = $stmt->fetchAll();
        // Secretary phone numbers are for logged-in admins and captains
        // only, matching getContactInfo().
        if (empty($_SESSION['admin_id']) && empty($_SESSION['captain_id'])) {
            $teams = array_map(function($t) {
                $t['secretary_phone'] = null;
                return $t;
            }, $teams);
        }
        returnJson($teams);
    } catch (Exception $e) {
        error_log("Error in getTeams: " . $e->getMessage());
        returnJson(['success' => false, 'error' => 'Error fetching teams']);
    }
}

function getPlayers() {
    global $pdo;
    $teamId = isset($_GET['team_id']) ? $_GET['team_id'] : null;
    $teamName = isset($_GET['team_name']) ? $_GET['team_name'] : null;
    
    try {
        if ($teamId) {
            $stmt = $pdo->prepare("SELECT * FROM players WHERE team_id = ?");
            $stmt->execute([$teamId]);
        } elseif ($teamName) {
            $stmt = $pdo->prepare("SELECT p.* FROM players p JOIN teams t ON p.team_id = t.team_id WHERE t.team_name = ?");
            $stmt->execute([$teamName]);
        } else {
            $stmt = $pdo->query("SELECT p.*, t.team_name FROM players p JOIN teams t ON p.team_id = t.team_id");
        }
        returnJson($stmt->fetchAll());
    } catch (Exception $e) {
        error_log("Error in getPlayers: " . $e->getMessage());
        returnJson(['success' => false, 'error' => 'Error fetching players']);
    }
}

function getLeagueTable() {
    global $pdo;
    $division = isset($_GET['division']) ? $_GET['division'] : 'premier';

    try {
        // Drive the query from `teams` (LEFT JOIN league_standings) so teams
        // that have just been added but have no standings row yet still show
        // up in the table with zeros for played / won / lost / points. The
        // previous INNER JOIN hid any team that hadn't had a match recorded.
        // Points shown = points earned on the oche (league_standings,
        // rebuilt from match scores on every submission) PLUS any
        // season-long adjustments. Adjustments live in their own table
        // that the rebuild never touches, which is why a deduction
        // survives every later score entry. Skipped gracefully if the
        // forfeits migration hasn't been run yet.
        $hasAdj  = schemaHas('points_adjustments');
        $adjJoin = $hasAdj
            ? 'LEFT JOIN (SELECT team_id, SUM(points) AS total FROM points_adjustments GROUP BY team_id) adj ON adj.team_id = t.team_id'
            : '';
        $adjExpr = $hasAdj ? 'COALESCE(adj.total, 0)' : '0';
        $stmt = $pdo->prepare("
            SELECT
                t.team_name,
                COALESCE(ls.played, 0)         AS played,
                COALESCE(ls.won, 0)            AS won,
                COALESCE(ls.drawn, 0)          AS drawn,
                COALESCE(ls.lost, 0)           AS lost,
                COALESCE(ls.games_for, 0)      AS games_for,
                COALESCE(ls.games_against, 0)  AS games_against,
                (COALESCE(ls.games_for, 0) - COALESCE(ls.games_against, 0)) AS goal_diff,
                COALESCE(ls.points, 0)         AS points_earned,
                $adjExpr                       AS points_adjustment,
                (COALESCE(ls.points, 0) + $adjExpr) AS points
            FROM teams t
            LEFT JOIN league_standings ls ON ls.team_id = t.team_id
            $adjJoin
            WHERE t.division = ?
            ORDER BY points DESC, goal_diff DESC, games_for DESC, t.team_name ASC
        ");

        $stmt->execute([$division]);
        returnJson($stmt->fetchAll());
    } catch (Exception $e) {
        error_log("Error in getLeagueTable: " . $e->getMessage());
        returnJson(['success' => false, 'error' => 'Error fetching league table']);
    }
}

function getSinglesRanking() {
    global $pdo;
    $division = isset($_GET['division']) ? $_GET['division'] : 'premier';
    $match_type = isset($_GET['match_type']) ? $_GET['match_type'] : null;

    // Build a subquery that pre-filters singles_results by match_type if a
    // match_type filter was supplied. Using a derived table preserves
    // LEFT JOIN semantics: players with no qualifying results still appear
    // in the result set with zeros, instead of being silently filtered out
    // by a WHERE m.match_type = 'league' that would turn the LEFT JOIN into
    // an effective INNER JOIN.
    if ($match_type) {
        $singlesJoin = "
            LEFT JOIN (
                SELECT sr.* FROM singles_results sr
                JOIN matches mm ON sr.match_id = mm.match_id
                WHERE mm.match_type = ?
            ) sr ON (p.player_id = sr.home_player_id OR p.player_id = sr.away_player_id)";
    } else {
        $singlesJoin = "
            LEFT JOIN singles_results sr
                ON (p.player_id = sr.home_player_id OR p.player_id = sr.away_player_id)";
    }

    try {
        // SQL query to get detailed breakdown of singles scores
        // Use different queries for Premier and A divisions due to different scoring systems
        if ($division === 'premier') {
            $sql = "
                SELECT 
                    p.player_id,
                    p.player_name,
                    t.team_name,
                    COUNT(sr.result_id) as played,
                    SUM(CASE 
                        WHEN (sr.home_player_id = p.player_id AND sr.home_score > sr.away_score) OR
                             (sr.away_player_id = p.player_id AND sr.away_score > sr.home_score)
                        THEN 1 ELSE 0 
                    END) as won,
                    SUM(CASE 
                        WHEN (sr.home_player_id = p.player_id AND sr.home_score < sr.away_score) OR
                             (sr.away_player_id = p.player_id AND sr.away_score < sr.home_score)
                        THEN 1 ELSE 0 
                    END) as lost,
                    
                    -- Premier Division - Detailed score breakdown - Wins
                    SUM(CASE WHEN sr.home_player_id = p.player_id AND sr.home_score = 3 AND sr.away_score = 0 THEN 1
                             WHEN sr.away_player_id = p.player_id AND sr.away_score = 3 AND sr.home_score = 0 THEN 1
                             ELSE 0 END) as win_3_0,

                    SUM(CASE WHEN sr.home_player_id = p.player_id AND sr.home_score = 3 AND sr.away_score = 1 THEN 1
                             WHEN sr.away_player_id = p.player_id AND sr.away_score = 3 AND sr.home_score = 1 THEN 1
                             ELSE 0 END) as win_3_1,

                    SUM(CASE WHEN sr.home_player_id = p.player_id AND sr.home_score = 3 AND sr.away_score = 2 THEN 1
                             WHEN sr.away_player_id = p.player_id AND sr.away_score = 3 AND sr.home_score = 2 THEN 1
                             ELSE 0 END) as win_3_2,

                    -- Premier Division - Detailed score breakdown - Losses
                    SUM(CASE WHEN sr.home_player_id = p.player_id AND sr.home_score = 2 AND sr.away_score = 3 THEN 1
                             WHEN sr.away_player_id = p.player_id AND sr.away_score = 2 AND sr.home_score = 3 THEN 1
                             ELSE 0 END) as loss_2_3,

                    SUM(CASE WHEN sr.home_player_id = p.player_id AND sr.home_score = 1 AND sr.away_score = 3 THEN 1
                             WHEN sr.away_player_id = p.player_id AND sr.away_score = 1 AND sr.home_score = 3 THEN 1
                             ELSE 0 END) as loss_1_3,

                    SUM(CASE WHEN sr.home_player_id = p.player_id AND sr.home_score = 0 AND sr.away_score = 3 THEN 1
                             WHEN sr.away_player_id = p.player_id AND sr.away_score = 0 AND sr.home_score = 3 THEN 1
                             ELSE 0 END) as loss_0_3,

                    -- Points calculation - Premier Division scoring (best of 5 for singles)
                    SUM(CASE
                        WHEN sr.home_player_id = p.player_id AND sr.home_score = 3 AND sr.away_score = 0 THEN 5
                        WHEN sr.home_player_id = p.player_id AND sr.home_score = 3 AND sr.away_score = 1 THEN 3
                        WHEN sr.home_player_id = p.player_id AND sr.home_score = 3 AND sr.away_score = 2 THEN 1
                        WHEN sr.away_player_id = p.player_id AND sr.away_score = 3 AND sr.home_score = 0 THEN 5
                        WHEN sr.away_player_id = p.player_id AND sr.away_score = 3 AND sr.home_score = 1 THEN 3
                        WHEN sr.away_player_id = p.player_id AND sr.away_score = 3 AND sr.home_score = 2 THEN 1
                        ELSE 0
                    END) as points
                FROM players p
                JOIN teams t ON p.team_id = t.team_id
                $singlesJoin
                WHERE t.division = ?
            ";
        } else {
            // A Division query - different scoring system (2-0, 2-1, 1-2, 0-2)
            $sql = "
                SELECT 
                    p.player_id,
                    p.player_name,
                    t.team_name,
                    COUNT(sr.result_id) as played,
                    SUM(CASE 
                        WHEN (sr.home_player_id = p.player_id AND sr.home_score > sr.away_score) OR
                             (sr.away_player_id = p.player_id AND sr.away_score > sr.home_score)
                        THEN 1 ELSE 0 
                    END) as won,
                    SUM(CASE 
                        WHEN (sr.home_player_id = p.player_id AND sr.home_score < sr.away_score) OR
                             (sr.away_player_id = p.player_id AND sr.away_score < sr.home_score)
                        THEN 1 ELSE 0 
                    END) as lost,
                    
                    -- A Division - Detailed score breakdown - Wins (2-0, 2-1)
                    SUM(CASE WHEN sr.home_player_id = p.player_id AND sr.home_score = 2 AND sr.away_score = 0 THEN 1
                             WHEN sr.away_player_id = p.player_id AND sr.away_score = 2 AND sr.home_score = 0 THEN 1
                             ELSE 0 END) as win_2_0,
                             
                    SUM(CASE WHEN sr.home_player_id = p.player_id AND sr.home_score = 2 AND sr.away_score = 1 THEN 1
                             WHEN sr.away_player_id = p.player_id AND sr.away_score = 2 AND sr.home_score = 1 THEN 1
                             ELSE 0 END) as win_2_1,
                    
                    -- A Division - Zero values for Premier Division fields
                    -- to maintain compatible structure with frontend
                    0 as win_3_0,
                    0 as win_3_1,
                    0 as win_3_2,
                    0 as loss_2_3,
                    0 as loss_1_3,
                    0 as loss_0_3,

                    -- A Division - Detailed score breakdown - Losses (1-2, 0-2)
                    SUM(CASE WHEN sr.home_player_id = p.player_id AND sr.home_score = 1 AND sr.away_score = 2 THEN 1
                             WHEN sr.away_player_id = p.player_id AND sr.away_score = 1 AND sr.home_score = 2 THEN 1
                             ELSE 0 END) as loss_1_2,
                             
                    SUM(CASE WHEN sr.home_player_id = p.player_id AND sr.home_score = 0 AND sr.away_score = 2 THEN 1
                             WHEN sr.away_player_id = p.player_id AND sr.away_score = 0 AND sr.home_score = 2 THEN 1
                             ELSE 0 END) as loss_0_2,
                    
                    -- Points calculation - A Division scoring (best of 3 for singles)
                    SUM(CASE 
                        WHEN sr.home_player_id = p.player_id AND sr.home_score = 2 AND sr.away_score = 0 THEN 3
                        WHEN sr.home_player_id = p.player_id AND sr.home_score = 2 AND sr.away_score = 1 THEN 2
                        WHEN sr.home_player_id = p.player_id AND sr.home_score = 1 AND sr.away_score = 2 THEN 1
                        WHEN sr.home_player_id = p.player_id AND sr.home_score = 0 AND sr.away_score = 2 THEN 0
                        WHEN sr.away_player_id = p.player_id AND sr.away_score = 2 AND sr.home_score = 0 THEN 3
                        WHEN sr.away_player_id = p.player_id AND sr.away_score = 2 AND sr.home_score = 1 THEN 2
                        WHEN sr.away_player_id = p.player_id AND sr.away_score = 1 AND sr.home_score = 2 THEN 1
                        WHEN sr.away_player_id = p.player_id AND sr.away_score = 0 AND sr.home_score = 2 THEN 0
                        ELSE 0
                    END) as points
                FROM players p
                JOIN teams t ON p.team_id = t.team_id
                $singlesJoin
                WHERE t.division = ?
            ";
        }

        // Parameter order has to match the placeholders in the assembled SQL.
        // When match_type is set, the subquery's `?` for mm.match_type comes
        // FIRST (it appears before WHERE t.division = ? in the query text).
        $params = $match_type ? [$match_type, $division] : [$division];

        // Complete the query with GROUP BY and ORDER BY. The final
        // player_name tiebreaker keeps unplayed (all-zero) rows in a stable,
        // alphabetical order at the bottom instead of bunching unpredictably.
        $sql .= "
            GROUP BY p.player_id, p.player_name, t.team_name
            ORDER BY points DESC, won DESC, p.player_name ASC
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        returnJson($stmt->fetchAll());
    } catch (Exception $e) {
        error_log("Error in getSinglesRanking: " . $e->getMessage());
        returnJson(['success' => false, 'error' => 'Error fetching Player Rankings: ' . $e->getMessage()]);
    }
}
function getLeagueFixtures() {
    global $pdo;
    $division = isset($_GET['division']) ? $_GET['division'] : 'premier';
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                m.match_id,
                m.match_date,
                COALESCE(ht.team_name, 'TBD') as home_team,
                COALESCE(at.team_name, 'TBD') as away_team,
                m.status
            FROM matches m
            LEFT JOIN teams ht ON m.home_team_id = ht.team_id
            LEFT JOIN teams at ON m.away_team_id = at.team_id
            WHERE m.division = ? AND m.match_type = 'league' AND m.status = 'scheduled'
            ORDER BY m.match_date ASC
        ");
        
        $stmt->execute([$division]);
        returnJson($stmt->fetchAll());
    } catch (Exception $e) {
        error_log("Error in getLeagueFixtures: " . $e->getMessage());
        returnJson(['success' => false, 'error' => 'Error fetching league fixtures']);
    }
}

function getLeagueResults() {
    global $pdo;
    $division = isset($_GET['division']) ? $_GET['division'] : 'premier';
    // Walkover flag + forfeiting team, when the forfeits migration exists.
    $hasWo  = schemaHas('matches', 'is_walkover');
    $woCols = $hasWo ? ', m.is_walkover, ft.team_name AS forfeit_team' : ', 0 AS is_walkover, NULL AS forfeit_team';
    $woJoin = $hasWo ? 'LEFT JOIN teams ft ON m.forfeit_team_id = ft.team_id' : '';
    // When the score was sent in (Unix time), so the results page can put a
    // score sent on a Friday before 6pm in the previous Friday's week.
    $sentCol = schemaHas('matches', 'created_at') ? ', UNIX_TIMESTAMP(m.created_at) AS submitted_ts' : ', NULL AS submitted_ts';

    try {
        $stmt = $pdo->prepare("
            SELECT
                m.match_id,
                m.match_date,
                COALESCE(ht.team_name, 'TBD') as home_team,
                COALESCE(at.team_name, 'TBD') as away_team,
                m.home_score,
                m.away_score,
                m.status
                $woCols
                $sentCol
            FROM matches m
            LEFT JOIN teams ht ON m.home_team_id = ht.team_id
            LEFT JOIN teams at ON m.away_team_id = at.team_id
            $woJoin
            WHERE m.division = ? AND m.match_type = 'league' AND m.status = 'completed'
            ORDER BY m.match_date DESC
        ");
        
        $stmt->execute([$division]);
        returnJson($stmt->fetchAll());
    } catch (Exception $e) {
        error_log("Error in getLeagueResults: " . $e->getMessage());
        returnJson(['success' => false, 'error' => 'Error fetching league results']);
    }
}
function getcupbracket() {
    global $pdo;
    $division = isset($_GET['division']) ? $_GET['division'] : 'premier';
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                m.match_id,
                m.match_date,
                COALESCE(ht.team_name, 'TBD') as home_team,
                COALESCE(at.team_name, 'TBD') as away_team,
                m.cup_round,
                m.status
            FROM matches m
            LEFT JOIN teams ht ON m.home_team_id = ht.team_id
            LEFT JOIN teams at ON m.away_team_id = at.team_id
            WHERE m.division = ? AND m.match_type = 'cup' AND m.status IN ('scheduled', 'pending')
            ORDER BY m.match_date ASC, m.match_id ASC
        ");
        
        $stmt->execute([$division]);
        returnJson($stmt->fetchAll());
    } catch (Exception $e) {
        error_log("Error in getcupbracket: " . $e->getMessage());
        returnJson(['success' => false, 'error' => 'Error fetching cup fixtures']);
    }
}

function getknockoutcup() {
    global $pdo;
    $division = isset($_GET['division']) ? $_GET['division'] : 'premier';
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                m.match_id,
                m.match_date,
                COALESCE(ht.team_name, 'TBD') as home_team,
                COALESCE(at.team_name, 'TBD') as away_team,
                m.home_score,
                m.away_score,
                m.cup_round,
                m.status
            FROM matches m
            LEFT JOIN teams ht ON m.home_team_id = ht.team_id
            LEFT JOIN teams at ON m.away_team_id = at.team_id
            WHERE m.division = ? AND m.match_type = 'cup' AND m.status = 'completed'
            ORDER BY m.match_date DESC
        ");
        
        $stmt->execute([$division]);
        returnJson($stmt->fetchAll());
    } catch (Exception $e) {
        error_log("Error in getknockoutcup: " . $e->getMessage());
        returnJson(['success' => false, 'error' => 'Error fetching cup results']);
    }
}

function getOneEighties() {
    global $pdo;
    $division = isset($_GET['division']) ? $_GET['division'] : 'premier';
    $match_type = isset($_GET['match_type']) ? $_GET['match_type'] : null;
    
    try {
        // Base query - now joins with matches table to filter by match_type if needed
        $sql = "
            SELECT 
                p.player_name,
                t.team_name,
                SUM(o.count) as total_180s
            FROM one_eighties o
            JOIN players p ON o.player_id = p.player_id
            JOIN teams t ON p.team_id = t.team_id
            JOIN matches m ON o.match_id = m.match_id
            WHERE t.division = ?
        ";
        
        // Add match type filter if specified
        $params = [$division];
        if ($match_type) {
            $sql .= " AND m.match_type = ?";
            $params[] = $match_type;
        }
        
        // Complete the query with GROUP BY and ORDER BY
        $sql .= "
            GROUP BY p.player_id, p.player_name, t.team_name
            ORDER BY total_180s DESC
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        returnJson($stmt->fetchAll());
    } catch (Exception $e) {
        error_log("Error in getOneEighties: " . $e->getMessage());
        returnJson(['success' => false, 'error' => 'Error fetching 180s']);
    }
}

function getHighFinishes() {
    global $pdo;
    $division = isset($_GET['division']) ? $_GET['division'] : 'premier';
    $match_type = isset($_GET['match_type']) ? $_GET['match_type'] : null;
    
    try {
        // Base query - now joins with matches table to filter by match_type if needed
        $sql = "
            SELECT 
                p.player_name,
                t.team_name,
                h.finish_value,
                h.created_at
            FROM high_finishes h
            JOIN players p ON h.player_id = p.player_id
            JOIN teams t ON p.team_id = t.team_id
            JOIN matches m ON h.match_id = m.match_id
            WHERE t.division = ?
        ";
        
        // Add match type filter if specified
        $params = [$division];
        if ($match_type) {
            $sql .= " AND m.match_type = ?";
            $params[] = $match_type;
        }
        
        // Complete the query with ORDER BY
        $sql .= "
            ORDER BY h.finish_value DESC, h.created_at DESC
        ";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        returnJson($stmt->fetchAll());
    } catch (Exception $e) {
        error_log("Error in getHighFinishes: " . $e->getMessage());
        returnJson(['success' => false, 'error' => 'Error fetching high finishes']);
    }
}

function getMatchDetails() {
    global $pdo;
    $matchId = isset($_GET['match_id']) ? (int)$_GET['match_id'] : 0;
    
    if (!$matchId) {
        returnJson(['success' => false, 'error' => 'No match ID provided']);
        return;
    }
    
    try {
        // Check if match exists first
        $checkStmt = $pdo->prepare("SELECT match_type, cup_round FROM matches WHERE match_id = ?");
        $checkStmt->execute([$matchId]);
        $matchInfo = $checkStmt->fetch();
        
        if (!$matchInfo) {
            returnJson(['success' => false, 'error' => 'Match not found']);
            return;
        }
        
        // Get basic match info
        $stmt = $pdo->prepare("
            SELECT m.match_id, m.match_type, m.division, m.home_score, m.away_score,
                   h.team_name as home_team, a.team_name as away_team, m.cup_round,
                   m.match_date, m.status, m.submitted_by_captain_id
            FROM matches m
            JOIN teams h ON m.home_team_id = h.team_id
            JOIN teams a ON m.away_team_id = a.team_id
            WHERE m.match_id = ?
        ");
        $stmt->execute([$matchId]);
        $match = $stmt->fetch();
        
        // Get doubles results - handle case where there might be no doubles results
        $doubles = [];
        $doublesStmt = $pdo->prepare("
            SELECT 
                dr.result_id,
                dr.home_score,
                dr.away_score,
                hp1.player_name as home_player1_name,
                hp2.player_name as home_player2_name,
                ap1.player_name as away_player1_name,
                ap2.player_name as away_player2_name
            FROM doubles_results dr
            JOIN players hp1 ON dr.home_player1_id = hp1.player_id
            JOIN players hp2 ON dr.home_player2_id = hp2.player_id
            JOIN players ap1 ON dr.away_player1_id = ap1.player_id
            JOIN players ap2 ON dr.away_player2_id = ap2.player_id
            WHERE dr.match_id = ?
        ");
        $doublesStmt->execute([$matchId]);
        $doublesResults = $doublesStmt->fetchAll();
        if ($doublesResults) {
            $doubles = $doublesResults;
        }
        
        // Get singles results - handle case where there might be no singles results
        $singles = [];
        $singlesStmt = $pdo->prepare("
            SELECT 
                sr.result_id,
                sr.home_score,
                sr.away_score,
                hp.player_name as home_player_name,
                ap.player_name as away_player_name
            FROM singles_results sr
            JOIN players hp ON sr.home_player_id = hp.player_id
            JOIN players ap ON sr.away_player_id = ap.player_id
            WHERE sr.match_id = ?
        ");
        $singlesStmt->execute([$matchId]);
        $singlesResults = $singlesStmt->fetchAll();
        if ($singlesResults) {
            $singles = $singlesResults;
        }
        
        // Get 180s - handle case where there might be no 180s
        $oneEighties = [];
        $oneEightiesStmt = $pdo->prepare("
            SELECT 
                o.id,
                p.player_id,
                p.player_name,
                o.count
            FROM one_eighties o
            JOIN players p ON o.player_id = p.player_id
            WHERE o.match_id = ?
        ");
        $oneEightiesStmt->execute([$matchId]);
        $oneEightiesResults = $oneEightiesStmt->fetchAll();
        if ($oneEightiesResults) {
            $oneEighties = $oneEightiesResults;
        }
        
        // Get high finishes - handle case where there might be no high finishes
        $highFinishes = [];
        $highFinishesStmt = $pdo->prepare("
            SELECT 
                h.id,
                p.player_id,
                p.player_name,
                h.finish_value
            FROM high_finishes h
            JOIN players p ON h.player_id = p.player_id
            WHERE h.match_id = ?
        ");
        $highFinishesStmt->execute([$matchId]);
        $highFinishesResults = $highFinishesStmt->fetchAll();
        if ($highFinishesResults) {
            $highFinishes = $highFinishesResults;
        }
        
        // Add debug logging
        error_log("Match details retrieved for match ID: $matchId");
        error_log("Match type: " . $matchInfo['match_type']);
        error_log("Doubles count: " . count($doubles));
        error_log("Singles count: " . count($singles));
        
        // Cup games (trebles, singles, 1001 team game), one entry per game
        // with the players on each side.
        $cupGames = [];
        if ($matchInfo['match_type'] === 'cup' && schemaHas('cup_games')) {
            $cupStmt = $pdo->prepare("
                SELECT g.game_id, g.game_type, g.game_no, g.home_score, g.away_score,
                       cp.side, p.player_id, p.player_name
                FROM cup_games g
                LEFT JOIN cup_game_players cp ON cp.game_id = g.game_id
                LEFT JOIN players p ON p.player_id = cp.player_id
                WHERE g.match_id = ?
                ORDER BY FIELD(g.game_type, 'trebles', 'singles', 'team1001'), g.game_no, cp.id
            ");
            $cupStmt->execute([$matchId]);
            $byGame = [];
            foreach ($cupStmt->fetchAll() as $r) {
                $gameId = (int)$r['game_id'];
                if (!isset($byGame[$gameId])) {
                    $byGame[$gameId] = [
                        'game_type'    => $r['game_type'],
                        'game_no'      => (int)$r['game_no'],
                        'home_score'   => (int)$r['home_score'],
                        'away_score'   => (int)$r['away_score'],
                        'home_players' => [],
                        'away_players' => [],
                    ];
                }
                if ($r['side'] !== null && $r['player_id'] !== null) {
                    $byGame[$gameId][$r['side'] . '_players'][] = ['player_id' => (int)$r['player_id'], 'player_name' => $r['player_name']];
                }
            }
            $cupGames = array_values($byGame);
        }

        // The match fields are also copied to the top level: the captain
        // and admin edit forms read detail.match_type, detail.home_team etc.
        // directly, while the public pages read detail.match.*.
        $result = array_merge($match ?: [], [
            'success' => true,
            'match' => $match,
            'doubles' => $doubles,
            'singles' => $singles,
            'oneEighties' => $oneEighties,
            'highFinishes' => $highFinishes,
            'cupGames' => $cupGames,
        ]);
        returnJson($result);
    } catch (Exception $e) {
        error_log("Error in getMatchDetails: " . $e->getMessage());
        error_log("Error trace: " . $e->getTraceAsString());
        returnJson(['success' => false, 'error' => 'Error fetching match details: ' . $e->getMessage()]);
    }
}

function handleLogin() {
    global $pdo;
    
    try {
        // Get raw POST data
        $rawData = file_get_contents('php://input');
        
        // Parse JSON
        $data = json_decode($rawData, true);
        
        // Check for JSON parsing errors
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("Login JSON error: " . json_last_error_msg());
            returnJson(['success' => false, 'error' => 'Invalid JSON data']);
            return;
        }
        
        $username = isset($data['username']) ? $data['username'] : '';
        $password = isset($data['password']) ? $data['password'] : '';
        
        error_log("Login attempt: username=$username");
        
        // Validate input
        if (empty($username) || empty($password)) {
            returnJson(['success' => false, 'error' => 'Username and password are required']);
            return;
        }
        
        // Query database for user
        $stmt = $pdo->prepare("
            SELECT tc.*, t.team_name, t.division 
            FROM team_captains tc 
            JOIN teams t ON tc.team_id = t.team_id 
            WHERE tc.username = ?
        ");
        $stmt->execute([$username]);
        $captain = $stmt->fetch();
        
        // Debug
        error_log("Captain found: " . ($captain ? "Yes" : "No"));
        
        if (!$captain) {
            returnJson(['success' => false, 'error' => 'Invalid credentials']);
            return;
        }
        
        // Verify password
        if (password_verify($password, $captain['password_hash'])) {
            error_log("Password verified successfully");
            unset($captain['password_hash']);
            // New session ID at login so a pre-login ID can't be reused (session fixation).
            session_regenerate_id(true);
            // Set session so admin/team-aware endpoints know who is acting.
            $_SESSION['captain_id'] = (int)$captain['captain_id'];
            $_SESSION['team_id']    = (int)$captain['team_id'];
            $_SESSION['team_name']  = $captain['team_name'];
            $_SESSION['division']   = $captain['division'];
            // Clear any admin session – a captain is logging in.
            unset($_SESSION['admin_id'], $_SESSION['admin_username']);
            returnJson(['success' => true, 'captain' => $captain]);
        } else {
            error_log("Password verification failed");
            returnJson(['success' => false, 'error' => 'Invalid credentials']);
        }
    } catch (Exception $e) {
        error_log("Login exception: " . $e->getMessage());
        returnJson(['success' => false, 'error' => 'Login failed, please try again.']);
    }
}

function submitMatch() {
    global $pdo;
    $transactionStarted = false;

    // Only a logged-in admin or team captain may submit scores.
    if (empty($_SESSION['admin_id']) && empty($_SESSION['captain_id'])) {
        returnJson(['success' => false, 'error' => 'Login required to submit a match']);
        return;
    }

    try {
        // Get raw POST data
        $rawData = file_get_contents('php://input');
        
        $data = json_decode($rawData, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            returnJson(['success' => false, 'error' => 'Invalid JSON data: ' . json_last_error_msg()]);
            return;
        }
        
        if (!$data) {
            returnJson(['success' => false, 'error' => 'No data received']);
            return;
        }
        
        // Validate required fields
        if (!isset($data['homeTeam']) || !isset($data['awayTeam'])) {
            returnJson(['success' => false, 'error' => 'Missing required fields: homeTeam or awayTeam']);
            return;
        }
        // Cup results go through submit-cup-result against the drawn
        // bracket match. A free-standing cup match created here would sit
        // outside the bracket and never advance anyone.
        if (($data['matchType'] ?? '') === 'cup') {
            returnJson(['success' => false, 'error' => 'Cup results are entered against your drawn cup match: choose Cup as the match type and pick your fixture.']);
            return;
        }
        if (!empty($data['matchId']) && isWalkoverMatch((int)$data['matchId'])) {
            returnJson(['success' => false, 'error' => walkoverLockedMsg()]);
            return;
        }

        // Resetting an existing match (matchId given) follows the same rule
        // as updateMatch: an admin may reset any match; a captain only one
        // their team played in or submitted.
        if (!empty($data['matchId']) && empty($_SESSION['admin_id'])) {
            $captainId = (int)$_SESSION['captain_id'];
            $teamId    = (int)($_SESSION['team_id'] ?? 0);
            $authStmt = $pdo->prepare('SELECT submitted_by_captain_id, home_team_id, away_team_id FROM matches WHERE match_id = ?');
            $authStmt->execute([(int)$data['matchId']]);
            $row = $authStmt->fetch();
            if (!$row) {
                returnJson(['success' => false, 'error' => 'Match not found']);
                return;
            }
            $owns = ((int)$row['submitted_by_captain_id'] === $captainId)
                 || ((int)$row['home_team_id'] === $teamId)
                 || ((int)$row['away_team_id'] === $teamId);
            if (!$owns) {
                returnJson(['success' => false, 'error' => 'Not allowed to edit this match']);
                return;
            }
        }

        // Start transaction
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $transactionStarted = true;
        }
        
        // Get team IDs
        $stmt = $pdo->prepare("SELECT team_id FROM teams WHERE team_name = ?");
        $stmt->execute([$data['homeTeam']]);
        $homeTeamId = $stmt->fetchColumn();
        
        if (!$homeTeamId) {
            throw new Exception('Home team not found: ' . $data['homeTeam']);
        }
        
        $stmt->execute([$data['awayTeam']]);
        $awayTeamId = $stmt->fetchColumn();
        
        if (!$awayTeamId) {
            throw new Exception('Away team not found: ' . $data['awayTeam']);
        }

        // A captain may only submit a new match their own team played in.
        // Admins may submit any match; resets (matchId given) were checked
        // against the stored match above.
        if (empty($data['matchId']) && empty($_SESSION['admin_id'])) {
            $teamId = (int)($_SESSION['team_id'] ?? 0);
            if ((int)$homeTeamId !== $teamId && (int)$awayTeamId !== $teamId) {
                throw new Exception('You can only submit matches your own team played in');
            }
        }

        // Create or update match
        $matchId = isset($data['matchId']) ? $data['matchId'] : null;
        
        // Get cup round if it's a cup match
        $cupRound = null;
        if ($data['matchType'] === 'cup' && isset($data['cupRound'])) {
            $cupRound = $data['cupRound'];
        }
        
        if ($matchId) {
            if ($data['matchType'] === 'cup' && $cupRound) {
                $stmt = $pdo->prepare("
                    UPDATE matches 
                    SET home_score = 0, away_score = 0, status = 'completed', cup_round = ? 
                    WHERE match_id = ?
                ");
                $stmt->execute([$cupRound, $matchId]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE matches 
                    SET home_score = 0, away_score = 0, status = 'completed' 
                    WHERE match_id = ?
                ");
                $stmt->execute([$matchId]);
            }
        } else {
            // Duplicate-submission guard. Reject if a match between these two
            // teams of the same type and division has already been submitted
            // today. This protects against rapid double-clicks of the Submit
            // Score button and accidental re-submissions. To change a result
            // already on file, the captain should use Edit Score instead.
            $dupStmt = $pdo->prepare("
                SELECT match_id FROM matches
                WHERE home_team_id = ?
                  AND away_team_id = ?
                  AND match_type = ?
                  AND division = ?
                  AND DATE(match_date) = CURDATE()
                LIMIT 1
            ");
            $dupStmt->execute([$homeTeamId, $awayTeamId, $data['matchType'], $data['division']]);
            $existingMatchId = $dupStmt->fetchColumn();
            if ($existingMatchId) {
                if ($transactionStarted && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                returnJson([
                    'success' => false,
                    'error'   => 'This match has already been submitted today. To change the result, use Edit Score instead.',
                    'duplicate' => true,
                    'existingMatchId' => (int)$existingMatchId,
                ]);
                return;
            }

            if ($data['matchType'] === 'cup' && $cupRound) {
                $stmt = $pdo->prepare("
                    INSERT INTO matches (home_team_id, away_team_id, match_type, division, status, match_date, cup_round, submitted_by_captain_id)
                    VALUES (?, ?, ?, ?, 'completed', NOW(), ?, ?)
                ");
                $stmt->execute([$homeTeamId, $awayTeamId, $data['matchType'], $data['division'], $cupRound, $_SESSION['captain_id'] ?? null]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO matches (home_team_id, away_team_id, match_type, division, status, match_date, submitted_by_captain_id)
                    VALUES (?, ?, ?, ?, 'completed', NOW(), ?)
                ");
                $stmt->execute([$homeTeamId, $awayTeamId, $data['matchType'], $data['division'], $_SESSION['captain_id'] ?? null]);
            }
            $matchId = $pdo->lastInsertId();
        }
        
        $homeScore = 0;
        $awayScore = 0;
        
        // Process doubles matches
        if (isset($data['doubles']) && is_array($data['doubles'])) {
            foreach ($data['doubles'] as $doubles) {
                // Validate doubles data
                if (!isset($doubles['homePlayer1']) || !isset($doubles['homePlayer2']) || 
                    !isset($doubles['awayPlayer1']) || !isset($doubles['awayPlayer2']) || 
                    !isset($doubles['score'])) {
                    continue;
                }
                
                // Get player IDs
                $stmt = $pdo->prepare("SELECT player_id FROM players WHERE player_name = ?");
                $stmt->execute([$doubles['homePlayer1']]);
                $homePlayer1Id = $stmt->fetchColumn();
                
                $stmt->execute([$doubles['homePlayer2']]);
                $homePlayer2Id = $stmt->fetchColumn();
                
                $stmt->execute([$doubles['awayPlayer1']]);
                $awayPlayer1Id = $stmt->fetchColumn();
                
                $stmt->execute([$doubles['awayPlayer2']]);
                $awayPlayer2Id = $stmt->fetchColumn();
                
                if (!$homePlayer1Id || !$homePlayer2Id || !$awayPlayer1Id || !$awayPlayer2Id) {
                    continue;
                }
                
                // Parse score
                $scoreParts = explode('-', $doubles['score']);
                if (count($scoreParts) !== 2) {
                    continue;
                }
                
                $homeDoublesScore = (int)$scoreParts[0];
                $awayDoublesScore = (int)$scoreParts[1];
                
                // Insert doubles result
                $stmt = $pdo->prepare("
                    INSERT INTO doubles_results (match_id, home_player1_id, home_player2_id, away_player1_id, away_player2_id, home_score, away_score) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$matchId, $homePlayer1Id, $homePlayer2Id, $awayPlayer1Id, $awayPlayer2Id, $homeDoublesScore, $awayDoublesScore]);
                
                // Update match score - each game won counts as 1 point
                if ($homeDoublesScore > $awayDoublesScore) {
                    $homeScore++;
                } else {
                    $awayScore++;
                }
            }
        }
        
        // Process singles matches
        if (isset($data['singles']) && is_array($data['singles'])) {
            foreach ($data['singles'] as $singles) {
                // Validate singles data
                if (!isset($singles['homePlayer']) || !isset($singles['awayPlayer']) || !isset($singles['score'])) {
                    continue;
                }
                
                // Get player IDs
                $stmt = $pdo->prepare("SELECT player_id FROM players WHERE player_name = ?");
                $stmt->execute([$singles['homePlayer']]);
                $homePlayerId = $stmt->fetchColumn();
                
                $stmt->execute([$singles['awayPlayer']]);
                $awayPlayerId = $stmt->fetchColumn();
                
                if (!$homePlayerId || !$awayPlayerId) {
                    continue;
                }
                
                // Parse score
                $scoreParts = explode('-', $singles['score']);
                if (count($scoreParts) !== 2) {
                    continue;
                }
                
                $homePlayerScore = (int)$scoreParts[0];
                $awayPlayerScore = (int)$scoreParts[1];
                
                // Insert singles result
                $stmt = $pdo->prepare("
                    INSERT INTO singles_results (match_id, home_player_id, away_player_id, home_score, away_score) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$matchId, $homePlayerId, $awayPlayerId, $homePlayerScore, $awayPlayerScore]);
                
                // Update match score - each game won counts as 1 point
                if ($homePlayerScore > $awayPlayerScore) {
                    $homeScore++;
                } else {
                    $awayScore++;
                }
            }
        }
        
        // Update match final score
        $stmt = $pdo->prepare("
            UPDATE matches 
            SET home_score = ?, away_score = ? 
            WHERE match_id = ?
        ");
        $stmt->execute([$homeScore, $awayScore, $matchId]);
        
        // Process 180s
        if (isset($data['oneEighties']) && is_array($data['oneEighties'])) {
            foreach ($data['oneEighties'] as $oneEighty) {
                if (!isset($oneEighty['player']) || !isset($oneEighty['count'])) {
                    continue;
                }
                
                // Get player ID
                $stmt = $pdo->prepare("SELECT player_id FROM players WHERE player_name = ?");
                $stmt->execute([$oneEighty['player']]);
                $playerId = $stmt->fetchColumn();
                
                if (!$playerId) {
                    continue;
                }
                
                // Insert 180 record
                $stmt = $pdo->prepare("
                    INSERT INTO one_eighties (match_id, player_id, count) 
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$matchId, $playerId, $oneEighty['count']]);
            }
        }
        
        // Process high finishes
        if (isset($data['highFinishes']) && is_array($data['highFinishes'])) {
            foreach ($data['highFinishes'] as $highFinish) {
                if (!isset($highFinish['player']) || !isset($highFinish['value'])) {
                    continue;
                }
                
                // Get player ID
                $stmt = $pdo->prepare("SELECT player_id FROM players WHERE player_name = ?");
                $stmt->execute([$highFinish['player']]);
                $playerId = $stmt->fetchColumn();
                
                if (!$playerId) {
                    continue;
                }
                
                // Insert high finish record
                $stmt = $pdo->prepare("
                    INSERT INTO high_finishes (match_id, player_id, finish_value) 
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$matchId, $playerId, $highFinish['value']]);
            }
        }
        
        // Commit transaction
        if ($transactionStarted) {
            $pdo->commit();
        }
        
        // Update league standings if it's a league match
        if ($data['matchType'] === 'league') {
            try {
                // First check if the stored procedure exists
                $stmt = $pdo->query("SHOW PROCEDURE STATUS WHERE Db = DATABASE() AND Name = 'update_league_standings'");
                if ($stmt->rowCount() > 0) {
                    $pdo->exec("CALL update_league_standings()");
                } else {
                    error_log("Stored procedure 'update_league_standings' not found");
                }
            } catch (Exception $e) {
                error_log("Error updating league standings: " . $e->getMessage());
                // Continue anyway - standings can be updated manually
            }
        }
        
        // If it's a cup match, advance winner via the bracket-link
        // columns set up by the draw. Falls back to a no-op (with log)
        // for legacy cup matches that pre-date the bracket linkage.
        if ($data['matchType'] === 'cup') {
            advanceCupWinner($matchId);
        }

        returnJson(['success' => true, 'matchId' => $matchId]);
    } catch (Exception $e) {
        // Only rollback if we started a transaction
        if ($transactionStarted && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error in submitMatch: " . $e->getMessage());
        error_log("Error trace: " . $e->getTraceAsString());
        returnJson(['success' => false, 'error' => $e->getMessage()]);
    }
}

function updateMatch() {
    global $pdo;
    $transactionStarted = false;

    try {
        // Get raw POST data
        $rawData = file_get_contents('php://input');

        $data = json_decode($rawData, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            returnJson(['success' => false, 'error' => 'Invalid JSON data: ' . json_last_error_msg()]);
            return;
        }

        if (!$data) {
            returnJson(['success' => false, 'error' => 'No data received']);
            return;
        }

        // Validate required fields
        if (!isset($data['matchId']) || !isset($data['homeTeam']) || !isset($data['awayTeam'])) {
            returnJson(['success' => false, 'error' => 'Missing required fields: matchId, homeTeam or awayTeam']);
            return;
        }
        if (isWalkoverMatch((int)$data['matchId'])) {
            returnJson(['success' => false, 'error' => walkoverLockedMsg()]);
            return;
        }

        // Authorisation: an admin session may edit any match; a captain
        // session may only edit a match their team played in or submitted.
        // No session at all is rejected.
        $matchIdForAuth = (int)$data['matchId'];
        if (!empty($_SESSION['admin_id'])) {
            // admin – allow
        } elseif (!empty($_SESSION['captain_id'])) {
            $captainId = (int)$_SESSION['captain_id'];
            $teamId    = (int)($_SESSION['team_id'] ?? 0);
            $authStmt = $pdo->prepare('SELECT submitted_by_captain_id, home_team_id, away_team_id FROM matches WHERE match_id = ?');
            $authStmt->execute([$matchIdForAuth]);
            $row = $authStmt->fetch();
            if (!$row) {
                returnJson(['success' => false, 'error' => 'Match not found']);
                return;
            }
            $owns = ((int)$row['submitted_by_captain_id'] === $captainId)
                 || ((int)$row['home_team_id'] === $teamId)
                 || ((int)$row['away_team_id'] === $teamId);
            if (!$owns) {
                returnJson(['success' => false, 'error' => 'Not allowed to edit this match']);
                return;
            }
        } else {
            returnJson(['success' => false, 'error' => 'Login required to edit a match']);
            return;
        }

        // Cup matches belong to the bracket and have their own game format,
        // so they are only changed through submit-cup-result.
        $typeStmt = $pdo->prepare('SELECT match_type FROM matches WHERE match_id = ?');
        $typeStmt->execute([$matchIdForAuth]);
        if ($typeStmt->fetchColumn() === 'cup' || ($data['matchType'] ?? '') === 'cup') {
            returnJson(['success' => false, 'error' => 'Cup results are entered and edited from the Cup option on the Team Login page.']);
            return;
        }

        // Start transaction
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $transactionStarted = true;
        }
        
        $matchId = $data['matchId'];
        
        // Verify match exists
        $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM matches WHERE match_id = ?");
        $checkStmt->execute([$matchId]);
        if ($checkStmt->fetchColumn() == 0) {
            throw new Exception('Match not found with ID: ' . $matchId);
        }
        
        // Get team IDs
        $stmt = $pdo->prepare("SELECT team_id FROM teams WHERE team_name = ?");
        $stmt->execute([$data['homeTeam']]);
        $homeTeamId = $stmt->fetchColumn();
        
        if (!$homeTeamId) {
            throw new Exception('Home team not found: ' . $data['homeTeam']);
        }
        
        $stmt->execute([$data['awayTeam']]);
        $awayTeamId = $stmt->fetchColumn();
        
        if (!$awayTeamId) {
            throw new Exception('Away team not found: ' . $data['awayTeam']);
        }
        
        // Get cup round if it's a cup match
        if ($data['matchType'] === 'cup' && isset($data['cupRound'])) {
            $cupRound = $data['cupRound'];
            // Update with cup round
            $stmt = $pdo->prepare("
                UPDATE matches 
                SET home_team_id = ?, away_team_id = ?, cup_round = ?
                WHERE match_id = ?
            ");
            $stmt->execute([$homeTeamId, $awayTeamId, $cupRound, $matchId]);
        } else {
            // Update without cup round
            $stmt = $pdo->prepare("
                UPDATE matches 
                SET home_team_id = ?, away_team_id = ?
                WHERE match_id = ?
            ");
            $stmt->execute([$homeTeamId, $awayTeamId, $matchId]);
        }
        
        // Delete existing match details
        $stmt = $pdo->prepare("DELETE FROM doubles_results WHERE match_id = ?");
        $stmt->execute([$matchId]);
        
        $stmt = $pdo->prepare("DELETE FROM singles_results WHERE match_id = ?");
        $stmt->execute([$matchId]);
        
        $stmt = $pdo->prepare("DELETE FROM one_eighties WHERE match_id = ?");
        $stmt->execute([$matchId]);
        
        $stmt = $pdo->prepare("DELETE FROM high_finishes WHERE match_id = ?");
        $stmt->execute([$matchId]);
        
        // Reset match score
        $stmt = $pdo->prepare("
            UPDATE matches 
            SET home_score = 0, away_score = 0
            WHERE match_id = ?
        ");
        $stmt->execute([$matchId]);
        
        $homeScore = 0;
        $awayScore = 0;
        
        // Process doubles match data
        if (isset($data['doubles']) && is_array($data['doubles'])) {
            foreach ($data['doubles'] as $doubles) {
                // Validate doubles data
                if (!isset($doubles['homePlayer1']) || !isset($doubles['homePlayer2']) || 
                    !isset($doubles['awayPlayer1']) || !isset($doubles['awayPlayer2']) || 
                    !isset($doubles['score'])) {
                    continue;
                }
                
                // Get player IDs
                $stmt = $pdo->prepare("SELECT player_id FROM players WHERE player_name = ?");
                $stmt->execute([$doubles['homePlayer1']]);
                $homePlayer1Id = $stmt->fetchColumn();
                
                $stmt->execute([$doubles['homePlayer2']]);
                $homePlayer2Id = $stmt->fetchColumn();
                
                $stmt->execute([$doubles['awayPlayer1']]);
                $awayPlayer1Id = $stmt->fetchColumn();
                
                $stmt->execute([$doubles['awayPlayer2']]);
                $awayPlayer2Id = $stmt->fetchColumn();
                
                if (!$homePlayer1Id || !$homePlayer2Id || !$awayPlayer1Id || !$awayPlayer2Id) {
                    continue;
                }
                
                // Parse score
                $scoreParts = explode('-', $doubles['score']);
                if (count($scoreParts) !== 2) {
                    continue;
                }
                
                $homeDoublesScore = (int)$scoreParts[0];
                $awayDoublesScore = (int)$scoreParts[1];
                
                // Insert doubles result
                $stmt = $pdo->prepare("
                    INSERT INTO doubles_results (match_id, home_player1_id, home_player2_id, away_player1_id, away_player2_id, home_score, away_score) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$matchId, $homePlayer1Id, $homePlayer2Id, $awayPlayer1Id, $awayPlayer2Id, $homeDoublesScore, $awayDoublesScore]);
                
                // Update match score - each game won counts as 1 point
                if ($homeDoublesScore > $awayDoublesScore) {
                    $homeScore++;
                } else {
                    $awayScore++;
                }
            }
        }

        // Process singles match data
        if (isset($data['singles']) && is_array($data['singles'])) {
            foreach ($data['singles'] as $singles) {
                // Validate singles data
                if (!isset($singles['homePlayer']) || !isset($singles['awayPlayer']) || !isset($singles['score'])) {
                    continue;
                }
                
                // Get player IDs
                $stmt = $pdo->prepare("SELECT player_id FROM players WHERE player_name = ?");
                $stmt->execute([$singles['homePlayer']]);
                $homePlayerId = $stmt->fetchColumn();
                
                $stmt->execute([$singles['awayPlayer']]);
                $awayPlayerId = $stmt->fetchColumn();
                
                if (!$homePlayerId || !$awayPlayerId) {
                    continue;
                }
                
                // Parse score
                $scoreParts = explode('-', $singles['score']);
                if (count($scoreParts) !== 2) {
                    continue;
                }
                
                $homePlayerScore = (int)$scoreParts[0];
                $awayPlayerScore = (int)$scoreParts[1];
                
                // Insert singles result
                $stmt = $pdo->prepare("
                    INSERT INTO singles_results (match_id, home_player_id, away_player_id, home_score, away_score) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                $stmt->execute([$matchId, $homePlayerId, $awayPlayerId, $homePlayerScore, $awayPlayerScore]);
                
                // Update match score - each game won counts as 1 point
                if ($homePlayerScore > $awayPlayerScore) {
                    $homeScore++;
                } else {
                    $awayScore++;
                }
            }
        }
        
        // Update match final score
        $stmt = $pdo->prepare("
            UPDATE matches 
            SET home_score = ?, away_score = ? 
            WHERE match_id = ?
        ");
        $stmt->execute([$homeScore, $awayScore, $matchId]);
        
        // Process 180s data
        if (isset($data['oneEighties']) && is_array($data['oneEighties'])) {
            foreach ($data['oneEighties'] as $oneEighty) {
                if (!isset($oneEighty['player']) || !isset($oneEighty['count'])) {
                    continue;
                }
                
                // Get player ID
                $stmt = $pdo->prepare("SELECT player_id FROM players WHERE player_name = ?");
                $stmt->execute([$oneEighty['player']]);
                $playerId = $stmt->fetchColumn();
                
                if (!$playerId) {
                    continue;
                }
                
                // Insert 180 record
                $stmt = $pdo->prepare("
                    INSERT INTO one_eighties (match_id, player_id, count) 
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$matchId, $playerId, $oneEighty['count']]);
            }
        }
        
        // Process high finishes data
        if (isset($data['highFinishes']) && is_array($data['highFinishes'])) {
            foreach ($data['highFinishes'] as $highFinish) {
                if (!isset($highFinish['player']) || !isset($highFinish['value'])) {
                    continue;
                }
                
                // Get player ID
                $stmt = $pdo->prepare("SELECT player_id FROM players WHERE player_name = ?");
                $stmt->execute([$highFinish['player']]);
                $playerId = $stmt->fetchColumn();
                
                if (!$playerId) {
                    continue;
                }
                
                // Insert high finish record
                $stmt = $pdo->prepare("
                    INSERT INTO high_finishes (match_id, player_id, finish_value) 
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$matchId, $playerId, $highFinish['value']]);
            }
        }
        
        // Commit transaction
        if ($transactionStarted) {
            $pdo->commit();
        }
        
        // Update league standings if it's a league match
        if ($data['matchType'] === 'league') {
            try {
                // First check if the stored procedure exists
                $stmt = $pdo->query("SHOW PROCEDURE STATUS WHERE Db = DATABASE() AND Name = 'update_league_standings'");
                if ($stmt->rowCount() > 0) {
                    $pdo->exec("CALL update_league_standings()");
                } else {
                    error_log("Stored procedure 'update_league_standings' not found");
                }
            } catch (Exception $e) {
                error_log("Error updating league standings: " . $e->getMessage());
                // Continue anyway - standings can be updated manually
            }
        }
        
        // If it's a cup match, advance the (possibly changed) winner via
        // bracket-link columns. Safe to call repeatedly on the same match.
        if ($data['matchType'] === 'cup') {
            advanceCupWinner($matchId);
        }
        
        returnJson(['success' => true, 'matchId' => $matchId]);
    } catch (Exception $e) {
        // Only rollback if we started a transaction
        if ($transactionStarted && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error in updateMatch: " . $e->getMessage());
        error_log("Error trace: " . $e->getTraceAsString());
        returnJson(['success' => false, 'error' => $e->getMessage()]);
    }
}

// =====================================================================
// New admin + team-session helpers and endpoints
// =====================================================================

function requirePost() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        returnJson(['success' => false, 'error' => 'Method not allowed']);
    }
}

// The site's own pages POST JSON (Content-Type: application/json) from
// one of the two hosts below. A cross-site HTML form can only send
// form-encoded, multipart or text/plain bodies, and browsers always send
// Origin on a cross-site POST, so either rule alone blocks a forged
// request. A missing Origin (non-browser client) is allowed through to
// the Content-Type rule. There are no file uploads to exempt.
function rejectCrossSitePost() {
    $allowedOrigins = ['https://crawleydartsleague.com', 'https://www.crawleydartsleague.com'];
    $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
    if ($origin !== null && !in_array($origin, $allowedOrigins, true)) {
        error_log("Rejected POST from origin: $origin");
        returnJson(['success' => false, 'error' => 'Cross-site request rejected']);
    }

    $contentType = $_SERVER['CONTENT_TYPE'] ?? ($_SERVER['HTTP_CONTENT_TYPE'] ?? '');
    $mediaType = strtolower(trim(explode(';', $contentType)[0]));
    if ($mediaType !== 'application/json') {
        error_log("Rejected POST with Content-Type: $contentType");
        returnJson(['success' => false, 'error' => 'Content-Type must be application/json']);
    }
}

function readJsonBody() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        returnJson(['success' => false, 'error' => 'Invalid JSON: ' . json_last_error_msg()]);
    }
    return $data ?: [];
}

function requireAdmin() {
    if (empty($_SESSION['admin_id'])) {
        returnJson(['success' => false, 'error' => 'Admin login required']);
    }
    return (int)$_SESSION['admin_id'];
}

function requireCaptain() {
    if (empty($_SESSION['captain_id'])) {
        returnJson(['success' => false, 'error' => 'Team login required']);
    }
    return (int)$_SESSION['captain_id'];
}

// Recompute the league_standings cache after any match changes. The
// individual stat tables (singles_results, doubles_results, 180s, finishes)
// are queried live, so they reflect changes automatically as soon as the
// CASCADE deletes (or update rebuilds) finish. league_standings is the only
// denormalised table and must be refreshed explicitly via the stored
// procedure that the fresh schema defines. Failures are logged but never
// propagated – a stale standings cache is recoverable on the next match
// submission, whereas a failing API call is not.
// True if $table (and optionally $column) exists in the current database.
// Cached per request. New features use this to degrade gracefully on the
// live site in the window between deploying code and running its
// migration in phpMyAdmin, instead of breaking public pages.
function schemaHas($table, $column = null) {
    global $pdo;
    static $cache = [];
    $key = $table . '.' . ($column ?? '');
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
        if ($column === null) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $stmt->execute([$table]);
        } else {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $stmt->execute([$table, $column]);
        }
        $cache[$key] = ((int)$stmt->fetchColumn()) > 0;
    } catch (Exception $e) {
        $cache[$key] = false;
    }
    return $cache[$key];
}

// A function, not a top-level const: api.php exits from the router at the
// top of the file before execution would ever reach a const statement here.
function walkoverLockedMsg() {
    return 'This result is a forfeit (walkover) and cannot be edited. An admin can delete it from Match Results and record it again under Forfeits & Deductions.';
}

// Trim a reason to the 255-char column without depending on mbstring
// being installed on the host.
function clipReason($s) {
    if (function_exists('mb_substr')) return mb_substr($s, 0, 255, 'UTF-8');
    return preg_match('/^.{0,255}/su', $s, $m) ? $m[0] : substr($s, 0, 255);
}

// A walkover carries its score directly with no game rows. Running it
// through the normal submit/update flow would re-total it from games
// (giving 0-0), so every edit path refuses walkovers.
function isWalkoverMatch($matchId) {
    global $pdo;
    if ($matchId <= 0 || !schemaHas('matches', 'is_walkover')) return false;
    $stmt = $pdo->prepare('SELECT is_walkover FROM matches WHERE match_id = ?');
    $stmt->execute([$matchId]);
    return ((int)$stmt->fetchColumn()) === 1;
}

function refreshLeagueStandings() {
    global $pdo;
    try {
        $stmt = $pdo->query("SHOW PROCEDURE STATUS WHERE Db = DATABASE() AND Name = 'update_league_standings'");
        if ($stmt && $stmt->rowCount() > 0) {
            $pdo->exec('CALL update_league_standings()');
        } else {
            error_log("Stored procedure 'update_league_standings' not found");
        }
    } catch (Exception $e) {
        error_log('refreshLeagueStandings failed: ' . $e->getMessage());
    }
}

// ---------------------- Admin auth ----------------------

function adminLogin() {
    global $pdo;
    $d = readJsonBody();
    $username = isset($d['username']) ? trim($d['username']) : '';
    $password = isset($d['password']) ? $d['password'] : '';
    if ($username === '' || $password === '') {
        returnJson(['success' => false, 'error' => 'Username and password are required']);
    }
    try {
        $stmt = $pdo->prepare('SELECT * FROM admins WHERE username = ?');
        $stmt->execute([$username]);
        $admin = $stmt->fetch();
    } catch (PDOException $e) {
        // Most common cause: the `admins` table doesn't exist because
        // fresh-schema.sql wasn't imported, or setup-admin.php was
        // never visited to seed the initial admin row.
        $msg = $e->getMessage();
        if (stripos($msg, "doesn't exist") !== false || stripos($msg, 'no such table') !== false) {
            returnJson(['success' => false, 'error' => "The 'admins' table does not exist. Import db/fresh-schema.sql via phpMyAdmin, then visit /setup-admin.php once to create the initial admin."]);
        }
        returnJson(['success' => false, 'error' => 'Database error during login: ' . $msg]);
    }
    if (!$admin) {
        returnJson(['success' => false, 'error' => 'Invalid credentials (no such admin user)']);
    }
    if (!password_verify($password, $admin['password_hash'])) {
        returnJson(['success' => false, 'error' => 'Invalid credentials (wrong password)']);
    }
    // New session ID at login so a pre-login ID can't be reused (session fixation).
    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int)$admin['admin_id'];
    $_SESSION['admin_username'] = $admin['username'];
    unset($_SESSION['captain_id'], $_SESSION['team_id'], $_SESSION['team_name'], $_SESSION['division']);
    returnJson(['success' => true, 'admin' => ['admin_id' => (int)$admin['admin_id'], 'username' => $admin['username']]]);
}

function adminLogout() {
    unset($_SESSION['admin_id'], $_SESSION['admin_username']);
    returnJson(['success' => true]);
}

function adminMe() {
    if (empty($_SESSION['admin_id'])) {
        returnJson(['success' => true, 'admin' => null]);
    }
    returnJson(['success' => true, 'admin' => [
        'admin_id' => (int)$_SESSION['admin_id'],
        'username' => $_SESSION['admin_username'] ?? null,
    ]]);
}

function adminChangePassword() {
    global $pdo;
    $adminId = requireAdmin();
    $d = readJsonBody();
    $current = isset($d['currentPassword']) ? $d['currentPassword'] : '';
    $new = isset($d['newPassword']) ? $d['newPassword'] : '';
    if (strlen($new) < 8) {
        returnJson(['success' => false, 'error' => 'New password must be at least 8 characters']);
    }
    $stmt = $pdo->prepare('SELECT password_hash FROM admins WHERE admin_id = ?');
    $stmt->execute([$adminId]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($current, $row['password_hash'])) {
        returnJson(['success' => false, 'error' => 'Current password is incorrect']);
    }
    $newHash = password_hash($new, PASSWORD_BCRYPT);
    $upd = $pdo->prepare('UPDATE admins SET password_hash = ? WHERE admin_id = ?');
    $upd->execute([$newHash, $adminId]);
    returnJson(['success' => true]);
}

// ---------------------- Admin: teams ----------------------

function adminAddTeam() {
    global $pdo;
    requireAdmin();
    $d = readJsonBody();
    $name = isset($d['team_name']) ? trim($d['team_name']) : '';
    $division = isset($d['division']) ? $d['division'] : '';
    $captainUsername = isset($d['captain_username']) ? trim($d['captain_username']) : '';
    $captainPassword = isset($d['captain_password']) ? $d['captain_password'] : '';
    if ($name === '' || !in_array($division, ['premier','a'], true)) {
        returnJson(['success' => false, 'error' => 'team_name and division (premier|a) are required']);
    }
    if ($captainUsername === '' || strlen($captainPassword) < 4) {
        returnJson(['success' => false, 'error' => 'captain_username and captain_password (4+ chars) are required']);
    }
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('INSERT INTO teams (team_name, division) VALUES (?, ?)');
        $stmt->execute([$name, $division]);
        $teamId = (int)$pdo->lastInsertId();
        $hash = password_hash($captainPassword, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare('INSERT INTO team_captains (team_id, username, password_hash) VALUES (?, ?, ?)');
        $stmt->execute([$teamId, $captainUsername, $hash]);
        $pdo->commit();
        returnJson(['success' => true, 'team_id' => $teamId]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        // Detect duplicate username
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            returnJson(['success' => false, 'error' => 'Username already in use, choose another']);
        }
        returnJson(['success' => false, 'error' => 'Failed to add team: ' . $e->getMessage()]);
    }
}

function adminListTeams() {
    global $pdo;
    requireAdmin();
    // Each team has at most one captain in normal use, but the schema doesn't
    // enforce uniqueness on team_id in team_captains, so pick the first by id.
    $sql = "SELECT t.team_id, t.team_name, t.division, t.venue,
                   t.secretary_name, t.secretary_phone, t.created_at,
                   (SELECT tc.username   FROM team_captains tc WHERE tc.team_id = t.team_id ORDER BY tc.captain_id ASC LIMIT 1) AS captain_username,
                   (SELECT tc.captain_id FROM team_captains tc WHERE tc.team_id = t.team_id ORDER BY tc.captain_id ASC LIMIT 1) AS captain_id
            FROM teams t
            ORDER BY t.division, t.team_name";
    $rows = $pdo->query($sql)->fetchAll();
    returnJson(['success' => true, 'teams' => $rows]);
}

function adminUpdateTeam() {
    global $pdo;
    requireAdmin();
    $d = readJsonBody();
    $teamId   = isset($d['team_id']) ? (int)$d['team_id'] : 0;
    $name     = isset($d['team_name']) ? trim($d['team_name']) : '';
    $division = isset($d['division']) ? $d['division'] : '';
    $venue          = array_key_exists('venue', $d) ? trim((string)$d['venue']) : null;
    $secretaryName  = array_key_exists('secretary_name', $d) ? trim((string)$d['secretary_name']) : null;
    $secretaryPhone = array_key_exists('secretary_phone', $d) ? trim((string)$d['secretary_phone']) : null;
    $newUsername = isset($d['captain_username']) ? trim($d['captain_username']) : '';
    $newPassword = isset($d['captain_password']) ? $d['captain_password'] : '';
    if ($teamId <= 0 || $name === '' || !in_array($division, ['premier','a'], true)) {
        returnJson(['success' => false, 'error' => 'team_id, team_name and division (premier|a) are required']);
    }
    try {
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE teams SET team_name = ?, division = ?, venue = ?, secretary_name = ?, secretary_phone = ? WHERE team_id = ?')
            ->execute([
                $name,
                $division,
                $venue === '' ? null : $venue,
                $secretaryName === '' ? null : $secretaryName,
                $secretaryPhone === '' ? null : $secretaryPhone,
                $teamId,
            ]);

        // Optionally update the captain login. Skip silently if no captain row
        // yet (admin can create one via add-team for a brand-new team).
        $cap = $pdo->prepare('SELECT captain_id FROM team_captains WHERE team_id = ? ORDER BY captain_id ASC LIMIT 1');
        $cap->execute([$teamId]);
        $captainId = $cap->fetchColumn();
        if ($captainId) {
            if ($newUsername !== '') {
                $pdo->prepare('UPDATE team_captains SET username = ? WHERE captain_id = ?')
                    ->execute([$newUsername, (int)$captainId]);
            }
            if ($newPassword !== '') {
                if (strlen($newPassword) < 4) {
                    $pdo->rollBack();
                    returnJson(['success' => false, 'error' => 'New captain password must be at least 4 characters']);
                }
                $hash = password_hash($newPassword, PASSWORD_BCRYPT);
                $pdo->prepare('UPDATE team_captains SET password_hash = ? WHERE captain_id = ?')
                    ->execute([$hash, (int)$captainId]);
            }
        }
        $pdo->commit();
        returnJson(['success' => true]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (strpos($e->getMessage(), 'Duplicate') !== false) {
            returnJson(['success' => false, 'error' => 'That captain username is already in use, choose another']);
        }
        returnJson(['success' => false, 'error' => 'Failed to update team: ' . $e->getMessage()]);
    }
}

function adminListPlayers() {
    global $pdo;
    requireAdmin();
    $sql = 'SELECT p.player_id, p.player_name, p.team_id, p.created_at, t.team_name, t.division
            FROM players p LEFT JOIN teams t ON p.team_id = t.team_id
            ORDER BY t.division, t.team_name, p.player_name';
    $rows = $pdo->query($sql)->fetchAll();
    returnJson(['success' => true, 'players' => $rows]);
}

function adminUpdatePlayer() {
    global $pdo;
    requireAdmin();
    $d = readJsonBody();
    $playerId = isset($d['player_id']) ? (int)$d['player_id'] : 0;
    $name     = isset($d['player_name']) ? trim($d['player_name']) : '';
    $teamId   = isset($d['team_id']) ? (int)$d['team_id'] : 0;
    if ($playerId <= 0 || $name === '' || $teamId <= 0) {
        returnJson(['success' => false, 'error' => 'player_id, player_name and team_id are required']);
    }
    try {
        $pdo->prepare('UPDATE players SET player_name = ?, team_id = ? WHERE player_id = ?')
            ->execute([$name, $teamId, $playerId]);
        returnJson(['success' => true]);
    } catch (Exception $e) {
        returnJson(['success' => false, 'error' => 'Failed to update player: ' . $e->getMessage()]);
    }
}

function adminDeleteTeam() {
    global $pdo;
    requireAdmin();
    $d = readJsonBody();
    $teamId = isset($d['team_id']) ? (int)$d['team_id'] : 0;
    if ($teamId <= 0) returnJson(['success' => false, 'error' => 'team_id required']);
    try {
        // Cascade via FKs on team_captains, player_requests, league_standings.
        // Matches reference teams without ON DELETE – block delete if any matches exist.
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM matches WHERE home_team_id = ? OR away_team_id = ?');
        $stmt->execute([$teamId, $teamId]);
        if ((int)$stmt->fetchColumn() > 0) {
            returnJson(['success' => false, 'error' => 'Cannot delete: team has match history. Delete those matches first.']);
        }
        // Players: keep history by detaching, or delete if you prefer. We detach.
        $pdo->prepare('UPDATE players SET team_id = NULL WHERE team_id = ?')->execute([$teamId]);
        $pdo->prepare('DELETE FROM teams WHERE team_id = ?')->execute([$teamId]);
        returnJson(['success' => true]);
    } catch (Exception $e) {
        returnJson(['success' => false, 'error' => 'Failed to delete team: ' . $e->getMessage()]);
    }
}

// ---------------------- Admin: players ----------------------

function adminAddPlayer() {
    global $pdo;
    requireAdmin();
    $d = readJsonBody();
    $teamId = isset($d['team_id']) ? (int)$d['team_id'] : 0;
    $name = isset($d['player_name']) ? trim($d['player_name']) : '';
    if ($teamId <= 0 || $name === '') {
        returnJson(['success' => false, 'error' => 'team_id and player_name are required']);
    }
    try {
        $stmt = $pdo->prepare('INSERT INTO players (player_name, team_id) VALUES (?, ?)');
        $stmt->execute([$name, $teamId]);
        returnJson(['success' => true, 'player_id' => (int)$pdo->lastInsertId()]);
    } catch (Exception $e) {
        returnJson(['success' => false, 'error' => 'Failed to add player: ' . $e->getMessage()]);
    }
}

function adminDeletePlayer() {
    global $pdo;
    requireAdmin();
    $d = readJsonBody();
    $playerId = isset($d['player_id']) ? (int)$d['player_id'] : 0;
    if ($playerId <= 0) returnJson(['success' => false, 'error' => 'player_id required']);
    try {
        // Block delete if player has any results recorded against them.
        $hasResults = false;
        $resultTables = ['singles_results' => ['home_player_id','away_player_id'],
                         'doubles_results' => ['home_player1_id','home_player2_id','away_player1_id','away_player2_id'],
                         'high_finishes'   => ['player_id'],
                         'one_eighties'    => ['player_id']];
        if (schemaHas('cup_game_players')) $resultTables['cup_game_players'] = ['player_id'];
        foreach ($resultTables as $tbl => $cols) {
            $clauses = implode(' OR ', array_map(fn($c) => "$c = ?", $cols));
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM $tbl WHERE $clauses");
            $stmt->execute(array_fill(0, count($cols), $playerId));
            if ((int)$stmt->fetchColumn() > 0) { $hasResults = true; break; }
        }
        if ($hasResults) {
            returnJson(['success' => false, 'error' => 'Cannot delete: player has recorded results. Detach by editing match results first, or keep them on file.']);
        }
        $pdo->prepare('DELETE FROM players WHERE player_id = ?')->execute([$playerId]);
        returnJson(['success' => true]);
    } catch (Exception $e) {
        returnJson(['success' => false, 'error' => 'Failed to delete player: ' . $e->getMessage()]);
    }
}

// ---------------------- Admin: player requests ----------------------

function adminListPendingRequests() {
    global $pdo;
    requireAdmin();
    $status = isset($_GET['status']) ? $_GET['status'] : 'pending';
    if (!in_array($status, ['pending','approved','denied','all'], true)) $status = 'pending';
    $sql = 'SELECT pr.*, t.team_name, t.division
            FROM player_requests pr
            JOIN teams t ON pr.team_id = t.team_id';
    $params = [];
    if ($status !== 'all') { $sql .= ' WHERE pr.status = ?'; $params[] = $status; }
    $sql .= ' ORDER BY pr.requested_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    returnJson(['success' => true, 'requests' => $stmt->fetchAll()]);
}

function adminApproveRequest() {
    global $pdo;
    $adminId = requireAdmin();
    $d = readJsonBody();
    $requestId = isset($d['request_id']) ? (int)$d['request_id'] : 0;
    if ($requestId <= 0) returnJson(['success' => false, 'error' => 'request_id required']);
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT * FROM player_requests WHERE request_id = ? FOR UPDATE');
        $stmt->execute([$requestId]);
        $req = $stmt->fetch();
        if (!$req) { $pdo->rollBack(); returnJson(['success' => false, 'error' => 'Request not found']); }
        if ($req['status'] !== 'pending') { $pdo->rollBack(); returnJson(['success' => false, 'error' => 'Request already reviewed']); }
        $pdo->prepare('INSERT INTO players (player_name, team_id) VALUES (?, ?)')
            ->execute([$req['player_name'], $req['team_id']]);
        $pdo->prepare("UPDATE player_requests SET status = 'approved', reviewed_at = NOW(), reviewed_by_admin_id = ? WHERE request_id = ?")
            ->execute([$adminId, $requestId]);
        $pdo->commit();
        returnJson(['success' => true]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        returnJson(['success' => false, 'error' => 'Failed to approve: ' . $e->getMessage()]);
    }
}

function adminDenyRequest() {
    global $pdo;
    $adminId = requireAdmin();
    $d = readJsonBody();
    $requestId = isset($d['request_id']) ? (int)$d['request_id'] : 0;
    if ($requestId <= 0) returnJson(['success' => false, 'error' => 'request_id required']);
    try {
        $stmt = $pdo->prepare("UPDATE player_requests SET status = 'denied', reviewed_at = NOW(), reviewed_by_admin_id = ? WHERE request_id = ? AND status = 'pending'");
        $stmt->execute([$adminId, $requestId]);
        if ($stmt->rowCount() === 0) {
            returnJson(['success' => false, 'error' => 'Request not found or already reviewed']);
        }
        returnJson(['success' => true]);
    } catch (Exception $e) {
        returnJson(['success' => false, 'error' => 'Failed to deny: ' . $e->getMessage()]);
    }
}

// ---------------------- Admin: matches ----------------------

function adminListMatches() {
    global $pdo;
    requireAdmin();
    $hasWo = schemaHas('matches', 'forfeit_team_id');
    $sql = 'SELECT m.*, ht.team_name AS home_team_name, at.team_name AS away_team_name'
         . ($hasWo ? ', ft.team_name AS forfeit_team_name' : '') . '
            FROM matches m
            LEFT JOIN teams ht ON m.home_team_id = ht.team_id
            LEFT JOIN teams at ON m.away_team_id = at.team_id '
         . ($hasWo ? 'LEFT JOIN teams ft ON m.forfeit_team_id = ft.team_id ' : '') . '
            ORDER BY m.match_date DESC, m.match_id DESC';
    $stmt = $pdo->query($sql);
    returnJson(['success' => true, 'matches' => $stmt->fetchAll()]);
}

function adminDeleteMatch() {
    global $pdo;
    requireAdmin();
    $d = readJsonBody();
    $matchId = isset($d['match_id']) ? (int)$d['match_id'] : 0;
    if ($matchId <= 0) returnJson(['success' => false, 'error' => 'match_id required']);
    try {
        // A cup match is a fixed slot in the bracket: deleting it clears the
        // result and takes the winner back out of the next round instead.
        $cup = loadCupMatch($matchId);
        if ($cup) {
            $err = resetCupResult($cup);
            returnJson($err ? ['success' => false, 'error' => $err] : ['success' => true, 'cup_reset' => true]);
        }
        // FKs are ON DELETE CASCADE for child stat tables in the fresh schema,
        // so singles_results/doubles_results/180s/high_finishes are tidied
        // automatically. league_standings is a denormalised cache and must be
        // refreshed explicitly via the stored procedure.
        $pdo->prepare('DELETE FROM matches WHERE match_id = ?')->execute([$matchId]);
        refreshLeagueStandings();
        returnJson(['success' => true]);
    } catch (Exception $e) {
        returnJson(['success' => false, 'error' => 'Failed to delete match: ' . $e->getMessage()]);
    }
}

// -------- Site settings (admin-editable URLs etc.) --------

function listSettings() {
    global $pdo;
    try {
        $stmt = $pdo->query('SELECT setting_key, setting_value FROM site_settings');
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['setting_key']] = $row['setting_value'];
        }
        returnJson(['success' => true, 'settings' => $out]);
    } catch (Exception $e) {
        // Table missing on a not-yet-migrated DB – return empty so the
        // public pages fall back to their static content rather than 500.
        returnJson(['success' => true, 'settings' => new stdClass()]);
    }
}

function adminUpdateSetting() {
    global $pdo;
    requireAdmin();
    $d = readJsonBody();
    $key   = isset($d['key'])   ? trim($d['key'])   : '';
    $value = isset($d['value']) ? (string)$d['value'] : '';
    if ($key === '' || strlen($key) > 64) {
        returnJson(['success' => false, 'error' => 'key required (max 64 chars)']);
    }
    try {
        $stmt = $pdo->prepare('INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)
                               ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        $stmt->execute([$key, $value]);
        returnJson(['success' => true]);
    } catch (Exception $e) {
        returnJson(['success' => false, 'error' => 'Failed to save setting: ' . $e->getMessage()]);
    }
}

// -------- Venues --------

function listVenues() {
    global $pdo;
    try {
        $stmt = $pdo->query('SELECT venue_id, venue_name, address, phone FROM venues ORDER BY venue_name');
        returnJson(['success' => true, 'venues' => $stmt->fetchAll()]);
    } catch (Exception $e) {
        returnJson(['success' => true, 'venues' => []]);
    }
}

function adminAddVenue() {
    global $pdo;
    requireAdmin();
    $d = readJsonBody();
    $name    = isset($d['venue_name']) ? trim($d['venue_name']) : '';
    $address = isset($d['address']) ? trim($d['address']) : '';
    $phone   = isset($d['phone']) ? trim($d['phone']) : '';
    if ($name === '') returnJson(['success' => false, 'error' => 'venue_name required']);
    try {
        $stmt = $pdo->prepare('INSERT INTO venues (venue_name, address, phone) VALUES (?, ?, ?)');
        $stmt->execute([$name, $address ?: null, $phone ?: null]);
        returnJson(['success' => true, 'venue_id' => (int)$pdo->lastInsertId()]);
    } catch (Exception $e) {
        returnJson(['success' => false, 'error' => 'Failed to add venue: ' . $e->getMessage()]);
    }
}

function adminUpdateVenue() {
    global $pdo;
    requireAdmin();
    $d = readJsonBody();
    $id      = isset($d['venue_id']) ? (int)$d['venue_id'] : 0;
    $name    = isset($d['venue_name']) ? trim($d['venue_name']) : '';
    $address = isset($d['address']) ? trim($d['address']) : '';
    $phone   = isset($d['phone']) ? trim($d['phone']) : '';
    if ($id <= 0 || $name === '') returnJson(['success' => false, 'error' => 'venue_id and venue_name required']);
    try {
        $stmt = $pdo->prepare('UPDATE venues SET venue_name = ?, address = ?, phone = ? WHERE venue_id = ?');
        $stmt->execute([$name, $address ?: null, $phone ?: null, $id]);
        returnJson(['success' => true]);
    } catch (Exception $e) {
        returnJson(['success' => false, 'error' => 'Failed to update venue: ' . $e->getMessage()]);
    }
}

function adminDeleteVenue() {
    global $pdo;
    requireAdmin();
    $d = readJsonBody();
    $id = isset($d['venue_id']) ? (int)$d['venue_id'] : 0;
    if ($id <= 0) returnJson(['success' => false, 'error' => 'venue_id required']);
    try {
        $pdo->prepare('DELETE FROM venues WHERE venue_id = ?')->execute([$id]);
        returnJson(['success' => true]);
    } catch (Exception $e) {
        returnJson(['success' => false, 'error' => 'Failed to delete venue: ' . $e->getMessage()]);
    }
}

// Combined feed for contactinfo.html – venues + teams with their
// contact info. Phone is only returned when the request is from an
// authenticated session (admin OR captain) per the existing GDPR
// model the page implements client-side. Anonymous callers still get
// every other field.
function getContactInfo() {
    global $pdo;
    $isAuthed = !empty($_SESSION['admin_id']) || !empty($_SESSION['captain_id']);
    try {
        $venuesStmt = $pdo->query('SELECT venue_id, venue_name, address, phone FROM venues ORDER BY venue_name');
        $teamsStmt  = $pdo->query("SELECT team_id, team_name, division, venue, secretary_name,
                                          " . ($isAuthed ? 'secretary_phone' : 'NULL AS secretary_phone') . "
                                   FROM teams
                                   WHERE team_name NOT LIKE '%Bye%' AND team_name NOT LIKE 'bye'
                                   ORDER BY division, team_name");
        returnJson([
            'success' => true,
            'authed' => $isAuthed,
            'venues' => $venuesStmt->fetchAll(),
            'teams'  => $teamsStmt->fetchAll(),
        ]);
    } catch (Exception $e) {
        returnJson(['success' => false, 'error' => 'Failed to load contact info: ' . $e->getMessage()]);
    }
}

// -------- Cup bracket --------

// Given a team count, return [bracketSize, roundNames] for a standard
// single-elimination bracket. The size is the next power of 2 ≥ teams
// (so any non-power-of-2 team count just means some first-round byes).
// Returns [0, null] for unsupported sizes (< 2 or > 64).
function cupBracketLayout($teamCount) {
    if ($teamCount < 2) return [0, null];
    $size = 1;
    while ($size < $teamCount) $size *= 2;
    $layouts = [
        2  => ['Final'],
        4  => ['Semi Final', 'Final'],
        8  => ['Quarter Final', 'Semi Final', 'Final'],
        16 => ['Last 16', 'Quarter Final', 'Semi Final', 'Final'],
        32 => ['Last 32', 'Last 16', 'Quarter Final', 'Semi Final', 'Final'],
        64 => ['Last 64', 'Last 32', 'Last 16', 'Quarter Final', 'Semi Final', 'Final'],
    ];
    if (!isset($layouts[$size])) return [0, null];
    return [$size, $layouts[$size]];
}

// Preview the draw – no DB writes. Tells the admin what the bracket
// will look like (size, rounds, how many byes there will be) so they
// can confirm the team list is complete before committing.
function adminCupDrawPreview() {
    global $pdo;
    requireAdmin();
    $division = isset($_GET['division']) ? $_GET['division'] : '';
    if (!in_array($division, ['premier', 'a'], true)) {
        returnJson(['success' => false, 'error' => 'division must be premier or a']);
    }
    $stmt = $pdo->prepare('SELECT team_id, team_name FROM teams WHERE division = ? ORDER BY team_name');
    $stmt->execute([$division]);
    $teams = $stmt->fetchAll();

    // Count teams that participate vs teams that are named "Bye"
    // (DB-Bye placeholders still count toward the bracket but are
    // labelled distinctly so the admin can see them).
    $realCount = 0; $byeNamedCount = 0;
    $teamRows = [];
    foreach ($teams as $t) {
        $isByeNamed = stripos($t['team_name'], 'bye') !== false;
        if ($isByeNamed) { $byeNamedCount++; } else { $realCount++; }
        $teamRows[] = ['team_id' => (int)$t['team_id'], 'team_name' => $t['team_name'], 'is_bye_named' => $isByeNamed];
    }
    list($bracketSize, $rounds) = cupBracketLayout(count($teams));
    if (!$rounds) {
        returnJson([
            'success' => true,
            'division' => $division,
            'teams' => $teamRows,
            'team_count' => count($teams),
            'real_count' => $realCount,
            'bye_named_count' => $byeNamedCount,
            'bracket_size' => 0,
            'rounds' => [],
            'first_round_byes' => 0,
            'supported' => false,
            'message' => count($teams) < 2 ? 'Need at least 2 teams to draw a cup.' : 'Unsupported team count: ' . count($teams),
        ]);
    }
    $padding = $bracketSize - count($teams);
    $firstRoundByes = $padding + $byeNamedCount;
    $firstRoundMatches = (int)($bracketSize / 2) - $firstRoundByes;
    if ($firstRoundMatches < 0) $firstRoundMatches = 0;
    returnJson([
        'success' => true,
        'division' => $division,
        'teams' => $teamRows,
        'team_count' => count($teams),
        'real_count' => $realCount,
        'bye_named_count' => $byeNamedCount,
        'bracket_size' => $bracketSize,
        'rounds' => $rounds,
        'first_round_name' => $rounds[0],
        'first_round_byes' => $firstRoundByes,
        'first_round_matches' => $firstRoundMatches,
        'supported' => true,
        'message' => $firstRoundByes > 0
            ? "{$realCount} teams will be drawn into a {$bracketSize}-slot bracket ({$firstRoundMatches} match" . ($firstRoundMatches === 1 ? '' : 'es') . ", {$firstRoundByes} bye" . ($firstRoundByes === 1 ? '' : 's') . ")."
            : "{$realCount} teams will be drawn into a clean {$bracketSize}-slot bracket (no byes needed).",
    ]);
}

// Returns the full cup bracket for a division (all rounds, current
// statuses, both team names). Named getBracket() not getCupBracket()
// because the legacy getcupbracket() – used by the cup-fixtures
// endpoint – already exists, and PHP function names are
// case-insensitive so the two would collide as a fatal
// "Cannot redeclare" at parse time.
function getBracket() {
    global $pdo;
    $division = isset($_GET['division']) ? $_GET['division'] : 'premier';
    try {
        // The home team (drawn first) hosts, so a fixture's venue is the
        // home team's venue. venue_name isn't unique, hence the LIMIT 1
        // subqueries. Skipped if the venues migration hasn't been run.
        $venueCols = schemaHas('teams', 'venue')
            ? ', ht.venue AS venue' . (schemaHas('venues')
                ? ', (SELECT v.address FROM venues v WHERE v.venue_name = ht.venue ORDER BY v.venue_id LIMIT 1) AS venue_address'
                  . ', (SELECT v.phone FROM venues v WHERE v.venue_name = ht.venue ORDER BY v.venue_id LIMIT 1) AS venue_phone'
                : ', NULL AS venue_address, NULL AS venue_phone')
            : ', NULL AS venue, NULL AS venue_address, NULL AS venue_phone';
        $stmt = $pdo->prepare("
            SELECT m.match_id, m.match_date, m.cup_round, m.status,
                   m.home_score, m.away_score,
                   m.home_team_id, m.away_team_id,
                   ht.team_name AS home_team, at.team_name AS away_team,
                   m.next_match_id, m.next_match_slot
                   $venueCols
            FROM matches m
            LEFT JOIN teams ht ON m.home_team_id = ht.team_id
            LEFT JOIN teams at ON m.away_team_id = at.team_id
            WHERE m.match_type = 'cup' AND m.division = ?
            ORDER BY m.match_date ASC, m.match_id ASC");
        $stmt->execute([$division]);
        returnJson(['success' => true, 'matches' => $stmt->fetchAll()]);
    } catch (Exception $e) {
        returnJson(['success' => false, 'error' => 'Failed to load bracket: ' . $e->getMessage()]);
    }
}

// Advance a cup winner to the linked downstream match. Replaces the
// pre-existing `advance_cup_winner` stored procedure with PHP that uses
// the explicit next_match_id / next_match_slot columns on matches. Safe
// to call repeatedly (idempotent for the same winner). Failures are
// logged and swallowed so a bracket mishap can't break score submission.
function advanceCupWinner($matchId) {
    global $pdo;
    try {
        $stmt = $pdo->prepare('SELECT match_type, home_team_id, away_team_id,
                               home_score, away_score, next_match_id, next_match_slot
                               FROM matches WHERE match_id = ?');
        $stmt->execute([$matchId]);
        $m = $stmt->fetch();
        if (!$m || $m['match_type'] !== 'cup' || empty($m['next_match_id'])) return;

        // Determine winner; draws do nothing (cup matches shouldn't draw).
        $winner = null;
        if ((int)$m['home_score'] > (int)$m['away_score']) $winner = (int)$m['home_team_id'];
        elseif ((int)$m['away_score'] > (int)$m['home_score']) $winner = (int)$m['away_team_id'];
        if (!$winner) return;

        $col = $m['next_match_slot'] === 'away' ? 'away_team_id' : 'home_team_id';
        $pdo->prepare("UPDATE matches SET $col = ? WHERE match_id = ?")
            ->execute([$winner, (int)$m['next_match_id']]);

        // If the next match now has both teams set and is still pending,
        // promote it to 'scheduled' so the captains can submit a score.
        $stmt = $pdo->prepare('SELECT home_team_id, away_team_id, status FROM matches WHERE match_id = ?');
        $stmt->execute([(int)$m['next_match_id']]);
        $next = $stmt->fetch();
        if ($next && $next['home_team_id'] && $next['away_team_id'] && $next['status'] === 'pending') {
            $pdo->prepare("UPDATE matches SET status = 'scheduled' WHERE match_id = ?")
                ->execute([(int)$m['next_match_id']]);
        }
    } catch (Exception $e) {
        error_log('advanceCupWinner failed: ' . $e->getMessage());
    }
}

// Generate a random first-round draw for a division's knockout cup.
// Wipes any existing cup matches for the division (and their child
// stats via CASCADE), then inserts a complete bracket (all rounds)
// with first-round teams populated and later rounds left pending so
// they fill in as winners advance. Handles odd team counts by padding
// with NULL placeholders that auto-advance the real opponent. Also
// treats any team with "bye" in its name (case-insensitive) as a
// virtual bye and auto-advances its opponent.
function adminCupDraw() {
    global $pdo;
    requireAdmin();
    $d = readJsonBody();
    $division = isset($d['division']) ? $d['division'] : '';
    if (!in_array($division, ['premier', 'a'], true)) {
        returnJson(['success' => false, 'error' => 'division must be premier or a']);
    }

    try {
        // Snapshot teams
        $stmt = $pdo->prepare('SELECT team_id, team_name FROM teams WHERE division = ? ORDER BY team_id');
        $stmt->execute([$division]);
        $teams = $stmt->fetchAll();
        if (count($teams) < 2) {
            returnJson(['success' => false, 'error' => 'Need at least 2 teams in the division to draw a cup']);
        }

        list($bracketSize, $rounds) = cupBracketLayout(count($teams));
        if (!$rounds) {
            returnJson(['success' => false, 'error' => 'Unsupported team count: ' . count($teams)]);
        }

        $pdo->beginTransaction();

        // Wipe existing cup matches in this division; cascade clears
        // singles/doubles/180s/finishes via ON DELETE CASCADE.
        $pdo->prepare("DELETE FROM matches WHERE division = ? AND match_type = 'cup'")
            ->execute([$division]);

        // Shuffle teams, then pad with NULLs to bracket size.
        shuffle($teams);
        while (count($teams) < $bracketSize) $teams[] = null;

        // Round-spread dates: round 1 today, each later round +7 days.
        $base = new DateTime();
        $dates = [];
        for ($r = 0; $r < count($rounds); $r++) {
            $dt = clone $base;
            $dt->modify('+' . ($r * 7) . ' days');
            $dates[] = $dt->format('Y-m-d');
        }

        // Insert all matches, capture IDs round-by-round.
        $insertScheduled = $pdo->prepare("INSERT INTO matches (home_team_id, away_team_id, match_date, match_type, division, status, cup_round) VALUES (?, ?, ?, 'cup', ?, 'scheduled', ?)");
        $insertCompleted = $pdo->prepare("INSERT INTO matches (home_team_id, away_team_id, match_date, match_type, division, status, cup_round, home_score, away_score) VALUES (?, ?, ?, 'cup', ?, 'completed', ?, ?, ?)");
        $insertPending   = $pdo->prepare("INSERT INTO matches (home_team_id, away_team_id, match_date, match_type, division, status, cup_round) VALUES (NULL, NULL, ?, 'cup', ?, 'pending', ?)");
        $updateNext      = $pdo->prepare('UPDATE matches SET next_match_id = ?, next_match_slot = ? WHERE match_id = ?');

        // ROUND 1
        $isByeTeam = function($t) {
            if (!$t) return true;
            return stripos($t['team_name'], 'bye') !== false;
        };
        $round1Ids = [];
        $autoAdvanced = [];   // [match_id => winning_team_id] for bye matches
        $matchCount = $bracketSize / 2;
        for ($i = 0; $i < $matchCount; $i++) {
            $h = $teams[$i * 2];
            $a = $teams[$i * 2 + 1];
            $hId = $h ? (int)$h['team_id'] : null;
            $aId = $a ? (int)$a['team_id'] : null;
            $hBye = $isByeTeam($h);
            $aBye = $isByeTeam($a);

            if ($hBye && $aBye) {
                // Both sides empty – degenerate, mark completed 0-0 with no winner.
                $insertCompleted->execute([$hId, $aId, $dates[0], $division, $rounds[0], 0, 0]);
            } elseif ($hBye) {
                // Away advances
                $insertCompleted->execute([$hId, $aId, $dates[0], $division, $rounds[0], 0, 1]);
                $autoAdvanced[(int)$pdo->lastInsertId()] = $aId;
            } elseif ($aBye) {
                // Home advances
                $insertCompleted->execute([$hId, $aId, $dates[0], $division, $rounds[0], 1, 0]);
                $autoAdvanced[(int)$pdo->lastInsertId()] = $hId;
            } else {
                $insertScheduled->execute([$hId, $aId, $dates[0], $division, $rounds[0]]);
            }
            $round1Ids[] = (int)$pdo->lastInsertId();
        }

        // ROUNDS 2..N (pending placeholders)
        $previousIds = $round1Ids;
        for ($r = 1; $r < count($rounds); $r++) {
            $thisRoundIds = [];
            $thisCount = $bracketSize / pow(2, $r + 1);
            for ($i = 0; $i < $thisCount; $i++) {
                $insertPending->execute([$dates[$r], $division, $rounds[$r]]);
                $thisRoundIds[] = (int)$pdo->lastInsertId();
            }
            // Link previous round into this one (two-at-a-time).
            for ($i = 0; $i < count($previousIds); $i++) {
                $nextId = $thisRoundIds[intdiv($i, 2)];
                $slot   = ($i % 2 === 0) ? 'home' : 'away';
                $updateNext->execute([$nextId, $slot, $previousIds[$i]]);
            }
            $previousIds = $thisRoundIds;
        }

        // Propagate bye auto-advances into the next round so the captain
        // never has to submit a placeholder bye match.
        foreach ($autoAdvanced as $matchId => $_) advanceCupWinner($matchId);

        $pdo->commit();
        returnJson(['success' => true, 'bracket_size' => $bracketSize, 'rounds' => $rounds, 'team_count' => count(array_filter($teams))]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        returnJson(['success' => false, 'error' => 'Draw failed: ' . $e->getMessage()]);
    }
}

// -------- Announcements --------

function listAnnouncements() {
    global $pdo;
    $stmt = $pdo->query("SELECT announcement_id, title, body, created_at
                         FROM announcements
                         ORDER BY created_at DESC, announcement_id DESC");
    returnJson(['success' => true, 'announcements' => $stmt->fetchAll()]);
}

function adminAddAnnouncement() {
    global $pdo;
    $adminId = requireAdmin();
    $d = readJsonBody();
    $title = isset($d['title']) ? trim($d['title']) : '';
    $body  = isset($d['body']) ? trim($d['body']) : '';
    if ($title === '' || $body === '') {
        returnJson(['success' => false, 'error' => 'Title and body are both required']);
    }
    try {
        $stmt = $pdo->prepare('INSERT INTO announcements (title, body, created_by_admin_id) VALUES (?, ?, ?)');
        $stmt->execute([$title, $body, $adminId]);
        returnJson(['success' => true, 'announcement_id' => (int)$pdo->lastInsertId()]);
    } catch (Exception $e) {
        returnJson(['success' => false, 'error' => 'Failed to post announcement: ' . $e->getMessage()]);
    }
}

function adminDeleteAnnouncement() {
    global $pdo;
    requireAdmin();
    $d = readJsonBody();
    $id = isset($d['announcement_id']) ? (int)$d['announcement_id'] : 0;
    if ($id <= 0) returnJson(['success' => false, 'error' => 'announcement_id required']);
    try {
        $pdo->prepare('DELETE FROM announcements WHERE announcement_id = ?')->execute([$id]);
        returnJson(['success' => true]);
    } catch (Exception $e) {
        returnJson(['success' => false, 'error' => 'Failed to delete announcement: ' . $e->getMessage()]);
    }
}

// Wipe everything except the admins table. Designed to be triggered at the
// end of a season after the user has confirmed twice in the UI. Note that
// TRUNCATE in MySQL implicitly commits, so we do NOT wrap this in an explicit
// transaction; FK checks are disabled and re-enabled around the loop instead.
function adminSeasonReset() {
    global $pdo;
    requireAdmin();
    $tables = [
        // Child tables first (defensive – FK checks off anyway).
        'doubles_results',
        'singles_results',
        'high_finishes',
        'one_eighties',
        'league_standings',
        'player_requests',
        'matches',
        // Then parents.
        'team_captains',
        'players',
        'teams',
    ];
    // Tables from later migrations: only truncate when present, so a reset
    // still works on a database that hasn't been upgraded.
    if (schemaHas('points_adjustments')) array_unshift($tables, 'points_adjustments');
    if (schemaHas('cup_games')) array_unshift($tables, 'cup_game_players', 'cup_games');
    if (schemaHas('comp_draws')) array_unshift($tables, 'comp_entry_players', 'comp_matches', 'comp_entries', 'comp_draws');
    $cleared = [];
    try {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $t) {
            $pdo->exec("TRUNCATE TABLE `$t`");
            $cleared[] = $t;
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        returnJson(['success' => true, 'cleared' => $cleared]);
    } catch (Exception $e) {
        try { $pdo->exec('SET FOREIGN_KEY_CHECKS = 1'); } catch (Exception $e2) {}
        returnJson(['success' => false, 'error' => 'Reset failed: ' . $e->getMessage(), 'cleared' => $cleared]);
    }
}

// ---------------------- Team session helpers ----------------------

function teamMe() {
    if (empty($_SESSION['captain_id'])) {
        returnJson(['success' => true, 'captain' => null]);
    }
    returnJson(['success' => true, 'captain' => [
        'captain_id' => (int)$_SESSION['captain_id'],
        'team_id'    => (int)($_SESSION['team_id'] ?? 0),
        'team_name'  => $_SESSION['team_name'] ?? null,
        'division'   => $_SESSION['division'] ?? null,
    ]]);
}

function teamLogout() {
    unset($_SESSION['captain_id'], $_SESSION['team_id'], $_SESSION['team_name'], $_SESSION['division']);
    returnJson(['success' => true]);
}

function teamRequestPlayer() {
    global $pdo;
    $captainId = requireCaptain();
    $teamId = (int)($_SESSION['team_id'] ?? 0);
    $d = readJsonBody();
    $name = isset($d['player_name']) ? trim($d['player_name']) : '';
    if ($name === '') returnJson(['success' => false, 'error' => 'player_name is required']);
    try {
        $stmt = $pdo->prepare('INSERT INTO player_requests (team_id, requested_by_captain_id, player_name) VALUES (?, ?, ?)');
        $stmt->execute([$teamId, $captainId, $name]);
        returnJson(['success' => true, 'request_id' => (int)$pdo->lastInsertId()]);
    } catch (Exception $e) {
        returnJson(['success' => false, 'error' => 'Failed to submit request: ' . $e->getMessage()]);
    }
}

function teamMyRequests() {
    global $pdo;
    requireCaptain();
    $teamId = (int)($_SESSION['team_id'] ?? 0);
    $stmt = $pdo->prepare('SELECT * FROM player_requests WHERE team_id = ? ORDER BY requested_at DESC');
    $stmt->execute([$teamId]);
    returnJson(['success' => true, 'requests' => $stmt->fetchAll()]);
}

function teamListMyMatches() {
    global $pdo;
    requireCaptain();
    $captainId = (int)$_SESSION['captain_id'];
    $teamId    = (int)($_SESSION['team_id'] ?? 0);
    // Show matches submitted by this captain OR involving this team.
    $stmt = $pdo->prepare('SELECT m.*, ht.team_name AS home_team_name, at.team_name AS away_team_name
            FROM matches m
            LEFT JOIN teams ht ON m.home_team_id = ht.team_id
            LEFT JOIN teams at ON m.away_team_id = at.team_id
            WHERE m.submitted_by_captain_id = ? OR m.home_team_id = ? OR m.away_team_id = ?
            ORDER BY m.match_date DESC, m.match_id DESC');
    $stmt->execute([$captainId, $teamId, $teamId]);
    returnJson(['success' => true, 'matches' => $stmt->fetchAll()]);
}

function teamDeleteMatch() {
    global $pdo;
    requireCaptain();
    $captainId = (int)$_SESSION['captain_id'];
    $teamId    = (int)($_SESSION['team_id'] ?? 0);
    $d = readJsonBody();
    $matchId = isset($d['match_id']) ? (int)$d['match_id'] : 0;
    if ($matchId <= 0) returnJson(['success' => false, 'error' => 'match_id required']);
    try {
        // Captains can delete a match they submitted, or one their team played in.
        $stmt = $pdo->prepare('SELECT submitted_by_captain_id, home_team_id, away_team_id FROM matches WHERE match_id = ?');
        $stmt->execute([$matchId]);
        $row = $stmt->fetch();
        if (!$row) returnJson(['success' => false, 'error' => 'Match not found']);
        $allowed = ((int)$row['submitted_by_captain_id'] === $captainId)
                || ((int)$row['home_team_id'] === $teamId)
                || ((int)$row['away_team_id'] === $teamId);
        if (!$allowed) returnJson(['success' => false, 'error' => 'Not allowed to delete this match']);
        if (isWalkoverMatch($matchId)) {
            returnJson(['success' => false, 'error' => 'This result is an admin-recorded forfeit and can only be removed by a league admin.']);
        }
        // A cup match is a fixed slot in the bracket: deleting it clears the
        // result and takes the winner back out of the next round instead.
        $cup = loadCupMatch($matchId);
        if ($cup) {
            $err = resetCupResult($cup);
            returnJson($err ? ['success' => false, 'error' => $err] : ['success' => true, 'cup_reset' => true]);
        }
        // Stats cascade automatically via FKs; standings need an explicit
        // refresh so leaguetable.html reflects the deletion immediately.
        $pdo->prepare('DELETE FROM matches WHERE match_id = ?')->execute([$matchId]);
        refreshLeagueStandings();
        returnJson(['success' => true]);
    } catch (Exception $e) {
        returnJson(['success' => false, 'error' => 'Failed to delete match: ' . $e->getMessage()]);
    }
}

// ---------------------- Cup results ----------------------

function requireCupSchema() {
    if (!schemaHas('cup_games') || !schemaHas('cup_game_players')) {
        returnJson(['success' => false, 'error' => 'Database not upgraded yet: run db/2026-add-cup-games.sql in phpMyAdmin first.']);
    }
}

// A cup match with both team names, or null if it isn't a cup match.
function loadCupMatch($matchId) {
    global $pdo;
    $stmt = $pdo->prepare('SELECT m.match_id, m.match_type, m.division, m.status, m.cup_round,
                                  m.home_team_id, m.away_team_id, m.home_score, m.away_score,
                                  m.next_match_id, m.next_match_slot,
                                  ht.team_name AS home_team, at.team_name AS away_team
                           FROM matches m
                           LEFT JOIN teams ht ON m.home_team_id = ht.team_id
                           LEFT JOIN teams at ON m.away_team_id = at.team_id
                           WHERE m.match_id = ?');
    $stmt->execute([(int)$matchId]);
    $m = $stmt->fetch();
    return ($m && $m['match_type'] === 'cup') ? $m : null;
}

// A bye: one side empty or a team named "Bye". The draw completes byes
// itself, so they have no games to enter or delete.
function isCupBye($m) {
    $homeBye = empty($m['home_team_id']) || stripos((string)$m['home_team'], 'bye') !== false;
    $awayBye = empty($m['away_team_id']) || stripos((string)$m['away_team'], 'bye') !== false;
    return $homeBye || $awayBye;
}

// Winning team_id from a match's stored score, or null for no winner.
function cupMatchWinner($m) {
    if ((int)$m['home_score'] > (int)$m['away_score']) return (int)$m['home_team_id'];
    if ((int)$m['away_score'] > (int)$m['home_score']) return (int)$m['away_team_id'];
    return null;
}

// Enter or correct the result of a drawn cup match. Either team's captain
// (or an admin) may submit. Format: 2 trebles (3 a side) and 6 singles,
// one game each; every trebles and singles game is best of 5 legs in the
// Premier and best of 3 in the A Division. First to 5 games wins – play
// may stop once a side reaches 5 – and at 4-4 after all 8 games a 1001
// team game (6 a side) decides it. Games go into cup_games, never
// singles_results / doubles_results, so they don't count towards players'
// individual stats; 180s and high finishes go into the normal tables.
// The winner is moved into the next round straight away.
function submitCupResult() {
    global $pdo;
    if (empty($_SESSION['admin_id']) && empty($_SESSION['captain_id'])) {
        returnJson(['success' => false, 'error' => 'Login required to submit a cup result']);
    }
    requireCupSchema();
    $d = readJsonBody();
    $matchId = isset($d['match_id']) ? (int)$d['match_id'] : 0;
    $m = $matchId > 0 ? loadCupMatch($matchId) : null;
    if (!$m) returnJson(['success' => false, 'error' => 'Cup match not found']);
    if (empty($m['home_team_id']) || empty($m['away_team_id'])) {
        returnJson(['success' => false, 'error' => 'This cup match is still waiting for both teams.']);
    }
    if (isCupBye($m)) returnJson(['success' => false, 'error' => 'Byes go through automatically; there is no score to enter.']);

    $homeId = (int)$m['home_team_id'];
    $awayId = (int)$m['away_team_id'];
    if (empty($_SESSION['admin_id'])) {
        $teamId = (int)($_SESSION['team_id'] ?? 0);
        if ($teamId !== $homeId && $teamId !== $awayId) {
            returnJson(['success' => false, 'error' => "You can only enter results for your own team's cup matches"]);
        }
    }

    // Each side's squad, so every player picked can be checked against it.
    $squads = [];
    $squadStmt = $pdo->prepare('SELECT player_id FROM players WHERE team_id = ?');
    foreach (['home' => $homeId, 'away' => $awayId] as $side => $tid) {
        $squadStmt->execute([$tid]);
        $squads[$side] = array_map('intval', $squadStmt->fetchAll(PDO::FETCH_COLUMN));
    }
    // Validates one side's line-up for a game and returns the player ids.
    $lineUp = function($ids, $side, $count, $label) use ($squads) {
        if (!is_array($ids) || count($ids) !== $count) {
            returnJson(['success' => false, 'error' => "$label: pick $count $side player" . ($count === 1 ? '' : 's') . '.']);
        }
        $ids = array_map('intval', array_values($ids));
        if (count(array_unique($ids)) !== $count) {
            returnJson(['success' => false, 'error' => "$label: the same $side player is picked twice."]);
        }
        foreach ($ids as $id) {
            if (!in_array($id, $squads[$side], true)) {
                returnJson(['success' => false, 'error' => "$label: a $side player isn't in that team's squad."]);
            }
        }
        return $ids;
    };

    // Trebles and singles. Only the games actually played are sent, each
    // with its game number, because play can stop once a side has 5.
    $legsToWin = $m['division'] === 'premier' ? 3 : 2;
    $games = [];    // [type, game_no, home legs, away legs, home ids, away ids]
    $homeWins = 0;
    $awayWins = 0;
    foreach ([['trebles', 2, 3, 'Trebles'], ['singles', 6, 1, 'Singles']] as [$type, $maxGames, $perSide, $name]) {
        $rows = isset($d[$type]) && is_array($d[$type]) ? $d[$type] : [];
        $seenNos = [];
        $used = ['home' => [], 'away' => []];
        foreach ($rows as $g) {
            $no = isset($g['game_no']) ? (int)$g['game_no'] : 0;
            if ($no < 1 || $no > $maxGames || in_array($no, $seenNos, true)) {
                returnJson(['success' => false, 'error' => "$name: invalid game number."]);
            }
            $seenNos[] = $no;
            $label = "$name game $no";
            $homeIds = $lineUp($g['home'] ?? null, 'home', $perSide, $label);
            $awayIds = $lineUp($g['away'] ?? null, 'away', $perSide, $label);
            foreach (['home' => $homeIds, 'away' => $awayIds] as $side => $ids) {
                if (array_intersect($ids, $used[$side])) {
                    returnJson(['success' => false, 'error' => "$label: a player can only play in one $type game."]);
                }
                $used[$side] = array_merge($used[$side], $ids);
            }
            $parts = explode('-', (string)($g['score'] ?? ''));
            $homeLegs = count($parts) === 2 ? (int)$parts[0] : -1;
            $awayLegs = count($parts) === 2 ? (int)$parts[1] : -1;
            if ($homeLegs < 0 || $awayLegs < 0 || max($homeLegs, $awayLegs) !== $legsToWin || min($homeLegs, $awayLegs) >= $legsToWin) {
                returnJson(['success' => false, 'error' => "$label: pick a valid score."]);
            }
            if ($homeLegs > $awayLegs) $homeWins++; else $awayWins++;
            $games[] = [$type, $no, $homeLegs, $awayLegs, $homeIds, $awayIds];
        }
    }

    // 1001 team game: only at 4-4 after all 8 games, and then required.
    $decider = $d['team1001'] ?? null;
    if (count($games) === 8 && $homeWins === 4 && $awayWins === 4) {
        if (!is_array($decider)) {
            returnJson(['success' => false, 'error' => "It's 4-4, so enter the 1001 team game to decide the match."]);
        }
        $homeIds = $lineUp($decider['home'] ?? null, 'home', 6, '1001 team game');
        $awayIds = $lineUp($decider['away'] ?? null, 'away', 6, '1001 team game');
        $winner = $decider['winner'] ?? '';
        if ($winner !== 'home' && $winner !== 'away') {
            returnJson(['success' => false, 'error' => '1001 team game: pick the winning side.']);
        }
        if ($winner === 'home') $homeWins++; else $awayWins++;
        $games[] = ['team1001', 1, $winner === 'home' ? 1 : 0, $winner === 'away' ? 1 : 0, $homeIds, $awayIds];
    } elseif (!empty($decider)) {
        returnJson(['success' => false, 'error' => 'The 1001 team game is only played when the trebles and singles finish 4-4.']);
    }
    if (max($homeWins, $awayWins) < 5) {
        returnJson(['success' => false, 'error' => "Not finished yet: it's $homeWins-$awayWins and a team needs 5 games to win. Enter the remaining games."]);
    }

    // 180s and high finishes, by players from either team.
    $everyone = array_merge($squads['home'], $squads['away']);
    $oneEighties = [];
    foreach ((isset($d['oneEighties']) && is_array($d['oneEighties']) ? $d['oneEighties'] : []) as $o) {
        $pid = (int)($o['player_id'] ?? 0);
        $count = (int)($o['count'] ?? 0);
        if (!in_array($pid, $everyone, true) || $count < 1) {
            returnJson(['success' => false, 'error' => '180s: pick a player from either team and a count of at least 1.']);
        }
        $oneEighties[] = [$pid, $count];
    }
    $highFinishes = [];
    foreach ((isset($d['highFinishes']) && is_array($d['highFinishes']) ? $d['highFinishes'] : []) as $f) {
        $pid = (int)($f['player_id'] ?? 0);
        $value = (int)($f['value'] ?? 0);
        if (!in_array($pid, $everyone, true) || $value < 100 || $value > 170) {
            returnJson(['success' => false, 'error' => 'High finishes: pick a player from either team and a finish from 100 to 170.']);
        }
        $highFinishes[] = [$pid, $value];
    }

    // Correcting a result may change the winner only while the next round
    // match is unplayed; advanceCupWinner then swaps the team in that slot.
    $newWinner = $homeWins > $awayWins ? $homeId : $awayId;
    $oldWinner = $m['status'] === 'completed' ? cupMatchWinner($m) : null;
    if ($oldWinner !== null && $oldWinner !== $newWinner && !empty($m['next_match_id'])) {
        $nextStmt = $pdo->prepare('SELECT status FROM matches WHERE match_id = ?');
        $nextStmt->execute([(int)$m['next_match_id']]);
        if ($nextStmt->fetchColumn() === 'completed') {
            returnJson(['success' => false, 'error' => "The winner can't change now: the next round match has already been played. Delete that result first."]);
        }
    }

    try {
        $pdo->beginTransaction();
        // Replace any earlier entry for this match.
        foreach (['cup_games', 'one_eighties', 'high_finishes'] as $t) {
            $pdo->prepare("DELETE FROM $t WHERE match_id = ?")->execute([$matchId]);
        }
        $insGame   = $pdo->prepare('INSERT INTO cup_games (match_id, game_type, game_no, home_score, away_score) VALUES (?, ?, ?, ?, ?)');
        $insPlayer = $pdo->prepare('INSERT INTO cup_game_players (game_id, side, player_id) VALUES (?, ?, ?)');
        foreach ($games as [$type, $no, $homeLegs, $awayLegs, $homeIds, $awayIds]) {
            $insGame->execute([$matchId, $type, $no, $homeLegs, $awayLegs]);
            $gameId = (int)$pdo->lastInsertId();
            foreach ($homeIds as $pid) $insPlayer->execute([$gameId, 'home', $pid]);
            foreach ($awayIds as $pid) $insPlayer->execute([$gameId, 'away', $pid]);
        }
        $ins180 = $pdo->prepare('INSERT INTO one_eighties (match_id, player_id, count) VALUES (?, ?, ?)');
        foreach ($oneEighties as [$pid, $count]) $ins180->execute([$matchId, $pid, $count]);
        $insFinish = $pdo->prepare('INSERT INTO high_finishes (match_id, player_id, finish_value) VALUES (?, ?, ?)');
        foreach ($highFinishes as [$pid, $value]) $insFinish->execute([$matchId, $pid, $value]);

        // The first captain to submit is kept as the submitter on later edits.
        $pdo->prepare("UPDATE matches SET home_score = ?, away_score = ?, status = 'completed',
                       submitted_by_captain_id = COALESCE(submitted_by_captain_id, ?)
                       WHERE match_id = ?")
            ->execute([$homeWins, $awayWins, $_SESSION['captain_id'] ?? null, $matchId]);
        advanceCupWinner($matchId);
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        returnJson(['success' => false, 'error' => 'Failed to save cup result: ' . $e->getMessage()]);
    }

    $nextRound = null;
    if (!empty($m['next_match_id'])) {
        $roundStmt = $pdo->prepare('SELECT cup_round FROM matches WHERE match_id = ?');
        $roundStmt->execute([(int)$m['next_match_id']]);
        $nextRound = $roundStmt->fetchColumn() ?: null;
    }
    returnJson([
        'success'    => true,
        'match_id'   => $matchId,
        'home_score' => $homeWins,
        'away_score' => $awayWins,
        'winner'     => $newWinner === $homeId ? $m['home_team'] : $m['away_team'],
        'next_round' => $nextRound,
    ]);
}

// Deleting a cup result puts the match back to "Awaiting result" instead
// of removing it, because the match is a fixed slot in the bracket. The
// winner is taken back out of the next round, unless that match has
// already been played – its result must be deleted first. Returns an
// error message, or null on success.
function resetCupResult($m) {
    global $pdo;
    if (isCupBye($m)) return "Byes go through automatically and can't be deleted.";
    if ($m['status'] !== 'completed') return 'This cup match has no result to delete.';
    if (!empty($m['next_match_id'])) {
        $stmt = $pdo->prepare('SELECT status FROM matches WHERE match_id = ?');
        $stmt->execute([(int)$m['next_match_id']]);
        if ($stmt->fetchColumn() === 'completed') {
            return 'The next round match has already been played. Delete that result first.';
        }
    }
    $matchId = (int)$m['match_id'];
    try {
        $pdo->beginTransaction();
        $tables = ['singles_results', 'doubles_results', 'one_eighties', 'high_finishes'];
        if (schemaHas('cup_games')) $tables[] = 'cup_games';
        foreach ($tables as $t) {
            $pdo->prepare("DELETE FROM $t WHERE match_id = ?")->execute([$matchId]);
        }
        $pdo->prepare("UPDATE matches SET home_score = 0, away_score = 0, status = 'scheduled', submitted_by_captain_id = NULL WHERE match_id = ?")
            ->execute([$matchId]);
        if (!empty($m['next_match_id'])) {
            $col = $m['next_match_slot'] === 'away' ? 'away_team_id' : 'home_team_id';
            $pdo->prepare("UPDATE matches SET $col = NULL, status = 'pending' WHERE match_id = ?")
                ->execute([(int)$m['next_match_id']]);
        }
        $pdo->commit();
        return null;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return 'Failed to delete cup result: ' . $e->getMessage();
    }
}

// ---------------------- Forfeits & points adjustments ----------------------

function requireForfeitSchema() {
    if (!schemaHas('points_adjustments') || !schemaHas('matches', 'is_walkover')) {
        returnJson(['success' => false, 'error' => 'Database not upgraded yet: run db/2026-add-forfeits-adjustments.sql in phpMyAdmin first.']);
    }
}

// Public: adjustments for the league table footnotes, newest first.
function listPointsAdjustments() {
    global $pdo;
    if (!schemaHas('points_adjustments')) returnJson(['success' => true, 'adjustments' => []]);
    $division = isset($_GET['division']) ? $_GET['division'] : '';
    $sql = 'SELECT pa.adjustment_id, pa.team_id, t.team_name, t.division, pa.points, pa.reason,
                   pa.match_id, pa.created_at
            FROM points_adjustments pa
            JOIN teams t ON t.team_id = pa.team_id';
    $params = [];
    if (in_array($division, ['premier', 'a'], true)) { $sql .= ' WHERE t.division = ?'; $params[] = $division; }
    $sql .= ' ORDER BY pa.created_at DESC, pa.adjustment_id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    returnJson(['success' => true, 'adjustments' => $stmt->fetchAll()]);
}

function adminAddAdjustment() {
    global $pdo;
    $adminId = requireAdmin();
    requireForfeitSchema();
    $d = readJsonBody();
    $teamId = (int)($d['team_id'] ?? 0);
    $points = (int)($d['points'] ?? 0);
    $reason = trim((string)($d['reason'] ?? ''));
    if ($teamId <= 0) returnJson(['success' => false, 'error' => 'Choose a team.']);
    if ($points === 0 || $points < -100 || $points > 100) returnJson(['success' => false, 'error' => 'Points must be a non-zero whole number between -100 and 100 (negative = deduction).']);
    if ($reason === '') returnJson(['success' => false, 'error' => 'A reason is required. It is shown under the league table.']);
    $exists = $pdo->prepare('SELECT COUNT(*) FROM teams WHERE team_id = ?');
    $exists->execute([$teamId]);
    if (!(int)$exists->fetchColumn()) returnJson(['success' => false, 'error' => 'Team not found.']);
    $pdo->prepare('INSERT INTO points_adjustments (team_id, points, reason, created_by_admin_id) VALUES (?, ?, ?, ?)')
        ->execute([$teamId, $points, clipReason($reason), $adminId]);
    returnJson(['success' => true, 'adjustment_id' => (int)$pdo->lastInsertId()]);
}

function adminDeleteAdjustment() {
    global $pdo;
    requireAdmin();
    requireForfeitSchema();
    $d = readJsonBody();
    $id = (int)($d['adjustment_id'] ?? 0);
    if ($id <= 0) returnJson(['success' => false, 'error' => 'adjustment_id required']);
    $pdo->prepare('DELETE FROM points_adjustments WHERE adjustment_id = ?')->execute([$id]);
    returnJson(['success' => true]);
}

// Record a forfeited league fixture. The match is stored completed with
// the awarded score (default 9-0) and NO singles/doubles/180/finish rows:
// the standings procedure turns the score into team points, while player
// rankings - built from singles_results - credit nobody. The forfeiting
// team's deduction (default 5) goes into points_adjustments, linked to
// this match, so it persists through every later standings rebuild.
function adminRecordWalkover() {
    global $pdo;
    $adminId = requireAdmin();
    requireForfeitSchema();
    $d = readJsonBody();
    $homeId    = (int)($d['home_team_id'] ?? 0);
    $awayId    = (int)($d['away_team_id'] ?? 0);
    $forfeitId = (int)($d['forfeit_team_id'] ?? 0);
    $date      = trim((string)($d['match_date'] ?? ''));
    $awarded   = isset($d['points_awarded']) ? (int)$d['points_awarded'] : 9;
    $deduction = isset($d['deduction']) ? (int)$d['deduction'] : 5;
    $note      = trim((string)($d['note'] ?? ''));

    if ($homeId <= 0 || $awayId <= 0 || $homeId === $awayId) returnJson(['success' => false, 'error' => 'Pick two different teams.']);
    if ($forfeitId !== $homeId && $forfeitId !== $awayId) returnJson(['success' => false, 'error' => 'The forfeiting team must be one of the two teams.']);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $mm) || !checkdate((int)$mm[2], (int)$mm[3], (int)$mm[1])) {
        returnJson(['success' => false, 'error' => 'Enter a valid match date.']);
    }
    if ($awarded < 1 || $awarded > 20) returnJson(['success' => false, 'error' => 'Points awarded must be between 1 and 20.']);
    if ($deduction < 0 || $deduction > 50) returnJson(['success' => false, 'error' => 'Deduction must be between 0 and 50.']);

    $t = $pdo->prepare('SELECT team_id, team_name, division FROM teams WHERE team_id IN (?, ?)');
    $t->execute([$homeId, $awayId]);
    $teams = [];
    foreach ($t->fetchAll() as $row) $teams[(int)$row['team_id']] = $row;
    if (count($teams) !== 2) returnJson(['success' => false, 'error' => 'One or both teams were not found.']);
    if ($teams[$homeId]['division'] !== $teams[$awayId]['division']) returnJson(['success' => false, 'error' => 'Both teams must be in the same division.']);
    $division = $teams[$homeId]['division'];

    // Refuse a second result for the same fixture. Captain cards are dated
    // the day they were submitted, not the fixture date, so look a week
    // either side rather than at the exact date.
    $dup = $pdo->prepare("SELECT match_date, home_score, away_score FROM matches
        WHERE match_type = 'league' AND ABS(DATEDIFF(match_date, ?)) <= 7
          AND ((home_team_id = ? AND away_team_id = ?) OR (home_team_id = ? AND away_team_id = ?))
        ORDER BY match_date LIMIT 1");
    $dup->execute([$date, $homeId, $awayId, $awayId, $homeId]);
    if ($existing = $dup->fetch()) {
        returnJson(['success' => false, 'error' => 'A league result between these teams already exists dated '
            . date('j M Y', strtotime($existing['match_date'])) . ' (' . $existing['home_score'] . '-' . $existing['away_score']
            . '). Delete it first from Match Results if it is wrong.']);
    }

    $homeScore = ($forfeitId === $awayId) ? $awarded : 0;
    $awayScore = ($forfeitId === $homeId) ? $awarded : 0;
    $opponent  = $teams[$forfeitId === $homeId ? $awayId : $homeId]['team_name'];

    try {
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO matches (home_team_id, away_team_id, match_date, match_type, division,
                                            home_score, away_score, status, is_walkover, forfeit_team_id)
                       VALUES (?, ?, ?, 'league', ?, ?, ?, 'completed', 1, ?)")
            ->execute([$homeId, $awayId, $date, $division, $homeScore, $awayScore, $forfeitId]);
        $matchId = (int)$pdo->lastInsertId();
        if ($deduction > 0) {
            $reason = 'Forfeited league match vs ' . $opponent . ' (' . date('j M Y', strtotime($date)) . ')'
                    . ($note !== '' ? ': ' . $note : '');
            $pdo->prepare('INSERT INTO points_adjustments (team_id, points, reason, match_id, created_by_admin_id) VALUES (?, ?, ?, ?, ?)')
                ->execute([$forfeitId, -$deduction, clipReason($reason), $matchId, $adminId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        returnJson(['success' => false, 'error' => 'Failed to record forfeit: ' . $e->getMessage()]);
    }
    refreshLeagueStandings();
    returnJson(['success' => true, 'match_id' => $matchId]);
}
?>