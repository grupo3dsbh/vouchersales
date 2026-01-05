<?php
/**
 * Migração: Permite comissões gerais por mês (promoter_name = NULL)
 *
 * Antes: Apenas comissão por (promotor + mês)
 * Depois: Comissão por mês (todos) OU por (promotor + mês específico)
 *
 * ACESSE: /admin/migrate_commission_defaults.php
 */

require_once __DIR__ . '/../Database.php';

echo "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>Migração: Comissões Gerais por Mês</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #28a745; border-bottom: 3px solid #28a745; padding-bottom: 10px; }
        .alert { padding: 15px; margin: 15px 0; border-radius: 8px; }
        .alert-success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
        .alert-danger { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
        .alert-info { background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; }
        .btn { display: inline-block; padding: 10px 20px; background: #28a745; color: white; text-decoration: none; border-radius: 5px; border: none; cursor: pointer; }
        .btn:hover { background: #218838; }
        code { background: #2d2d2d; color: #d4d4d4; padding: 2px 6px; border-radius: 3px; font-family: monospace; }
    </style>
</head>
<body>
<div class='container'>";

echo "<h1>📅 Migração: Comissões Gerais por Mês</h1>";

try {
    $db = Database::getConnection();

    // Verifica se já foi modificado
    $sql = "SHOW INDEX FROM promoter_commission_history WHERE Key_name = 'uk_promoter_month'";
    $result = $db->query($sql)->fetch();

    if (!$result) {
        echo "<div class='alert alert-success'>";
        echo "<strong>✅ Migração já foi executada!</strong><br>";
        echo "A tabela já permite comissões gerais por mês.";
        echo "</div>";
    } else {
        echo "<h2>📋 O que será feito:</h2>";
        echo "<ol>";
        echo "<li>Permitir <code>promoter_name = NULL</code> (comissão geral do mês)</li>";
        echo "<li>Remover UNIQUE KEY antigo <code>uk_promoter_month</code></li>";
        echo "<li>Criar UNIQUE KEY parcial para evitar duplicatas</li>";
        echo "</ol>";

        echo "<h2>💡 Como Funcionará:</h2>";
        echo "<ul>";
        echo "<li><strong>Comissão Geral (Mês):</strong> promoter_name = NULL → Todos os consultores usam</li>";
        echo "<li><strong>Comissão Específica:</strong> promoter_name = 'João' → Sobrescreve a geral apenas para João</li>";
        echo "</ul>";

        echo "<h2>🔄 Lógica de Busca:</h2>";
        echo "<ol>";
        echo "<li>Verifica se tem comissão específica de <code>(promotor + mês)</code></li>";
        echo "<li>Se não, verifica se tem comissão geral do <code>mês (NULL)</code></li>";
        echo "<li>Se não, usa do cadastro do promotor</li>";
        echo "<li>Se não, usa 25%</li>";
        echo "</ol>";

        if (isset($_POST['execute_migration'])) {
            echo "<h2>⚡ Executando Migração...</h2>";

            $db->beginTransaction();

            try {
                // Remove UNIQUE KEY antigo
                echo "<p><strong>Passo 1:</strong> Removendo UNIQUE KEY antigo...</p>";
                $db->exec("ALTER TABLE promoter_commission_history DROP INDEX uk_promoter_month");
                echo "<div class='alert alert-success'>✅ UNIQUE KEY removido!</div>";

                // Permite NULL em promoter_name
                echo "<p><strong>Passo 2:</strong> Permitindo promoter_name = NULL...</p>";
                $db->exec("ALTER TABLE promoter_commission_history MODIFY promoter_name VARCHAR(255) NULL");
                echo "<div class='alert alert-success'>✅ Coluna modificada para aceitar NULL!</div>";

                // Cria novo UNIQUE KEY (funciona com NULL)
                echo "<p><strong>Passo 3:</strong> Criando novo índice...</p>";
                $db->exec("ALTER TABLE promoter_commission_history
                          ADD UNIQUE KEY uk_promoter_month_v2 (promoter_name, month_reference)");
                echo "<div class='alert alert-success'>✅ Novo índice criado!</div>";

                $db->commit();

                echo "<div class='alert alert-success'>";
                echo "<h3>🎉 MIGRAÇÃO CONCLUÍDA!</h3>";
                echo "<p>✅ Tabela atualizada com sucesso!<br>";
                echo "✅ Agora você pode definir comissões gerais por mês<br>";
                echo "✅ Comissões específicas sobrescrevem a geral</p>";
                echo "<p><strong>Próximos passos:</strong></p>";
                echo "<ul>";
                echo "<li>Acesse <strong>Gerenciar Comissões</strong></li>";
                echo "<li>Selecione <strong>\"-- Todos os Consultores --\"</strong> no dropdown</li>";
                echo "<li>Configure a comissão padrão do mês</li>";
                echo "<li>Configure comissões individuais quando necessário</li>";
                echo "</ul>";
                echo "<p><a href='manage_commissions.php' class='btn' target='_blank'>→ Ir para Gerenciar Comissões</a></p>";
                echo "</div>";

            } catch (Exception $e) {
                $db->rollBack();
                echo "<div class='alert alert-danger'>";
                echo "<strong>❌ ERRO durante a migração:</strong><br>";
                echo htmlspecialchars($e->getMessage());
                echo "</div>";
            }

        } else {
            // Mostra botão de confirmação
            echo "<div class='alert alert-info'>";
            echo "<h3>⚠️ Atenção</h3>";
            echo "<p>Esta operação irá:</p>";
            echo "<ul>";
            echo "<li>Modificar a estrutura da tabela <code>promoter_commission_history</code></li>";
            echo "<li>Remover e recriar índices únicos</li>";
            echo "<li>Permitir comissões gerais por mês</li>";
            echo "</ul>";
            echo "<p><strong>Os dados existentes serão preservados!</strong></p>";
            echo "</div>";

            echo "<form method='POST'>";
            echo "<button type='submit' name='execute_migration' class='btn' onclick='return confirm(\"Tem certeza? Esta operação irá alterar a estrutura do banco!\");'>";
            echo "🔧 EXECUTAR MIGRAÇÃO";
            echo "</button>";
            echo " <a href='../index.php?admin=1&godmode=on' class='btn' style='background: #6c757d;'>← Cancelar</a>";
            echo "</form>";
        }
    }

} catch (Exception $e) {
    echo "<div class='alert alert-danger'>";
    echo "<strong>❌ Erro:</strong> " . htmlspecialchars($e->getMessage());
    echo "</div>";
}

echo "</div></body></html>";
