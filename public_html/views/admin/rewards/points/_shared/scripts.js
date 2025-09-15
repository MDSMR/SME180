/**
 * Modern Clean Design for Discount Programs
 * Path: /public_html/views/admin/rewards/discounts/_shared/styles.css
 * Version: 1.0.0
 * 
 * Table of Contents:
 * 1. CSS Variables
 * 2. Reset & Base Styles
 * 3. Layout Components
 * 4. Header & Navigation
 * 5. Content Areas
 * 6. Buttons
 * 7. Forms
 * 8. Tables
 * 9. Badges & Pills
 * 10. Alerts & Messages
 * 11. Empty States
 * 12. Utilities
 * 13. Responsive Design
 */

/* ========================================
   1. CSS Variables
   ======================================== */
:root {
  /* Colors */
  --primary: #0066CC;
  --primary-hover: #0052A3;
  --primary-light: rgba(0, 102, 204, 0.05);
  --primary-focus: rgba(0, 102, 204, 0.1);
  
  --success: #00A854;
  --success-light: #E6F7ED;
  
  --danger: #DC3545;
  --danger-hover: #B02A37;
  --danger-light: rgba(220, 53, 69, 0.05);
  
  --warning: #FFA500;
  --warning-light: #FFF3E0;
  
  --info: #17A2B8;
  --info-light: #E7F5F8;
  
  /* Grays */
  --text-primary: #2C3E50;
  --text-secondary: #6C757D;
  --text-muted: #95A5A6;
  
  --bg-main: #F8F9FA;
  --bg-white: #FFFFFF;
  --bg-gray: #F0F2F5;
  
  --border: #E1E4E8;
  --border-light: #F0F2F5;
  --border-dark: #CED4DA;
  
  /* Shadows */
  --shadow-xs: 0 1px 2px rgba(0, 0, 0, 0.05);
  --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.08);
  --shadow-md: 0 2px 8px rgba(0, 0, 0, 0.1);
  --shadow-lg: 0 4px 12px rgba(0, 0, 0, 0.15);
  --shadow-hover: 0 4px 12px rgba(0, 102, 204, 0.25);
  
  /* Border Radius */
  --radius-sm: 6px;
  --radius-md: 8px;
  --radius-lg: 12px;
  --radius-xl: 16px;
  --radius-pill: 50px;
  
  /* Spacing */
  --spacing-xs: 4px;
  --spacing-sm: 8px;
  --spacing-md: 16px;
  --spacing-lg: 24px;
  --spacing-xl: 32px;
  --spacing-2xl: 48px;
  
  /* Typography */
  --font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', 'Oxygen', 'Ubuntu', 'Cantarell', 'Fira Sans', 'Droid Sans', 'Helvetica Neue', sans-serif;
  --font-size-xs: 12px;
  --font-size-sm: 13px;
  --font-size-base: 14px;
  --font-size-md: 15px;
  --font-size-lg: 16px;
  --font-size-xl: 18px;
  --font-size-2xl: 24px;
  --font-size-3xl: 32px;
  
  /* Transitions */
  --transition-fast: 150ms ease;
  --transition-base: 200ms ease;
  --transition-slow: 300ms ease;
}

/* ========================================
   2. Reset & Base Styles
   ======================================== */
* {
  box-sizing: border-box;
  margin: 0;
  padding: 0;
}

*::before,
*::after {
  box-sizing: border-box;
}

html {
  scroll-behavior: smooth;
  -webkit-text-size-adjust: 100%;
}

body {
  font-family: var(--font-family);
  font-size: var(--font-size-base);
  line-height: 1.6;
  color: var(--text-primary);
  background-color: var(--bg-main);
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
  text-rendering: optimizeLegibility;
}

a {
  color: var(--primary);
  text-decoration: none;
  transition: color var(--transition-base);
}

a:hover {
  color: var(--primary-hover);
}

img {
  max-width: 100%;
  height: auto;
  display: block;
}

/* ========================================
   3. Layout Components
   ======================================== */
.main-wrapper {
  background: var(--bg-white);
  position: relative;
}

