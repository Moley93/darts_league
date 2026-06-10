<?php
// phpdiag2.php — second-pass diagnostic. Loads api.php inside a
// shutdown handler so even a true parse error (which kills api.php
// before any of its own handlers register) is captured and surfaced
// to the browser. Delete after diagnosis.

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

$ranToCompletion = false;

// Register the shutdown handler BEFORE loading api.php so it survives
// a parse error inside api.php. If a fatal error occurred, force a
// text/plain content type and print the error details.
register_shutdown_function(function() use (&$ranToCompletion) {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
        // Headers may already have been sent; do the best we can.
        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo "\n\n=========== FATAL ERROR DURING LOAD OF api.php ===========\n";
        $names = [
            E_ERROR             => 'E_ERROR',
            E_PARSE             => 'E_PARSE',
            E_CORE_ERROR        => 'E_CORE_ERROR',
            E_COMPILE_ERROR     => 'E_COMPILE_ERROR',
            E_USER_ERROR        => 'E_USER_ERROR',
            E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
        ];
        $type = $names[$err['type']] ?? ('TYPE_' . $err['type']);
        echo "Type   : $type\n";
        echo "Message: " . $err['message'] . "\n";
        echo "File   : " . $err['file'] . "\n";
        echo "Line   : " . $err['line'] . "\n";
        echo "==========================================================\n";
        return;
    }
    if (!$ranToCompletion) {
        // Reached shutdown without a fatal, but our script didn't reach
        // the "ran to completion" line — most likely api.php called
        // exit() (e.g. from returnJson). That's fine; the output above
        // should show what it sent.
    }
});

header('Content-Type: text/plain; charset=utf-8');

echo "phpdiag2.php — load test for api.php\n";
echo "====================================\n\n";

// Force a clearly non-existent endpoint so api.php's switch falls
// through to the 'Invalid endpoint' default rather than running any
// real handler.
$_GET['endpoint'] = '__diag_nonexistent__';
$_SERVER['REQUEST_METHOD'] = 'GET';

$apiPath = __DIR__ . DIRECTORY_SEPARATOR . 'api.php';
echo "Path : $apiPath\n";
echo "Size : " . (file_exists($apiPath) ? filesize($apiPath) : 'not found') . " bytes\n\n";
echo "----- api.php raw output below -----\n";

// Load and run api.php. If it has a parse error, this line never
// returns and the shutdown handler will print the parse error info.
// If it runs cleanly with the bogus endpoint, returnJson() will emit
// {"success":false,"error":"Invalid endpoint"} and call exit().
include $apiPath;

// If we reach here, api.php returned (didn't call exit) — surprising
// because every endpoint path ultimately exits. Note it for the log.
echo "\n----- api.php returned without calling exit -----\n";
$ranToCompletion = true;
