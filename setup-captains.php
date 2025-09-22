<?php
// setup-captains.php - Script to create team captain accounts with hashed passwords

// Database configuration
$db_host = 'localhost';
$db_name = 'darts_league';
$db_user = 'darts_league';  // Update with your MySQL username
$db_pass = 'M0l3y1993#cdl';   // Update with your MySQL password

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// List of team captains to create
$captains = [
    ['team_id' => 1, 'username' => 'jclub', 'password' => 'tokyo1'],
    ['team_id' => 2, 'username' => 'darks', 'password' => 'paris2'],
    ['team_id' => 3, 'username' => 'rebel', 'password' => 'sydney3'],
    ['team_id' => 4, 'username' => 'grass', 'password' => 'toronto4'],
    ['team_id' => 5, 'username' => 'dartb', 'password' => 'dubai5'],
    ['team_id' => 6, 'username' => 'unbea', 'password' => 'rome6'],
    ['team_id' => 7, 'username' => 'rugby', 'password' => 'berlin7'],
    ['team_id' => 8, 'username' => 'premier_bye', 'password' => 'premierbye8'],  // Premier division bye
    ['team_id' => 9, 'username' => 'disap', 'password' => 'madrid9'],
    ['team_id' => 10, 'username' => 'ewarr', 'password' => 'chicago10'],
    ['team_id' => 11, 'username' => 'ewwoo', 'password' => 'moscow11'],
    ['team_id' => 12, 'username' => 'wonky', 'password' => 'istanbul12'],
    ['team_id' => 13, 'username' => 'dingl', 'password' => 'lisbon13'],
    ['team_id' => 14, 'username' => 'apv', 'password' => 'athens14'],
    ['team_id' => 15, 'username' => 'pelha', 'password' => 'nairobi15'],
    ['team_id' => 16, 'username' => 'drsam', 'password' => 'jakarta16']  // Division A bye
];

// Clear existing captains
$pdo->exec("DELETE FROM team_captains");

// Insert new captains with hashed passwords
$stmt = $pdo->prepare("INSERT INTO team_captains (team_id, username, password_hash) VALUES (?, ?, ?)");

foreach ($captains as $captain) {
    try {
        $hashedPassword = password_hash($captain['password'], PASSWORD_DEFAULT);
        $stmt->execute([$captain['team_id'], $captain['username'], $hashedPassword]);
        echo "Created captain: " . $captain['username'] . "\n";
    } catch (PDOException $e) {
        echo "Failed to create captain " . $captain['username'] . ": " . $e->getMessage() . "\n";
    }
}

echo "All captains have been processed!\n";
?>