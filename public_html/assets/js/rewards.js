// /public_html/views/admin/customers/rewards.js
// Customers list + details (deduped profile header, nice Back button, stable sections)

/* ---------- Global ---------- */
let currentCustomer = null;
let currentTab = 'points';
let allTransactions = [];

/* ---------- Init ---------- */
document.addEventListener('DOMContentLoaded', function () {
  loadCustomers();

  if (window.REWARDS_PAGE && window.REWARDS_PAGE.customerId) {
    loadCustomerDetails(window.REWARDS_PAGE.customerId);
  }

  loadBranches();
});

/* ---------- Utilities for cleaning the details area ---------- */
function cleanupDetailsSection() {
  const details = document.getElementById('customerDetailsSection');
  if (!details) return;

  // Remove any previously injected profile headers
  details.querySelectorAll('.profile-header').forEach(h => h.remove());

  // Remove the legacy info card (pre-existing customer details block) to avoid duplication
  // If you still need it elsewhere, comment out the next line.
  details.querySelectorAll('.customer-info-card').forEach(c => c.remove());

  // Clear tab containers so they don't stack
  const pts = document.getElementById('pointsTransactions');
  const csh = document.getElementById('cashbackTransactions');
  const stp = document.getElementById('stampsTransactions');
  if (pts) pts.innerHTML = '';
  if (csh) csh.innerHTML = '';
  if (stp) stp.innerHTML = '';
}

/* ---------- Data Loads ---------- */
function loadCustomers() {
  const search = (document.getElementById('tableSearchInput') || {}).value || '';
  const classification = (document.getElementById('classificationFilter') || {}).value || 'all';
  const rewards = (document.getElementById('rewardsFilter') || {}).value || 'all';

  const params = new URLSearchParams({
    action: 'list_customers',
    q: search,
    classification: classification,
    rewards: rewards
  });

  fetch(`?${params}`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        renderCustomersTable(data.data);
        updateClearFiltersVisibility();
      } else {
        console.error('Failed to load customers:', data.error || data);
      }
    })
    .catch(err => console.error('Error loading customers:', err));
}

/* ---------- Table Rendering (includes View button) ---------- */
function renderCustomersTable(customers) {
  const container = document.getElementById('customersTable');
  if (!container) return;

  if (!customers || customers.length === 0) {
    container.innerHTML = `
      <div class="empty-state">
        <h3>No customers found</h3>
        <p>No customers match the selected filters.</p>
      </div>
    `;
    return;
  }

  let html = `
    <table class="table">
      <thead>
        <tr>
          <th style="width:90px;">ID</th>
          <th>Customer</th>
          <th>Member</th>
          <th style="text-align:right;">Points</th>
          <th>Last Activity</th>
          <th style="width:120px; text-align:right;">Action</th>
        </tr>
      </thead>
      <tbody>
  `;

  customers.forEach(customer => {
    const memberDisplay = customer.member_display || '-';
    const lastActivity = customer.last_activity_at ? formatDate(customer.last_activity_at) : 'Never';
    const enrolled = Number(customer.rewards_enrolled) === 1;
    const pointsColor = (Number(customer.points_balance) > 0) ? 'var(--ms-green)' : 'var(--ms-gray-110)';
    const pointsValue = enrolled ? (Number(customer.points_balance) || 0).toLocaleString() : '-';

    html += `
      <tr onclick="loadCustomerDetails(${Number(customer.id)})">
        <td style="color: var(--ms-gray-110); font-size: 12px;">#${Number(customer.id)}</td>
        <td>
          <div style="font-weight: 500;">${escapeHtml(customer.name || '')}</div>
          <div style="font-size: 12px; color: var(--ms-gray-110);">${escapeHtml(customer.phone || '')}</div>
        </td>
        <td>
          ${memberDisplay !== '-' 
            ? `<span style="font-family: monospace; font-size: 12px; background: var(--ms-gray-20); padding: 2px 6px; border-radius: 3px;">${escapeHtml(memberDisplay)}</span>`
            : '<span style="color: var(--ms-gray-110);">-</span>'}
        </td>
        <td style="text-align:right; font-weight: 600; color: ${pointsColor};">${pointsValue}</td>
        <td style="font-size: 12px; color: var(--ms-gray-110);">${lastActivity}</td>
        <td>
          <div class="action-buttons">
            <button class="btn small" onclick="event.stopPropagation(); loadCustomerDetails(${Number(customer.id)})">View</button>
          </div>
        </td>
      </tr>
    `;
  });

  html += '</tbody></table>';
  container.innerHTML = html;
}

