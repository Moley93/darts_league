<?php
// setup-captains.php - Script to create team captain accounts with hashed passwords

// Database configuration
$db_host = 'localhost';
$db_name = 'darts_league';
$db_user = 'root';
$db_pass = '123';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// List of team captains to create
$captains = [
    ['team_id' => 1, 'username' => 'unbearables', 'password' => 'SunnyTree42'],
    ['team_id' => 2, 'username' => 'jubileeclub', 'password' => 'HappyFish91'],
    ['team_id' => 3, 'username' => 'rebels', 'password' => 'BlueMoon77'],
    ['team_id' => 4, 'username' => 'dsotm', 'password' => 'TinyRock88'],
    ['team_id' => 5, 'username' => 'dartbreakers', 'password' => 'FastCar19'],
    ['team_id' => 6, 'username' => 'rugbyclub', 'password' => 'LuckyDog25'],
    ['team_id' => 7, 'username' => 'ewhurstarrows', 'password' => 'MagicBook31'],
    ['team_id' => 8, 'username' => 'bye', 'password' => 'QuietSun53'],
    ['team_id' => 9, 'username' => 'grasshopper', 'password' => 'BrightStar64'],
    ['team_id' => 10, 'username' => 'dingles', 'password' => 'SweetCake22'],
    ['team_id' => 11, 'username' => 'jonhson', 'password' => 'CrazyCat14'],
    ['team_id' => 12, 'username' => 'disappointers', 'password' => 'FreshWind80'],
    ['team_id' => 13, 'username' => 'apv', 'password' => 'RedApple11'],
    ['team_id' => 14, 'username' => 'ewhurstwoodys', 'password' => 'LazyPanda07'],
    ['team_id' => 15, 'username' => 'pelham', 'password' => 'CoolRiver29'],
    ['team_id' => 16, 'username' => 'bye', 'password' => 'FunnyBee65']
];

// Clear existing captains
$pdo->exec("DELETE FROM team_captains");

// Insert new captains with hashed passwords
$stmt = $pdo->prepare("INSERT INTO team_captains (team_id, username, password_hash) VALUES (?, ?, ?)");

foreach ($captains as $captain) {
    $hashedPassword = password_hash($captain['password'], PASSWORD_DEFAULT);
    $stmt->execute([$captain['team_id'], $captain['username'], $hashedPassword]);
    echo "Created captain: " . $captain['username'] . "\n";
}

echo "All captains have been created successfully!\n";
?>