.header-container {
  max-width: 1400px;
  margin: 0 auto;
}

.tabs-container {
  max-width: 1400px;
  margin: 0 auto;
  padding: 0 var(--spacing-lg);
}

.content-wrapper {
  max-width: 1400px;
  margin: 0 auto;
  padding: var(--spacing-xl) var(--spacing-lg);
}

.page-content {
  background: var(--bg-main);
  min-height: calc(100vh - 200px);
}

/* ========================================
   4. Header & Navigation
   ======================================== */
.main-header {
  background: var(--bg-white);
  padding: var(--spacing-xl) var(--spacing-lg) 0;
  border-bottom: none;
}

.main-header h1 {
  font-size: var(--font-size-3xl);
  font-weight: 600;
  color: var(--text-primary);
  margin: 0 0 var(--spacing-sm) 0;
  letter-spacing: -0.5px;
}

.main-header p {
  font-size: var(--font-size-lg);
  color: var(--text-secondary);
  margin: 0 0 var(--spacing-xl) 0;
  line-height: 1.5;
}

/* Tabs Navigation */
.tabs-nav {
  background: var(--bg-white);
  border-bottom: 1px solid var(--border);
  padding: 0;
  margin: 0;
  position: sticky;
  top: 0;
  z-index: 100;
}

.tabs-wrapper {
  display: flex;
  align-items: center;
}

.tab-item {
  position: relative;
  padding: 20px var(--spacing-xl);
  font-size: var(--font-size-lg);
  font-weight: 500;
  color: var(--text-secondary);
  text-decoration: none;
  cursor: pointer;
  transition: color var(--transition-base);
  background: none;
  border: none;
  outline: none;
  white-space: nowrap;
}

.tab-item:first-child {
  padding-left: 0;
}

.tab-item:hover {
  color: var(--text-primary);
}

.tab-item:focus-visible {
  outline: 2px solid var(--primary);
  outline-offset: -2px;
  border-radius: var(--radius-sm);
}

.tab-item.active {
  color: var(--primary);
  font-weight: 500;
}

.tab-item.active::after {
  content: '';
  position: absolute;
  bottom: -1px;
  left: 0;
  right: 0;
  height: 3px;
  background: var(--primary);
}

/* Tab Panels */
.tab-panel {
  display: none;
  animation: fadeIn var(--transition-slow);
}

.tab-panel.active {
  display: block;
}

@keyframes fadeIn {
  from {
    opacity: 0;
    transform: translateY(10px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

/* ========================================
   5. Content Areas
   ======================================== */
.content-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: var(--spacing-lg);
  flex-wrap: wrap;
  gap: var(--spacing-md);
}

.content-title {
  font-size: var(--font-size-2xl);
  font-weight: 600;
  color: var(--text-primary);
  margin: 0;
  letter-spacing: -0.3px;
}

.content-card {
  background: var(--bg-white);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-sm);
  padding: var(--spacing-lg);
  margin-bottom: var(--spacing-lg);
}

.content-card:last-child {
  margin-bottom: 0;
}

/* ========================================
   6. Buttons
   ======================================== */
.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 10px 20px;
  font-size: var(--font-size-base);
  font-weight: 500;
  border-radius: var(--radius-md);
  text-decoration: none;
  cursor: pointer;
  transition: all var(--transition-base);
  border: none;
  outline: none;
  white-space: nowrap;
  user-select: none;
  position: relative;
  overflow: hidden;
}

.btn:focus-visible {
  outline: 2px solid var(--primary);
  outline-offset: 2px;
}

.btn:active {
  transform: scale(0.98);
}

.btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
  transform: none !important;
}

/* Button Variants */
.btn-primary {
  background: var(--primary);
  color: white;
}

.btn-primary:hover:not(:disabled) {
  background: var(--primary-hover);
  transform: translateY(-1px);
  box-shadow: var(--shadow-hover);
  color: white;
}

