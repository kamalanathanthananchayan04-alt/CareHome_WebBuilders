// Residents page functionality
document.addEventListener('DOMContentLoaded', function() {
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

    // Form submission
    const residentForm = document.querySelector('.resident-form');
    if (residentForm) {
        residentForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            // Get form data
            const formData = new FormData(this);
            const residentData = Object.fromEntries(formData);
            
            // Validate form
            if (validateResidentForm(residentData)) {
                // Add resident to table
                addResidentToTable(residentData);
                
                // Show success notification
                showNotification('Resident added successfully!', 'success');
                
                // Reset form
                this.reset();
                
                // Switch to list view
                document.querySelector('[data-target="list-residents"]').click();
            }
        });
    }

    // Search functionality
    const searchInput = document.getElementById('searchResidents');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            filterResidents(this.value);
        });
    }

    // Filter functionality
    const filterRoom = document.getElementById('filterRoom');
    const filterGender = document.getElementById('filterGender');
    
    if (filterRoom) {
        filterRoom.addEventListener('change', applyFilters);
    }
    if (filterGender) {
        filterGender.addEventListener('change', applyFilters);
    }

    // Action buttons
    document.addEventListener('click', function(e) {
        if (e.target.closest('.btn-edit')) {
            editResident(e.target.closest('tr'));
        } else if (e.target.closest('.btn-view')) {
            viewResident(e.target.closest('tr'));
        } else if (e.target.closest('.btn-delete')) {
            deleteResident(e.target.closest('tr'));
        }
    });
});

function validateResidentForm(data) {
    const required = ['firstName', 'lastName', 'dateOfBirth', 'gender', 'nhsNumber', 'phone', 'nokName', 'nokNumber', 'address', 'admissionDate', 'roomNumber'];
    
    for (let field of required) {
        if (!data[field] || data[field].trim() === '') {
            showNotification(`Please fill in the ${field.replace(/([A-Z])/g, ' $1').toLowerCase()} field`, 'error');
            return false;
        }
    }
    
    // Validate NHS Number (10 digits)
    const nhsRegex = /^\d{10}$/;
    if (!nhsRegex.test((data.nhsNumber || '').replace(/\s/g, ''))) {
        showNotification('Please enter a valid 10 digit NHS Number', 'error');
        return false;
    }

    // Validate phone number format
    const phoneRegex = /^[\+]?[1-9][\d]{0,15}$/;
    if (!phoneRegex.test(data.phone.replace(/[\s\-\(\)]/g, ''))) {
        showNotification('Please enter a valid phone number', 'error');
        return false;
    }
    if (!phoneRegex.test((data.nokNumber || '').replace(/[\s\-\(\)]/g, ''))) {
        showNotification('Please enter a valid NOK phone number', 'error');
        return false;
    }
    
    return true;
}

function addResidentToTable(data) {
    const tableBody = document.getElementById('residentsTableBody');
    const newRow = document.createElement('tr');
    
    // Calculate age
    const birthDate = new Date(data.dateOfBirth);
    const today = new Date();
    const age = today.getFullYear() - birthDate.getFullYear();
    
    // Generate resident ID
    const residentId = 'RES' + String(Date.now()).slice(-3);
    
    newRow.innerHTML = `
        <td>
            <div class="resident-info">
                <div class="resident-avatar">
                    <i class="fas fa-user"></i>
                </div>
                <div>
                    <strong>${data.firstName} ${data.lastName}</strong>
                    <small>ID: ${residentId}</small>
                </div>
            </div>
        </td>
        <td>${age}</td>
        <td>Room ${data.roomNumber}</td>
        <td>${data.phone}</td>
        <td>${data.admissionDate}</td>
        <td>
            <div class="action-buttons">
                <button class="btn-action btn-edit" title="Edit">
                    <i class="fas fa-edit"></i>
                </button>
                <button class="btn-action btn-view" title="View Details">
                    <i class="fas fa-eye"></i>
                </button>
                <button class="btn-action btn-delete" title="Delete">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        </td>
    `;
    
    // Add animation
    newRow.style.opacity = '0';
    newRow.style.transform = 'translateY(-20px)';
    tableBody.appendChild(newRow);
    
    // Animate in
    setTimeout(() => {
        newRow.style.transition = 'all 0.3s ease';
        newRow.style.opacity = '1';
        newRow.style.transform = 'translateY(0)';
    }, 100);
}

function filterResidents(searchTerm) {
    const rows = document.querySelectorAll('#residentsTableBody tr');
    
    rows.forEach(row => {
        const name = row.querySelector('strong').textContent.toLowerCase();
        const room = row.querySelector('td:nth-child(3)').textContent.toLowerCase();
        const phone = row.querySelector('td:nth-child(4)').textContent.toLowerCase();
        
        const matchesSearch = name.includes(searchTerm.toLowerCase()) || 
                            room.includes(searchTerm.toLowerCase()) || 
                            phone.includes(searchTerm.toLowerCase());
        
        row.style.display = matchesSearch ? '' : 'none';
    });
}

