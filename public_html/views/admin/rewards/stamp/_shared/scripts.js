// Stamp Rewards System JavaScript
// Enhanced functionality for stamp program management with Microsoft 365 Command Bar

// Global configuration
window.STAMP_CONFIG = {
    currency: '$',
    maxProducts: 20,
    ajaxTimeout: 30000
};

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    console.log('Stamp Rewards System - Initializing...');
    
    initTabNavigation();
    initSlideOverPanels();
    initTagDropdowns();
    initFormValidation();
    initTableActions();
    
    console.log('Stamp Rewards System - Ready!');
});

/* ===== TAB NAVIGATION - Updated for MS Command Bar ===== */
function initTabNavigation() {
    // Microsoft 365 style pivot tabs
    const pivotTabs = document.querySelectorAll('.ms-pivot-tab');
    const tabContents = document.querySelectorAll('.tab-content');
    
    // Also handle legacy internal nav tabs for backwards compatibility
    const internalTabs = document.querySelectorAll('.internal-nav-tab');
    
    const allTabs = pivotTabs.length > 0 ? pivotTabs : internalTabs;
    
    if (allTabs.length > 0 && tabContents.length > 0) {
        allTabs.forEach(tab => {
            tab.addEventListener('click', function() {
                const targetId = this.getAttribute('data-tab');
                
                // Update active tab
                allTabs.forEach(t => t.classList.remove('active'));
                this.classList.add('active');
                
                // Update content visibility
                tabContents.forEach(content => {
                    content.classList.remove('active');
                    if (content.id === targetId) {
                        content.classList.add('active');
                    }
                });
                
                // Store active tab in session storage for persistence
                try {
                    sessionStorage.setItem('activeStampTab', targetId);
                } catch(e) {}
                
                // Trigger tab-specific initialization
                onTabChange(targetId);
            });
        });
        
        // Restore last active tab
        try {
            const lastActive = sessionStorage.getItem('activeStampTab');
            if (lastActive) {
                const targetTab = document.querySelector(`[data-tab="${lastActive}"]`);
                if (targetTab) {
                    targetTab.click();
                }
            }
        } catch(e) {}
    }
}

function onTabChange(tabId) {
    console.log(`Tab changed to: ${tabId}`);
    
    // Tab-specific initialization
    if (tabId === 'customers') {
        loadCustomersIfNeeded();
    } else if (tabId === 'transactions') {
        loadTransactionsIfNeeded();
    }
}

/* ===== SLIDE-OVER PANELS ===== */
function initSlideOverPanels() {
    const slideover = document.getElementById('slideover');
    const backdrop = document.getElementById('backdrop');
    const closeBtn = document.getElementById('panelClose');
    
    if (!slideover || !backdrop || !closeBtn) return;
    
    // Close handlers
    closeBtn.addEventListener('click', closeSlideOver);
    backdrop.addEventListener('click', closeSlideOver);
    
    // ESC key handler
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && slideover.classList.contains('open')) {
            closeSlideOver();
        }
    });
    
    // Tab switching within slide-over
    const slideoverTabs = document.querySelectorAll('.slideover-tab');
    slideoverTabs.forEach(tab => {
        tab.addEventListener('click', function() {
            const targetPanel = this.getAttribute('data-panel');
            switchSlideoverTab(targetPanel);
        });
    });
    
    // Bind customer action buttons
    bindCustomerActions();
}

function openSlideOver(title, customerId, initialTab = 'ledger') {
    const slideover = document.getElementById('slideover');
    const backdrop = document.getElementById('backdrop');
    const panelTitle = document.getElementById('panelTitle');
    const panelId = document.getElementById('panelId');
    
    if (!slideover || !backdrop) return;
    
    panelTitle.textContent = title || 'Customer';
    panelId.textContent = '#' + customerId;
    
    slideover.classList.add('open');
    backdrop.classList.add('open');
    
    switchSlideoverTab(initialTab);
    
    if (initialTab === 'ledger') {
        loadCustomerLedger(customerId);
    }
}

function closeSlideOver() {
    const slideover = document.getElementById('slideover');
    const backdrop = document.getElementById('backdrop');
    
    if (slideover) slideover.classList.remove('open');
    if (backdrop) backdrop.classList.remove('open');
}

