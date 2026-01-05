// Variável global para armazenar o nome do promotor atual
let currentPromoterName = '';

function openDetailsModal(promoterName) {
    currentPromoterName = promoterName;
    const modal = document.getElementById('detailsModal');
    const modalPromoterName = document.getElementById('modalPromoterName');
    const modalTableBody = document.getElementById('modalTableBody');
    
    // Define o nome do consultor
    modalPromoterName.textContent = promoterName;
    
    // Limpa o corpo da tabela
    modalTableBody.innerHTML = '';
    
    // Pega os dados do consultor
    const promoterData = promotersData[promoterName];
    
    if (!promoterData) {
        modalTableBody.innerHTML = '<tr><td colspan="6">Nenhum dado encontrado</td></tr>';
        modal.classList.add('active');
        return;
    }
    
    let totalVouchers = 0;
    let totalValue = 0;
    let totalCommission = 0;
    let paidVouchers = 0;
    let paidValue = 0;
    let paidCommission = 0;
    let unpaidVouchers = 0;
    let unpaidValue = 0;
    let unpaidCommission = 0;
    
    // Itera pelos meses (do mais antigo para o mais recente)
    availableMonths.forEach(month => {
        const monthData = promoterData.months[month.value];
        
        const row = document.createElement('tr');
        
        if (monthData) {
            totalVouchers += monthData.vouchers;
            totalValue += monthData.value;
            totalCommission += monthData.commission;
            
            if (monthData.paid) {
                paidVouchers += monthData.vouchers;
                paidValue += monthData.value;
                paidCommission += monthData.commission;
                row.style.background = '#d4edda';
            } else {
                unpaidVouchers += monthData.vouchers;
                unpaidValue += monthData.value;
                unpaidCommission += monthData.commission;
            }
            
            row.innerHTML = `
                <td style="text-align: center;">
                    <input type="checkbox"
                           class="payment-checkbox"
                           data-promoter="${escapeHtml(promoterName)}"
                           data-month="${month.value}"
                           ${monthData.paid ? 'checked' : ''}
                           onchange="togglePaymentConfirm(this, '${escapeHtml(promoterName)}', '${month.value}')">
                </td>
                <td><strong>${month.label}</strong></td>
                <td>${monthData.vouchers}</td>
                <td>R$ ${formatNumber(monthData.value)}</td>
                <td style="color: #28a745; font-weight: 600;">R$ ${formatNumber(monthData.commission)}</td>
                <td style="font-size: 11px; color: #666;">
                    ${monthData.paid ? '<span style="color: #28a745;"><i class="fas fa-check-circle"></i> Pago</span>' : '<span style="color: #dc3545;"><i class="fas fa-clock"></i> Pendente</span>'}
                </td>
                <td style="text-align: center;">
                    ${monthData.paid ? (
                        monthData.receipt && monthData.receipt.exists ? `
                            <div style="display: flex; gap: 3px; justify-content: center;">
                                <button onclick="viewReceipt('${escapeHtml(promoterName)}', '${month.value}')"
                                        class="btn btn-sm btn-success" style="padding: 3px 6px; font-size: 10px;"
                                        title="Ver Comprovante (${monthData.receipt.filename || ''})">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button onclick="openReceiptModal('${escapeHtml(promoterName)}', '${month.value}', '${month.label}')"
                                        class="btn btn-sm btn-warning" style="padding: 3px 6px; font-size: 10px;"
                                        title="Alterar Comprovante">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button onclick="deleteReceiptConfirm('${escapeHtml(promoterName)}', '${month.value}')"
                                        class="btn btn-sm btn-danger" style="padding: 3px 6px; font-size: 10px;"
                                        title="Deletar Comprovante">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        ` : `
                            <button onclick="openReceiptModal('${escapeHtml(promoterName)}', '${month.value}', '${month.label}')"
                                    class="btn btn-sm btn-primary" style="padding: 3px 8px; font-size: 11px;"
                                    title="Upload Comprovante">
                                <i class="fas fa-upload"></i>
                            </button>
                        `
                    ) : '<span style="color: #999; font-size: 10px;">-</span>'}
                </td>
            `;
        } else {
            row.innerHTML = `
                <td></td>
                <td><strong>${month.label}</strong></td>
                <td style="color: #999;">-</td>
                <td style="color: #999;">-</td>
                <td style="color: #999;">-</td>
                <td style="color: #999;">Sem vendas</td>
                <td></td>
            `;
        }
        
        modalTableBody.appendChild(row);
    });
    
    // Atualiza os totais
    document.getElementById('modalTotalVouchers').textContent = totalVouchers;
    document.getElementById('modalTotalValue').textContent = 'R$ ' + formatNumber(totalValue);
    document.getElementById('modalTotalCommission').textContent = 'R$ ' + formatNumber(totalCommission);
    
    document.getElementById('modalPaidVouchers').textContent = paidVouchers;
    document.getElementById('modalPaidValue').textContent = 'R$ ' + formatNumber(paidValue);
    document.getElementById('modalPaidCommission').textContent = 'R$ ' + formatNumber(paidCommission);
    
    document.getElementById('modalUnpaidVouchers').textContent = unpaidVouchers;
    document.getElementById('modalUnpaidValue').textContent = 'R$ ' + formatNumber(unpaidValue);
    document.getElementById('modalUnpaidCommission').textContent = 'R$ ' + formatNumber(unpaidCommission);
    
    // Mostra o modal
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function selectAllPayments(markAsPaid) {
    const checkboxes = document.querySelectorAll('.payment-checkbox');
    const action = markAsPaid ? 'MARCAR TODOS como PAGO' : 'DESMARCAR TODOS';
    const message = `Tem certeza que deseja ${action}?`;
    
    if (!confirm(message)) {
        return;
    }
    
    const doubleCheck = confirm('Esta ação será registrada no sistema para TODOS os meses. Confirma?');
    if (!doubleCheck) {
        return;
    }
    
    let processedCount = 0;
    let totalCheckboxes = 0;
    
    // Conta quantos checkboxes precisam ser alterados
    checkboxes.forEach(checkbox => {
        if (checkbox.checked !== markAsPaid) {
            totalCheckboxes++;
        }
    });
    
    if (totalCheckboxes === 0) {
        alert(markAsPaid ? 'Todos já estão marcados como pagos!' : 'Todos já estão desmarcados!');
        return;
    }
    
    // Pega o token CSRF
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    // Debug: Verifica se o token existe
    if (!csrfToken) {
        console.error('Token CSRF não encontrado! Recarregue a página.');
        alert('Erro de segurança: Token CSRF não encontrado. Por favor, recarregue a página e tente novamente.');
        return;
    }

    // Processa cada checkbox
    checkboxes.forEach(checkbox => {
        if (checkbox.checked !== markAsPaid) {
            const promoter = checkbox.dataset.promoter;
            const month = checkbox.dataset.month;

            // Pega dados do promoter
            const promoterData = promotersData[promoter];
            const monthData = promoterData?.months[month] || {};
            const amount = monthData.commission || 0;
            const vouchers = monthData.vouchers || 0;

            checkbox.disabled = true;

            fetch('ajax_handler.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=toggle_payment&promoter=${encodeURIComponent(promoter)}&month=${encodeURIComponent(month)}&paid=${markAsPaid ? '1' : '0'}&amount=${amount}&vouchers=${vouchers}&csrf_token=${encodeURIComponent(csrfToken)}`
            })
            .then(response => response.json())
            .then(data => {
                processedCount++;
                checkbox.disabled = false;
                
                if (data.success) {
                    checkbox.checked = markAsPaid;
                    const row = checkbox.closest('tr');
                    if (markAsPaid) {
                        row.style.background = '#d4edda';
                    } else {
                        row.style.background = '';
                    }
                    
                    // Atualiza dados no objeto
                    if (promotersData[promoter] && promotersData[promoter].months[month]) {
                        promotersData[promoter].months[month].paid = markAsPaid;
                    }
                }
                
                // Se processou todos, recarrega
                if (processedCount === totalCheckboxes) {
                    setTimeout(() => {
                        closeDetailsModal();
                        location.reload();
                    }, 500);
                }
            })
            .catch(error => {
                checkbox.disabled = false;
                console.error('Erro:', error);
            });
        }
    });
}

