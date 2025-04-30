<?php
// api.php - Backend API for the Darts League website

// Ensure no output before JSON
ob_start();

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
$db_host = 'localhost';
$db_name = 'darts_league';
$db_user = 'root';  // Update with your MySQL username
$db_pass = '123';   // Update with your MySQL password

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
        getCupFixtures();
        break;
    
    case 'cup-results':
        getCupResults();
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
        returnJson($stmt->fetchAll());
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
        $stmt = $pdo->prepare("
            SELECT 
                t.team_name,
                ls.played,
                ls.won,
                ls.drawn,
                ls.lost,
                ls.games_for,
                ls.games_against,
                (ls.games_for - ls.games_against) as goal_diff,
                ls.points
            FROM league_standings ls
            JOIN teams t ON ls.team_id = t.team_id
            WHERE ls.division = ?
            ORDER BY ls.points DESC, goal_diff DESC, ls.games_for DESC
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
                    
                    -- Points calculation - Premier Division scoring (best of 5)
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
                LEFT JOIN singles_results sr ON p.player_id = sr.home_player_id OR p.player_id = sr.away_player_id
                LEFT JOIN matches m ON sr.match_id = m.match_id
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
                             
                    -- A Division - Detailed score breakdown - Losses (1-2, 0-2)
                    SUM(CASE WHEN sr.home_player_id = p.player_id AND sr.home_score = 1 AND sr.away_score = 2 THEN 1
                             WHEN sr.away_player_id = p.player_id AND sr.away_score = 1 AND sr.home_score = 2 THEN 1
                             ELSE 0 END) as loss_1_2,
                             
                    SUM(CASE WHEN sr.home_player_id = p.player_id AND sr.home_score = 0 AND sr.away_score = 2 THEN 1
                             WHEN sr.away_player_id = p.player_id AND sr.away_score = 0 AND sr.home_score = 2 THEN 1
                             ELSE 0 END) as loss_0_2,
                    
                    -- Points calculation - A Division scoring (best of 3)
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
                LEFT JOIN singles_results sr ON p.player_id = sr.home_player_id OR p.player_id = sr.away_player_id
                LEFT JOIN matches m ON sr.match_id = m.match_id
                WHERE t.division = ?
            ";
        }
        
        // Add match type filter if specified
        $params = [$division];
        if ($match_type) {
            $sql .= " AND m.match_type = ?";
            $params[] = $match_type;
        }
        
        // Complete the query with GROUP BY and ORDER BY
        $sql .= "
            GROUP BY p.player_id, p.player_name, t.team_name
            ORDER BY points DESC, won DESC
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
            FROM matches m
            LEFT JOIN teams ht ON m.home_team_id = ht.team_id
            LEFT JOIN teams at ON m.away_team_id = at.team_id
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
function getCupFixtures() {
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
        error_log("Error in getCupFixtures: " . $e->getMessage());
        returnJson(['success' => false, 'error' => 'Error fetching cup fixtures']);
    }
}

function getCupResults() {
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
        error_log("Error in getCupResults: " . $e->getMessage());
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
                   h.team_name as home_team, a.team_name as away_team, m.cup_round
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
        
        $result = [
            'success' => true,
            'match' => $match,
            'doubles' => $doubles,
            'singles' => $singles,
            'oneEighties' => $oneEighties,
            'highFinishes' => $highFinishes
        ];
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
        error_log("Login raw data: " . $rawData);
        
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
            returnJson(['success' => true, 'captain' => $captain]);
        } else {
            error_log("Password verification failed");
            returnJson(['success' => false, 'error' => 'Invalid credentials']);
        }
    } catch (Exception $e) {
        error_log("Login exception: " . $e->getMessage());
        returnJson(['success' => false, 'error' => 'Login error: ' . $e->getMessage()]);
    }
}

