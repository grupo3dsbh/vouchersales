<?php
/**
 * Handler AJAX para Upload de Comprovantes de Pagamento
 *
 * Suporta dois modos:
 * - file: Salva arquivo no servidor
 * - base64: Salva base64 no banco de dados
 */
session_start();
header('Content-Type: application/json');

require_once 'functions.php';
require_once 'Database.php';

// Verifica timeout de sessão
if (!checkSessionTimeout()) {
    echo json_encode(['success' => false, 'message' => 'Sessão expirada. Faça login novamente.']);
    exit;
}

// Verifica autenticação GODMODE
if (!isset($_SESSION['godmode_authenticated']) || $_SESSION['godmode_authenticated'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Não autorizado']);
    exit;
}

// Verifica se pode editar
if (!canEdit()) {
    echo json_encode(['success' => false, 'message' => 'Você não tem permissão para editar pagamentos']);
    exit;
}

// Sanitiza inputs
$action = sanitizeInput($_POST['action'] ?? '');
$promoter = sanitizeInput($_POST['promoter'] ?? '');
$month = sanitizeInput($_POST['month'] ?? '');
$storageMode = sanitizeInput($_POST['storage_mode'] ?? 'none');
$userId = $_SESSION['godmode_user_id'] ?? 0;

// Valida dados obrigatórios
if (empty($promoter) || empty($month)) {
    echo json_encode(['success' => false, 'message' => 'Promotor e mês são obrigatórios']);
    exit;
}

try {
    $db = Database::getConnection();

    // ===== UPLOAD DE COMPROVANTE =====
    if ($action === 'upload_receipt') {
        // Verifica se o pagamento existe
        $payment = getPaymentData($promoter, $month);
        if (!$payment) {
            // Cria registro de pagamento se não existir
            $sql = "INSERT INTO payments (promoter, month, paid, paid_by, paid_at)
                    VALUES (?, ?, 1, ?, NOW())";
            Database::execute($sql, [$promoter, $month, $userId]);
        }

        // Remove comprovante anterior (se houver)
        deleteReceipt($promoter, $month);

        if ($storageMode === 'none') {
            // Apenas remove o comprovante, não adiciona novo
            echo json_encode([
                'success' => true,
                'message' => 'Comprovante removido com sucesso'
            ]);
            exit;
        }

        // Valida se arquivo foi enviado
        if (!isset($_FILES['receipt']) || $_FILES['receipt']['error'] === UPLOAD_ERR_NO_FILE) {
            echo json_encode(['success' => false, 'message' => 'Nenhum arquivo foi enviado']);
            exit;
        }

        $file = $_FILES['receipt'];

        if ($storageMode === 'file') {
            // ===== SALVA COMO ARQUIVO =====
            $result = saveReceiptFile($file, $promoter, $month);

            if (!$result['success']) {
                echo json_encode(['success' => false, 'message' => $result['error']]);
                exit;
            }

            // Atualiza banco de dados
            $sql = "UPDATE payments
                    SET receipt_storage_type = 'file',
                        receipt_file_path = ?,
                        receipt_filename = ?,
                        receipt_mime_type = ?,
                        receipt_base64 = NULL
                    WHERE promoter = ? AND month = ?";

            Database::execute($sql, [
                $result['file_path'],
                $result['filename'],
                $result['mime_type'],
                $promoter,
                $month
            ]);

            // Log de auditoria
            logAudit($userId, 'upload_receipt_file', 'payment', null, null, json_encode([
                'promoter' => $promoter,
                'month' => $month,
                'filename' => $result['filename'],
                'storage' => 'file'
            ]));

            echo json_encode([
                'success' => true,
                'message' => 'Comprovante salvo como arquivo com sucesso!',
                'storage_type' => 'file',
                'filename' => $result['filename']
            ]);

        } elseif ($storageMode === 'base64') {
            // ===== SALVA COMO BASE64 =====
            $result = saveReceiptBase64($file);

            if (!$result['success']) {
                echo json_encode(['success' => false, 'message' => $result['error']]);
                exit;
            }

            // Atualiza banco de dados
            $sql = "UPDATE payments
                    SET receipt_storage_type = 'base64',
                        receipt_base64 = ?,
                        receipt_filename = ?,
                        receipt_mime_type = ?,
                        receipt_file_path = NULL
                    WHERE promoter = ? AND month = ?";

            Database::execute($sql, [
                $result['base64'],
                $result['filename'],
                $result['mime_type'],
                $promoter,
                $month
            ]);

            // Log de auditoria
            logAudit($userId, 'upload_receipt_base64', 'payment', null, null, json_encode([
                'promoter' => $promoter,
                'month' => $month,
                'filename' => $result['filename'],
                'storage' => 'base64'
            ]));

            echo json_encode([
                'success' => true,
                'message' => 'Comprovante salvo como base64 com sucesso!',
                'storage_type' => 'base64',
                'filename' => $result['filename']
            ]);

        } else {
            echo json_encode(['success' => false, 'message' => 'Modo de armazenamento inválido']);
        }
    }

    // ===== DELETAR COMPROVANTE =====
    elseif ($action === 'delete_receipt') {
        $result = deleteReceipt($promoter, $month);

        if ($result) {
            // Log de auditoria
            logAudit($userId, 'delete_receipt', 'payment', null, null, json_encode([
                'promoter' => $promoter,
                'month' => $month
            ]));

            echo json_encode([
                'success' => true,
                'message' => 'Comprovante deletado com sucesso!'
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Erro ao deletar comprovante']);
        }
    }

    // ===== VISUALIZAR COMPROVANTE =====
    elseif ($action === 'view_receipt') {
        $receipt = getReceiptData($promoter, $month);

        if (!$receipt || $receipt['receipt_storage_type'] === 'none') {
            echo json_encode(['success' => false, 'message' => 'Comprovante não encontrado']);
            exit;
        }

        echo json_encode([
            'success' => true,
            'storage_type' => $receipt['receipt_storage_type'],
            'filename' => $receipt['receipt_filename'],
            'mime_type' => $receipt['receipt_mime_type'],
            'file_path' => $receipt['receipt_file_path'],
            'base64' => $receipt['receipt_storage_type'] === 'base64' ? $receipt['receipt_base64'] : null
        ]);
    }

    else {
        echo json_encode(['success' => false, 'message' => 'Ação inválida']);
    }

} catch (Exception $e) {
    error_log("Erro no ajax_receipt_upload.php: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Erro interno: ' . $e->getMessage()
    ]);
}