/* ---------- Customer Details (cleans up + renders header) ---------- */
function loadCustomerDetails(customerId) {
  // Ensure a clean slate each time we open a customer
  cleanupDetailsSection();

  fetch(`?action=load_customer&customer_id=${Number(customerId)}`, {
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
    .then(r => r.json())
    .then(data => {
      if (!data.success) {
        showToast(data.error || 'Error loading customer details', 'error');
        return;
      }

      currentCustomer = data.customer;
      allTransactions = Array.isArray(data.transactions) ? data.transactions : [];

      const tableCard = document.getElementById('customerTableCard');
      const filtersBar = document.getElementById('filtersBar');
      const detailsSection = document.getElementById('customerDetailsSection');
      if (tableCard) tableCard.style.display = 'none';
      if (filtersBar) filtersBar.style.display = 'none';
      if (detailsSection) detailsSection.classList.add('active');

      // Render the new profile header (only one)
      renderProfileHeader(currentCustomer);

      // Back-compat updates in case legacy nodes exist
      const nameEl = document.getElementById('customerName');
      if (nameEl) nameEl.textContent = currentCustomer.name || '';

      const phoneEl = document.getElementById('customerPhone');
      if (phoneEl) phoneEl.textContent = currentCustomer.phone || 'Not provided';

      const idEl = document.getElementById('customerIdDisplay');
      if (idEl) idEl.textContent = currentCustomer.id;

      const typeBadge = document.getElementById('customerTypeBadge');
      if (typeBadge) {
        typeBadge.className = `customer-type-badge ${currentCustomer.classification || 'regular'}`;
        typeBadge.textContent = (currentCustomer.classification || 'regular').toUpperCase();
      }

      const enrolled = Number(currentCustomer.rewards_enrolled) === 1;
      const statusEl = document.getElementById('customerStatus');
      if (statusEl) {
        statusEl.textContent = enrolled ? 'Enrolled' : 'Not Enrolled';
        statusEl.style.color = enrolled ? 'var(--ms-green)' : 'var(--ms-gray-110)';
      }

      // Summary cards
      const points = Number(currentCustomer.points_balance) || 0;
      const cashback = Number(data.cashbackBalance) || 0;
      const totalStamps = (Array.isArray(data.stampBalances) ? data.stampBalances : [])
        .reduce((sum, item) => sum + parseInt(item.stamp_balance || 0, 10), 0);
      const discountStatus = currentCustomer.discount_scheme_id ? 'Active' : 'None';

      const elPoints = document.getElementById('summaryPoints');
      if (elPoints) elPoints.textContent = points.toLocaleString();

      const elCashback = document.getElementById('summaryCashback');
      if (elCashback) elCashback.textContent = `EGP ${cashback.toFixed(2)}`;

      const elStamps = document.getElementById('summaryStamps');
      if (elStamps) elStamps.textContent = totalStamps;

      const elDiscount = document.getElementById('summaryDiscount');
      if (elDiscount) elDiscount.textContent = discountStatus;

      // Tab counts
      const pointsTrans = allTransactions.filter(t => t.program_type === 'points');
      const cashbackTrans = allTransactions.filter(t => t.program_type === 'cashback');
      const stampsTrans = allTransactions.filter(t => t.program_type === 'stamp');

      const cPoints = document.getElementById('countPoints');
      if (cPoints) cPoints.textContent = pointsTrans.length;
      const cCashback = document.getElementById('countCashback');
      if (cCashback) cCashback.textContent = cashbackTrans.length;
      const cStamps = document.getElementById('countStamps');
      if (cStamps) cStamps.textContent = stampsTrans.length;
      const cDiscounts = document.getElementById('countDiscounts');
      if (cDiscounts) cDiscounts.textContent = '0';

      // Default tab
      switchTab('points', { target: document.querySelector('.tab.active') });
    })
    .catch(err => {
      console.error('Error loading customer:', err);
      showToast('Error loading customer details', 'error');
    });
}

/* ---------- Profile Header (keeps single instance) ---------- */
function renderProfileHeader(customer) {
  const details = document.getElementById('customerDetailsSection');
  if (!details) return;

  // Ensure no prior header remains
  details.querySelectorAll('.profile-header').forEach(h => h.remove());

  const classification = (customer.classification || 'regular').toLowerCase();
  const enrolled = Number(customer.rewards_enrolled) === 1;

  const classificationBadge = `
    <span class="badge ${classification}">${classification.toUpperCase()}</span>
  `;
  const enrolledBadge = enrolled ? `<span class="badge enrolled">ENROLLED</span>` : '';
  const memberDisplay = customer.member_display
    ? `<span style="font-family: monospace; font-size: 12px; background: var(--ms-gray-20); padding: 2px 6px; border-radius: 3px;">${escapeHtml(customer.member_display)}</span>`
    : '';

  // Buttons: Adjust (primary) + Back (nice subtle)
  const actionsHtml = `
    <div class="profile-actions">
      <button class="btn btn-primary small" onclick="showAdjustModal()">Adjust Points</button>
      <button class="btn small" onclick="backToList()">Back</button>
    </div>
  `;

  const headerHtml = `
    <div class="profile-header">
      <div class="profile-top">
        <div class="profile-info">
          <div class="profile-name">
            ${escapeHtml(customer.name || '')}
            <span style="font-size:12px; color: var(--ms-gray-110);">#${Number(customer.id)}</span>
          </div>
          <div class="profile-meta">
            <span><strong>Phone:</strong> ${escapeHtml(customer.phone || 'Not provided')}</span>
            ${memberDisplay ? `<span><strong>Member:</strong> ${memberDisplay}</span>` : ''}
            <span><strong>Status:</strong> ${enrolled ? 'Enrolled' : 'Not Enrolled'}</span>
          </div>
          <div class="badges">
            ${classificationBadge}
            ${enrolledBadge}
          </div>
        </div>
        ${actionsHtml}
      </div>
    </div>
  `;

  // Insert header at top of details section
  const wrapper = document.createElement('div');
  wrapper.innerHTML = headerHtml;
  details.insertBefore(wrapper.firstElementChild, details.firstChild);
}

/* ---------- Tabs ---------- */
function switchTab(tabName, event) {
  currentTab = tabName;

  document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));
  if (event && event.target) event.target.classList.add('active');

  document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
  const target = document.getElementById(`tab${capitalizeFirst(tabName)}`);
  if (target) target.classList.add('active');

  switch (tabName) {
    case 'points':   renderPointsTransactions();  break;
    case 'cashback': renderCashbackTransactions(); break;
    case 'stamps':   renderStampsTransactions();   break;
    case 'discounts': /* static */ break;
  }
}

