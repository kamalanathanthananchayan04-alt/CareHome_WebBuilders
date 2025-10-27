// Accounts page functionality
document.addEventListener('DOMContentLoaded', function() {
    // Set today's date as default
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('transactionDate').value = today;
    
    // Sub-content navigation
    const subNavBtns = document.querySelectorAll('.sub-nav-btn');
    const subContents = document.querySelectorAll('.sub-content');

    subNavBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const target = this.getAttribute('data-target');
            
            // Remove active class from all buttons and contents
            subNavBtns.forEach(b => b.classList.remove('active'));
            subContents.forEach(c => c.classList.remove('active'));
            
            // Add active class to clicked button and target content
            this.classList.add('active');
            document.getElementById(target).classList.add('active');
        });
    });

    // Form submissions
    const forms = document.querySelectorAll('.account-form');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            handleFormSubmission(this);
        });
    });

    // Transaction date change
    const transactionDate = document.getElementById('transactionDate');
    if (transactionDate) {
        transactionDate.addEventListener('change', function() {
            loadTransactionsForDate(this.value);
        });
    }

    // Transaction type filter
    const transactionType = document.getElementById('transactionType');
    if (transactionType) {
        transactionType.addEventListener('change', function() {
            filterTransactions(this.value);
        });
    }

    // Action buttons
    document.addEventListener('click', function(e) {
        if (e.target.closest('.btn-view')) {
            viewTransaction(e.target.closest('tr'));
        }
    });
});

function handleFormSubmission(form) {
    const formData = new FormData(form);
    const data = Object.fromEntries(formData);
    
    // Validate form
    if (validateAccountForm(data)) {
        // Process the form based on its ID
        const formId = form.closest('.sub-content').id;
        
        switch(formId) {
            case 'add-income':
                processIncome(data);
                break;
            case 'add-expense':
                processExpense(data);
                break;
            case 'drop-amount':
                processDrop(data);
                break;
        }
        
        // Show success notification
        showNotification('Transaction recorded successfully!', 'success');
        
        // Reset form
        form.reset();
        
        // Update financial summary
        updateFinancialSummary();
    }
}

function validateAccountForm(data) {
    const required = ['amount', 'date'];
    
    for (let field of required) {
        if (!data[field] || data[field].trim() === '') {
            showNotification(`Please fill in the ${field} field`, 'error');
            return false;
        }
    }
    
    // Validate amount
    const amount = parseFloat(data.amount);
    if (isNaN(amount) || amount <= 0) {
        showNotification('Please enter a valid amount greater than 0', 'error');
        return false;
    }
    
    return true;
}

function processIncome(data) {
    // Add income to daily transactions
    addTransactionToTable({
        time: new Date().toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }),
        type: 'income',
        description: `${data.incomeType} - ${data.incomeSource}`,
        amount: parseFloat(data.incomeAmount),
        user: 'Admin'
    });
    
    // Update summary cards
    updateSummaryCard('income', parseFloat(data.incomeAmount));
}

function processExpense(data) {
    // Add expense to daily transactions
    addTransactionToTable({
        time: new Date().toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }),
        type: 'expense',
        description: `${data.expenseType} - ${data.expenseVendor}`,
        amount: parseFloat(data.expenseAmount),
        user: 'Admin'
    });
    
    // Update summary cards
    updateSummaryCard('expense', parseFloat(data.expenseAmount));
}

function processDrop(data) {
    // Add drop to daily transactions
    addTransactionToTable({
        time: new Date().toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }),
        type: 'drop',
        description: `${data.dropReason} - ${data.dropDescription}`,
        amount: parseFloat(data.dropAmount),
        user: 'Admin'
    });
    
    // Update summary cards
    updateSummaryCard('expense', parseFloat(data.dropAmount));
}

function addTransactionToTable(transaction) {
    const tableBody = document.getElementById('transactionsTableBody');
    const newRow = document.createElement('tr');
    newRow.className = `transaction-${transaction.type}`;
    
    const typeIcon = transaction.type === 'income' ? 'arrow-up' : 
                    transaction.type === 'expense' ? 'arrow-down' : 'hand-holding-usd';
    const typeLabel = transaction.type === 'income' ? 'Transfer in' : (transaction.type === 'expense' ? 'Transfer out' : (transaction.type === 'drop' ? 'Paid back' : (transaction.type.charAt ? transaction.type.charAt(0).toUpperCase() + transaction.type.slice(1) : transaction.type)));

    const amountClass = transaction.type === 'income' ? 'income' : 
                       transaction.type === 'expense' ? 'expense' : 'drop';
    const amountSign = transaction.type === 'income' ? '+' : '-';
    
    newRow.innerHTML = `
        <td>${transaction.time}</td>
        <td>
            <span class="transaction-type ${transaction.type}">
                <i class="fas fa-${typeIcon}"></i>
                ${typeLabel}
            </span>
        </td>
        <td>${transaction.description}</td>
        <td class="amount ${amountClass}">${amountSign}$${transaction.amount.toFixed(2)}</td>
        <td>${transaction.user}</td>
        <td>
            <button class="btn-action btn-view" title="View Details">
                <i class="fas fa-eye"></i>
            </button>
        </td>
    `;
    
    // Add animation
    newRow.style.opacity = '0';
    newRow.style.transform = 'translateY(-20px)';
    tableBody.insertBefore(newRow, tableBody.firstChild);
    
    // Animate in
    setTimeout(() => {
        newRow.style.transition = 'all 0.3s ease';
        newRow.style.opacity = '1';
        newRow.style.transform = 'translateY(0)';
    }, 100);
    
    // Update transaction summary
    updateTransactionSummary();
}

