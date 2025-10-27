// Care Home Selector - Shared functionality for all admin pages
// Sample data for multiple care homes
const careHomesData = {
    1: {
        id: 1,
        name: "Rolekulla Care Home",
        address: "12 Elm Street, London",
        beds: 40,
        residents: 28,
        bank: 5000,
        cash: 2500,
        balance: 7500,
        userName: "roadmin",
        userPassword: "••••••",
        lastAction: "Payment received - 2h ago"
    },
    2: {
        id: 2,
        name: "Sunshine Care Home",
        address: "45 Oak Avenue, Manchester",
        beds: 35,
        residents: 32,
        bank: 3200,
        cash: 1800,
        balance: 5000,
        userName: "sunadmin",
        userPassword: "••••••",
        lastAction: "New resident admitted - 1h ago"
    },
    3: {
        id: 3,
        name: "Golden Years Care Home",
        address: "78 Pine Street, Birmingham",
        beds: 50,
        residents: 45,
        bank: 7500,
        cash: 3200,
        balance: 10700,
        userName: "goldadmin",
        userPassword: "••••••",
        lastAction: "Expense recorded - 30m ago"
    },
    4: {
        id: 4,
        name: "Peaceful Living Care Home",
        address: "123 Maple Drive, Liverpool",
        beds: 30,
        residents: 25,
        bank: 2100,
        cash: 900,
        balance: 3000,
        userName: "peaceadmin",
        userPassword: "••••••",
        lastAction: "Income added - 15m ago"
    }
};

let selectedCareHome = null;

// Initialize care home selector functionality
function initCareHomeSelector() {
    const selector = document.getElementById('carehomeSelector');
    const refreshBtn = document.getElementById('btnRefreshData');
    
    if (!selector) return;

    // Care home selector change
    selector.addEventListener('change', function() {
        selectedCareHome = this.value;
        onCareHomeChange(selectedCareHome);
    });

    // Refresh button
    if (refreshBtn) {
        refreshBtn.addEventListener('click', function() {
            if (selectedCareHome) {
                onCareHomeChange(selectedCareHome);
                showNotification('Data refreshed successfully!', 'success');
            } else {
                showNotification('Please select a care home first.', 'warning');
            }
        });
    }

    // Load saved selection from localStorage
    const savedSelection = localStorage.getItem('selectedCareHome');
    if (savedSelection && careHomesData[savedSelection]) {
        selector.value = savedSelection;
        selectedCareHome = savedSelection;
        onCareHomeChange(selectedCareHome);
    }
}

// Handle care home selection change
function onCareHomeChange(careHomeId) {
    if (!careHomeId || !careHomesData[careHomeId]) {
        showNotification('Please select a valid care home.', 'warning');
        return;
    }

    // Save selection to localStorage
    localStorage.setItem('selectedCareHome', careHomeId);
    
    // Get selected care home data
    const selectedHome = careHomesData[careHomeId];
    
    // Update page title to show selected care home
    updatePageTitle(selectedHome.name);
    
    // Call page-specific update function
    if (typeof updatePageForCareHome === 'function') {
        updatePageForCareHome(selectedHome);
    }
    
    showNotification(`Switched to ${selectedHome.name}`, 'success');
}

// Update page title to show selected care home
function updatePageTitle(careHomeName) {
    const pageTitle = document.querySelector('h1');
    if (pageTitle && !pageTitle.querySelector('.carehome-indicator')) {
        const indicator = document.createElement('span');
        indicator.className = 'carehome-indicator';
        indicator.style.cssText = 'font-size:0.7em;color:#666;margin-left:10px;font-weight:normal;';
        indicator.textContent = `(${careHomeName})`;
        pageTitle.appendChild(indicator);
    }
}

// Show notification
function showNotification(message, type = 'info') {
    // Create notification element
    const notification = document.createElement('div');
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 12px 20px;
        border-radius: 6px;
        color: white;
        font-weight: 500;
        z-index: 10000;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        transform: translateX(100%);
        transition: transform 0.3s ease;
    `;
    
    // Set background color based on type
    const colors = {
        success: '#27ae60',
        warning: '#f39c12',
        error: '#e74c3c',
        info: '#3498db'
    };
    notification.style.backgroundColor = colors[type] || colors.info;
    notification.textContent = message;
    
    document.body.appendChild(notification);
    
    // Animate in
    setTimeout(() => {
        notification.style.transform = 'translateX(0)';
    }, 100);
    
    // Remove after 3 seconds
    setTimeout(() => {
        notification.style.transform = 'translateX(100%)';
        setTimeout(() => {
            if (notification.parentNode) {
                notification.parentNode.removeChild(notification);
            }
        }, 300);
    }, 3000);
}

// Get current selected care home data
function getCurrentCareHome() {
    return selectedCareHome ? careHomesData[selectedCareHome] : null;
}

// Get all care homes data
function getAllCareHomes() {
    return careHomesData;
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', initCareHomeSelector);
