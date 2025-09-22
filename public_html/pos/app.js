// pos/app.js - minimal frontend to interact with API (no frameworks)
let state = { token: null, items: [], categories: [], cart: [], aggregators: [] };

async function api(path, method='GET', body=null) {
    const headers = {};
    if (state.token) headers['Authorization'] = 'Bearer ' + state.token;
    if (body && !(body instanceof FormData)) {
        headers['Content-Type'] = 'application/json';
        body = JSON.stringify(body);
    }
    const res = await fetch('/api/' + path, { method, headers, body });
    return res.json();
}

async function init() {
    // try to load token from localStorage
    state.token = localStorage.getItem('smr_token');
    const menu = await api('menu.php');
    if (menu.success) {
        state.items = menu.items;
        state.categories = menu.categories;
        state.addons = menu.addons;
        renderCategories();
        renderItems();
    } else {
        console.error('menu load failed', menu);
        // prompt login
        await loginPrompt();
    }
    const tables = await api('tables.php');
    if (tables.success) {
        const sel = document.getElementById('tableSelect');
        tables.tables.forEach(t => { const opt = document.createElement('option'); opt.value = t.id; opt.textContent = 'Table ' + t.table_number; sel.appendChild(opt); });
    }
    const ags = await api('aggregators.php');
    if (ags.success) state.aggregators = ags.aggregators;
    bindUI();
}

function bindUI() {
    document.getElementById('payBtn').addEventListener('click', checkout);
    document.getElementById('printBtn').addEventListener('click', ()=> window.print());
    document.getElementById('logoutBtn').addEventListener('click', ()=> { localStorage.removeItem('smr_token'); location.reload(); });
}

function renderCategories() {
    const el = document.getElementById('categories');
    el.innerHTML='';
    state.categories.forEach(c => { const d = document.createElement('div'); d.className='cat'; d.textContent = c.name; d.onclick = ()=> filterByCategory(c.id); el.appendChild(d); });
}

function renderItems(filterId=null) {
    const el = document.getElementById('items');
    el.innerHTML='';
    const items = filterId ? state.items.filter(i=>i.category_id==filterId) : state.items;
    items.forEach(it => {
        const d = document.createElement('div'); d.className='item'; d.innerHTML = `<strong>${it.name}</strong><div>${it.price.toFixed(2)}</div>`;
        d.onclick = ()=> addToCart(it.id);
        el.appendChild(d);
    });
}

function filterByCategory(id) { renderItems(id); }

function addToCart(itemId) {
    const it = state.items.find(x=>x.id==itemId);
    if (!it) return;
    const existing = state.cart.find(c=>c.item_id==itemId);
    if (existing) existing.qty += 1; else state.cart.push({item_id:itemId, qty:1, name:it.name, price:it.price});
    renderCart();
}

function renderCart() {
    const el = document.getElementById('cart'); el.innerHTML='';
    state.cart.forEach((c,idx)=>{
        const row = document.createElement('div'); row.className='cart-row';
        row.innerHTML = `<div>${c.name} x${c.qty}</div><div>${(c.price*c.qty).toFixed(2)}</div>`;
        el.appendChild(row);
    });
    renderPriceBreakdown();
}

function renderPriceBreakdown() {
    const el = document.getElementById('priceBreakdown');
    let subtotal = state.cart.reduce((s,c)=>s+c.price*c.qty,0);
    let couponDeduct = (state.applied_coupon ? parseFloat(state.applied_coupon.cashback_amount) : 0);

    let tax = 0; // frontend doesn't know tax per item reliably, skip
    let service = 0;
    let aggFee = 0;
    let total = subtotal + tax + service + aggFee - couponDeduct; if (total < 0) total = 0;
    el.innerHTML = `<div>Subtotal: ${subtotal.toFixed(2)}</div><div>Coupon: ${couponDeduct.toFixed(2)}</div><div>Total: ${total.toFixed(2)}</div>`;
}

async function checkout() {
    if (!state.token) { await loginPrompt(); if (!state.token) return; }
    const order = {
        order_type: document.getElementById('orderType').value,
        table_id: parseInt(document.getElementById('tableSelect').value || 0) || null,
        guest_count: parseInt(document.getElementById('guestCount').value||1),
        items: state.cart.map(c=>({item_id:c.item_id, qty:c.qty}))
    };
    const res = await api('orders.php', 'POST', order);
    if (res.success) { alert('Order created: ' + res.receipt_number); if (res.coupon_code) { window.open('/print/coupon.php?code='+encodeURIComponent(res.coupon_code),'_blank'); } state.cart = []; renderCart(); } else alert('Error: ' + (res.error || 'unknown'));
}

async function loginPrompt() {
    const username = prompt('Username:');
    if (!username) return;
    const password = prompt('Password:');
    if (!password) return;
    const res = await fetch('/api/auth.php', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({username, password}) });
    const data = await res.json();
    if (data.success && data.access_token) {
        state.token = data.access_token;
        localStorage.setItem('smr_token', state.token);
        return true;
    } else {
        alert('Login failed');
        return false;
    }
}

window.addEventListener('DOMContentLoaded', init);



async function applyCoupon() {
    const code = document.getElementById('couponCode').value.trim();
    if (!code) { alert('Enter coupon code'); return; }
    const res = await api('coupons.php','POST',{code:code, order_id:0}); // order_id 0 for pre-check; backend requires order_id when redeeming; we'll support apply by fetching coupon details
    // Instead, use GET to fetch coupon details
    const info = await fetch('/api/coupons.php?code=' + encodeURIComponent(code)).then(r=>r.json());
    if (!info.success) { alert('Coupon invalid'); return; }
    alert('Coupon valid. Discount: ' + parseFloat(info.coupon.cashback_amount).toFixed(2));
    // store applied coupon in state for checkout flow
    state.applied_coupon = info.coupon;
    renderPriceBreakdown();
}
document.getElementById('applyCouponBtn').addEventListener('click', applyCoupon);