.btn-create {
  background: var(--primary);
  color: white;
  padding: 12px var(--spacing-lg);
  font-size: var(--font-size-md);
  font-weight: 500;
  border-radius: var(--radius-md);
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  transition: all var(--transition-base);
  cursor: pointer;
  border: none;
  white-space: nowrap;
}

.btn-create::before {
  content: '+';
  margin-right: var(--spacing-sm);
  font-size: 20px;
  font-weight: 400;
  line-height: 1;
}

.btn-create:hover {
  background: var(--primary-hover);
  transform: translateY(-1px);
  box-shadow: var(--shadow-hover);
  color: white;
}

.btn-secondary {
  background: var(--bg-white);
  color: var(--text-primary);
  border: 1px solid var(--border);
}

.btn-secondary:hover:not(:disabled) {
  background: var(--bg-gray);
  border-color: var(--border-dark);
}

.btn-text {
  background: transparent;
  color: var(--primary);
  padding: 6px 12px;
  font-size: var(--font-size-sm);
}

.btn-text:hover:not(:disabled) {
  background: var(--primary-light);
  color: var(--primary-hover);
}

.btn-danger-text {
  background: transparent;
  color: var(--danger);
  padding: 6px 12px;
  font-size: var(--font-size-sm);
  border: none;
  cursor: pointer;
}

.btn-danger-text:hover:not(:disabled) {
  background: var(--danger-light);
  color: var(--danger-hover);
}

/* ========================================
   7. Forms
   ======================================== */
.form-group {
  margin-bottom: var(--spacing-lg);
}

.form-label {
  display: block;
  margin-bottom: var(--spacing-xs);
  font-weight: 500;
  color: var(--text-primary);
  font-size: var(--font-size-base);
}

.form-input,
.form-select,
.form-textarea {
  width: 100%;
  padding: 10px 12px;
  font-size: var(--font-size-base);
  font-family: var(--font-family);
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  background: var(--bg-white);
  color: var(--text-primary);
  transition: all var(--transition-base);
}

.form-input:focus,
.form-select:focus,
.form-textarea:focus {
  outline: none;
  border-color: var(--primary);
  box-shadow: 0 0 0 3px var(--primary-focus);
}

.form-textarea {
  min-height: 100px;
  resize: vertical;
}

/* Filter Section */
.filter-section {
  display: flex;
  align-items: center;
  gap: var(--spacing-md);
  margin-bottom: var(--spacing-lg);
  padding-bottom: var(--spacing-lg);
  border-bottom: 1px solid var(--border);
}

.filter-label {
  color: var(--text-secondary);
  font-size: var(--font-size-base);
  font-weight: 500;
}

.filter-select {
  padding: 8px 36px 8px 12px;
  font-size: var(--font-size-base);
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  background: white;
  color: var(--text-primary);
  cursor: pointer;
  appearance: none;
  background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236C757D' d='M6 8.5L2 4.5h8z'/%3E%3C/svg%3E");
  background-repeat: no-repeat;
  background-position: right 12px center;
  min-width: 160px;
  transition: all var(--transition-base);
}

.filter-select:focus {
  outline: none;
  border-color: var(--primary);
  box-shadow: 0 0 0 3px var(--primary-focus);
}

.filter-select:hover {
  border-color: var(--border-dark);
}

/* ========================================
   8. Tables
   ======================================== */
.table-responsive {
  overflow-x: auto;
  margin: -1px;
  padding: 1px;
}

.data-table {
  width: 100%;
  border-collapse: separate;
  border-spacing: 0;
  font-size: var(--font-size-base);
}

.data-table th {
  padding: 12px var(--spacing-md);
  text-align: left;
  font-size: var(--font-size-xs);
  font-weight: 600;
  color: var(--text-secondary);
  text-transform: uppercase;
  letter-spacing: 0.5px;
  border-bottom: 2px solid var(--border-light);
  white-space: nowrap;
}

.data-table th.text-right {
  text-align: right;
}

.data-table td {
  padding: var(--spacing-md);
  border-bottom: 1px solid var(--border-light);
  color: var(--text-primary);
  font-size: var(--font-size-base);
  vertical-align: middle;
}

