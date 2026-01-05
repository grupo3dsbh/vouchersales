<?php
/**
 * Interface de Logs de Auditoria
 *
 * Exibe histórico completo de ações no sistema
 * Acesso restrito a superadmins
 *
 * ACESSE: /admin/audit_logs.php
 */

session_start();
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../functions.php';

// Verifica se usuário está autenticado e é superadmin
if (!isset($_SESSION['godmode_user_role']) || $_SESSION['godmode_user_role'] !== 'superadmin') {
    die('<h1>Acesso Negado</h1><p>Apenas superadmins podem visualizar logs de auditoria.</p><p><a href="../index.php?admin=1&godmode=on">← Voltar</a></p>');
}

// Paginação
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 50;
$offset = ($page - 1) * $per_page;

// Filtros
$user_filter = $_GET['user_id'] ?? '';
$action_filter = $_GET['action'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Monta query com filtros
$where_clauses = [];
$params = [];

if (!empty($user_filter)) {
    $where_clauses[] = "al.user_id = ?";
    $params[] = $user_filter;
}

if (!empty($action_filter)) {
    $where_clauses[] = "al.action = ?";
    $params[] = $action_filter;
}

if (!empty($date_from)) {
    $where_clauses[] = "al.created_at >= ?";
    $params[] = $date_from . ' 00:00:00';
}

if (!empty($date_to)) {
    $where_clauses[] = "al.created_at <= ?";
    $params[] = $date_to . ' 23:59:59';
}

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

try {
    $db = Database::getConnection();

    // Conta total de registros
    $count_sql = "SELECT COUNT(*) as total
                  FROM audit_logs al
                  $where_sql";
    $stmt = $db->prepare($count_sql);
    $stmt->execute($params);
    $total_records = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $total_pages = ceil($total_records / $per_page);

    // Busca logs com paginação
    $sql = "SELECT
                al.*,
                u.username,
                u.name as user_name
            FROM audit_logs al
            LEFT JOIN users u ON al.user_id = u.id
            $where_sql
            ORDER BY al.created_at DESC
            LIMIT $per_page OFFSET $offset";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Busca lista de usuários para filtro
    $users = $db->query("SELECT id, username, name FROM users ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

    // Busca lista de ações únicas
    $actions = $db->query("SELECT DISTINCT action FROM audit_logs ORDER BY action")->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    die('<h1>Erro</h1><p>' . htmlspecialchars($e->getMessage()) . '</p>');
}

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logs de Auditoria</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.5.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        body { background: #f5f5f5; padding: 20px; }
        .container-fluid { max-width: 1400px; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #667eea; border-bottom: 3px solid #667eea; padding-bottom: 15px; margin-bottom: 25px; }
        .filters { background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 25px; }
        .log-row { border-bottom: 1px solid #eee; padding: 12px 0; }
        .log-row:hover { background: #f8f9fa; }
        .log-action { display: inline-block; padding: 4px 10px; border-radius: 4px; font-size: 12px; font-weight: bold; margin-right: 10px; }
        .action-login { background: #28a745; color: white; }
        .action-logout { background: #6c757d; color: white; }
        .action-create, .action-add { background: #17a2b8; color: white; }
        .action-edit, .action-update { background: #ffc107; color: #333; }
        .action-delete { background: #dc3545; color: white; }
        .action-import { background: #667eea; color: white; }
        .action-payment { background: #20c997; color: white; }
        .log-time { color: #999; font-size: 13px; }
        .log-user { color: #667eea; font-weight: 600; }
        .log-details { font-size: 13px; color: #666; margin-top: 5px; }
        .pagination { margin-top: 25px; }
        .badge-superadmin { background: #667eea; }
        .badge-admin { background: #17a2b8; }
        .badge-viewer { background: #6c757d; }
    </style>
</head>
<body>
<div class="container-fluid">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
        <h1><i class="fas fa-history"></i> Logs de Auditoria</h1>
        <a href="../index.php?admin=1&godmode=on" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Voltar ao Sistema
        </a>
    </div>

    <!-- Filtros -->
    <div class="filters">
        <form method="GET" class="form-inline">
            <div class="form-group mr-3">
                <label class="mr-2"><i class="fas fa-user"></i> Usuário:</label>
                <select name="user_id" class="form-control">
                    <option value="">Todos</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?= $user['id'] ?>" <?= $user_filter == $user['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($user['name']) ?> (<?= htmlspecialchars($user['username']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group mr-3">
                <label class="mr-2"><i class="fas fa-bolt"></i> Ação:</label>
                <select name="action" class="form-control">
                    <option value="">Todas</option>
                    <?php foreach ($actions as $action): ?>
                        <option value="<?= $action['action'] ?>" <?= $action_filter == $action['action'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($action['action']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group mr-3">
                <label class="mr-2"><i class="fas fa-calendar"></i> De:</label>
                <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($date_from) ?>">
            </div>

            <div class="form-group mr-3">
                <label class="mr-2">Até:</label>
                <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($date_to) ?>">
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="fas fa-search"></i> Filtrar
            </button>
            <a href="?" class="btn btn-secondary ml-2">
                <i class="fas fa-times"></i> Limpar
            </a>
        </form>
    </div>

    <!-- Resultados -->
    <div class="mb-3">
        <strong><?= number_format($total_records) ?></strong> registros encontrados
        (Página <?= $page ?> de <?= $total_pages ?>)
    </div>

    <!-- Logs -->
    <?php if (empty($logs)): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i> Nenhum log encontrado com os filtros selecionados.
        </div>
    <?php else: ?>
        <?php foreach ($logs as $log): ?>
            <div class="log-row">
                <div style="display: flex; align-items: start; gap: 15px;">
                    <div style="flex: 0 0 150px;">
                        <div class="log-time">
                            <i class="fas fa-clock"></i>
                            <?= date('d/m/Y H:i:s', strtotime($log['created_at'])) ?>
                        </div>
                    </div>

                    <div style="flex: 1;">
                        <div>
                            <span class="log-action action-<?= explode('_', $log['action'])[0] ?>">
                                <?= htmlspecialchars($log['action']) ?>
                            </span>

                            <?php if ($log['user_name']): ?>
                                <span class="log-user">
                                    <i class="fas fa-user"></i>
                                    <?= htmlspecialchars($log['user_name']) ?>
                                    <small class="text-muted">(<?= htmlspecialchars($log['username']) ?>)</small>
                                </span>
                            <?php else: ?>
                                <span class="text-muted"><i>Usuário desconhecido</i></span>
                            <?php endif; ?>

                            <?php if ($log['entity_type']): ?>
                                <span class="text-muted">
                                    → <strong><?= htmlspecialchars($log['entity_type']) ?></strong>
                                    <?php if ($log['entity_id']): ?>
                                        #<?= $log['entity_id'] ?>
                                    <?php endif; ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <?php if ($log['new_data'] || $log['old_data']): ?>
                            <div class="log-details">
                                <?php if ($log['new_data']): ?>
                                    <details>
                                        <summary style="cursor: pointer; color: #667eea;">
                                            <i class="fas fa-code"></i> Ver dados
                                        </summary>
                                        <pre style="background: #f8f9fa; padding: 10px; border-radius: 4px; font-size: 11px; margin-top: 5px;"><?= htmlspecialchars($log['new_data']) ?></pre>
                                    </details>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($log['ip_address']): ?>
                            <div class="text-muted" style="font-size: 11px; margin-top: 5px;">
                                <i class="fas fa-network-wired"></i> IP: <?= htmlspecialchars($log['ip_address']) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

        <!-- Paginação -->
        <?php if ($total_pages > 1): ?>
            <nav class="pagination">
                <ul class="pagination">
                    <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?= $page - 1 ?>&user_id=<?= $user_filter ?>&action=<?= $action_filter ?>&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>">
                                ← Anterior
                            </a>
                        </li>
                    <?php endif; ?>

                    <?php for ($i = max(1, $page - 3); $i <= min($total_pages, $page + 3); $i++): ?>
                        <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                            <a class="page-link" href="?page=<?= $i ?>&user_id=<?= $user_filter ?>&action=<?= $action_filter ?>&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>">
                                <?= $i ?>
                            </a>
                        </li>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?page=<?= $page + 1 ?>&user_id=<?= $user_filter ?>&action=<?= $action_filter ?>&date_from=<?= $date_from ?>&date_to=<?= $date_to ?>">
                                Próxima →
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
