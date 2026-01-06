<?php
require_once '../functions.php';

// Adiciona coluna master_pin para cada admin ter seu próprio PIN mestre

try {
    $db = Database::getConnection();

    echo "<h2>Migração: Adicionar PIN Mestre para Admins</h2>";

    // Verifica se coluna já existe
    $columns = $db->query("SHOW COLUMNS FROM users LIKE 'master_pin'")->fetchAll();

    if (empty($columns)) {
        echo "<p>Adicionando coluna master_pin...</p>";

        $db->exec("ALTER TABLE users ADD COLUMN master_pin VARCHAR(10) NULL DEFAULT NULL COMMENT 'PIN mestre do admin para acessar relatórios'");

        echo "<p style='color: green;'>✅ Coluna master_pin adicionada com sucesso!</p>";
    } else {
        echo "<p style='color: orange;'>⚠️ Coluna master_pin já existe!</p>";
    }

    echo "<p><strong>Próximo passo:</strong> Cada administrador deve configurar seu PIN mestre em Gerenciar Usuários.</p>";
    echo "<p><a href='../index.php?godmode=on'>← Voltar</a></p>";

} catch (PDOException $e) {
    echo "<p style='color: red;'>❌ Erro: " . htmlspecialchars($e->getMessage()) . "</p>";
}