function switchSlideoverTab(tabName) {
    const tabs = document.querySelectorAll('.slideover-tab');
    const panels = document.querySelectorAll('.slideover-panel');
    
    tabs.forEach(tab => {
        if (tab.getAttribute('data-panel') === tabName) {
            tab.classList.add('active');
        } else {
            tab.classList.remove('active');
        }
    });
    
    panels.forEach(panel => {
        if (panel.id === `pane${capitalize(tabName)}`) {
            panel.style.display = 'block';
        } else {
            panel.style.display = 'none';
        }
    });
}

function bindCustomerActions() {
    // Ledger buttons
    document.querySelectorAll('.js-ledger').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const customerId = this.getAttribute('data-id');
            const customerName = getCustomerName(this);
            openSlideOver(customerName, customerId, 'ledger');
        });
    });
    
    // Adjust buttons  
    document.querySelectorAll('.js-adjust').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const customerId = this.getAttribute('data-id');
            const customerName = getCustomerName(this);
            openSlideOver(customerName, customerId, 'adjust');
            
            // Focus amount input
            setTimeout(() => {
                const amountInput = document.getElementById('adj_amount');
                if (amountInput) amountInput.focus();
            }, 300);
        });
    });
}

function getCustomerName(button) {
    const row = button.closest('tr');
    if (!row) return 'Customer';
    
    const nameCell = row.querySelector('td div');
    return nameCell ? nameCell.textContent.trim() : 'Customer';
}

/* ===== AJAX FUNCTIONS ===== */
async function loadCustomerLedger(customerId) {
    const ledgerContainer = document.getElementById('ledgerList');
    const programId = getCurrentProgramId();
    
    if (!ledgerContainer || !programId) return;
    
    ledgerContainer.textContent = 'Loading...';
    
    try {
        const params = new URLSearchParams({
            customer_id: customerId,
            program_id: programId
        });
        
        const response = await fetch(`controllers/ledger_list.php?${params}`, {
            credentials: 'same-origin',
            headers: {
                'Accept': 'text/html,application/json'
            }
        });
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        
        const html = await response.text();
        ledgerContainer.innerHTML = html;
        
    } catch (error) {
        console.error('Ledger load error:', error);
        ledgerContainer.innerHTML = `
            <div class="notice alert-error">
                Could not load ledger: ${error.message}
            </div>
        `;
    }
}

async function submitAdjustment(formData) {
    const programId = getCurrentProgramId();
    const customerId = getSlideoverCustomerId();
    
    if (!programId || !customerId) {
        throw new Error('Missing program or customer ID');
    }
    
    formData.append('program_id', programId);
    formData.append('customer_id', customerId);
    
    const response = await fetch('controllers/adjustment_create.php', {
        method: 'POST',
        body: formData,
        credentials: 'same-origin'
    });
    
    if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
    }
    
    const result = await response.json();
    
    if (!result.ok) {
        throw new Error(result.error || 'Adjustment failed');
    }
    
    return result;
}

function getCurrentProgramId() {
    // Get from URL parameter or selected program
    const params = new URLSearchParams(window.location.search);
    return parseInt(params.get('program_id')) || window.STAMP_CONFIG.selectedProgramId || 0;
}

