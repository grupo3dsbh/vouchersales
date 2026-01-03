<?php
/**
 * Script para criar usuário admin de emergência
 * Acesse: http://seu-site.com/vouchersales/create_admin_user.php
 */

require_once 'Database.php';

// Configurações do novo usuário
$username = 'superadmin';
$password = 'admin@2025'; // ALTERE AQUI se quiser outra senha
$name = 'Super Administrador';

try {
    $db = Database::getConnection();

    // Verifica se usuário já existe
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result['count'] > 0) {
        echo "<h2>⚠️ Usuário '$username' já existe!</h2>";
        echo "<p>Use o script reset_admin_password.php para resetar a senha.</p>";
        exit;
    }

    // Hash da senha
    $hashed = password_hash($password, PASSWORD_BCRYPT);

    // Cria o usuário
    $stmt = $db->prepare("INSERT INTO users (username, password, name, active) VALUES (?, ?, ?, 1)");
    $stmt->execute([$username, $hashed, $name]);

    echo "<h2>✅ Usuário criado com sucesso!</h2>";
    echo "<div style='background: #d4edda; padding: 20px; border-radius: 8px; margin: 20px 0; border: 2px solid #28a745;'>";
    echo "<h3>📋 Credenciais de Login:</h3>";
    echo "<p><strong>Usuário:</strong> <code style='background: #333; color: #fff; padding: 5px 10px; border-radius: 4px;'>" . htmlspecialchars($username) . "</code></p>";
    echo "<p><strong>Senha:</strong> <code style='background: #333; color: #fff; padding: 5px 10px; border-radius: 4px;'>" . htmlspecialchars($password) . "</code></p>";
    echo "</div>";
    echo "<p><strong>⚠️ IMPORTANTE:</strong> Delete este arquivo (create_admin_user.php) depois de anotar as credenciais!</p>";
    echo "<p><a href='?admin=1&godmode=on' style='display: inline-block; background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>→ Ir para Login</a></p>";

} catch (Exception $e) {
    echo "<h2>❌ Erro ao criar usuário</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>Verifique se o banco de dados foi instalado: <a href='admin/install.php'>admin/install.php</a></p>";
}
