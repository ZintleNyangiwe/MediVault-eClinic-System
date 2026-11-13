<?php
declare(strict_types=1);
$config = require __DIR__ . '/config.php';
$dsn = "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}";
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
try { $pdo = new PDO($dsn, $config['username'], $config['password'], $options); }
catch (PDOException $e) { http_response_code(500); die('Database connection failed. Check config.php and MySQL.'); }