function getSlideoverCustomerId() {
    const panelId = document.getElementById('panelId');
    if (!panelId) return 0;
    
    const text = panelId.textContent;
    const match = text.match(/#(\d+)/);
    return match ? parseInt(match[1]) : 0;
}

/* ===== TAG DROPDOWNS ===== */
function initTagDropdowns() {
    document.querySelectorAll('.tagbox').forEach(tagbox => {
        new TagDropdown(tagbox);
    });
}

class TagDropdown {
    constructor(container) {
        this.container = container;
        this.name = container.dataset.name;
        this.input = container.querySelector('.tag-search');
        this.dropdown = container.querySelector('.dropdown');
        this.tagsContainer = container.querySelector('.tags');
        this.clearBtn = container.querySelector('.js-clear');
        this.selected = new Map();
        
        this.init();
    }
    
    init() {
        if (!this.input || !this.dropdown || !this.tagsContainer) return;
        
        this.input.addEventListener('focus', () => this.openDropdown());
        this.input.addEventListener('input', () => this.filterOptions());
        
        this.clearBtn?.addEventListener('click', () => this.clearAll());
        
        document.addEventListener('click', (e) => {
            if (!this.container.contains(e.target)) {
                this.closeDropdown();
            }
        });
        
        this.renderTags();
    }
    
    openDropdown() {
        this.dropdown.classList.add('open');
        this.filterOptions();
    }
    
    closeDropdown() {
        this.dropdown.classList.remove('open');
    }
    
    filterOptions() {
        const query = this.input.value.trim().toLowerCase();
        const products = window.PRODUCTS || [];
        
        const filtered = products.filter(p => 
            p.name_en.toLowerCase().includes(query) && 
            !this.selected.has(p.id)
        );
        
        if (filtered.length === 0) {
            this.dropdown.innerHTML = '<div class="opt" style="cursor:default">No matches</div>';
        } else {
            this.dropdown.innerHTML = filtered.map(p => 
                `<div class="opt" data-id="${p.id}" data-name="${this.escapeHtml(p.name_en)}">
                    ${this.escapeHtml(p.name_en)}
                </div>`
            ).join('');
            
            // Bind click handlers
            this.dropdown.querySelectorAll('.opt[data-id]').forEach(opt => {
                opt.addEventListener('click', () => {
                    const id = parseInt(opt.getAttribute('data-id'));
                    const name = opt.getAttribute('data-name');
                    this.addItem(id, name);
                });
            });
        }
    }
    
    addItem(id, name) {
        this.selected.set(id, { id, name });
        this.input.value = '';
        this.closeDropdown();
        this.renderTags();
        this.syncHiddenInputs();
    }
    
    removeItem(id) {
        this.selected.delete(id);
        this.renderTags();
        this.syncHiddenInputs();
    }
    
    clearAll() {
        this.selected.clear();
        this.input.value = '';
        this.renderTags();
        this.syncHiddenInputs();
    }
    
    renderTags() {
        const tags = Array.from(this.selected.values()).map(item => `
            <span class="tag">
                <span>${this.escapeHtml(item.name)}</span>
                <button type="button" data-id="${item.id}" aria-label="Remove">×</button>
            </span>
        `).join('');
        
        this.tagsContainer.innerHTML = tags;
        
        // Bind remove handlers
        this.tagsContainer.querySelectorAll('button').forEach(btn => {
            btn.addEventListener('click', () => {
                const id = parseInt(btn.getAttribute('data-id'));
                this.removeItem(id);
            });
        });
    }
    
    syncHiddenInputs() {
        // Remove old hidden inputs
        this.container.querySelectorAll('input[type="hidden"][data-tag]').forEach(input => {
            input.remove();
        });
        
        // Add new hidden inputs
        this.selected.forEach(item => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = this.name;
            input.value = item.id.toString();
            input.setAttribute('data-tag', '1');
            this.container.appendChild(input);
        });
    }
    
    escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }
}

/* ===== FORM HANDLING ===== */
function initFormValidation() {
    // Adjustment form handler
    const adjustForm = document.getElementById('adjustForm');
    if (adjustForm) {
        adjustForm.addEventListener('submit', handleAdjustmentSubmit);
    }
    
    // Program form handler
    const programForm = document.getElementById('programForm');
    if (programForm) {
        programForm.addEventListener('submit', handleProgramSubmit);
    }
}