function updateSummaryCard(type, amount) {
    const cards = document.querySelectorAll('.summary-card');
    cards.forEach(card => {
        if (card.classList.contains(type)) {
            const amountElement = card.querySelector('.summary-amount');
            const currentAmount = parseFloat(amountElement.textContent.replace(/[$,]/g, ''));
            const newAmount = currentAmount + amount;
            amountElement.textContent = '$' + newAmount.toLocaleString('en-US', { minimumFractionDigits: 2 });
        }
    });
    
    // Update profit card
    const incomeCard = document.querySelector('.summary-card.income .summary-amount');
    const expenseCard = document.querySelector('.summary-card.expense .summary-amount');
    const profitCard = document.querySelector('.summary-card.profit .summary-amount');
    
    if (incomeCard && expenseCard && profitCard) {
        const income = parseFloat(incomeCard.textContent.replace(/[$,]/g, ''));
        const expense = parseFloat(expenseCard.textContent.replace(/[$,]/g, ''));
        const profit = income - expense;
        profitCard.textContent = '$' + profit.toLocaleString('en-US', { minimumFractionDigits: 2 });
    }
}

function loadTransactionsForDate(date) {
    // In a real application, this would load transactions from a server
    showNotification(`Loading transactions for ${date}`, 'info');
}

