/**
 * SME 180 - Branch Selector Handler
 * File: /public_html/pos/assets/js/branch-selector.js
 * Version: 1.0.0
 */

(function() {
    'use strict';
    
    // Initialize branch selector
    function initBranchSelector() {
        const branchCards = document.querySelectorAll('.branch-card');
        const branchInput = document.getElementById('selectedBranchId');
        
        if (!branchCards.length) return;
        
        branchCards.forEach(card => {
            card.addEventListener('click', function() {
                // Remove previous selection
                branchCards.forEach(c => {
                    c.classList.remove('selected');
                    c.classList.remove('selecting');
                });
                
                // Add selection animation
                this.classList.add('selecting');
                
                // Add selected state after animation
                setTimeout(() => {
                    this.classList.remove('selecting');
                    this.classList.add('selected');
                }, 300);
                
                // Update hidden input
                if (branchInput) {
                    branchInput.value = this.dataset.branchId;
                }
                
                // Store in localStorage
                localStorage.setItem('pos_selected_branch_id', this.dataset.branchId);
                localStorage.setItem('pos_selected_branch_name', this.dataset.branchName);
                
                // Auto-submit if in login flow
                if (document.getElementById('branchAutoSubmit')) {
                    setTimeout(() => {
                        document.getElementById('loginForm').submit();
                    }, 500);
                }
            });
        });
        
        // Pre-select branch if stored
        const storedBranchId = localStorage.getItem('pos_selected_branch_id');
        if (storedBranchId) {
            const storedCard = document.querySelector(`[data-branch-id="${storedBranchId}"]`);
            if (storedCard) {
                storedCard.classList.add('selected');
                if (branchInput) {
                    branchInput.value = storedBranchId;
                }
            }
        }
    }
    
    // Load branches dynamically
    function loadBranches() {
        const branchGrid = document.querySelector('.branch-grid');
        if (!branchGrid) return;
        
        // Get branches from localStorage (stored during device setup)
        const branches = JSON.parse(localStorage.getItem('pos_branches') || '[]');
        
        if (branches.length === 0) {
            branchGrid.innerHTML = '<div style="text-align: center; padding: 20px; color: #64748b;">No branches available</div>';
            return;
        }
        
        // Generate branch cards
        let html = '';
        branches.forEach((branch, index) => {
            const type = branch.type || branch.branch_type || 'sales_branch';
            const typeLabel = type === 'central_kitchen' ? 'Kitchen' : 
                             type === 'warehouse' ? 'Warehouse' : 'Sales';
            const typeClass = type === 'central_kitchen' ? 'branch-type-kitchen' :
                             type === 'warehouse' ? 'branch-type-warehouse' : 'branch-type-sales';
            
            html += `
                <div class="branch-card ${index === 0 ? 'selected' : ''}" 
                     data-branch-id="${branch.id}"
                     data-branch-name="${branch.name}">
                    <div class="branch-card-header"></div>
                    <div class="branch-card-body">
                        <div class="branch-name">${branch.name}</div>
                        ${branch.address ? `<div class="branch-details">📍 ${branch.address}</div>` : ''}
                        ${branch.phone ? `<div class="branch-details">📞 ${branch.phone}</div>` : ''}
                        <div class="branch-type-badge ${typeClass}">${typeLabel}</div>
                    </div>
                </div>
            `;
        });
        
        branchGrid.innerHTML = html;
        
        // Re-initialize event listeners
        initBranchSelector();
    }
    
    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            loadBranches();
            initBranchSelector();
        });
    } else {
        loadBranches();
        initBranchSelector();
    }
    
    // Export for external use
    window.BranchSelector = {
        init: initBranchSelector,
        load: loadBranches
    };
    
})();