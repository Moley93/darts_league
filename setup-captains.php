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
    ['team_id' => 1, 'username' => 'unbear', 'password' => 'unbear'],
    ['team_id' => 2, 'username' => 'jubile', 'password' => 'jubile'],
    ['team_id' => 3, 'username' => 'rebel', 'password' => 'rebel'],
    ['team_id' => 4, 'username' => 'dsotm', 'password' => 'dsotm'],
    ['team_id' => 5, 'username' => 'dartb', 'password' => 'dartb'],
    ['team_id' => 6, 'username' => 'rugby', 'password' => 'rugby'],
    ['team_id' => 7, 'username' => 'grass', 'password' => 'grass'],
    ['team_id' => 8, 'username' => 'premier_bye', 'password' => 'premierbye123'],  // Premier division bye
    ['team_id' => 9, 'username' => 'arrow', 'password' => 'arrows'],
    ['team_id' => 10, 'username' => 'dingl', 'password' => 'dingl'],
    ['team_id' => 11, 'username' => 'johns', 'password' => 'johns'],
    ['team_id' => 12, 'username' => 'disap', 'password' => 'disap'],
    ['team_id' => 13, 'username' => 'apv', 'password' => 'apv'],
    ['team_id' => 14, 'username' => 'woody', 'password' => 'woody'],
    ['team_id' => 15, 'username' => 'pelha', 'password' => 'pelha'],
    ['team_id' => 16, 'username' => 'division_a_bye', 'password' => 'abye123']  // Division A bye
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