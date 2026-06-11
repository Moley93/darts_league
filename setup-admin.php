<?php
// =====================================================================
// setup-admin.php
// One-time setup script. Creates the initial admin account in the
// `admins` table. Self-disables once an admin already exists.
//
// USAGE:
//   1. Import db/fresh-schema.sql via phpMyAdmin.
//   2. Visit https://your-domain/setup-admin.php in a browser.
//   3. Note the credentials shown, log in, then DELETE this file
//      from your host (this script will refuse to seed twice but
//      leaving it on a live server is still bad hygiene).
// =====================================================================

ini_set('display_errors', 1);
error_reporting(E_ALL);

// Database configuration – must match api.php
$db_host = 'localhost';
$db_name = 'darts_league';
$db_user = 'darts_league';
$db_pass = 'M0l3y1993#cdl';

$default_username = 'admin';
$default_password = 'changeme123';

header('Content-Type: text/html; charset=utf-8');

echo '<!doctype html><html><head><meta charset="utf-8"><title>CDL Admin Setup</title>';
echo '<style>body{font-family:Segoe UI,Tahoma,sans-serif;max-width:640px;margin:60px auto;padding:0 20px;color:#222;line-height:1.6}';
echo 'h1{color:#1a237e}.box{background:#f5f5f5;border-left:4px solid #1a237e;padding:15px 20px;border-radius:4px;margin:20px 0}';
echo '.ok{border-left-color:#2e7d32;background:#e8f5e9}.warn{border-left-color:#c79100;background:#fffaf0}';
echo '.err{border-left-color:#c62828;background:#ffebee}code{background:#fff;padding:2px 6px;border-radius:3px;border:1px solid #ddd}';
echo '</style></head><body>';
echo '<h1>Crawley Darts League – Admin Setup</h1>';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    echo '<div class="box err"><strong>Database connection failed.</strong><br>' . htmlspecialchars($e->getMessage()) . '</div>';
    echo '</body></html>';
    exit;
}

// Verify the admins table exists (i.e. fresh-schema.sql has been imported)
try {
    $pdo->query('SELECT 1 FROM admins LIMIT 0');
} catch (PDOException $e) {
    echo '<div class="box err"><strong>The <code>admins</code> table does not exist.</strong><br>';
    echo 'Import <code>db/fresh-schema.sql</code> via phpMyAdmin first, then refresh this page.</div>';
    echo '</body></html>';
    exit;
}

// If an admin already exists, refuse to seed again
$count = (int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
if ($count > 0) {
    echo '<div class="box warn"><strong>An admin account already exists.</strong><br>';
    echo 'This script will not seed a second one. Log in via <a href="admin-login.html">admin-login.html</a> with your existing credentials.<br><br>';
    echo '<strong>For security, delete this file (<code>setup-admin.php</code>) from your host.</strong></div>';
    echo '</body></html>';
    exit;
}

// Seed the default admin
$hash = password_hash($default_password, PASSWORD_BCRYPT);
$stmt = $pdo->prepare('INSERT INTO admins (username, password_hash) VALUES (?, ?)');
$stmt->execute([$default_username, $hash]);

echo '<div class="box ok"><strong>Admin account created.</strong></div>';
echo '<p>Use these credentials to log in at <a href="admin-login.html">admin-login.html</a>:</p>';
echo '<div class="box"><strong>Username:</strong> <code>' . htmlspecialchars($default_username) . '</code><br>';
echo '<strong>Password:</strong> <code>' . htmlspecialchars($default_password) . '</code></div>';
echo '<div class="box warn"><strong>Next steps:</strong><ol>';
echo '<li>Log in and change the password from the admin dashboard.</li>';
echo '<li><strong>Delete <code>setup-admin.php</code> from your host.</strong> This script refuses to re-seed, but it should not stay deployed.</li>';
echo '<li>Add your teams and player rosters from the admin dashboard.</li>';
echo '</ol></div>';
echo '</body></html>';
