<?php

require_once __DIR__ . '/config.php';

$db = db();

$result = $db->query("DESCRIBE appointments");

echo "<h1>Appointments Table</h1>";
echo "<table border='1' cellpadding='8'>";
echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";

while ($row = $result->fetch_assoc()) {
    echo "<tr>";
    echo "<td>" . htmlspecialchars($row['Field']) . "</td>";
    echo "<td>" . htmlspecialchars($row['Type']) . "</td>";
    echo "<td>" . htmlspecialchars($row['Null']) . "</td>";
    echo "<td>" . htmlspecialchars($row['Key']) . "</td>";
    echo "<td>" . htmlspecialchars((string)$row['Default']) . "</td>";
    echo "</tr>";
}

echo "</table>";