/* ---------- Transactions ---------- */
function renderPointsTransactions() {
  const transactions = allTransactions.filter(t => t.program_type === 'points');
  const container = document.getElementById('pointsTransactions');
  if (!container) return;

  if (transactions.length === 0) {
    container.innerHTML = `
      <div class="empty-state">
        <h3>No points transactions</h3>
        <p>Customer has no points history</p>
      </div>
    `;
    return;
  }

  let html = `
    <table class="transaction-table">
      <thead>
        <tr>
          <th>Date</th>
          <th>Type</th>
          <th>Description</th>
          <th>Branch</th>
          <th style="text-align: right;">Amount</th>
        </tr>
      </thead>
      <tbody>
  `;

  transactions.forEach(trans => {
    const isPositive = trans.direction === 'earn' || (trans.direction === 'adjust' && Number(trans.amount) > 0);
    const amount = parseInt(trans.amount || 0, 10);
    const sign = isPositive ? '+' : '-';

    html += `
      <tr>
        <td>${formatDate(trans.created_at)}</td>
        <td><span class="transaction-type ${escapeHtml(trans.direction)}">${escapeHtml(trans.direction)}</span></td>
        <td>${escapeHtml(trans.reason || `${trans.direction} points`)}</td>
        <td>${escapeHtml(trans.branch_name || '-')}</td>
        <td style="text-align: right;">
          <span class="transaction-amount ${isPositive ? 'positive' : 'negative'}">
            ${sign}${Math.abs(amount).toLocaleString()}
          </span>
        </td>
      </tr>
    `;
  });

  html += '</tbody></table>';
  container.innerHTML = html;
}

