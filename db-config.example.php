<?php
// Database credentials for api.php.
//
// Copy this file to db-config.php and fill in the real values. Never commit
// db-config.php: the GitHub repo is public, and .gitignore already lists it.
//
// api.php uses the first db-config.php it finds:
//   1. one level above the web root (best: the web server can't serve it)
//   2. next to api.php
//
// Keep each value in single quotes. If the password contains ' or \, put a
// backslash in front of that character.
return [
    'host' => 'localhost',
    'name' => 'your_database_name',
    'user' => 'your_database_user',
    'pass' => 'your_database_password',
];
