<?php
require_once __DIR__ . '/config.php';
header('Content-Type: text/html; charset=utf-8');
$message = '';
$error = '';
try {
    $root = new mysqli($DB_HOST, $DB_USER, $DB_PASS, '', $DB_PORT);
    $root->set_charset('utf8mb4');
    $root->query("CREATE DATABASE IF NOT EXISTS `medivault` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $root->close();
    $db = db();
    $sql = file_get_contents(__DIR__ . '/database/medivault.sql');
    $sql = preg_replace('/--.*$/m', '', $sql);
    $statements = array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $sql)));
    foreach ($statements as $statement) {
        if ($statement === '' || str_starts_with($statement, '--')) continue;
        $db->query($statement);
    }
    $message = 'MediVault database created successfully. You can now use the application.';
} catch (Throwable $e) {
    $error = $e->getMessage();
}
?>
<!doctype html><html><head><meta charset="utf-8"><title>MediVault Setup</title><link rel="stylesheet" href="styles.css"></head><body><main class="setup-page"><div class="setup-card"><div class="auth-logo">♥</div><h1>MediVault Database Setup</h1><?php if($message): ?><div class="form-message success"><?=htmlspecialchars($message)?></div><a class="btn btn-primary" href="index.php">Open MediVault</a><?php else: ?><div class="form-message error">Setup could not be completed: <?=htmlspecialchars($error)?></div><p>Check that MySQL80 is running and the database credentials in <code>config.php</code> are correct.</p><?php endif; ?></div></main></body></html>