function submitMatch() {
    global $pdo;
    $transactionStarted = false;
    
    try {
        // Get raw POST data
        $rawData = file_get_contents('php://input');
        error_log("Submit match raw data: " . $rawData);
        
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
            if ($data['matchType'] === 'cup' && $cupRound) {
                $stmt = $pdo->prepare("
                    INSERT INTO matches (home_team_id, away_team_id, match_type, division, status, match_date, cup_round) 
                    VALUES (?, ?, ?, ?, 'completed', NOW(), ?)
                ");
                $stmt->execute([$homeTeamId, $awayTeamId, $data['matchType'], $data['division'], $cupRound]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO matches (home_team_id, away_team_id, match_type, division, status, match_date) 
                    VALUES (?, ?, ?, ?, 'completed', NOW())
                ");
                $stmt->execute([$homeTeamId, $awayTeamId, $data['matchType'], $data['division']]);
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
        
        // If it's a cup match, advance winner to next round
        if ($data['matchType'] === 'cup') {
            try {
                // First check if the stored procedure exists
                $stmt = $pdo->query("SHOW PROCEDURE STATUS WHERE Db = DATABASE() AND Name = 'advance_cup_winner'");
                if ($stmt->rowCount() > 0) {
                    $pdo->exec("CALL advance_cup_winner($matchId)");
                } else {
                    error_log("Stored procedure 'advance_cup_winner' not found");
                }
            } catch (Exception $e) {
                error_log("Error advancing cup winner: " . $e->getMessage());
                // Continue anyway - cup progression can be handled manually
            }
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
        error_log("Update match raw data: " . $rawData);
        
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
        
        // If it's a cup match, update cup progression
        if ($data['matchType'] === 'cup') {
            try {
                // First check if the stored procedure exists
                $stmt = $pdo->query("SHOW PROCEDURE STATUS WHERE Db = DATABASE() AND Name = 'advance_cup_winner'");
                if ($stmt->rowCount() > 0) {
                    $pdo->exec("CALL advance_cup_winner($matchId)");
                } else {
                    error_log("Stored procedure 'advance_cup_winner' not found");
                }
            } catch (Exception $e) {
                error_log("Error advancing cup winner: " . $e->getMessage());
                // Continue anyway - cup progression can be handled manually
            }
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

// Add this case to the endpoint switch statement in api.php
case 'delete-match':
    if ($requestMethod === 'POST') {
        deleteMatch();
    } else {
        returnJson(['success' => false, 'error' => 'Method not allowed for delete-match']);
    }
    break;

// Add this function with the rest of the API functions
function deleteMatch() {
    global $pdo;
    $transactionStarted = false;
    
    try {
        // Get raw POST data
        $rawData = file_get_contents('php://input');
        error_log("Delete match raw data: " . $rawData);
        
        $data = json_decode($rawData, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            returnJson(['success' => false, 'error' => 'Invalid JSON data: ' . json_last_error_msg()]);
            return;
        }
        
        // Validate required fields
        if (!isset($data['matchId'])) {
            returnJson(['success' => false, 'error' => 'Missing required field: matchId']);
            return;
        }
        
        $matchId = $data['matchId'];
        
        // Start transaction
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $transactionStarted = true;
        }
        
        // Check if match exists
        $checkStmt = $pdo->prepare("SELECT match_type, division FROM matches WHERE match_id = ?");
        $checkStmt->execute([$matchId]);
        $matchInfo = $checkStmt->fetch();
        
        if (!$matchInfo) {
            throw new Exception('Match not found: ' . $matchId);
        }
        
        // Delete related data first (foreign key constraints)
        $stmt = $pdo->prepare("DELETE FROM doubles_results WHERE match_id = ?");
        $stmt->execute([$matchId]);
        
        $stmt = $pdo->prepare("DELETE FROM singles_results WHERE match_id = ?");
        $stmt->execute([$matchId]);
        
        $stmt = $pdo->prepare("DELETE FROM one_eighties WHERE match_id = ?");
        $stmt->execute([$matchId]);
        
        $stmt = $pdo->prepare("DELETE FROM high_finishes WHERE match_id = ?");
        $stmt->execute([$matchId]);
        
        // Finally delete the match
        $stmt = $pdo->prepare("DELETE FROM matches WHERE match_id = ?");
        $stmt->execute([$matchId]);
        
        // Commit transaction
        if ($transactionStarted) {
            $pdo->commit();
        }
        
        // If it was a league match, update the league standings
        if ($matchInfo['match_type'] === 'league') {
            try {
                // First check if the stored procedure exists
                $stmt = $pdo->query("SHOW PROCEDURE STATUS WHERE Db = DATABASE() AND Name = 'update_league_standings'");
                if ($stmt->rowCount() > 0) {
                    $pdo->exec("CALL update_league_standings()");
                } else {
                    error_log("Stored procedure 'update_league_standings' not found");
                }
            } catch (Exception $e) {
                error_log("Error updating league standings after deletion: " . $e->getMessage());
            }
        }
        
        returnJson(['success' => true]);
    } catch (Exception $e) {
        // Only rollback if we started a transaction
        if ($transactionStarted && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error in deleteMatch: " . $e->getMessage());
        error_log("Error trace: " . $e->getTraceAsString());
        returnJson(['success' => false, 'error' => $e->getMessage()]);
    }
}
?>