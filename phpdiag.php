<?php
// phpdiag.php — minimal diagnostic. Visit this URL in a browser to
// confirm PHP itself is alive and to verify api.php is parseable on
// the host. Delete after you're done.

// Force errors to display in this one file so we can see what's wrong.
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

header('Content-Type: text/plain; charset=utf-8');

echo "phpdiag.php — sanity check\n";
echo "==========================\n\n";

echo "PHP version : " . PHP_VERSION . "\n";
echo "SAPI        : " . PHP_SAPI . "\n";
echo "OS          : " . PHP_OS . "\n";
echo "Memory limit: " . ini_get('memory_limit') . "\n";
echo "Max post    : " . ini_get('post_max_size') . "\n";
echo "Server time : " . date('Y-m-d H:i:s') . "\n";
echo "\n";

echo "Required extensions\n";
echo "-------------------\n";
foreach (['pdo', 'pdo_mysql', 'json', 'session', 'mbstring'] as $ext) {
    echo str_pad($ext, 12) . ': ' . (extension_loaded($ext) ? 'loaded' : 'MISSING') . "\n";
}
echo "\n";

echo "Syntax check on api.php\n";
echo "-----------------------\n";
$apiPath = __DIR__ . DIRECTORY_SEPARATOR . 'api.php';
if (!file_exists($apiPath)) {
    echo "api.php NOT FOUND at $apiPath\n";
} else {
    echo "Path        : $apiPath\n";
    echo "Size        : " . filesize($apiPath) . " bytes\n";
    echo "Readable    : " . (is_readable($apiPath) ? 'yes' : 'NO') . "\n";

    // Use php -l via shell if available, else fall back to a lint via
    // include inside a sandbox-ish check. The cheapest reliable check
    // is php_check_syntax (removed in PHP 7), so we use the CLI if
    // available, otherwise attempt to detect a parse error by reading
    // the file and running token_get_all + a syntax-only attempt via
    // create_function (also removed in 8). The least invasive thing we
    // can do here is just include the file in a try/catch and report
    // exceptions; for a pure parse error PHP will produce a fatal that
    // halts THIS script — see the shutdown function below.

    // Try the CLI linter if available.
    $phpBin = defined('PHP_BINARY') ? PHP_BINARY : 'php';
    if (function_exists('shell_exec')) {
        $cmd = escapeshellarg($phpBin) . ' -l ' . escapeshellarg($apiPath) . ' 2>&1';
        $out = @shell_exec($cmd);
        if ($out !== null) {
            echo "Lint output : " . trim($out) . "\n";
        } else {
            echo "Lint output : (shell_exec returned null)\n";
        }
    } else {
        echo "Lint output : shell_exec disabled\n";
    }
}
echo "\n";

echo "Database connection test\n";
echo "------------------------\n";
$db_host = 'localhost';
$db_name = 'darts_league';
$db_user = 'darts_league';
$db_pass = 'M0l3y1993#cdl';
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    echo "Connected   : yes\n";
    // Check the tables that admin login needs.
    foreach (['admins', 'team_captains', 'teams', 'players', 'matches', 'announcements'] as $tbl) {
        try {
            $c = (int)$pdo->query("SELECT COUNT(*) FROM `$tbl`")->fetchColumn();
            echo str_pad($tbl, 16) . ": exists, $c rows\n";
        } catch (PDOException $e) {
            echo str_pad($tbl, 16) . ": MISSING (" . $e->getMessage() . ")\n";
        }
    }
} catch (PDOException $e) {
    echo "Connected   : NO — " . $e->getMessage() . "\n";
}
echo "\n";

echo "Session test\n";
echo "------------\n";
echo "save_path   : " . (session_save_path() ?: '(default)') . "\n";
if (@session_start()) {
    echo "session_start: ok (id=" . session_id() . ")\n";
} else {
    echo "session_start: FAILED\n";
}

echo "\nDone.\n";