.data-table tbody tr {
  transition: background-color var(--transition-fast);
}

.data-table tbody tr:last-child td {
  border-bottom: none;
}

.data-table tbody tr:hover {
  background: rgba(0, 0, 0, 0.01);
}

/* Table Cell Content */
.program-info {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.program-name {
  font-weight: 500;
  color: var(--text-primary);
  font-size: var(--font-size-base);
}

.program-id {
  font-size: var(--font-size-sm);
  color: var(--text-secondary);
}

.rates-info {
  line-height: 1.5;
  display: flex;
  flex-direction: column;
  gap: var(--spacing-xs);
}

.rate-label {
  color: var(--text-secondary);
  font-size: var(--font-size-sm);
  display: inline-block;
  min-width: 60px;
}

.rate-value {
  color: var(--text-primary);
  font-weight: 500;
  font-size: var(--font-size-base);
}

.period-text {
  color: var(--text-primary);
  font-size: var(--font-size-sm);
  display: flex;
  align-items: center;
  gap: var(--spacing-xs);
}

.period-separator {
  color: var(--text-muted);
}

.period-ongoing {
  color: var(--success);
  font-weight: 500;
}

.action-buttons {
  display: flex;
  gap: var(--spacing-sm);
  justify-content: flex-end;
  flex-wrap: wrap;
}

/* ========================================
   9. Badges & Pills
   ======================================== */
.status-badge {
  display: inline-flex;
  align-items: center;
  padding: 4px 12px;
  font-size: var(--font-size-xs);
  font-weight: 600;
  border-radius: var(--radius-pill);
  text-transform: uppercase;
  letter-spacing: 0.3px;
  white-space: nowrap;
}

.status-badge.active,
.status-badge.live {
  background: var(--success);
  color: white;
}

.status-badge.inactive,
.status-badge.draft {
  background: #E3E4E6;
  color: var(--text-secondary);
}

.status-badge.ended {
  background: #FFE5E5;
  color: #CC0000;
}

.channel-pills {
  display: flex;
  gap: var(--spacing-sm);
  flex-wrap: wrap;
}

.channel-pill {
  display: inline-flex;
  align-items: center;
  padding: 6px 14px;
  font-size: var(--font-size-sm);
  font-weight: 500;
  background: var(--primary);
  color: white;
  border-radius: var(--radius-pill);
  white-space: nowrap;
}

/* ========================================
   10. Alerts & Messages
   ======================================== */
.alert {
  padding: 12px var(--spacing-md);
  border-radius: var(--radius-md);
  margin-bottom: var(--spacing-md);
  display: flex;
  align-items: center;
  gap: var(--spacing-sm);
  font-size: var(--font-size-base);
  animation: slideDown var(--transition-slow);
}

@keyframes slideDown {
  from {
    opacity: 0;
    transform: translateY(-10px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.alert-success {
  background: var(--success-light);
  color: var(--success);
  border: 1px solid var(--success);
}

.alert-error {
  background: #FEE;
  color: var(--danger);
  border: 1px solid var(--danger-light);
}

.alert-warning {
  background: var(--warning-light);
  color: var(--warning);
  border: 1px solid var(--warning);
}

.alert-info {
  background: var(--info-light);
  color: var(--info);
  border: 1px solid var(--info);
}

.alert strong {
  font-weight: 600;
}

.error-message {
  color: var(--danger);
  font-size: var(--font-size-base);
  padding: var(--spacing-md);
}

.info-message {
  color: var(--text-secondary);
  font-size: var(--font-size-base);
  padding: var(--spacing-md);
}

/* ========================================
   11. Empty States
   ======================================== */
.empty-state {
  text-align: center;
  padding: var(--spacing-2xl) var(--spacing-lg);
  color: var(--text-secondary);
}

.empty-state-icon {
  font-size: 48px;
  margin-bottom: var(--spacing-md);
  opacity: 0.3;
  line-height: 1;
}

.empty-state-text {
  font-size: var(--font-size-lg);
  margin-bottom: var(--spacing-sm);
  color: var(--text-primary);
  font-weight: 500;
}

.empty-state-subtext {
  font-size: var(--font-size-base);
  color: var(--text-secondary);
  margin-bottom: var(--spacing-md);
}

/* ========================================
   12. Utilities
   ======================================== */
.text-right {
  text-align: right;
}

.text-center {
  text-align: center;
}

.text-left {
  text-align: left;
}

.mt-1 { margin-top: var(--spacing-xs); }
.mt-2 { margin-top: var(--spacing-sm); }
.mt-3 { margin-top: var(--spacing-md); }
.mt-4 { margin-top: var(--spacing-lg); }
.mt-5 { margin-top: var(--spacing-xl); }

.mb-1 { margin-bottom: var(--spacing-xs); }
.mb-2 { margin-bottom: var(--spacing-sm); }
.mb-3 { margin-bottom: var(--spacing-md); }
.mb-4 { margin-bottom: var(--spacing-lg); }
.mb-5 { margin-bottom: var(--spacing-xl); }

.p-1 { padding: var(--spacing-xs); }
.p-2 { padding: var(--spacing-sm); }
.p-3 { padding: var(--spacing-md); }
.p-4 { padding: var(--spacing-lg); }
.p-5 { padding: var(--spacing-xl); }

/* ========================================
   13. Responsive Design
   ======================================== */
@media (max-width: 1024px) {
  .tabs-container,
  .content-wrapper {
    padding-left: var(--spacing-md);
    padding-right: var(--spacing-md);
  }
}

@media (max-width: 768px) {
  /* Typography adjustments */
  .main-header h1 {
    font-size: 26px;
  }
  
  .main-header p {
    font-size: var(--font-size-md);
  }
  
  .content-title {
    font-size: 20px;
  }
  
  /* Layout adjustments */
  .content-header {
    flex-direction: column;
    align-items: flex-start;
    gap: var(--spacing-md);
  }
  
  /* Navigation adjustments */
  .tabs-nav {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
  }
  
  .tabs-container {
    padding: 0 var(--spacing-md);
  }
  
  .tab-item {
    padding: var(--spacing-md) 20px;
    font-size: var(--font-size-md);
  }
  
  .tab-item:first-child {
    padding-left: 20px;
  }
  
  /* Content adjustments */
  .content-wrapper {
    padding: 20px var(--spacing-md);
  }
  
  .content-card {
    padding: var(--spacing-md);
    border-radius: var(--radius-md);
  }
  
  /* Table adjustments */
  .data-table {
    font-size: var(--font-size-sm);
  }
  
  .data-table th,
  .data-table td {
    padding: var(--spacing-sm);
  }
  
  /* Hide less important columns on mobile */
  .data-table th:nth-child(4),
  .data-table td:nth-child(4),
  .data-table th:nth-child(5),
  .data-table td:nth-child(5) {
    display: none;
  }
  
  /* Button adjustments */
  .action-buttons {
    flex-direction: column;
    width: 100%;
  }
  
  .btn {
    width: 100%;
    justify-content: center;
  }
  
  .btn-create {
    width: 100%;
  }
  
  /* Filter adjustments */
  .filter-section {
    flex-direction: column;
    align-items: stretch;
    gap: var(--spacing-sm);
  }
  
  .filter-select {
    width: 100%;
  }
}

@media (max-width: 480px) {
  /* Further mobile optimizations */
  .main-header h1 {
    font-size: 22px;
  }
  
  .tab-item {
    padding: 12px var(--spacing-md);
    font-size: var(--font-size-base);
  }
  
  /* Stack everything vertically */
  .channel-pills {
    flex-direction: column;
    align-items: flex-start;
  }
  
  .channel-pill {
    font-size: var(--font-size-xs);
  }
}

/* Print styles */
@media print {
  body {
    background: white;
  }
  
  .tabs-nav,
  .btn-create,
  .filter-section,
  .action-buttons {
    display: none !important;
  }
  
  .content-card {
    box-shadow: none;
    border: 1px solid var(--border);
  }
}