<?php
/**
 * Migração: Adiciona níveis de acesso (roles) aos usuários
 *
 * Níveis:
 * - superadmin: Acesso total
 * - admin: Gerencia vendas e promotores
 * - viewer: Apenas visualização
 *
 * ACESSE: /admin/migrate_user_roles.php
 */

require_once __DIR__ . '/../Database.php';

echo "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>Migração: Níveis de Acesso</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #667eea; border-bottom: 3px solid #667eea; padding-bottom: 10px; }
        .alert { padding: 15px; margin: 15px 0; border-radius: 8px; }
        .alert-success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; }
        .alert-danger { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
        .alert-info { background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; }
        .btn { display: inline-block; padding: 10px 20px; background: #667eea; color: white; text-decoration: none; border-radius: 5px; border: none; cursor: pointer; }
        .btn:hover { background: #5568d3; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #667eea; color: white; }
    </style>
</head>
<body>
<div class='container'>";

echo "<h1>🔐 Migração: Níveis de Acesso</h1>";

try {
    $db = Database::getConnection();

    // Verifica se a coluna 'role' já existe
    $sql = "SHOW COLUMNS FROM users LIKE 'role'";
    $result = $db->query($sql)->fetch();

    if ($result) {
        echo "<div class='alert alert-info'>";
        echo "<strong>ℹ️ Coluna 'role' já existe!</strong><br>";
        echo "A migração já foi executada anteriormente.";
        echo "</div>";
    } else {
        echo "<h2>📋 O que será feito:</h2>";
        echo "<ol>";
        echo "<li>Adicionar coluna <code>role</code> na tabela <code>users</code></li>";
        echo "<li>Definir roles: <strong>superadmin</strong>, <strong>admin</strong>, <strong>viewer</strong></li>";
        echo "<li>Setar todos os usuários existentes como <strong>admin</strong> (pode alterar depois)</li>";
        echo "</ol>";

        if (isset($_POST['execute_migration'])) {
            echo "<h2>⚡ Executando Migração...</h2>";

            $db->beginTransaction();

            try {
                // Adiciona coluna role
                $sql = "ALTER TABLE users
                        ADD COLUMN role ENUM('superadmin', 'admin', 'viewer') NOT NULL DEFAULT 'admin'
                        AFTER active";
                $db->exec($sql);

                echo "<div class='alert alert-success'>✅ Coluna 'role' adicionada com sucesso!</div>";

                // Atualiza usuários existentes para admin
                $sql = "UPDATE users SET role = 'admin' WHERE role IS NULL OR role = ''";
                $stmt = $db->prepare($sql);
                $stmt->execute();
                $updated = $stmt->rowCount();

                echo "<div class='alert alert-success'>✅ $updated usuários atualizados para role 'admin'</div>";

                // Lista usuários
                $sql = "SELECT id, username, name, role, active FROM users ORDER BY id";
                $users = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

                echo "<h3>👥 Usuários Atuais:</h3>";
                echo "<table>";
                echo "<tr><th>ID</th><th>Username</th><th>Nome</th><th>Role</th><th>Status</th></tr>";
                foreach ($users as $user) {
                    $status = $user['active'] ? '✅ Ativo' : '❌ Inativo';
                    echo "<tr>";
                    echo "<td>{$user['id']}</td>";
                    echo "<td>{$user['username']}</td>";
                    echo "<td>{$user['name']}</td>";
                    echo "<td><strong>{$user['role']}</strong></td>";
                    echo "<td>$status</td>";
                    echo "</tr>";
                }
                echo "</table>";

                $db->commit();

                echo "<div class='alert alert-success'>";
                echo "<h3>🎉 MIGRAÇÃO CONCLUÍDA!</h3>";
                echo "<p>✅ Coluna 'role' adicionada<br>";
                echo "✅ Usuários existentes definidos como 'admin'<br>";
                echo "✅ Sistema de níveis de acesso está funcionando!</p>";
                echo "<p><strong>Próximos passos:</strong></p>";
                echo "<ul>";
                echo "<li>Altere o role dos usuários conforme necessário no banco de dados</li>";
                echo "<li><strong>superadmin</strong>: Acesso total ao sistema</li>";
                echo "<li><strong>admin</strong>: Gerencia vendas, promotores e pagamentos</li>";
                echo "<li><strong>viewer</strong>: Apenas visualização de relatórios</li>";
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
            echo "<h3>📊 Níveis de Acesso:</h3>";
            echo "<table>";
            echo "<tr><th>Role</th><th>Permissões</th></tr>";
            echo "<tr>";
            echo "<td><strong>superadmin</strong></td>";
            echo "<td>✅ Acesso total<br>✅ Gerencia usuários<br>✅ Gerencia vendas<br>✅ Gerencia promotores<br>✅ Gerencia pagamentos<br>✅ Visualiza logs de auditoria</td>";
            echo "</tr>";
            echo "<tr>";
            echo "<td><strong>admin</strong></td>";
            echo "<td>✅ Gerencia vendas<br>✅ Gerencia promotores<br>✅ Gerencia pagamentos<br>❌ Não gerencia usuários<br>❌ Não visualiza logs de auditoria</td>";
            echo "</tr>";
            echo "<tr>";
            echo "<td><strong>viewer</strong></td>";
            echo "<td>✅ Visualiza relatórios<br>❌ Não pode editar nada</td>";
            echo "</tr>";
            echo "</table>";
            echo "</div>";

            echo "<form method='POST'>";
            echo "<button type='submit' name='execute_migration' class='btn' onclick='return confirm(\"Tem certeza? Esta operação irá alterar a estrutura do banco de dados!\");'>";
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
