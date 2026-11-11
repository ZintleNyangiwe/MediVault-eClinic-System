(() => {
  const $ = (s, r=document) => r.querySelector(s);
  const $$ = (s, r=document) => [...r.querySelectorAll(s)];
  let loginMethod = 'email';
  let currentUser = null;

  function showView(name, addHistory=true) {
    const target = document.getElementById(name + 'View');
    if (!target) return;
    $$('.screen').forEach(s => s.classList.toggle('active', s === target));
    window.scrollTo({top:0, behavior:'smooth'});
    if(addHistory) history.pushState({view:name}, '', '#' + name);
  }
  window.addEventListener('popstate', () => showView(location.hash.replace('#','') || 'landing', false));

  function msg(id, text, type='success') {
    const el = $('#' + id); if (!el) return;
    el.textContent = text; el.className = 'form-message ' + type;
  }
  async function api(action, options={}) {
    const response = await fetch('api.php?action=' + encodeURIComponent(action), {headers:{'Content-Type':'application/json'}, ...options});
    let data; try { data = await response.json(); } catch { throw new Error('The server returned an invalid response.'); }
    if (!response.ok || !data.success) throw new Error(data.message || 'Request failed.');
    return data;
  }
  function initials(user) { return ((user.first_name?.[0]||'')+(user.last_name?.[0]||'')).toUpperCase(); }
  function esc(v) { return String(v ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c])); }
  function date(v) { if(!v) return ''; const d=new Date(v+'T00:00:00'); return d.toLocaleDateString('en-ZA',{day:'2-digit',month:'short',year:'numeric'}); }
  function status(v) { const c=String(v||'').toLowerCase(); return `<span class="status ${c==='confirmed'||c==='active'||c==='completed'||c==='reviewed'?'confirmed':'pending'}">${esc(v)}</span>`; }

  function openModal(title, html, onSave=null) {
    $('#modalRoot').innerHTML = `<div class="mv-modal-overlay" role="presentation"><section class="mv-modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle"><button class="mv-modal-close" type="button" aria-label="Close">×</button><div class="mv-modal-icon">♥</div><h2 id="modalTitle">${esc(title)}</h2><div class="mv-modal-body">${html}</div><div class="mv-modal-actions">${onSave ? '<button class="btn btn-primary" id="modalSave">Save</button>' : ''}<button class="btn btn-secondary" id="modalCancel">${onSave ? 'Cancel' : 'Close'}</button></div></section></div>`;
    $('#modalCancel').onclick=closeModal; $('.mv-modal-close').onclick=closeModal;
    if(onSave) $('#modalSave').onclick=async()=>{ try { await onSave(); closeModal(); } catch(e){ alert(e.message); } };
  }
  function closeModal(){ $('#modalRoot').innerHTML=''; }
  $('#modalRoot').addEventListener('click',e=>{if(e.target.classList.contains('mv-modal-overlay'))closeModal();});
  window.addEventListener('keydown',e=>{if(e.key==='Escape'&&$('#modalRoot').innerHTML)closeModal();});

  function updateLoginMethod(method) {
    loginMethod=method; $$('[data-login-method]').forEach(b=>b.classList.toggle('active', b.dataset.loginMethod===method));
    const input=$('#loginEmail'), label=$('#loginIdentifierLabel');
    if(method==='phone'){label.textContent='Phone Number'; input.type='tel'; input.placeholder='e.g. 071 234 5678';} else {label.textContent='Email Address'; input.type='email'; input.placeholder='you@example.com';}
  }
  $$('[data-login-method]').forEach(b=>b.onclick=()=>updateLoginMethod(b.dataset.loginMethod));
  $$('[data-view]').forEach(b=>b.onclick=e=>{e.preventDefault(); showView(b.dataset.view);});

  $$('.signup-tabs .tab').forEach(b=>b.onclick=()=>{$$('.signup-tabs .tab').forEach(x=>x.classList.remove('active')); b.classList.add('active'); $('#signupRole').value=b.dataset.role; const patientFields=['dob','gender','address','emergency']; patientFields.forEach(id=>{const el=$('#'+id); if(el) el.required=b.dataset.role==='Patient';});});

  $('#signupForm').onsubmit=async e=>{
    e.preventDefault(); const form=e.currentTarget;
    if(!form.checkValidity()){form.reportValidity();msg('signupMessage','Please complete all required fields correctly.','error');return;}
    const payload={first_name:$('#firstName').value.trim(),last_name:$('#lastName').value.trim(),email:$('#signupEmail').value.trim(),phone:$('#signupPhone').value.trim(),date_of_birth:$('#dob').value,gender:$('#gender').value,address:$('#address').value.trim(),emergency_contact:$('#emergency').value.trim(),password:$('#signupPassword').value,confirm_password:$('#signupConfirm').value,role:$('#signupRole').value};
    try{await api('register',{method:'POST',body:JSON.stringify(payload)}); msg('signupMessage','Account created successfully. You can now log in.','success'); form.reset(); $('#signupRole').value='Patient'; $$('.signup-tabs .tab').forEach((x,i)=>x.classList.toggle('active',i===0)); setTimeout(()=>showView('login'),800);}catch(err){msg('signupMessage',err.message,'error');}
  };

  $('#loginForm').onsubmit=async e=>{e.preventDefault(); const id=$('#loginEmail').value.trim(), pass=$('#loginPassword').value; if(!id||!pass){msg('loginMessage','Please complete the login fields first.','error');return;} try{const r=await api('login',{method:'POST',body:JSON.stringify({method:loginMethod,identifier:id,password:pass})}); currentUser=r.data.user; routeUser();}catch(err){msg('loginMessage',err.message,'error');}};
  $('#sendCode').onclick=()=>{msg('loginMessage','One-time code delivery is not configured for this installation. Please sign in with your password.','error');};
  $('#faceId').onclick=()=>openModal('Secure Login','<p>Secure authentication is enabled as a user-interface option. Account verification is performed through the registered credentials and role checks in the database.</p>');
  $('#forgotPassword').onclick=()=>openModal('Password Reset','<p>Password reset delivery is not configured for this installation. Please contact your clinic administrator to reset your account securely.</p>');

  $('#bookAppointment').onclick=()=>{
    const minDate=new Date().toISOString().slice(0,10);
    openModal('Book an appointment',`<p>Choose a date and time for your visit. Your request will be sent to the clinic as pending.</p><label class="mv-modal-label" for="appointmentDate">Date</label><input id="appointmentDate" class="mv-modal-input" type="date" min="${minDate}" required><label class="mv-modal-label" for="appointmentTime">Time</label><input id="appointmentTime" class="mv-modal-input" type="time" required><label class="mv-modal-label" for="appointmentReason">Reason for visit</label><input id="appointmentReason" class="mv-modal-input" maxlength="255" placeholder="e.g. General check-up" required>`,async()=>{
      const appointment_date=$('#appointmentDate').value, appointment_time=$('#appointmentTime').value, reason=$('#appointmentReason').value.trim();
      if(!appointment_date||!appointment_time||!reason) throw new Error('Please complete the date, time and reason.');
      await api('appointments',{method:'POST',body:JSON.stringify({appointment_date,appointment_time,reason})}); await loadPatient();
    });
  };

  async function routeUser(){
    if(!currentUser) return;
    if(currentUser.role==='Patient'){setUserHeader('patient'); showView('patient'); await loadPatient();}
    else if(currentUser.role==='Staff'){setUserHeader('staff'); showView('staff'); await loadStaff();}
    else { $('#adminName').textContent=currentUser.first_name+' '+currentUser.last_name; showView('admin'); await loadAdmin(); }
  }
  function setUserHeader(type){const u=currentUser; if(type==='patient'){ $('#patientName').textContent=u.first_name+' '+u.last_name; $('#patientWelcome').textContent=`Welcome back, ${u.first_name}. Here is your health overview.`; $('#patientAvatar').textContent=initials(u);} else {$('#staffName').textContent=u.first_name+' '+u.last_name;$('#staffAvatar').textContent=initials(u);}}
  async function logout(){try{await api('logout',{method:'POST',body:'{}'});}catch{} currentUser=null; showView('landing');}
  $('#patientLogout').onclick=logout; $('#staffLogout').onclick=logout; $('#adminLogout').onclick=logout;

  function setupPanels(screenId){const screen=$('#'+screenId+'View'); $$('.nav-item',screen).forEach(btn=>btn.onclick=()=>activatePanel(screen,btn.dataset.panel)); $$('[data-panel-link]',screen).forEach(btn=>btn.onclick=()=>activatePanel(screen,btn.dataset.panelLink));}
  function activatePanel(screen,panelId){ $$('.dash-panel',screen).forEach(p=>p.classList.toggle('active',p.id===panelId)); $$('.nav-item',screen).forEach(n=>n.classList.toggle('active',n.dataset.panel===panelId)); }
  setupPanels('patient'); setupPanels('staff'); setupPanels('admin');

  function renderAppointments(items, target, patient=false){if(!items.length){target.innerHTML='<div class="empty-state">No appointments found.</div>';return;} target.innerHTML=items.map(a=>`<div class="data-row"><div class="date-box"><strong>${esc((a.appointment_date||'').slice(8,10))}</strong><span>${esc((a.appointment_date||'').slice(5,7))}</span></div><div><strong>${esc(a.reason)}</strong><p>${patient?esc(a.clinician||'Clinic staff'):esc(a.patient||'Patient')} · ${esc(a.appointment_time)}</p></div>${status(a.status)}</div>`).join('');}
  function renderRecords(items,target){if(!items.length){target.innerHTML='<div class="empty-state">No medical records found.</div>';return;} target.innerHTML=items.map(r=>`<div class="record-row"><div><strong>${esc(r.record_type)}</strong><p>${date(r.record_date)} · ${esc(r.diagnosis)}</p><small>${esc(r.notes)}</small></div>${status('Reviewed')}</div>`).join('');}
  function renderMeds(items,target){if(!items.length){target.innerHTML='<div class="empty-state">No medications found.</div>';return;} target.innerHTML=items.map(m=>`<div class="record-row"><div><strong>${esc(m.name)}</strong><p>${esc(m.dosage)} · ${esc(m.frequency)}</p><small>${date(m.start_date)}${m.end_date?' – '+date(m.end_date):''}</small></div>${status(m.status)}</div>`).join('');}

  async function loadPatient(){try{const [a,r,m]=await Promise.all([api('appointments'),api('records'),api('medications')]); const ap=a.data.appointments, rec=r.data.records, meds=m.data.medications; $('#patientApptCount').textContent=ap.length;$('#patientRecordCount').textContent=rec.length;$('#patientMedCount').textContent=meds.filter(x=>x.status==='Active').length; renderAppointments(ap.slice(0,5),$('#patientAppointmentsList'),true);renderAppointments(ap,$('#patientAppointmentsFull'),true);renderRecords(rec.slice(0,5),$('#patientRecordsList'));renderRecords(rec,$('#patientRecordsFull'));renderMeds(meds,$('#patientMedicationFull'));}catch(e){alert(e.message);}}

  async function loadStaff(){try{const [p,a,r]=await Promise.all([api('patients'),api('appointments'),api('records')]); const patients=p.data.patients, apps=a.data.appointments, records=r.data.records; $('#totalPatients').textContent=patients.length;$('#totalAppointments').textContent=apps.length;$('#totalRecords').textContent=records.length; renderAppointments(apps.slice(0,5),$('#staffAppointmentsList')); renderAppointments(apps,$('#staffAppointmentsFull')); renderStaffRecords(records,$('#staffRecordsFull')); renderPatientTable(patients,$('#patientTable')); renderPatientResults(patients,$('#patientResults'));}catch(e){alert(e.message);}}
  function renderStaffRecords(items,target){if(!items.length){target.innerHTML='<div class="empty-state">No records found.</div>';return;} target.innerHTML='<table><thead><tr><th>Date</th><th>Patient</th><th>Type</th><th>Diagnosis</th></tr></thead><tbody>'+items.map(r=>`<tr><td>${date(r.record_date)}</td><td>${esc(r.patient)}</td><td>${esc(r.record_type)}</td><td>${esc(r.diagnosis)}</td></tr>`).join('')+'</tbody></table>';}
  function renderPatientResults(items,target){if(!items.length){target.innerHTML='<div class="empty-state">No patients found.</div>';return;} target.innerHTML=items.slice(0,5).map(p=>`<div class="patient-result"><span class="avatar">${esc((p.first_name[0]||'')+(p.last_name[0]||''))}</span><div><strong>${esc(p.first_name+' '+p.last_name)}</strong><p>${esc(p.patient_number)} · ${esc(p.email)}</p></div><button class="text-btn" data-open-patient="${p.patient_id}">Open</button></div>`).join(''); $$('[data-open-patient]').forEach(b=>b.onclick=()=>openPatientModal(items.find(p=>p.patient_id==b.dataset.openPatient)));
  }
  function renderPatientTable(items,target){if(!items.length){target.innerHTML='<div class="empty-state">No patients found.</div>';return;} target.innerHTML='<table><thead><tr><th>ID</th><th>Patient</th><th>Email</th><th>DOB</th><th>Gender</th><th>Actions</th></tr></thead><tbody>'+items.map(p=>`<tr><td>${esc(p.patient_number)}</td><td>${esc(p.first_name+' '+p.last_name)}</td><td>${esc(p.email)}</td><td>${date(p.date_of_birth)}</td><td>${esc(p.gender)}</td><td><button class="mini-btn" data-edit-patient="${p.patient_id}">Edit</button><button class="mini-btn danger" data-delete-patient="${p.patient_id}">Delete</button></td></tr>`).join('')+'</tbody></table>'; $$('[data-edit-patient]').forEach(b=>b.onclick=()=>openPatientModal(items.find(p=>p.patient_id==b.dataset.editPatient))); $$('[data-delete-patient]').forEach(b=>b.onclick=()=>deletePatient(b.dataset.deletePatient));}
  function patientForm(p={}){return `<div class="two-col"><div><label>First Name</label><input id="pFirst" value="${esc(p.first_name)}"></div><div><label>Last Name</label><input id="pLast" value="${esc(p.last_name)}"></div></div><div class="two-col"><div><label>Email</label><input id="pEmail" type="email" value="${esc(p.email)}"></div><div><label>Phone</label><input id="pPhone" value="${esc(p.phone)}"></div></div><div class="two-col"><div><label>Date of Birth</label><input id="pDob" type="date" value="${esc(p.date_of_birth)}"></div><div><label>Gender</label><input id="pGender" value="${esc(p.gender||'Prefer not to say')}"></div></div><label>Address</label><input id="pAddress" value="${esc(p.address)}"><label>Emergency Contact</label><input id="pEmergency" value="${esc(p.emergency_contact)}">`;}
  function getPatientPayload(){return {first_name:$('#pFirst').value.trim(),last_name:$('#pLast').value.trim(),email:$('#pEmail').value.trim(),phone:$('#pPhone').value.trim(),date_of_birth:$('#pDob').value,gender:$('#pGender').value.trim(),address:$('#pAddress').value.trim(),emergency_contact:$('#pEmergency').value.trim()};}
  function openPatientModal(p){const isEdit=!!p; openModal(isEdit?'Update Patient':'Add Patient',patientForm(p||{}),async()=>{const payload=getPatientPayload(); for(const [k,v] of Object.entries(payload)){if(['address','emergency_contact'].includes(k))continue;if(!v)throw new Error('All required patient fields must be completed.');} await api(isEdit?'patient_update':'patient_create',{method:'POST',body:JSON.stringify(isEdit?{...payload,patient_id:p.patient_id}:payload)}); await loadStaff(); if($('#adminView').classList.contains('active')) await loadAdmin();});}
  async function deletePatient(id){if(!confirm('Delete this patient and their linked records? This action cannot be undone.'))return; try{await api('patient_delete',{method:'POST',body:JSON.stringify({patient_id:Number(id)})}); await loadStaff(); if($('#adminView').classList.contains('active'))await loadAdmin();}catch(e){alert(e.message);}}

  async function searchPatients(inputId, resultId, tableId){try{const r=await api('patients&search='+encodeURIComponent($('#'+inputId).value)); renderPatientResults(r.data.patients,$('#'+resultId)); if(tableId)renderPatientTable(r.data.patients,$('#'+tableId));}catch(e){alert(e.message);}}
  // Correct query-string helper for patient searches.
  async function searchPatientData(inputId, resultId, tableId){try{const response=await fetch('api.php?action=patients&search='+encodeURIComponent($('#'+inputId).value));const data=await response.json();if(!data.success)throw new Error(data.message);renderPatientResults(data.data.patients,$('#'+resultId));if(tableId)renderPatientTable(data.data.patients,$('#'+tableId));}catch(e){alert(e.message);}}
  $('#searchPatients').onclick=()=>searchPatientData('patientSearch','patientResults'); $('#searchPatientsFull').onclick=()=>searchPatientData('patientSearchFull','patientResults','patientTable'); $('#patientSearch').onkeydown=e=>{if(e.key==='Enter')$('#searchPatients').click();}; $('#patientSearchFull').onkeydown=e=>{if(e.key==='Enter')$('#searchPatientsFull').click();};
  $('#newPatient').onclick=()=>openPatientModal(); $('#newPatient2').onclick=()=>openPatientModal();

  function renderAdminPatients(items){renderPatientTable(items,$('#adminPatientTable'));}
  async function loadAdmin(){try{const r=await api('patients');$('#adminPatientTable').innerHTML='';renderAdminPatients(r.data.patients);}catch(e){alert(e.message);}}
  $('#adminSearchPatients').onclick=async()=>{try{const res=await fetch('api.php?action=patients&search='+encodeURIComponent($('#adminPatientSearch').value));const d=await res.json();if(!d.success)throw new Error(d.message);renderAdminPatients(d.data.patients);}catch(e){alert(e.message);}};
  $('#adminPatientSearch').onkeydown=e=>{if(e.key==='Enter')$('#adminSearchPatients').click();};
  $('#adminNewPatient').onclick=()=>openPatientModal();

  // For admin screens, refresh table and attach delete permissions are already role-protected server-side.
  window.addEventListener('click',e=>{const b=e.target.closest('[data-view]'); if(b && !b.dataset.bound){b.dataset.bound='1';}});

  // Attempt to restore an existing authenticated session on refresh.
  (async()=>{try{const r=await api('session'); if(r.data.user){currentUser=r.data.user; routeUser();}}catch{}})();
})();