async function handleAdjustmentSubmit(e) {
    e.preventDefault();
    
    const form = e.target;
    const messageDiv = document.getElementById('adjustMsg');
    const submitBtn = form.querySelector('button[type="submit"]');
    
    // Clear previous messages
    if (messageDiv) {
        messageDiv.style.display = 'none';
        messageDiv.textContent = '';
    }
    
    // Disable submit button
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Saving...';
    }
    
    try {
        const formData = new FormData(form);
        const result = await submitAdjustment(formData);
        
        // Show success message
        if (messageDiv) {
            messageDiv.className = 'notice alert-ok';
            messageDiv.textContent = 'Adjustment saved successfully.';
            messageDiv.style.display = 'block';
        }
        
        // Update customer balance in main table if available
        const customerId = getSlideoverCustomerId();
        if (result.balance !== undefined && customerId) {
            updateCustomerBalance(customerId, result.balance);
        }
        
        // Clear form
        form.reset();
        
        // Reload ledger
        loadCustomerLedger(customerId);
        
    } catch (error) {
        console.error('Adjustment error:', error);
        
        if (messageDiv) {
            messageDiv.className = 'notice alert-error';
            messageDiv.textContent = `Error: ${error.message}`;
            messageDiv.style.display = 'block';
        }
    } finally {
        // Re-enable submit button
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Save Adjustment';
        }
    }
}

function handleProgramSubmit(e) {
    const form = e.target;
    const nameInput = document.getElementById('program_name');
    const stampsInput = document.getElementById('stamps_required');
    const startInput = document.getElementById('start_at');
    const endInput = document.getElementById('end_at');
    
    // Validate program name
    if (!nameInput || !nameInput.value.trim()) {
        e.preventDefault();
        alert('Program name is required.');
        if (nameInput) nameInput.focus();
        return;
    }
    
    // Validate stamps required
    if (!stampsInput || !stampsInput.value || parseInt(stampsInput.value) < 1) {
        e.preventDefault();
        alert('Stamps required must be at least 1.');
        if (stampsInput) stampsInput.focus();
        return;
    }
    
    // Validate dates
    if (startInput && endInput && startInput.value && endInput.value) {
        const startDate = new Date(startInput.value);
        const endDate = new Date(endInput.value);
        
        if (endDate <= startDate) {
            e.preventDefault();
            alert('End date must be after start date.');
            if (endInput) endInput.focus();
            return;
        }
    }
    
    // Show loading state
    const submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Creating Program...';
    }
}

/* ===== TABLE ACTIONS ===== */
function initTableActions() {
    // Duplicate program buttons
    document.querySelectorAll('.js-duplicate').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const row = this.closest('tr');
            const programData = row ? row.getAttribute('data-prog') : null;
            
            if (programData) {
                try {
                    const program = JSON.parse(programData);
                    const url = `create.php?duplicate=${program.id}`;
                    window.location.href = url;
                } catch (error) {
                    console.error('Duplicate error:', error);
                }
            }
        });
    });
    
    // Delete program buttons
    document.querySelectorAll('.js-delete').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const row = this.closest('tr');
            const programData = row ? row.getAttribute('data-prog') : null;
            
            if (programData) {
                try {
                    const program = JSON.parse(programData);
                    
                    if (confirm(`Delete "${program.name}"?\n\nThis action cannot be undone and will remove all associated customer data.`)) {
                        deleteProgram(program.id);
                    }
                } catch (error) {
                    console.error('Delete error:', error);
                }
            }
        });
    });
}

async function deleteProgram(programId) {
    try {
        const response = await fetch('controllers/program_delete.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ program_id: programId }),
            credentials: 'same-origin'
        });
        
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        
        const result = await response.json();
        
        if (result.ok) {
            // Reload page to show updated list
            window.location.reload();
        } else {
            throw new Error(result.error || 'Delete failed');
        }
        
    } catch (error) {
        console.error('Delete program error:', error);
        alert(`Error deleting program: ${error.message}`);
    }
}

/* ===== UTILITY FUNCTIONS ===== */
function updateCustomerBalance(customerId, newBalance) {
    const balanceElement = document.querySelector(`tr[data-customer="${customerId}"] .cust-balance`);
    if (balanceElement) {
        balanceElement.textContent = newBalance.toString();
    }
}

function loadCustomersIfNeeded() {
    // Placeholder for dynamic customer loading if needed
    console.log('Loading customers tab');
}

function loadTransactionsIfNeeded() {
    // Placeholder for dynamic transaction loading if needed  
    console.log('Loading transactions tab');
}

function capitalize(str) {
    return str.charAt(0).toUpperCase() + str.slice(1);
}

// Expose key functions globally for backwards compatibility
window.STAMP_REWARDS = {
    openSlideOver,
    closeSlideOver,
    loadCustomerLedger,
    submitAdjustment
};