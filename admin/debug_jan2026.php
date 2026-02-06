<?php
session_start();
require_once '../config.php';
require_once '../functions.php';

if (!isset($_SESSION['godmode_user_role']) || !in_array($_SESSION['godmode_user_role'], ['admin', 'superadmin'])) {
    die('Acesso negado');
}

$db = Database::getConnection();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Debug Janeiro 2026</title>
    <style>
        body { font-family: monospace; background: #1e1e1e; color: #00ff00; padding: 20px; }
        .section { background: #2d2d2d; padding: 15px; margin: 10px 0; border-left: 4px solid #00ff00; }
        .error { color: #ff4444; }
        .success { color: #00ff00; }
        .warning { color: #ffaa00; }
        table { border-collapse: collapse; width: 100%; margin: 10px 0; }
        th, td { border: 1px solid #444; padding: 8px; text-align: left; }
        th { background: #333; }
        .highlight { background: #ffff00; color: #000; font-weight: bold; }
    </style>
</head>
<body>
<h1>🔍 DEBUG ESPECÍFICO - JANEIRO 2026</h1>

<?php
echo "<div class='section'>";
echo "<h2>1️⃣ Buscando comissão de 2026-01 no banco</h2>";

$sql = "SELECT * FROM promoter_commission_history WHERE month_reference = '2026-01'";
echo "<p><strong>Query:</strong> $sql</p>";

$result = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

if (empty($result)) {
    echo "<p class='error'>❌ NENHUM registro encontrado para 2026-01!</p>";
} else {
    echo "<p class='success'>✅ Encontrados " . count($result) . " registro(s):</p>";
    echo "<table>";
    echo "<tr><th>ID</th><th>Promotor</th><th>Mês</th><th>Tipo</th><th>Valor</th><th>Criado em</th></tr>";
    foreach ($result as $row) {
        $promoter_display = ($row['promoter_name'] === '__ALL__') ? '<span class="highlight">__ALL__ (TODOS)</span>' : $row['promoter_name'];
        echo "<tr>";
        echo "<td>{$row['id']}</td>";
        echo "<td>$promoter_display</td>";
        echo "<td>{$row['month_reference']}</td>";
        echo "<td>{$row['commission_type']}</td>";
        echo "<td class='highlight'>{$row['commission_value']}%</td>";
        echo "<td>{$row['created_at']}</td>";
        echo "</tr>";
    }
    echo "</table>";
}
echo "</div>";

echo "<div class='section'>";
echo "<h2>2️⃣ Testando função getPromoterCommissionForMonth()</h2>";

$test_promoter = 'ERICA POLIANA GRILO DA SILVA';
echo "<p><strong>Promotor:</strong> $test_promoter</p>";
echo "<p><strong>Mês:</strong> 2026-01</p>";

$commission = getPromoterCommissionForMonth($test_promoter, '2026-01');

echo "<p><strong>Resultado:</strong></p>";
echo "<table>";
echo "<tr><th>Tipo</th><th>Valor</th><th>Fonte</th></tr>";
echo "<tr>";
echo "<td>{$commission['type']}</td>";
echo "<td class='" . ($commission['value'] == 25 ? 'error' : 'success') . "'>{$commission['value']}%</td>";
echo "<td>{$commission['source']}</td>";
echo "</tr>";
echo "</table>";

if ($commission['value'] == 25) {
    echo "<p class='error'>❌ PROBLEMA! Retornou 25% ao invés de 10%</p>";
} else if ($commission['value'] == 10) {
    echo "<p class='success'>✅ Correto! Retornou 10%</p>";
}
echo "</div>";

echo "<div class='section'>";
echo "<h2>3️⃣ Verificando formato do mês_referência no sales</h2>";

$sql = "SELECT DISTINCT month_reference FROM sales WHERE month_reference LIKE '2026-01%' LIMIT 5";
echo "<p><strong>Query:</strong> $sql</p>";

$months = $db->query($sql)->fetchAll(PDO::FETCH_COLUMN);

if (empty($months)) {
    echo "<p class='warning'>⚠️ Nenhuma venda encontrada para janeiro/2026</p>";
} else {
    echo "<p>Formatos de mês encontrados nas vendas:</p>";
    echo "<ul>";
    foreach ($months as $m) {
        echo "<li><code>$m</code></li>";
    }
    echo "</ul>";
}
echo "</div>";

echo "<div class='section'>";
echo "<h2>4️⃣ Calculando comissão manualmente</h2>";

$sql = "SELECT SUM(value) as total FROM sales WHERE promoter = ? AND month_reference = ?";
$stmt = $db->prepare($sql);
$stmt->execute([$test_promoter, '2026-01']);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
$total = $row['total'] ?? 0;

echo "<p><strong>Total de vendas:</strong> R$ " . number_format($total, 2, ',', '.') . "</p>";
echo "<p><strong>Comissão 10%:</strong> R$ " . number_format($total * 0.10, 2, ',', '.') . "</p>";
echo "<p><strong>Comissão 25%:</strong> R$ " . number_format($total * 0.25, 2, ',', '.') . "</p>";
echo "</div>";

echo "<div class='section'>";
echo "<h2>5️⃣ Verificando tabela promoter_accumulated_balance</h2>";

$sql = "SELECT * FROM promoter_accumulated_balance WHERE promoter_name = ? AND month = '2026-01'";
$stmt = $db->prepare($sql);
$stmt->execute([$test_promoter]);
$balance = $stmt->fetch(PDO::FETCH_ASSOC);

if ($balance) {
    echo "<p class='warning'>⚠️ Encontrado saldo acumulado (pode estar em CACHE!):</p>";
    echo "<table>";
    echo "<tr><th>Campo</th><th>Valor</th></tr>";
    foreach ($balance as $key => $value) {
        echo "<tr><td>$key</td><td>$value</td></tr>";
    }
    echo "</table>";
    echo "<p class='error'>🚨 ESTE PODE SER O PROBLEMA! O sistema pode estar usando valores em cache!</p>";
} else {
    echo "<p class='success'>✅ Nenhum cache encontrado para 2026-01</p>";
}
echo "</div>";

echo "<div class='section'>";
echo "<h2>6️⃣ TODOS os registros de comissões (para conferência)</h2>";

$sql = "SELECT * FROM promoter_commission_history ORDER BY month_reference DESC";
$all = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

echo "<p>Total: " . count($all) . " registros</p>";
echo "<table>";
echo "<tr><th>ID</th><th>Promotor</th><th>Mês</th><th>Tipo</th><th>Valor</th></tr>";
foreach ($all as $row) {
    $promoter_display = ($row['promoter_name'] === '__ALL__') ? '<span class="highlight">__ALL__</span>' : $row['promoter_name'];
    $highlight_class = ($row['month_reference'] === '2026-01') ? 'highlight' : '';
    echo "<tr class='$highlight_class'>";
    echo "<td>{$row['id']}</td>";
    echo "<td>$promoter_display</td>";
    echo "<td>{$row['month_reference']}</td>";
    echo "<td>{$row['commission_type']}</td>";
    echo "<td>{$row['commission_value']}</td>";
    echo "</tr>";
}
echo "</table>";
echo "</div>";
?>

<p><a href="../index.php?admin=1&godmode=on" style="color: #00ff00;">← Voltar</a></p>

</body>
</html>
