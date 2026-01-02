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
            `;
        } else {
            row.innerHTML = `
                <td></td>
                <td><strong>${month.label}</strong></td>
                <td style="color: #999;">-</td>
                <td style="color: #999;">-</td>
                <td style="color: #999;">-</td>
                <td style="color: #999;">Sem vendas</td>
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
    const action = checkbox.checked ? 'marcar como PAGO' : 'desmarcar como PAGO';
    const message = `Tem certeza que deseja ${action} a comissão de ${promoter} para o período ${month}?`;
    
    if (confirm(message)) {
        // Confirma novamente para ações críticas
        const doubleCheck = confirm('Esta ação será registrada no sistema. Confirma?');
        if (doubleCheck) {
            togglePayment(promoter, month, checkbox.checked, checkbox);
        } else {
            checkbox.checked = !checkbox.checked;
        }
    } else {
        checkbox.checked = !checkbox.checked;
    }
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
    }
});