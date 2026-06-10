<?php
require_once 'config.php';
echo "USERS TABLE:\n";
foreach($pdo->query("DESCRIBE users")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo $r['Field'] . " (" . $r['Type'] . ")\n";
}
echo "\nCOURSES TABLE:\n";
foreach($pdo->query("DESCRIBE courses")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo $r['Field'] . " (" . $r['Type'] . ")\n";
}
echo "\nSample user data:\n";
$rows = $pdo->query("SELECT * FROM users LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
foreach($rows as $r) print_r($r);