function applyFilters() {
    const roomFilter = document.getElementById('filterRoom').value;
    const genderFilter = document.getElementById('filterGender').value;
    const rows = document.querySelectorAll('#residentsTableBody tr');
    
    rows.forEach(row => {
        const room = row.querySelector('td:nth-child(3)').textContent;
        const gender = row.querySelector('td:nth-child(2)').textContent; // This would need to be updated to include gender
        
        const matchesRoom = !roomFilter || room.includes(roomFilter);
        const matchesGender = !genderFilter; // Simplified for demo
        
        row.style.display = matchesRoom && matchesGender ? '' : 'none';
    });
}

function editResident(row) {
    showNotification('Edit functionality coming soon!', 'info');
}

function viewResident(row) {
    const name = row.querySelector('strong').textContent;
    showNotification(`Viewing details for ${name}`, 'info');
}

function deleteResident(row) {
    if (confirm('Are you sure you want to delete this resident?')) {
        row.style.animation = 'fadeOut 0.3s ease';
        setTimeout(() => {
            row.remove();
            showNotification('Resident deleted successfully', 'success');
        }, 300);
    }
}

// Add CSS for animations
const style = document.createElement('style');
style.textContent = `
    @keyframes fadeOut {
        from {
            opacity: 1;
            transform: translateX(0);
        }
        to {
            opacity: 0;
            transform: translateX(-100%);
        }
    }
    
    .sub-content-nav {
        display: flex;
        gap: 1rem;
        margin-bottom: 2rem;
    }
    
    .sub-nav-btn {
        background: white;
        border: 2px solid #e9ecef;
        padding: 1rem 2rem;
        border-radius: 10px;
        cursor: pointer;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-weight: 500;
        color: #6c757d;
    }
    
    .sub-nav-btn:hover {
        border-color: #3498db;
        color: #3498db;
        transform: translateY(-2px);
    }
    
    .sub-nav-btn.active {
        background: linear-gradient(135deg, #3498db, #2ecc71);
        color: white;
        border-color: transparent;
    }
    
    .resident-form {
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
    
    .residents-controls {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 2rem;
        gap: 1rem;
    }
    
    .search-box {
        position: relative;
        flex: 1;
        max-width: 400px;
    }
    
    .search-box i {
        position: absolute;
        left: 1rem;
        top: 50%;
        transform: translateY(-50%);
        color: #6c757d;
    }
    
    .search-box input {
        width: 100%;
        padding: 0.75rem 1rem 0.75rem 3rem;
        border: 2px solid #e9ecef;
        border-radius: 25px;
        font-size: 1rem;
        transition: all 0.3s ease;
    }
    
    .search-box input:focus {
        outline: none;
        border-color: #3498db;
        box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
    }
    
    .filter-controls {
        display: flex;
        gap: 1rem;
    }
    
    .filter-controls select {
        padding: 0.75rem 1rem;
        border: 2px solid #e9ecef;
        border-radius: 8px;
        background: white;
        cursor: pointer;
    }
    
    .residents-table-container {
        background: white;
        border-radius: 10px;
        overflow: hidden;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
    }
    
    .residents-table {
        width: 100%;
        border-collapse: collapse;
    }
    
    .residents-table th {
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
        padding: 1rem;
        text-align: left;
        font-weight: 600;
    }
    
    .residents-table th i {
        margin-right: 0.5rem;
    }
    
    .residents-table td {
        padding: 1rem;
        border-bottom: 1px solid #e9ecef;
        vertical-align: middle;
    }
    
    .residents-table tr:hover {
        background: #f8f9fa;
    }
    
    .resident-info {
        display: flex;
        align-items: center;
        gap: 1rem;
    }
    
    .resident-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: linear-gradient(135deg, #3498db, #2ecc71);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
    }
    
    .action-buttons {
        display: flex;
        gap: 0.5rem;
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
    }
    
    .btn-edit {
        background: #f39c12;
        color: white;
    }
    
    .btn-edit:hover {
        background: #e67e22;
        transform: scale(1.1);
    }
    
    .btn-view {
        background: #3498db;
        color: white;
    }
    
    .btn-view:hover {
        background: #2980b9;
        transform: scale(1.1);
    }
    
    .btn-delete {
        background: #e74c3c;
        color: white;
    }
    
    .btn-delete:hover {
        background: #c0392b;
        transform: scale(1.1);
    }
    
    @media (max-width: 768px) {
        .form-row {
            grid-template-columns: 1fr;
        }
        
        .residents-controls {
            flex-direction: column;
            align-items: stretch;
        }
        
        .filter-controls {
            justify-content: space-between;
        }
        
        .residents-table {
            font-size: 0.9rem;
        }
        
        .action-buttons {
            flex-direction: column;
        }
    }
`;
document.head.appendChild(style);
