<?php
// api.php - Backend API for the Darts League website

// Ensure no output before JSON
ob_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Database configuration
$db_host = 'localhost';
$db_name = 'darts_league';
$db_user = 'root';  // Update with your MySQL username
$db_pass = '123';      // Update with your MySQL password

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    ob_end_clean();
    die(json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]));
}

// Handle OPTIONS request for CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_end_clean();
    exit(0);
}

// Parse request
$requestMethod = $_SERVER['REQUEST_METHOD'];
$endpoint = $_GET['endpoint'] ?? '';

// Clear any output buffers before responding
ob_end_clean();

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
    
    case 'login':
        if ($requestMethod === 'POST') {
            handleLogin();
        }
        break;
    
    case 'submit-match':
        if ($requestMethod === 'POST') {
            submitMatch();
        }
        break;
    
    default:
        echo json_encode(['error' => 'Invalid endpoint']);
        break;
}

function getTeams() {
    global $pdo;
    $division = $_GET['division'] ?? 'premier';
    
    $stmt = $pdo->prepare("SELECT * FROM teams WHERE division = ?");
    $stmt->execute([$division]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function getPlayers() {
    global $pdo;
    $teamId = $_GET['team_id'] ?? null;
    $teamName = $_GET['team_name'] ?? null;
    
    if ($teamId) {
        $stmt = $pdo->prepare("SELECT * FROM players WHERE team_id = ?");
        $stmt->execute([$teamId]);
    } elseif ($teamName) {
        $stmt = $pdo->prepare("SELECT p.* FROM players p JOIN teams t ON p.team_id = t.team_id WHERE t.team_name = ?");
        $stmt->execute([$teamName]);
    } else {
        $stmt = $pdo->query("SELECT p.*, t.team_name FROM players p JOIN teams t ON p.team_id = t.team_id");
    }
    
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function getLeagueTable() {
    global $pdo;
    $division = $_GET['division'] ?? 'premier';
    
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
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function getSinglesRanking() {
    global $pdo;
    $division = $_GET['division'] ?? 'premier';
    
    $stmt = $pdo->prepare("CALL calculate_player_rankings(?)");
    $stmt->execute([$division]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function getLeagueFixtures() {
    global $pdo;
    $division = $_GET['division'] ?? 'premier';
    
    $stmt = $pdo->prepare("
        SELECT 
            m.match_id,
            m.match_date,
            ht.team_name as home_team,
            at.team_name as away_team,
            m.status
        FROM matches m
        JOIN teams ht ON m.home_team_id = ht.team_id
        JOIN teams at ON m.away_team_id = at.team_id
        WHERE m.division = ? AND m.match_type = 'league' AND m.status = 'scheduled'
        ORDER BY m.match_date ASC
    ");
    
    $stmt->execute([$division]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function getLeagueResults() {
    global $pdo;
    $division = $_GET['division'] ?? 'premier';
    
    $stmt = $pdo->prepare("
        SELECT 
            m.match_id,
            m.match_date,
            ht.team_name as home_team,
            at.team_name as away_team,
            m.home_score,
            m.away_score,
            m.status
        FROM matches m
        JOIN teams ht ON m.home_team_id = ht.team_id
        JOIN teams at ON m.away_team_id = at.team_id
        WHERE m.division = ? AND m.match_type = 'league' AND m.status = 'completed'
        ORDER BY m.match_date DESC
    ");
    
    $stmt->execute([$division]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function getCupFixtures() {
    global $pdo;
    $division = $_GET['division'] ?? 'premier';
    
    $stmt = $pdo->prepare("
        SELECT 
            m.match_id,
            m.match_date,
            ht.team_name as home_team,
            at.team_name as away_team,
            m.status
        FROM matches m
        JOIN teams ht ON m.home_team_id = ht.team_id
        JOIN teams at ON m.away_team_id = at.team_id
        WHERE m.division = ? AND m.match_type = 'cup' AND m.status = 'scheduled'
        ORDER BY m.match_date ASC
    ");
    
    $stmt->execute([$division]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function getCupResults() {
    global $pdo;
    $division = $_GET['division'] ?? 'premier';
    
    $stmt = $pdo->prepare("
        SELECT 
            m.match_id,
            m.match_date,
            ht.team_name as home_team,
            at.team_name as away_team,
            m.home_score,
            m.away_score,
            m.status
        FROM matches m
        JOIN teams ht ON m.home_team_id = ht.team_id
        JOIN teams at ON m.away_team_id = at.team_id
        WHERE m.division = ? AND m.match_type = 'cup' AND m.status = 'completed'
        ORDER BY m.match_date DESC
    ");
    
    $stmt->execute([$division]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function getOneEighties() {
    global $pdo;
    $division = $_GET['division'] ?? 'premier';
    
    $stmt = $pdo->prepare("
        SELECT 
            p.player_name,
            t.team_name,
            SUM(o.count) as total_180s
        FROM one_eighties o
        JOIN players p ON o.player_id = p.player_id
        JOIN teams t ON p.team_id = t.team_id
        WHERE t.division = ?
        GROUP BY p.player_id, p.player_name, t.team_name
        ORDER BY total_180s DESC
    ");
    
    $stmt->execute([$division]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function getHighFinishes() {
    global $pdo;
    $division = $_GET['division'] ?? 'premier';
    
    $stmt = $pdo->prepare("
        SELECT 
            p.player_name,
            t.team_name,
            h.finish_value,
            h.created_at
        FROM high_finishes h
        JOIN players p ON h.player_id = p.player_id
        JOIN teams t ON p.team_id = t.team_id
        WHERE t.division = ?
        ORDER BY h.finish_value DESC, h.created_at DESC
    ");
    
    $stmt->execute([$division]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function handleLogin() {
    global $pdo;
    $data = json_decode(file_get_contents('php://input'), true);
    $username = $data['username'] ?? '';
    $password = $data['password'] ?? '';
    
    $stmt = $pdo->prepare("
        SELECT tc.*, t.team_name 
        FROM team_captains tc 
        JOIN teams t ON tc.team_id = t.team_id 
        WHERE tc.username = ?
    ");
    $stmt->execute([$username]);
    $captain = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($captain && password_verify($password, $captain['password_hash'])) {
        // In a production environment, you'd start a session here
        unset($captain['password_hash']);
        echo json_encode(['success' => true, 'captain' => $captain]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid credentials']);
    }
}

function submitMatch() {
    global $pdo;
    $transactionStarted = false;
    
    try {
        // Get raw POST data
        $rawData = file_get_contents('php://input');
        
        // Log the raw data for debugging (remove in production)
        error_log("Raw data received: " . $rawData);
        
        $data = json_decode($rawData, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo json_encode(['success' => false, 'error' => 'Invalid JSON data: ' . json_last_error_msg()]);
            return;
        }
        
        if (!$data) {
            echo json_encode(['success' => false, 'error' => 'No data received']);
            return;
        }
        
        // Validate required fields
        if (!isset($data['homeTeam']) || !isset($data['awayTeam'])) {
            echo json_encode(['success' => false, 'error' => 'Missing required fields: homeTeam or awayTeam']);
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
        $matchId = $data['matchId'] ?? null;
        
        if ($matchId) {
            $stmt = $pdo->prepare("
                UPDATE matches 
                SET home_score = 0, away_score = 0, status = 'completed' 
                WHERE match_id = ?
            ");
            $stmt->execute([$matchId]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO matches (home_team_id, away_team_id, match_type, division, status, match_date) 
                VALUES (?, ?, ?, ?, 'completed', NOW())
            ");
            $stmt->execute([$homeTeamId, $awayTeamId, $data['matchType'], $data['division']]);
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
        
        echo json_encode(['success' => true, 'matchId' => $matchId]);
    } catch (Exception $e) {
        // Only rollback if we started a transaction
        if ($transactionStarted && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error in submitMatch: " . $e->getMessage());
        error_log("Error trace: " . $e->getTraceAsString());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
?>