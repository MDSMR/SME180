// admin/users.js - interacts with api/users.php
async function api(path, method='GET', body=null){
  const headers = {'Content-Type':'application/json'};
  const token = localStorage.getItem('smr_token');
  if (token) headers['Authorization'] = 'Bearer ' + token;
  const res = await fetch('/api/' + path, {method, headers, body: body ? JSON.stringify(body) : null});
  return res.json();
}

async function loadUsers(){
  const res = await api('users.php');
  if (!res.success) { alert('Failed to load users'); return; }
  const list = document.getElementById('usersList'); list.innerHTML='';
  res.users.forEach(u=>{
    const row = document.createElement('div'); row.className='userRow';
    row.innerHTML = `<div><strong>${u.username}</strong><div>${u.full_name || ''}</div></div><div class="userActions"><button onclick="editUser(${u.id})">Edit</button><button onclick="toggleUser(${u.id},${u.active?0:1})">${u.active? 'Disable':'Enable'}</button><button onclick="impersonate(${u.id})">Create Order</button></div>`;
    list.appendChild(row);
  });
}

function showModal(title){ document.getElementById('modalTitle').textContent = title; document.getElementById('userModal').style.display='flex'; }
function hideModal(){ document.getElementById('userModal').style.display='none'; }

document.getElementById('newUserBtn').addEventListener('click', ()=>{ showModal('New User'); });

document.getElementById('cancelUserBtn').addEventListener('click', hideModal);

async function saveUser(){
  const form = document.getElementById('userForm');
  const data = { username: form.username.value, full_name: form.full_name.value, password: form.password.value, role_id: form.role_id.value, csrf_token: form.csrf_token.value };
  const res = await api('users.php','POST', data);
  if (res.success) { hideModal(); loadUsers(); } else alert('Error: '+(res.error||'unknown'));
}
document.getElementById('saveUserBtn').addEventListener('click', saveUser);

async function editUser(id){ const res = await api('users.php?id='+id); if (res.success && res.user){ showModal('Edit User'); const f=document.getElementById('userForm'); f.username.value=res.user.username; f.full_name.value=res.user.full_name; f.role_id.value=res.user.role_id; } }

async function toggleUser(id,enable){ const res = await api('users.php','PUT', {id, enable}); if (res.success) loadUsers(); else alert('Error'); }

function impersonate(id){ localStorage.setItem('smr_impersonate', id); window.location.href='/pos/index.php'; }

loadUsers();