function togglePaymentConfirm(checkbox, promoter, month) {
    const isPaying = checkbox.checked;

    if (isPaying) {
        // Se está marcando como PAGO, abre modal de comprovante
        checkbox.checked = false; // Reverte temporariamente

        // Formata o mês para exibição (YYYY-MM -> MMM/YYYY)
        const monthLabel = formatMonthLabel(month);

        // Abre modal de comprovante com opção de upload opcional
        openReceiptModalForPayment(promoter, month, monthLabel, checkbox);
    } else {
        // Se está desmarcando como pago, usa fluxo normal
        const message = `Tem certeza que deseja DESMARCAR como PAGO a comissão de ${promoter} para o período ${month}?`;

        if (confirm(message)) {
            togglePayment(promoter, month, false, checkbox);
        } else {
            checkbox.checked = !checkbox.checked;
        }
    }
}

/**
 * Formata mês YYYY-MM para exibição
 */
function formatMonthLabel(monthValue) {
    const [year, month] = monthValue.split('-');
    const months = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
    return `${months[parseInt(month) - 1]}/${year}`;
}

function togglePayment(promoter, month, paid, checkbox) {
    // Desabilita checkbox temporariamente
    checkbox.disabled = true;

    // Pega dados do promoter
    const promoterData = promotersData[promoter];
    const monthData = promoterData?.months[month] || {};
    const amount = monthData.commission || 0;
    const vouchers = monthData.vouchers || 0;

    // Pega o token CSRF
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    // Debug: Verifica se o token existe
    if (!csrfToken) {
        console.error('Token CSRF não encontrado! Recarregue a página.');
        alert('Erro de segurança: Token CSRF não encontrado. Por favor, recarregue a página e tente novamente.');
        checkbox.disabled = false;
        return;
    }

    fetch('ajax_handler.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=toggle_payment&promoter=${encodeURIComponent(promoter)}&month=${encodeURIComponent(month)}&paid=${paid ? '1' : '0'}&amount=${amount}&vouchers=${vouchers}&csrf_token=${encodeURIComponent(csrfToken)}`
    })
    .then(response => response.json())
    .then(data => {
        checkbox.disabled = false;
        
        if (data.success) {
            // Atualiza o status visual da linha
            const row = checkbox.closest('tr');
            if (paid) {
                row.style.background = '#d4edda';
            } else {
                row.style.background = '';
            }
            
            // Atualiza dados no objeto
            if (promotersData[promoter] && promotersData[promoter].months[month]) {
                promotersData[promoter].months[month].paid = paid;
            }
            
            // Fecha e reabre o modal para atualizar totais
            closeDetailsModal();
            setTimeout(() => openDetailsModal(promoter), 300);
            
            // Recarrega a página após 1 segundo para atualizar tabela principal
            setTimeout(() => location.reload(), 1000);
        } else {
            alert('Erro: ' + data.message);
            checkbox.checked = !checkbox.checked;
        }
    })
    .catch(error => {
        checkbox.disabled = false;
        console.error('Erro:', error);
        alert('Erro ao processar requisição');
        checkbox.checked = !checkbox.checked;
    });
}

