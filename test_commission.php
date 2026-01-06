<?php
require_once 'Database.php';

$db = Database::getConnection();

// Verifica estrutura da tabela
echo "=== ESTRUTURA DA TABELA ===\n";
$result = $db->query("SHOW CREATE TABLE promoter_commission_history");
$row = $result->fetch(PDO::FETCH_ASSOC);
echo $row['Create Table'] . "\n\n";

// Verifica registros com NULL
echo "=== REGISTROS COM NULL (TODOS) ===\n";
$stmt = $db->query("SELECT * FROM promoter_commission_history WHERE promoter_name IS NULL ORDER BY month_reference DESC");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    print_r($row);
}

// Verifica dezembro especificamente
echo "\n=== REGISTROS DE DEZEMBRO (2025-12) ===\n";
$stmt = $db->query("SELECT * FROM promoter_commission_history WHERE month_reference = '2025-12'");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    print_r($row);
}