function filterTransactions(type) {
    const rows = document.querySelectorAll('#transactionsTableBody tr');
    
    rows.forEach(row => {
        if (!type || row.classList.contains(`transaction-${type}`)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

function updateTransactionSummary() {
    const rows = document.querySelectorAll('#transactionsTableBody tr');
    let totalIncome = 0;
    let totalExpense = 0;
    let totalDrop = 0;
    
    rows.forEach(row => {
        const amountText = row.querySelector('.amount').textContent;
        const amount = parseFloat(amountText.replace(/[+$,]/g, ''));
        
        if (row.classList.contains('transaction-income')) {
            totalIncome += amount;
        } else if (row.classList.contains('transaction-expense')) {
            totalExpense += amount;
        } else if (row.classList.contains('transaction-drop')) {
            totalDrop += amount;
        }
    });
    
    const summaryItems = document.querySelectorAll('.transaction-summary .summary-item');
    if (summaryItems.length >= 4) {
        summaryItems[0].querySelector('.summary-amount').textContent = `+$${totalIncome.toFixed(2)}`;
        summaryItems[1].querySelector('.summary-amount').textContent = `-$${totalExpense.toFixed(2)}`;
        summaryItems[2].querySelector('.summary-amount').textContent = `-$${totalDrop.toFixed(2)}`;
        summaryItems[3].querySelector('.summary-amount').textContent = `+$${(totalIncome - totalExpense - totalDrop).toFixed(2)}`;
    }
}

function viewTransaction(row) {
    const description = row.querySelector('td:nth-child(3)').textContent;
    const amount = row.querySelector('td:nth-child(4)').textContent;
    showNotification(`Viewing: ${description} - ${amount}`, 'info');
}

// Add CSS for accounts page
const style = document.createElement('style');
style.textContent = `
    .page-header {
        background: white;
        padding: 2rem;
        border-radius: 15px;
        box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
        margin-bottom: 2rem;
        text-align: center;
        animation: fadeInUp 0.6s ease;
    }
    
    .page-header h2 {
        color: #2c3e50;
        font-size: 2rem;
        margin-bottom: 0.5rem;
        background: linear-gradient(135deg, #667eea, #764ba2);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }
    
    .page-header p {
        color: #7f8c8d;
        font-size: 1.1rem;
    }
    
    .financial-summary {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2rem;
    }
    
    .summary-card {
        background: white;
        padding: 2rem;
        border-radius: 15px;
        box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
        display: flex;
        align-items: center;
        gap: 1.5rem;
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
    }
    
    .summary-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
    }
    
    .summary-card.income::before {
        background: linear-gradient(90deg, #2ecc71, #27ae60);
    }
    
    .summary-card.expense::before {
        background: linear-gradient(90deg, #e74c3c, #c0392b);
    }
    
    .summary-card.profit::before {
        background: linear-gradient(90deg, #3498db, #2980b9);
    }
    
    .summary-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
    }
    
    .summary-icon {
        width: 60px;
        height: 60px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 1.5rem;
        animation: pulse 2s infinite;
    }
    
    .summary-card.income .summary-icon {
        background: linear-gradient(135deg, #2ecc71, #27ae60);
    }
    
    .summary-card.expense .summary-icon {
        background: linear-gradient(135deg, #e74c3c, #c0392b);
    }
    
    .summary-card.profit .summary-icon {
        background: linear-gradient(135deg, #3498db, #2980b9);
    }
    
    .summary-content h3 {
        color: #7f8c8d;
        font-size: 0.9rem;
        font-weight: 500;
        margin-bottom: 0.5rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .summary-amount {
        color: #2c3e50;
        font-size: 2rem;
        font-weight: 700;
        margin: 0;
    }
    
    .summary-content small {
        color: #95a5a6;
        font-size: 0.8rem;
    }
    
    .account-form {
        display: grid;
        gap: 1.5rem;
    }
    
    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1.5rem;
    }
    
    .form-group {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }
    
    .form-group label {
        font-weight: 600;
        color: #2c3e50;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .form-group label i {
        color: #3498db;
        width: 16px;
    }
    
    .form-group input,
    .form-group select,
    .form-group textarea {
        padding: 0.75rem;
        border: 2px solid #e9ecef;
        border-radius: 8px;
        font-size: 1rem;
        transition: all 0.3s ease;
    }
    
    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
        outline: none;
        border-color: #3498db;
        box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
    }
    
    .form-actions {
        display: flex;
        gap: 1rem;
        justify-content: flex-end;
        margin-top: 2rem;
    }
    
    .btn-secondary {
        background: #6c757d;
        color: white;
    }
    
    .btn-secondary:hover {
        background: #5a6268;
    }
    
    .transaction-controls {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 2rem;
        gap: 1rem;
    }
    
    .date-picker {
        display: flex;
        align-items: center;
        gap: 1rem;
    }
    
    .date-picker label {
        font-weight: 600;
        color: #2c3e50;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .date-picker input {
        padding: 0.75rem;
        border: 2px solid #e9ecef;
        border-radius: 8px;
        font-size: 1rem;
    }
    
    .transaction-filters select {
        padding: 0.75rem 1rem;
        border: 2px solid #e9ecef;
        border-radius: 8px;
        background: white;
        cursor: pointer;
    }
    
    .transactions-table-container {
        background: white;
        border-radius: 10px;
        overflow: hidden;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        margin-bottom: 2rem;
    }
    
    .transactions-table {
        width: 100%;
        border-collapse: collapse;
    }
    
    .transactions-table th {
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
        padding: 1rem;
        text-align: left;
        font-weight: 600;
    }
    
    .transactions-table th i {
        margin-right: 0.5rem;
    }
    
    .transactions-table td {
        padding: 1rem;
        border-bottom: 1px solid #e9ecef;
        vertical-align: middle;
    }
    
    .transactions-table tr:hover {
        background: #f8f9fa;
    }
    
    .transaction-type {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
        text-transform: uppercase;
    }
    
    .transaction-type.income {
        background: #d5f4e6;
        color: #27ae60;
    }
    
    .transaction-type.expense {
        background: #fadbd8;
        color: #e74c3c;
    }
    
    .transaction-type.drop {
        background: #fef9e7;
        color: #f39c12;
    }
    
    .amount.income {
        color: #27ae60;
        font-weight: 700;
    }
    
    .amount.expense {
        color: #e74c3c;
        font-weight: 700;
    }
    
    .amount.drop {
        color: #f39c12;
        font-weight: 700;
    }
    
    .btn-action {
        width: 32px;
        height: 32px;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.3s ease;
        background: #3498db;
        color: white;
    }
    
    .btn-action:hover {
        transform: scale(1.1);
    }
    
    .transaction-summary {
        background: white;
        padding: 1.5rem;
        border-radius: 10px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
    }
    
    .summary-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 1rem;
        border-radius: 8px;
        background: #f8f9fa;
    }
    
    .summary-item.total {
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
        font-weight: 700;
    }
    
    .summary-amount {
        font-weight: 700;
        font-size: 1.1rem;
    }
    
    @media (max-width: 768px) {
        .form-row {
            grid-template-columns: 1fr;
        }
        
        .transaction-controls {
            flex-direction: column;
            align-items: stretch;
        }
        
        .transaction-summary {
            grid-template-columns: 1fr;
        }
        
        .transactions-table {
            font-size: 0.9rem;
        }
    }
`;
document.head.appendChild(style);