function closeDetailsModal() {
    const modal = document.getElementById('detailsModal');
    modal.classList.remove('active');
    document.body.style.overflow = 'auto';
}

function closeModalOnOverlay(event) {
    if (event.target.classList.contains('modal-overlay')) {
        const modalId = event.target.id;
        if (modalId === 'detailsModal') closeDetailsModal();
        if (modalId === 'usersModal') closeUsersModal();
        if (modalId === 'editUserModal') closeEditUserModal();
    }
}

function formatNumber(num) {
    return num.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
}

function escapeHtml(text) {
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return text.replace(/[&<>"']/g, m => map[m]);
}

// Funções de gestão de usuários
function openUsersModal() {
    document.getElementById('usersModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeUsersModal() {
    document.getElementById('usersModal').classList.remove('active');
    document.body.style.overflow = 'auto';
}

function editUserModal(user) {
    document.getElementById('edit_id').value = user.id;
    document.getElementById('edit_username').value = user.username;
    document.getElementById('edit_name').value = user.name;
    document.getElementById('edit_password').value = '';

    // Define o role (com fallback para 'admin' se não existir)
    const roleField = document.getElementById('edit_role');
    if (roleField) {
        roleField.value = user.role || 'admin';
    }

    document.getElementById('editUserModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeEditUserModal() {
    document.getElementById('editUserModal').classList.remove('active');
    document.body.style.overflow = 'auto';
}

// Fechar modal com ESC
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeDetailsModal();
        closeUsersModal();
        closeEditUserModal();
        closeReceiptModal();
    }
});

// ===== FUNÇÕES DE COMPROVANTES DE PAGAMENTO =====

/**
 * Abre o modal de upload de comprovante
 */
function openReceiptModal(promoter, month, monthLabel) {
    document.getElementById('receiptPromoterName').textContent = promoter;
    document.getElementById('receiptMonth').textContent = monthLabel;
    document.getElementById('receipt_promoter').value = promoter;
    document.getElementById('receipt_month').value = month;

    // Reseta o formulário
    document.getElementById('receiptUploadForm').reset();
    document.getElementById('receiptPreview').style.display = 'none';
    document.getElementById('uploadProgress').style.display = 'none';
    document.getElementById('receipt_file').required = true;

    // Remove referência a checkbox (modo de edição de comprovante)
    delete window.pendingPaymentCheckbox;

    document.getElementById('receiptModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

/**
 * Abre modal de comprovante ao marcar pagamento como pago
 * Armazena referência ao checkbox para atualizar após sucesso
 */
function openReceiptModalForPayment(promoter, month, monthLabel, checkbox) {
    document.getElementById('receiptPromoterName').textContent = promoter;
    document.getElementById('receiptMonth').textContent = monthLabel;
    document.getElementById('receipt_promoter').value = promoter;
    document.getElementById('receipt_month').value = month;

    // Reseta o formulário
    document.getElementById('receiptUploadForm').reset();
    document.getElementById('receiptPreview').style.display = 'none';
    document.getElementById('uploadProgress').style.display = 'none';

    // Marca "sem comprovante" por padrão para permitir marcar como pago sem upload
    const noneRadio = document.querySelector('input[name="storage_mode"][value="none"]');
    if (noneRadio) {
        noneRadio.checked = true;
        // Dispara evento change para atualizar UI
        noneRadio.dispatchEvent(new Event('change'));
    }

    // Armazena checkbox para atualizar após sucesso
    window.pendingPaymentCheckbox = checkbox;

    document.getElementById('receiptModal').style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

/**
 * Fecha o modal de upload de comprovante
 */
function closeReceiptModal(event) {
    if (event && event.target.id !== 'receiptModal' && event.type === 'click') {
        return;
    }

    // Se havia checkbox pendente (cancelou marcação de pagamento), limpa
    if (window.pendingPaymentCheckbox) {
        delete window.pendingPaymentCheckbox;
    }

    document.getElementById('receiptModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

/**
 * Visualiza comprovante existente
 */
function viewReceipt(promoter, month) {
    fetch('ajax_receipt_upload.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=view_receipt&promoter=${encodeURIComponent(promoter)}&month=${encodeURIComponent(month)}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (data.storage_type === 'file') {
                // Abre arquivo em nova aba
                window.open(data.file_path, '_blank');
            } else if (data.storage_type === 'base64') {
                // Cria modal para exibir base64
                const mimeType = data.mime_type;
                const base64Data = data.base64;

                if (mimeType.startsWith('image/')) {
                    // Exibe imagem
                    const img = new Image();
                    img.src = `data:${mimeType};base64,${base64Data}`;
                    img.style.maxWidth = '100%';
                    img.style.maxHeight = '80vh';

                    const viewer = window.open('', '_blank');
                    viewer.document.write(`
                        <html>
                        <head><title>${data.filename}</title></head>
                        <body style="margin: 0; display: flex; align-items: center; justify-content: center; background: #333;">
                            ${img.outerHTML}
                        </body>
                        </html>
                    `);
                } else if (mimeType === 'application/pdf') {
                    // Exibe PDF
                    const pdfWindow = window.open('', '_blank');
                    pdfWindow.document.write(`
                        <html>
                        <head><title>${data.filename}</title></head>
                        <body style="margin: 0;">
                            <embed src="data:${mimeType};base64,${base64Data}" type="application/pdf" width="100%" height="100%">
                        </body>
                        </html>
                    `);
                }
            }
        } else {
            alert('Erro ao carregar comprovante: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Erro:', error);
        alert('Erro ao carregar comprovante');
    });
}

/**
 * Deleta comprovante
 */
function deleteReceiptConfirm(promoter, month) {
    if (!confirm('Tem certeza que deseja DELETAR o comprovante deste pagamento?')) {
        return;
    }

    fetch('ajax_receipt_upload.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `action=delete_receipt&promoter=${encodeURIComponent(promoter)}&month=${encodeURIComponent(month)}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Comprovante deletado com sucesso!');
            closeDetailsModal();
            setTimeout(() => openDetailsModal(promoter), 300);
        } else {
            alert('Erro ao deletar: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Erro:', error);
        alert('Erro ao deletar comprovante');
    });
}

// Preview de imagem ao selecionar arquivo
document.addEventListener('DOMContentLoaded', function() {
    const fileInput = document.getElementById('receipt_file');
    const preview = document.getElementById('receiptPreview');
    const previewImage = document.getElementById('previewImage');
    const storageModeInputs = document.querySelectorAll('input[name="storage_mode"]');
    const fileUploadSection = document.getElementById('fileUploadSection');
    const uploadButtonText = document.getElementById('uploadButtonText');

    // Atualiza visual quando muda o modo de armazenamento
    storageModeInputs.forEach(input => {
        input.addEventListener('change', function() {
            if (this.value === 'none') {
                fileUploadSection.style.display = 'none';
                preview.style.display = 'none';
                fileInput.required = false;
                uploadButtonText.textContent = 'Remover Comprovante';
            } else {
                fileUploadSection.style.display = 'block';
                fileInput.required = true;
                uploadButtonText.textContent = 'Enviar Comprovante';
            }
        });
    });

    // Preview de imagem
    if (fileInput) {
        fileInput.addEventListener('change', function(e) {
            const file = e.target.files[0];

            if (file) {
                const reader = new FileReader();

                reader.onload = function(e) {
                    if (file.type.startsWith('image/')) {
                        previewImage.src = e.target.result;
                        preview.style.display = 'block';
                    } else {
                        preview.style.display = 'none';
                    }
                };

                reader.readAsDataURL(file);
            } else {
                preview.style.display = 'none';
            }
        });
    }

    // Submit do formulário de upload
    const uploadForm = document.getElementById('receiptUploadForm');
    if (uploadForm) {
        uploadForm.addEventListener('submit', function(e) {
            e.preventDefault();

            const formData = new FormData(this);
            formData.append('action', 'upload_receipt');

            const uploadProgress = document.getElementById('uploadProgress');
            const submitBtn = uploadForm.querySelector('button[type="submit"]');

            submitBtn.disabled = true;
            uploadProgress.style.display = 'block';

            fetch('ajax_receipt_upload.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                submitBtn.disabled = false;
                uploadProgress.style.display = 'none';

                if (data.success) {
                    alert(data.message);
                    closeReceiptModal();

                    // Se há checkbox pendente, atualiza o status do pagamento
                    if (window.pendingPaymentCheckbox) {
                        const checkbox = window.pendingPaymentCheckbox;
                        checkbox.checked = true;
                        checkbox.disabled = false;

                        // Limpa referência
                        delete window.pendingPaymentCheckbox;

                        // Recarrega dados do promotor
                        const promoter = document.getElementById('receipt_promoter').value;
                        setTimeout(() => {
                            // Atualiza a página para refletir mudanças
                            window.location.reload();
                        }, 500);
                    } else {
                        // Modo de edição de comprovante - recarrega modal de detalhes
                        const promoter = document.getElementById('receipt_promoter').value;
                        closeDetailsModal();
                        setTimeout(() => openDetailsModal(promoter), 300);
                    }
                } else {
                    alert('Erro: ' + data.message);
                }
            })
            .catch(error => {
                submitBtn.disabled = false;
                uploadProgress.style.display = 'none';
                console.error('Erro:', error);
                alert('Erro ao enviar comprovante');
            });
        });
    }
});