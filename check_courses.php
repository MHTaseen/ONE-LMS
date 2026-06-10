<?php
require 'config.php';
$cols = $pdo->query('DESCRIBE courses')->fetchAll(PDO::FETCH_ASSOC);
foreach($cols as $c) {
    echo $c['Field'].' '.$c['Type'].' '.$c['Null']."\n";
}
