<?php
/**
 * Migração: Adiciona suporte para comprovantes de pagamento
 *
 * Opções de armazenamento:
 * - file: Arquivo salvo no servidor (/uploads/receipts/)
 * - base64: Imagem/PDF codificado em base64 no banco
 * - none: Sem comprovante
 *
 * ACESSE: /admin/migrate_payment_receipts.php
 */

require_once __DIR__ . '/../Database.php';

echo "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>Migração: Comprovantes de Pagamento</title>
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
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #28a745; color: white; }
        code { background: #2d2d2d; color: #d4d4d4; padding: 2px 6px; border-radius: 3px; font-family: monospace; }
    </style>
</head>
<body>
<div class='container'>";

echo "<h1>📄 Migração: Comprovantes de Pagamento</h1>";

try {
    $db = Database::getConnection();

    // Verifica se as colunas já existem
    $sql = "SHOW COLUMNS FROM payments LIKE 'receipt_storage_type'";
    $result = $db->query($sql)->fetch();

    if ($result) {
        echo "<div class='alert alert-info'>";
        echo "<strong>ℹ️ Colunas de comprovantes já existem!</strong><br>";
        echo "A migração já foi executada anteriormente.";
        echo "</div>";

        // Mostra estrutura atual
        echo "<h2>📊 Estrutura Atual:</h2>";
        $columns = $db->query("SHOW COLUMNS FROM payments WHERE Field LIKE 'receipt%'")->fetchAll(PDO::FETCH_ASSOC);
        echo "<table>";
        echo "<tr><th>Coluna</th><th>Tipo</th><th>Permite NULL</th><th>Padrão</th></tr>";
        foreach ($columns as $col) {
            echo "<tr>";
            echo "<td><code>{$col['Field']}</code></td>";
            echo "<td><code>{$col['Type']}</code></td>";
            echo "<td>{$col['Null']}</td>";
            echo "<td>" . ($col['Default'] ?? '<em>NULL</em>') . "</td>";
            echo "</tr>";
        }
        echo "</table>";

    } else {
        echo "<h2>📋 O que será feito:</h2>";
        echo "<ol>";
        echo "<li>Adicionar coluna <code>receipt_storage_type</code> ENUM('file', 'base64', 'none')</li>";
        echo "<li>Adicionar coluna <code>receipt_file_path</code> VARCHAR(255) para caminho do arquivo</li>";
        echo "<li>Adicionar coluna <code>receipt_base64</code> LONGBLOB para dados em base64</li>";
        echo "<li>Adicionar coluna <code>receipt_filename</code> VARCHAR(255) para nome original</li>";
        echo "<li>Adicionar coluna <code>receipt_mime_type</code> VARCHAR(100) para tipo do arquivo</li>";
        echo "<li>Criar diretório <code>/uploads/receipts/</code> com permissões corretas</li>";
        echo "</ol>";

        echo "<h2>💡 Modos de Armazenamento:</h2>";
        echo "<table>";
        echo "<tr><th>Modo</th><th>Descrição</th><th>Vantagens</th></tr>";
        echo "<tr>";
        echo "<td><strong>file</strong></td>";
        echo "<td>Arquivo salvo no servidor em /uploads/receipts/YYYY-MM/</td>";
        echo "<td>✅ Não aumenta banco<br>✅ Fácil backup de arquivos<br>✅ Suporta arquivos grandes</td>";
        echo "</tr>";
        echo "<tr>";
        echo "<td><strong>base64</strong></td>";
        echo "<td>Imagem/PDF codificado em base64 no campo LONGBLOB</td>";
        echo "<td>✅ Tudo em um lugar<br>✅ Não depende de filesystem<br>✅ Backup único do banco</td>";
        echo "</tr>";
        echo "<tr>";
        echo "<td><strong>none</strong></td>";
        echo "<td>Sem comprovante anexado</td>";
        echo "<td>✅ Para pagamentos antigos ou sem necessidade</td>";
        echo "</tr>";
        echo "</table>";

        if (isset($_POST['execute_migration'])) {
            echo "<h2>⚡ Executando Migração...</h2>";

            $db->beginTransaction();

            try {
                // Adiciona colunas
                echo "<p><strong>Passo 1:</strong> Adicionando colunas...</p>";

                $db->exec("ALTER TABLE payments
                          ADD COLUMN receipt_storage_type ENUM('file', 'base64', 'none') NOT NULL DEFAULT 'none'
                          AFTER notes");
                echo "<div class='alert alert-success'>✅ Coluna 'receipt_storage_type' adicionada!</div>";

                $db->exec("ALTER TABLE payments
                          ADD COLUMN receipt_file_path VARCHAR(255) NULL
                          AFTER receipt_storage_type");
                echo "<div class='alert alert-success'>✅ Coluna 'receipt_file_path' adicionada!</div>";

                $db->exec("ALTER TABLE payments
                          ADD COLUMN receipt_base64 LONGBLOB NULL
                          AFTER receipt_file_path");
                echo "<div class='alert alert-success'>✅ Coluna 'receipt_base64' adicionada!</div>";

                $db->exec("ALTER TABLE payments
                          ADD COLUMN receipt_filename VARCHAR(255) NULL
                          AFTER receipt_base64");
                echo "<div class='alert alert-success'>✅ Coluna 'receipt_filename' adicionada!</div>";

                $db->exec("ALTER TABLE payments
                          ADD COLUMN receipt_mime_type VARCHAR(100) NULL
                          AFTER receipt_filename");
                echo "<div class='alert alert-success'>✅ Coluna 'receipt_mime_type' adicionada!</div>";

                // Cria diretório de uploads
                echo "<p><strong>Passo 2:</strong> Criando diretório de uploads...</p>";

                $uploadsDir = __DIR__ . '/../uploads';
                $receiptsDir = $uploadsDir . '/receipts';

                if (!is_dir($uploadsDir)) {
                    mkdir($uploadsDir, 0755, true);
                    echo "<div class='alert alert-success'>✅ Diretório '/uploads' criado!</div>";
                } else {
                    echo "<div class='alert alert-info'>ℹ️ Diretório '/uploads' já existe.</div>";
                }

                if (!is_dir($receiptsDir)) {
                    mkdir($receiptsDir, 0755, true);
                    echo "<div class='alert alert-success'>✅ Diretório '/uploads/receipts' criado!</div>";
                } else {
                    echo "<div class='alert alert-info'>ℹ️ Diretório '/uploads/receipts' já existe.</div>";
                }

                // Cria arquivo .htaccess para proteger diretório
                $htaccessFile = $receiptsDir . '/.htaccess';
                if (!file_exists($htaccessFile)) {
                    $htaccess = "# Proteção do diretório de comprovantes\n";
                    $htaccess .= "# Permite apenas usuários autenticados acessarem\n\n";
                    $htaccess .= "# Bloqueia listagem de diretórios\n";
                    $htaccess .= "Options -Indexes\n\n";
                    $htaccess .= "# Permite apenas GET e HEAD\n";
                    $htaccess .= "<LimitExcept GET HEAD>\n";
                    $htaccess .= "    Deny from all\n";
                    $htaccess .= "</LimitExcept>\n";

                    file_put_contents($htaccessFile, $htaccess);
                    echo "<div class='alert alert-success'>✅ Arquivo '.htaccess' criado para segurança!</div>";
                }

                $db->commit();

                echo "<div class='alert alert-success'>";
                echo "<h3>🎉 MIGRAÇÃO CONCLUÍDA!</h3>";
                echo "<p>✅ 5 colunas adicionadas à tabela 'payments'<br>";
                echo "✅ Diretório '/uploads/receipts' criado<br>";
                echo "✅ Proteção .htaccess configurada<br>";
                echo "✅ Sistema de comprovantes está pronto para uso!</p>";
                echo "<p><strong>Próximos passos:</strong></p>";
                echo "<ul>";
                echo "<li>Interface de upload estará disponível ao marcar pagamentos</li>";
                echo "<li>Escolha entre salvar como arquivo no servidor ou base64 no banco</li>";
                echo "<li>Visualize comprovantes diretamente no histórico de pagamentos</li>";
                echo "</ul>";
                echo "<p><a href='../index.php?admin=1&godmode=on' class='btn'>→ Voltar ao Sistema</a></p>";
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
            echo "<li>Alterar a estrutura da tabela <code>payments</code></li>";
            echo "<li>Criar diretórios no servidor</li>";
            echo "<li>Adicionar 5 novas colunas</li>";
            echo "</ul>";
            echo "<p>Certifique-se de ter um backup do banco de dados antes de prosseguir!</p>";
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
