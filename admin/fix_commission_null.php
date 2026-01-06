<?php
/**
 * Correção: Substitui NULL por valor especial '__ALL__'
 *
 * Problema: UNIQUE KEY com NULL permite duplicatas no MySQL
 * Solução: Usar string '__ALL__' para representar "todos os consultores"
 */

require_once __DIR__ . '/../Database.php';

echo "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>Correção: Comissões Gerais</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #dc3545; border-bottom: 3px solid #dc3545; padding-bottom: 10px; }
        .alert { padding: 15px; margin: 15px 0; border-radius: 8px; }
        .alert-success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
        .alert-danger { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
        .btn { display: inline-block; padding: 10px 20px; background: #dc3545; color: white; text-decoration: none; border-radius: 5px; border: none; cursor: pointer; }
        .btn:hover { background: #c82333; }
        code { background: #2d2d2d; color: #d4d4d4; padding: 2px 6px; border-radius: 3px; }
    </style>
</head>
<body>
<div class='container'>";

echo "<h1>🔧 Correção: Problema com NULL em UNIQUE KEY</h1>";

try {
    $db = Database::getConnection();

    echo "<h2>❌ Problema Identificado:</h2>";
    echo "<p>MySQL permite múltiplos registros com <code>NULL</code> em UNIQUE KEYs, causando:</p>";
    echo "<ul>";
    echo "<li>Múltiplas entradas de <code>(NULL, '2025-12')</code> na tabela</li>";
    echo "<li>Busca retorna valor errado ou nenhum resultado</li>";
    echo "<li>Comissão configurada em 10% mas sistema usa 25%</li>";
    echo "</ul>";

    echo "<h2>✅ Solução:</h2>";
    echo "<p>Substituir <code>NULL</code> por valor especial <code>'__ALL__'</code></p>";

    if (isset($_POST['execute_fix'])) {
        echo "<h2>⚡ Executando Correção...</h2>";

        $db->beginTransaction();

        try {
            // Atualiza registros NULL para '__ALL__'
            echo "<p><strong>Passo 1:</strong> Atualizando registros NULL para '__ALL__'...</p>";
            $sql = "UPDATE promoter_commission_history SET promoter_name = '__ALL__' WHERE promoter_name IS NULL";
            $affected = $db->exec($sql);
            echo "<div class='alert alert-success'>✅ {$affected} registro(s) atualizado(s)!</div>";

            // Modifica coluna para NOT NULL com default
            echo "<p><strong>Passo 2:</strong> Modificando coluna para NOT NULL...</p>";
            $db->exec("ALTER TABLE promoter_commission_history MODIFY promoter_name VARCHAR(255) NOT NULL");
            echo "<div class='alert alert-success'>✅ Coluna modificada!</div>";

            $db->commit();

            echo "<div class='alert alert-success'>";
            echo "<h3>🎉 CORREÇÃO CONCLUÍDA!</h3>";
            echo "<p>✅ Agora as comissões gerais usam <code>'__ALL__'</code> ao invés de <code>NULL</code><br>";
            echo "✅ UNIQUE KEY funcionará corretamente<br>";
            echo "✅ Dezembro deve mostrar 10% agora!</p>";
            echo "<p><a href='../index.php?godmode=on' class='btn'>← Voltar ao Sistema</a></p>";
            echo "</div>";

        } catch (Exception $e) {
            $db->rollBack();
            echo "<div class='alert alert-danger'>";
            echo "<strong>❌ ERRO:</strong> " . htmlspecialchars($e->getMessage());
            echo "</div>";
        }

    } else {
        echo "<form method='POST'>";
        echo "<button type='submit' name='execute_fix' class='btn'>🔧 EXECUTAR CORREÇÃO</button>";
        echo " <a href='../ index.php?godmode=on' class='btn' style='background: #6c757d;'>← Cancelar</a>";
        echo "</form>";
    }

} catch (Exception $e) {
    echo "<div class='alert alert-danger'>";
    echo "<strong>❌ Erro:</strong> " . htmlspecialchars($e->getMessage());
    echo "</div>";
}

echo "</div></body></html>";