function renderCashbackTransactions() {
  const transactions = allTransactions.filter(t => t.program_type === 'cashback');
  const container = document.getElementById('cashbackTransactions');
  if (!container) return;

  if (transactions.length === 0) {
    container.innerHTML = `
      <div class="empty-state">
        <h3>No cashback transactions</h3>
        <p>Customer has no cashback history</p>
      </div>
    `;
    return;
  }

  let html = `
    <table class="transaction-table">
      <thead>
        <tr>
          <th>Date</th>
          <th>Type</th>
          <th>Description</th>
          <th>Branch</th>
          <th style="text-align: right;">Amount</th>
        </tr>
      </thead>
      <tbody>
  `;

  transactions.forEach(trans => {
    const isPositive = trans.direction === 'earn';
    const amount = parseFloat(trans.amount || 0);
    const sign = isPositive ? '+' : '-';

    html += `
      <tr>
        <td>${formatDate(trans.created_at)}</td>
        <td><span class="transaction-type ${escapeHtml(trans.direction)}">${escapeHtml(trans.direction)}</span></td>
        <td>${escapeHtml(trans.reason || `Cashback ${trans.direction}`)}</td>
        <td>${escapeHtml(trans.branch_name || '-')}</td>
        <td style="text-align: right;">
          <span class="transaction-amount ${isPositive ? 'positive' : 'negative'}">
            ${sign}EGP ${Math.abs(amount).toFixed(2)}
          </span>
        </td>
      </tr>
    `;
  });

  html += '</tbody></table>';
  container.innerHTML = html;
}

function renderStampsTransactions() {
  const transactions = allTransactions.filter(t => t.program_type === 'stamp');
  const container = document.getElementById('stampsTransactions');
  if (!container) return;

  if (transactions.length === 0) {
    container.innerHTML = `
      <div class="empty-state">
        <h3>No stamp transactions</h3>
        <p>Customer has no stamp history</p>
      </div>
    `;
    return;
  }

  let html = `
    <table class="transaction-table">
      <thead>
        <tr>
          <th>Date</th>
          <th>Program</th>
          <th>Type</th>
          <th>Description</th>
          <th style="text-align: right;">Stamps</th>
        </tr>
      </thead>
      <tbody>
  `;

  transactions.forEach(trans => {
    const isPositive = trans.direction === 'earn';
    const amount = parseInt(trans.amount || 0, 10);
    const sign = isPositive ? '+' : '-';

    html += `
      <tr>
        <td>${formatDate(trans.created_at)}</td>
        <td>${escapeHtml(trans.program_name || 'Stamp Program')}</td>
        <td><span class="transaction-type ${escapeHtml(trans.direction)}">${escapeHtml(trans.direction)}</span></td>
        <td>${escapeHtml(trans.reason || `${trans.direction} stamps`)}</td>
        <td style="text-align: right;">
          <span class="transaction-amount ${isPositive ? 'positive' : 'negative'}">
            ${sign}${Math.abs(amount)}
          </span>
        </td>
      </tr>
    `;
  });

  html += '</tbody></table>';
  container.innerHTML = html;
}

/* ---------- Navigation ---------- */
function backToList() {
  const detailsSection = document.getElementById('customerDetailsSection');
  const tableCard = document.getElementById('customerTableCard');
  const filtersBar = document.getElementById('filtersBar');

  // Clean up injected content so nothing lingers when we come back later
  cleanupDetailsSection();

  if (detailsSection) detailsSection.classList.remove('active');
  if (tableCard) tableCard.style.display = 'block';
  if (filtersBar) filtersBar.style.display = 'flex';

  currentCustomer = null;
}

/* ---------- Adjust Modal ---------- */
function showAdjustModal() {
  if (!currentCustomer) return;

  const idInput = document.getElementById('adjustCustomerId');
  const hint = document.getElementById('currentBalanceHint');
  const modal = document.getElementById('adjustModal');

  if (idInput) idInput.value = currentCustomer.id;
  if (hint) hint.textContent = Number(currentCustomer.points_balance) || 0;
  if (modal) modal.classList.add('active');
}

function closeModal() {
  const modal = document.getElementById('adjustModal');
  const form = document.getElementById('adjustForm');
  if (modal) modal.classList.remove('active');
  if (form) form.reset();
}

