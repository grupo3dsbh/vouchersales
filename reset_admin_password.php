<?php
/**
 * Script de emergência para resetar senha do admin
 * Acesse: http://seu-site.com/vouchersales/reset_admin_password.php
 */

require_once 'Database.php';

// Nova senha
$new_password = 'admin@2025'; // ALTERE AQUI se quiser outra senha

try {
    $db = Database::getConnection();

    // Hash da nova senha
    $hashed = password_hash($new_password, PASSWORD_BCRYPT);

    // Atualiza senha do usuário admin
    $stmt = $db->prepare("UPDATE users SET password = ? WHERE username = 'admin'");
    $stmt->execute([$hashed]);

    echo "<h2>✓ Senha do admin resetada com sucesso!</h2>";
    echo "<p><strong>Usuário:</strong> admin</p>";
    echo "<p><strong>Nova senha:</strong> " . htmlspecialchars($new_password) . "</p>";
    echo "<p><strong>IMPORTANTE:</strong> Delete este arquivo (reset_admin_password.php) depois de usar!</p>";
    echo "<p><a href='?admin=1&godmode=on'>→ Ir para área de login</a></p>";

} catch (Exception $e) {
    echo "<h2>✗ Erro ao resetar senha</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>Verifique se o banco de dados foi instalado executando: <a href='admin/install.php'>admin/install.php</a></p>";
}
