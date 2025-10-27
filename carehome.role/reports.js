// Reports page functionality
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

    // Report type changes
    const residentReportType = document.getElementById('residentReportType');
    const carehomeReportType = document.getElementById('carehomeReportType');
    
    if (residentReportType) {
        residentReportType.addEventListener('change', function() {
            updateResidentReportPreview(this.value);
        });
    }
    
    if (carehomeReportType) {
        carehomeReportType.addEventListener('change', function() {
            updateCarehomeReportPreview(this.value);
        });
    }

    // Date range changes
    const residentDateRange = document.getElementById('residentDateRange');
    const carehomeDateRange = document.getElementById('carehomeDateRange');
    
    if (residentDateRange) {
        residentDateRange.addEventListener('change', function() {
            updateDateRange(this.value, 'resident');
        });
    }
    
    if (carehomeDateRange) {
        carehomeDateRange.addEventListener('change', function() {
            updateDateRange(this.value, 'carehome');
        });
    }

    // Initialize report previews
    updateResidentReportPreview('individual');
    updateCarehomeReportPreview('financial');
});

function updateResidentReportPreview(reportType) {
    const previewContent = document.querySelector('#resident-report .preview-content');
    
    let previewHTML = '';
    
    switch(reportType) {
        case 'individual':
            previewHTML = `
                <div class="report-stats">
                    <div class="stat-item">
                        <i class="fas fa-user"></i>
                        <span>Selected Resident: John Smith</span>
                    </div>
                    <div class="stat-item">
                        <i class="fas fa-bed"></i>
                        <span>Room: 101</span>
                    </div>
                    <div class="stat-item">
                        <i class="fas fa-calendar"></i>
                        <span>Admission: 2024-01-15</span>
                    </div>
                    <div class="stat-item">
                        <i class="fas fa-heart"></i>
                        <span>Health Status: Good</span>
                    </div>
                </div>
                <div class="resident-details-preview">
                    <h4>Resident Details</h4>
                    <div class="detail-grid">
                        <div class="detail-item">
                            <label>Full Name:</label>
                            <span>John Smith</span>
                        </div>
                        <div class="detail-item">
                            <label>Age:</label>
                            <span>72 years</span>
                        </div>
                        <div class="detail-item">
                            <label>Gender:</label>
                            <span>Male</span>
                        </div>
                        <div class="detail-item">
                            <label>Phone:</label>
                            <span>+1 234-567-8900</span>
                        </div>
                        <div class="detail-item">
                            <label>Emergency Contact:</label>
                            <span>Jane Smith (+1 234-567-8901)</span>
                        </div>
                        <div class="detail-item">
                            <label>Medical Conditions:</label>
                            <span>Diabetes, Hypertension</span>
                        </div>
                    </div>
                </div>
            `;
            break;
        case 'all':
            previewHTML = `
                <div class="report-stats">
                    <div class="stat-item">
                        <i class="fas fa-users"></i>
                        <span>Total Residents: 45</span>
                    </div>
                    <div class="stat-item">
                        <i class="fas fa-bed"></i>
                        <span>Occupied Rooms: 38</span>
                    </div>
                    <div class="stat-item">
                        <i class="fas fa-percentage"></i>
                        <span>Occupancy Rate: 84%</span>
                    </div>
                    <div class="stat-item">
                        <i class="fas fa-calendar-plus"></i>
                        <span>New Admissions: 3</span>
                    </div>
                </div>
                <div class="resident-list-preview">
                    <h4>All Residents Summary</h4>
                    <div class="resident-item">
                        <div class="resident-avatar">
                            <i class="fas fa-user"></i>
                        </div>
                        <div class="resident-details">
                            <h5>John Smith</h5>
                            <p>Room 101 • Age 72 • Admitted: 2024-01-15</p>
                        </div>
                        <div class="resident-status">
                            <span class="status-badge active">Active</span>
                        </div>
                    </div>
                    <div class="resident-item">
                        <div class="resident-avatar">
                            <i class="fas fa-user"></i>
                        </div>
                        <div class="resident-details">
                            <h5>Mary Johnson</h5>
                            <p>Room 102 • Age 68 • Admitted: 2024-01-20</p>
                        </div>
                        <div class="resident-status">
                            <span class="status-badge active">Active</span>
                        </div>
                    </div>
                    <div class="resident-item">
                        <div class="resident-avatar">
                            <i class="fas fa-user"></i>
                        </div>
                        <div class="resident-details">
                            <h5>Robert Wilson</h5>
                            <p>Room 103 • Age 75 • Admitted: 2024-02-01</p>
                        </div>
                        <div class="resident-status">
                            <span class="status-badge active">Active</span>
                        </div>
                    </div>
                </div>
            `;
            break;
        case 'medical':
            previewHTML = `
                <div class="report-stats">
                    <div class="stat-item">
                        <i class="fas fa-heartbeat"></i>
                        <span>Medical Records: 45</span>
                    </div>
                    <div class="stat-item">
                        <i class="fas fa-pills"></i>
                        <span>Active Medications: 127</span>
                    </div>
                    <div class="stat-item">
                        <i class="fas fa-user-md"></i>
                        <span>Doctor Visits: 23</span>
                    </div>
                    <div class="stat-item">
                        <i class="fas fa-ambulance"></i>
                        <span>Emergency Visits: 2</span>
                    </div>
                </div>
                <div class="medical-preview">
                    <h4>Medical Summary</h4>
                    <div class="medical-chart">
                        <div class="chart-item">
                            <div class="chart-label">Healthy Residents</div>
                            <div class="chart-bar">
                                <div class="bar-fill healthy" style="width: 70%"></div>
                                <span class="bar-value">70%</span>
                            </div>
                        </div>
                        <div class="chart-item">
                            <div class="chart-label">Chronic Conditions</div>
                            <div class="chart-bar">
                                <div class="bar-fill chronic" style="width: 25%"></div>
                                <span class="bar-value">25%</span>
                            </div>
                        </div>
                        <div class="chart-item">
                            <div class="chart-label">Critical Care</div>
                            <div class="chart-bar">
                                <div class="bar-fill critical" style="width: 5%"></div>
                                <span class="bar-value">5%</span>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            break;
    }
    
    if (previewContent) {
        previewContent.innerHTML = previewHTML;
    }
}

function updateCarehomeReportPreview(reportType) {
    const previewContent = document.querySelector('#carehome-report .preview-content');
    
    let previewHTML = '';
    
    switch(reportType) {
        case 'financial':
            previewHTML = `
                <div class="report-stats">
                    <div class="stat-item">
                        <i class="fas fa-dollar-sign"></i>
                        <span>Monthly Revenue: $25,450</span>
                    </div>
                    <div class="stat-item">
                        <i class="fas fa-chart-line"></i>
                        <span>Growth Rate: +12%</span>
                    </div>
                    <div class="stat-item">
                        <i class="fas fa-piggy-bank"></i>
                        <span>Profit Margin: 28%</span>
                    </div>
                    <div class="stat-item">
                        <i class="fas fa-trending-up"></i>
                        <span>YoY Growth: +15%</span>
                    </div>
                </div>
                <div class="chart-preview">
                    <h4>Financial Overview</h4>
                    <div class="chart-container">
                        <div class="chart-bar">
                            <div class="bar-label">Income</div>
                            <div class="bar-fill income" style="width: 85%"></div>
                            <div class="bar-value">$25,450</div>
                        </div>
                        <div class="chart-bar">
                            <div class="bar-label">Expenses</div>
                            <div class="bar-fill expense" style="width: 65%"></div>
                            <div class="bar-value">$18,230</div>
                        </div>
                        <div class="chart-bar">
                            <div class="bar-label">Profit</div>
                            <div class="bar-fill profit" style="width: 30%"></div>
                            <div class="bar-value">$7,220</div>
                        </div>
                    </div>
                </div>
            `;
            break;
        case 'operational':
            previewHTML = `
                <div class="report-stats">
                    <div class="stat-item">
                        <i class="fas fa-bed"></i>
                        <span>Room Occupancy: 84%</span>
                    </div>
                    <div class="stat-item">
                        <i class="fas fa-clock"></i>
                        <span>Average Stay: 2.3 years</span>
                    </div>
                    <div class="stat-item">
                        <i class="fas fa-star"></i>
                        <span>Quality Rating: 4.8/5</span>
                    </div>
                    <div class="stat-item">
                        <i class="fas fa-shield-alt"></i>
                        <span>Safety Score: 98%</span>
                    </div>
                </div>
                <div class="operational-metrics">
                    <h4>Operational Metrics</h4>
                    <div class="metrics-grid">
                        <div class="metric-item">
                            <i class="fas fa-bed"></i>
                            <div>
                                <h5>Room Occupancy</h5>
                                <p>84% (38/45 rooms)</p>
                            </div>
                        </div>
                        <div class="metric-item">
                            <i class="fas fa-clock"></i>
                            <div>
                                <h5>Average Stay</h5>
                                <p>2.3 years</p>
                            </div>
                        </div>
                        <div class="metric-item">
                            <i class="fas fa-heart"></i>
                            <div>
                                <h5>Health Score</h5>
                                <p>92%</p>
                            </div>
                        </div>
                        <div class="metric-item">
                            <i class="fas fa-smile"></i>
                            <div>
                                <h5>Satisfaction</h5>
                                <p>4.8/5</p>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            break;
        case 'staff':
            previewHTML = `
                <div class="report-stats">
                    <div class="stat-item">
                        <i class="fas fa-users"></i>
                        <span>Total Staff: 12</span>
                    </div>
                    <div class="stat-item">
                        <i class="fas fa-user-nurse"></i>
                        <span>Nurses: 6</span>
                    </div>
                    <div class="stat-item">
                        <i class="fas fa-user-tie"></i>
                        <span>Administrative: 3</span>
                    </div>
                    <div class="stat-item">
                        <i class="fas fa-tools"></i>
                        <span>Support Staff: 3</span>
                    </div>
                </div>
                <div class="staff-preview">
                    <h4>Staff Overview</h4>
                    <div class="staff-chart">
                        <div class="staff-item">
                            <div class="staff-avatar">
                                <i class="fas fa-user-nurse"></i>
                            </div>
                            <div class="staff-details">
                                <h5>Sarah Johnson</h5>
                                <p>Head Nurse • 5 years experience</p>
                            </div>
                            <div class="staff-status">
                                <span class="status-badge active">Active</span>
                            </div>
                        </div>
                        <div class="staff-item">
                            <div class="staff-avatar">
                                <i class="fas fa-user-nurse"></i>
                            </div>
                            <div class="staff-details">
                                <h5>Michael Brown</h5>
                                <p>Nurse • 3 years experience</p>
                            </div>
                            <div class="staff-status">
                                <span class="status-badge active">Active</span>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            break;
    }
    
    if (previewContent) {
        previewContent.innerHTML = previewHTML;
    }
}

function updateDateRange(dateRange, type) {
    let message = '';
    
    switch(dateRange) {
        case 'today':
            message = 'Showing data for today';
            break;
        case 'week':
            message = 'Showing data for this week';
            break;
        case 'month':
            message = 'Showing data for this month';
            break;
        case 'quarter':
            message = 'Showing data for this quarter';
            break;
        case 'year':
            message = 'Showing data for this year';
            break;
        case 'custom':
            message = 'Please select custom date range';
            break;
    }
    
    showNotification(`${message} (${type} report)`, 'info');
}

function generateReport(type) {
    const reportType = type === 'resident' ? 
        document.getElementById('residentReportType').value : 
        document.getElementById('carehomeReportType').value;
    const format = type === 'resident' ? 
        document.getElementById('residentFormat').value : 
        document.getElementById('carehomeFormat').value;
    
    showNotification(`Generating ${reportType} report in ${format.toUpperCase()} format...`, 'info');
    
    // Simulate report generation
    setTimeout(() => {
        showNotification(`${reportType} report generated successfully!`, 'success');
    }, 2000);
}

function previewReport(type) {
    const reportType = type === 'resident' ? 
        document.getElementById('residentReportType').value : 
        document.getElementById('carehomeReportType').value;
    
    showNotification(`Previewing ${reportType} report...`, 'info');
}

function scheduleReport(type) {
    const reportType = type === 'resident' ? 
        document.getElementById('residentReportType').value : 
        document.getElementById('carehomeReportType').value;
    
    showNotification(`Scheduling ${reportType} report...`, 'info');
}

// Add CSS for reports page
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
    
    .report-summary {
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
        background: linear-gradient(90deg, #3498db, #2ecc71);
    }
    
    .summary-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
    }
    
    .summary-icon {
        width: 60px;
        height: 60px;
        border-radius: 50%;
        background: linear-gradient(135deg, #3498db, #2ecc71);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 1.5rem;
        animation: pulse 2s infinite;
    }
    
    .summary-content h3 {
        color: #7f8c8d;
        font-size: 0.9rem;
        font-weight: 500;
        margin-bottom: 0.5rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .summary-number {
        color: #2c3e50;
        font-size: 2rem;
        font-weight: 700;
        margin: 0;
    }
    
    .summary-content small {
        color: #95a5a6;
        font-size: 0.8rem;
    }
    
    .report-controls {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2rem;
        padding: 1.5rem;
        background: white;
        border-radius: 10px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
    }
    
    .control-group {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
    }
    
    .control-group label {
        font-weight: 600;
        color: #2c3e50;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .control-group label i {
        color: #3498db;
        width: 16px;
    }
    
    .control-group select {
        padding: 0.75rem;
        border: 2px solid #e9ecef;
        border-radius: 8px;
        font-size: 1rem;
        background: white;
        cursor: pointer;
        transition: all 0.3s ease;
    }
    
    .control-group select:focus {
        outline: none;
        border-color: #3498db;
        box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
    }
    
    .report-preview {
        background: white;
        border-radius: 10px;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        margin-bottom: 2rem;
        overflow: hidden;
    }
    
    .report-preview h3 {
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
        padding: 1rem 1.5rem;
        margin: 0;
        font-size: 1.2rem;
    }
    
    .preview-content {
        padding: 2rem;
    }
    
    .report-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        margin-bottom: 2rem;
    }
    
    .stat-item {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 1rem;
        background: #f8f9fa;
        border-radius: 8px;
        border-left: 4px solid #3498db;
    }
    
    .stat-item i {
        color: #3498db;
        font-size: 1.2rem;
    }
    
    .stat-item span {
        font-weight: 600;
        color: #2c3e50;
    }
    
    .resident-list-preview,
    .resident-details-preview,
    .medical-preview,
    .staff-preview {
        margin-top: 1.5rem;
    }
    
    .resident-list-preview h4,
    .resident-details-preview h4,
    .medical-preview h4,
    .staff-preview h4 {
        color: #2c3e50;
        margin-bottom: 1rem;
        font-size: 1.1rem;
    }
    
    .resident-item,
    .staff-item {
        display: flex;
        align-items: center;
        gap: 1rem;
        padding: 1rem;
        border: 1px solid #e9ecef;
        border-radius: 8px;
        margin-bottom: 0.75rem;
        transition: all 0.3s ease;
    }
    
    .resident-item:hover,
    .staff-item:hover {
        background: #f8f9fa;
        border-color: #3498db;
    }
    
    .resident-avatar,
    .staff-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: linear-gradient(135deg, #3498db, #2ecc71);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
    }
    
    .resident-details,
    .staff-details {
        flex: 1;
    }
    
    .resident-details h5,
    .staff-details h5 {
        margin: 0 0 0.25rem 0;
        color: #2c3e50;
        font-size: 1rem;
    }
    
    .resident-details p,
    .staff-details p {
        margin: 0;
        color: #7f8c8d;
        font-size: 0.9rem;
    }
    
    .resident-status,
    .staff-status {
        margin-left: auto;
    }
    
    .status-badge {
        padding: 0.25rem 0.75rem;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
        text-transform: uppercase;
    }
    
    .status-badge.active {
        background: #d5f4e6;
        color: #27ae60;
    }
    
    .detail-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 1rem;
    }
    
    .detail-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.75rem;
        background: #f8f9fa;
        border-radius: 6px;
    }
    
    .detail-item label {
        font-weight: 600;
        color: #2c3e50;
    }
    
    .detail-item span {
        color: #7f8c8d;
    }
    
    .chart-preview {
        margin-top: 1.5rem;
    }
    
    .chart-preview h4 {
        color: #2c3e50;
        margin-bottom: 1rem;
        font-size: 1.1rem;
    }
    
    .chart-container {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }
    
    .chart-bar {
        display: flex;
        align-items: center;
        gap: 1rem;
    }
    
    .bar-label {
        min-width: 80px;
        font-weight: 600;
        color: #2c3e50;
    }
    
    .bar-fill {
        height: 20px;
        border-radius: 10px;
        position: relative;
        flex: 1;
        max-width: 200px;
    }
    
    .bar-fill.income {
        background: linear-gradient(90deg, #2ecc71, #27ae60);
    }
    
    .bar-fill.expense {
        background: linear-gradient(90deg, #e74c3c, #c0392b);
    }
    
    .bar-fill.profit {
        background: linear-gradient(90deg, #3498db, #2980b9);
    }
    
    .bar-fill.healthy {
        background: linear-gradient(90deg, #2ecc71, #27ae60);
    }
    
    .bar-fill.chronic {
        background: linear-gradient(90deg, #f39c12, #e67e22);
    }
    
    .bar-fill.critical {
        background: linear-gradient(90deg, #e74c3c, #c0392b);
    }
    
    .bar-value {
        font-weight: 700;
        color: #2c3e50;
        min-width: 80px;
        text-align: right;
    }
    
    .medical-chart {
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }
    
    .chart-item {
        display: flex;
        align-items: center;
        gap: 1rem;
    }
    
    .chart-label {
        min-width: 120px;
        font-weight: 600;
        color: #2c3e50;
    }
    
    .operational-metrics {
        margin-top: 1.5rem;
    }
    
    .operational-metrics h4 {
        color: #2c3e50;
        margin-bottom: 1rem;
        font-size: 1.1rem;
    }
    
    .metrics-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
    }
    
    .metric-item {
        display: flex;
        align-items: center;
        gap: 1rem;
        padding: 1rem;
        background: #f8f9fa;
        border-radius: 8px;
        border-left: 4px solid #3498db;
    }
    
    .metric-item i {
        color: #3498db;
        font-size: 1.5rem;
    }
    
    .metric-item h5 {
        margin: 0 0 0.25rem 0;
        color: #2c3e50;
        font-size: 0.9rem;
    }
    
    .metric-item p {
        margin: 0;
        color: #7f8c8d;
        font-size: 0.8rem;
    }
    
    .report-actions {
        display: flex;
        gap: 1rem;
        justify-content: center;
        flex-wrap: wrap;
    }
    
    .btn-primary {
        background: linear-gradient(135deg, #3498db, #2ecc71);
        color: white;
    }
    
    .btn-secondary {
        background: #6c757d;
        color: white;
    }
    
    .btn-info {
        background: #17a2b8;
        color: white;
    }
    
    .btn {
        padding: 0.75rem 1.5rem;
        border: none;
        border-radius: 25px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
    }
    
    @media (max-width: 768px) {
        .report-controls {
            grid-template-columns: 1fr;
        }
        
        .report-stats {
            grid-template-columns: 1fr;
        }
        
        .detail-grid {
            grid-template-columns: 1fr;
        }
        
        .metrics-grid {
            grid-template-columns: 1fr;
        }
        
        .chart-bar {
            flex-direction: column;
            align-items: stretch;
            gap: 0.5rem;
        }
        
        .bar-fill {
            max-width: none;
        }
        
        .report-actions {
            flex-direction: column;
            align-items: center;
        }
    }
`;
document.head.appendChild(style);