function updateMaxAmount() {
  const directionEl = document.getElementById('adjustDirection');
  const amountInput = document.getElementById('adjustAmount');
  if (!directionEl || !amountInput) return;

  const direction = directionEl.value;
  if (direction === 'redeem' && currentCustomer) {
    amountInput.max = Number(currentCustomer.points_balance) || 0;
  } else {
    amountInput.max = 10000;
  }
}

function submitAdjustment(event) {
  event.preventDefault();
  const form = event.target;

  fetch('', {
    method: 'POST',
    body: new FormData(form),
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        showToast(data.message || 'Updated successfully', 'success');
        closeModal();
        if (currentCustomer) loadCustomerDetails(currentCustomer.id);
      } else {
        showToast(data.error || 'Error adjusting points', 'error');
      }
    })
    .catch(err => {
      console.error('Error:', err);
      showToast('Error adjusting points', 'error');
    });
}

/* ---------- Branches ---------- */
function loadBranches() {
  fetch('?action=get_branches', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(r => r.json())
    .then(data => {
      if (data.success && Array.isArray(data.branches)) {
        const select = document.getElementById('adjustBranch');
        if (!select) return;
        select.innerHTML = '';
        data.branches.forEach(branch => {
          const option = document.createElement('option');
          option.value = branch.id;
          option.textContent = branch.name;
          if (window.REWARDS_PAGE && branch.id == window.REWARDS_PAGE.userBranchId) {
            option.selected = true;
          }
          select.appendChild(option);
        });
      }
    })
    .catch(err => console.error('Error loading branches:', err));
}

/* ---------- Filters / Search ---------- */
let searchTimer = null;
function debounceTableSearch() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(loadCustomers, 600);
}

function applyTableFilters() {
  loadCustomers();
}

function clearTableFilters() {
  const s = document.getElementById('tableSearchInput');
  const c = document.getElementById('classificationFilter');
  const r = document.getElementById('rewardsFilter');
  if (s) s.value = '';
  if (c) c.value = 'all';
  if (r) r.value = 'all';
  loadCustomers();
}

function updateClearFiltersVisibility() {
  const s = (document.getElementById('tableSearchInput') || {}).value || '';
  const c = (document.getElementById('classificationFilter') || {}).value || 'all';
  const r = (document.getElementById('rewardsFilter') || {}).value || 'all';
  const hasFilters = s !== '' || c !== 'all' || r !== 'all';

  const btn = document.getElementById('clearFiltersBtn');
  if (btn) btn.style.display = hasFilters ? 'block' : 'none';
}

/* ---------- Helpers ---------- */
function formatDate(dateString) {
  const date = new Date(dateString);
  if (isNaN(date.getTime())) return '';
  return date.toLocaleDateString('en-US', {
    year: 'numeric', month: 'short', day: 'numeric',
    hour: '2-digit', minute: '2-digit'
  });
}

function capitalizeFirst(str) {
  if (!str) return '';
  return str.charAt(0).toUpperCase() + str.slice(1);
}

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = String(str ?? '');
  return div.innerHTML;
}

/* ---------- Toasts ---------- */
function showToast(message, type = 'success') {
  const container = document.getElementById('toastContainer') || createToastContainer();
  const toast = document.createElement('div');
  toast.className = `toast ${type}`;
  toast.innerHTML = `<span>${escapeHtml(message)}</span>`;
  container.appendChild(toast);

  setTimeout(() => {
    toast.style.opacity = '0';
    toast.style.transform = 'translateX(100%)';
    setTimeout(() => toast.remove(), 300);
  }, 4000);
}

function createToastContainer() {
  const container = document.createElement('div');
  container.id = 'toastContainer';
  container.className = 'toast-container';
  document.body.appendChild(container);
  return container;
}

/* ---------- Expose for inline handlers ---------- */
window.loadCustomerDetails = loadCustomerDetails;
window.backToList = backToList;
window.showAdjustModal = showAdjustModal;
window.closeModal = closeModal;
window.updateMaxAmount = updateMaxAmount;
window.submitAdjustment = submitAdjustment;
window.debounceTableSearch = debounceTableSearch;
window.applyTableFilters = applyTableFilters;
window.clearTableFilters = clearTableFilters;