<?php

require_once __DIR__ . '/config.php';

$db = db();

$result = $db->query("
    SELECT staff_id, employee_number, job_title, department
    FROM staff
    ORDER BY staff_id
");

echo "<h1>Available Staff</h1>";

echo "<table border='1' cellpadding='10'>";
echo "<tr>";
echo "<th>Staff ID</th>";
echo "<th>Employee Number</th>";
echo "<th>Job Title</th>";
echo "<th>Department</th>";
echo "</tr>";

while ($row = $result->fetch_assoc()) {
    echo "<tr>";
    echo "<td>" . htmlspecialchars($row['staff_id']) . "</td>";
    echo "<td>" . htmlspecialchars($row['employee_number']) . "</td>";
    echo "<td>" . htmlspecialchars($row['job_title']) . "</td>";
    echo "<td>" . htmlspecialchars($row['department']) . "</td>";
    echo "</tr>";
}

echo "</table>";