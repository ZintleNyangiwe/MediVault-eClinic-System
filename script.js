(() => {
  const $ = (s, r=document) => r.querySelector(s);
  const $$ = (s, r=document) => [...r.querySelectorAll(s)];
  let loginMethod = 'email';
  let currentUser = null;
  let calendarMonth = new Date(new Date().getFullYear(), new Date().getMonth(), 1);
  let staffCalendarAppointments = [];
  let selectedCalendarDate = '';

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
    const {query={}, ...requestOptions} = options;
    const params = new URLSearchParams({action});
    Object.entries(query).forEach(([key, value]) => params.set(key, value));
    const response = await fetch('api.php?' + params.toString(), {headers:{'Content-Type':'application/json'}, ...requestOptions});
    const body = await response.text();
    let data;
    try { data = JSON.parse(body); }
    catch {
      const type = response.headers.get('content-type') || 'unknown content type';
      throw new Error(`The server returned a page instead of JSON (HTTP ${response.status}, ${type}). Check that api.php is uploaded and PHP is enabled, then verify the database settings in config.php.`);
    }
    if (!response.ok || !data.success) throw new Error(data.message || 'Request failed.');
    return data;
  }
  function initials(user) { return ((user.first_name?.[0]||'')+(user.last_name?.[0]||'')).toUpperCase(); }
  function esc(v) { return String(v ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c])); }
  function date(v) { if(!v) return ''; const d=new Date(v+'T00:00:00'); return d.toLocaleDateString('en-ZA',{day:'2-digit',month:'short',year:'numeric'}); }
  function status(v) { const c=String(v||'').toLowerCase(); return `<span class="status ${c==='confirmed'||c==='active'||c==='completed'||c==='reviewed'?'confirmed':'pending'}">${esc(v)}</span>`; }

  function openModal(title, html, onSave=null) {
    $('#modalRoot').innerHTML = `<div class="mv-modal-overlay" role="presentation"><section class="mv-modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle"><button class="mv-modal-close" type="button" aria-label="Close">×</button><img class="mv-modal-icon" src="assets/medivault-logo-cropped.jpg" alt="MediVault"><h2 id="modalTitle">${esc(title)}</h2><div class="mv-modal-body">${html}</div><div class="mv-modal-actions">${onSave ? '<button class="btn btn-primary" id="modalSave">Save</button>' : ''}<button class="btn btn-secondary" id="modalCancel">${onSave ? 'Cancel' : 'Close'}</button></div></section></div>`;
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

  $('#loginForm').onsubmit=async e=>{e.preventDefault(); const id=$('#loginEmail').value.trim(), pass=$('#loginPassword').value; if(!id||!pass){msg('loginMessage','Please complete the login fields first.','error');return;} try{const r=await api('login',{method:'POST',body:JSON.stringify({method:loginMethod,identifier:id,password:pass,remember:document.querySelector('#rememberMe').checked})}); currentUser=r.data.user; routeUser();}catch(err){msg('loginMessage',err.message,'error');}};
  $('#sendCode').onclick=()=>{msg('loginMessage','One-time code delivery is not configured for this installation. Please sign in with your password.','error');};
  $('#faceId').onclick=()=>openModal('Secure Login','<p>Secure authentication is enabled as a user-interface option. Account verification is performed through the registered credentials and role checks in the database.</p>');
  $('#forgotPassword').onclick=()=>openModal('Password Reset','<p>Password reset delivery is not configured for this installation. Please contact your clinic administrator to reset your account securely.</p>');

  $('#bookAppointment').onclick=()=>{
    const minDate=new Date().toISOString().slice(0,10);
    openModal('Book an appointment',`<p>Choose a weekday and a 30-minute time between 09:00 and 16:30. The calendar checks your selected clinician's schedule.</p><label class="mv-modal-label" for="appointmentDate">Date</label><input id="appointmentDate" class="mv-modal-input" type="date" min="${minDate}" required><label class="mv-modal-label" for="appointmentTime">Time</label><select id="appointmentTime" class="mv-modal-input" required><option value="">Select a time</option>${clinicSlots().map(time=>`<option value="${time}">${time}</option>`).join("")}</select><label class="mv-modal-label" for="appointmentClinician">Available clinician</label><select id="appointmentClinician" class="mv-modal-input" disabled><option value="">Select a date and time first</option></select><p id="clinicianAvailability" class="availability-note" aria-live="polite">Choose a date and time to check clinician availability. Availability reflects existing bookings at that exact time.</p><label class="mv-modal-label" for="appointmentReason">Reason for visit</label><input id="appointmentReason" class="mv-modal-input" maxlength="255" placeholder="e.g. General check-up" required>`,async()=>{
      const appointment_date=$('#appointmentDate').value, appointment_time=$('#appointmentTime').value, reason=$('#appointmentReason').value.trim();
      const staff_id=Number($('#appointmentClinician').value);
      if(!appointment_date||!appointment_time||!staff_id||!reason) throw new Error('Choose a date, time, available clinician, and reason.');
      await api('appointments',{method:'POST',body:JSON.stringify({appointment_date,appointment_time,staff_id,reason})}); await loadPatient();
    });
    let availabilityRequest=0;
    const checkAvailability=async()=>{
      const requestId=++availabilityRequest;
      const dateValue=$('#appointmentDate').value,timeValue=$('#appointmentTime').value;
      const select=$('#appointmentClinician'),note=$('#clinicianAvailability');
      select.innerHTML='<option value="">Checking clinicians…</option>';select.disabled=true;
      if(!dateValue||!timeValue){select.innerHTML='<option value="">Select a date and time first</option>';note.textContent='Choose a date and time to check clinician availability.';return;}
      note.textContent='Checking the clinic schedule…';
      try{
        const result=await api('clinicians',{query:{date:dateValue,time:timeValue}});
        if(requestId!==availabilityRequest)return;
        const clinicians=result.data.clinicians||[];
        const available=clinicians.filter(c=>Number(c.available)===1);
        if(!clinicians.length){select.innerHTML='<option value="">No clinicians are registered</option>';note.textContent='No active clinicians are registered. For this demo, create a Staff account, then try again.';return;}
        select.innerHTML='<option value="">Choose an available clinician</option>'+clinicians.map(c=>`<option value="${Number(c.staff_id)}" ${Number(c.available)===1?'':'disabled'}>${esc(c.clinician)} — ${esc(c.job_title)} · ${Number(c.available)===1?'Available':'Already booked'}</option>`).join('');
        select.disabled=available.length===0;
        note.textContent=available.length?`${available.length} clinician${available.length===1?' is':'s are'} available at this date and time.`:'No clinicians are available at this date and time. Try another time.';
      }catch(error){if(requestId!==availabilityRequest)return;select.innerHTML='<option value="">Availability could not be checked</option>';note.textContent=error.message;}
    };
    $('#appointmentDate').addEventListener('change',checkAvailability);
    $('#appointmentTime').addEventListener('change',checkAvailability);
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

  function calendarDateKey(d){return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;}
  function clinicSlots(){const slots=[];for(let hour=9;hour<17;hour++){slots.push(`${String(hour).padStart(2,'0')}:00`,`${String(hour).padStart(2,'0')}:30`);}return slots;}
  function calendarAppointmentsOn(day){return staffCalendarAppointments.filter(a=>a.appointment_date===day);}
  function renderCalendarSlots(day){
    const target=$('#calendarSelectedSlots');if(!target)return;
    const d=new Date(`${day}T00:00:00`),today=calendarDateKey(new Date()),weekday=d.getDay();
    const bookings=calendarAppointmentsOn(day),busy=new Set(bookings.filter(a=>['Pending','Confirmed'].includes(a.status)).map(a=>(a.appointment_time||'').slice(0,5)));
    const title=d.toLocaleDateString('en-ZA',{weekday:'long',day:'numeric',month:'long',year:'numeric'});
    if(day<today||weekday===0||weekday===6){target.innerHTML=`<h3>${esc(title)}</h3><p class="empty-state">${day<today?'Past dates are not bookable.':'The clinic is closed on weekends.'}</p>`;return;}
    const slots=clinicSlots();
    const now=new Date(),currentTime=`${String(now.getHours()).padStart(2,'0')}:${String(now.getMinutes()).padStart(2,'0')}`;
    const rows=slots.map(time=>day===today&&time<=currentTime?`<div class="calendar-slot closed"><strong>${time}</strong><span>Passed</span></div>`:busy.has(time)?`<div class="calendar-slot booked"><strong>${time}</strong><span>Booked</span></div>`:`<div class="calendar-slot open"><strong>${time}</strong><span>Open</span></div>`).join('');
    const nonstandard=bookings.filter(a=>busy.has((a.appointment_time||'').slice(0,5))&&!slots.includes((a.appointment_time||'').slice(0,5)));
    const extras=nonstandard.map(a=>`<div class="calendar-slot booked"><strong>${esc((a.appointment_time||'').slice(0,5))}</strong><span>${esc(a.status)} · ${esc(a.patient)}</span></div>`).join('');
    target.innerHTML=`<h3>${esc(title)}</h3><p>Open slots are Monday–Friday, 09:00–17:00, in 30-minute blocks. Pending and confirmed appointments are reserved.</p><div class="calendar-slot-list">${rows}${extras}</div>`;
  }
  function renderStaffCalendar(){
    const grid=$('#staffCalendarGrid');if(!grid)return;
    const year=calendarMonth.getFullYear(),month=calendarMonth.getMonth(),first=new Date(year,month,1),days=new Date(year,month+1,0).getDate(),today=calendarDateKey(new Date());
    $('#calendarMonthLabel').textContent=first.toLocaleDateString('en-ZA',{month:'long',year:'numeric'});
    const offset=(first.getDay()+6)%7,cells=Array(offset).fill('<span class="calendar-empty" aria-hidden="true"></span>');
    for(let day=1;day<=days;day++){
      const d=new Date(year,month,day),key=calendarDateKey(d),bookings=calendarAppointmentsOn(key),weekday=d.getDay(),isPast=key<today,closed=weekday===0||weekday===6;
      const reserved=new Set(bookings.filter(a=>['Pending','Confirmed'].includes(a.status)).map(a=>(a.appointment_time||'').slice(0,5)));
      const now=new Date(),currentTime=`${String(now.getHours()).padStart(2,'0')}:${String(now.getMinutes()).padStart(2,'0')}`;
      const open=(!isPast&&!closed)?clinicSlots().filter(time=>!reserved.has(time)&&!(key===today&&time<=currentTime)).length:0;
      const bookedCount=bookings.filter(a=>['Pending','Confirmed'].includes(a.status)).length;
      const label=isPast?'Past':closed?'Closed':`${open} open`;
      cells.push(`<button type="button" class="calendar-day ${key===selectedCalendarDate?'selected':''} ${isPast||closed?'closed':''} ${key===today?'today':''}" data-calendar-date="${key}" ${isPast||closed?'disabled':''}><strong>${day}</strong><small>${bookedCount?`${bookedCount} booked · ${label}`:label}</small></button>`);
    }
    grid.innerHTML='<div class="calendar-weekday">Mon</div><div class="calendar-weekday">Tue</div><div class="calendar-weekday">Wed</div><div class="calendar-weekday">Thu</div><div class="calendar-weekday">Fri</div><div class="calendar-weekday">Sat</div><div class="calendar-weekday">Sun</div>'+cells.join('');
    $$('[data-calendar-date]',grid).forEach(button=>button.onclick=()=>{selectedCalendarDate=button.dataset.calendarDate;renderStaffCalendar();renderCalendarSlots(selectedCalendarDate);});
    if(!selectedCalendarDate||!selectedCalendarDate.startsWith(`${year}-${String(month+1).padStart(2,'0')}`)||selectedCalendarDate<today){const candidate=new Date(Math.max(Date.now(),first.getTime()));while(candidate.getMonth()===month&&(candidate.getDay()===0||candidate.getDay()===6))candidate.setDate(candidate.getDate()+1);selectedCalendarDate=candidate.getMonth()===month?calendarDateKey(candidate):'';}
    if(selectedCalendarDate){$$('[data-calendar-date="'+selectedCalendarDate+'"]',grid).forEach(b=>b.classList.add('selected'));renderCalendarSlots(selectedCalendarDate);}else $('#calendarSelectedSlots').innerHTML='<p class="empty-state">No remaining weekdays in this month.</p>';
  }
  async function loadStaffCalendar(){try{const r=await api('staff_calendar',{query:{month:`${calendarMonth.getFullYear()}-${String(calendarMonth.getMonth()+1).padStart(2,'0')}`}});staffCalendarAppointments=r.data.appointments||[];renderStaffCalendar();}catch(e){const el=$('#staffCalendarGrid');if(el)el.innerHTML=`<div class="empty-state">${esc(e.message)}</div>`;}}
  $('#calendarPrev')?.addEventListener('click',()=>{calendarMonth=new Date(calendarMonth.getFullYear(),calendarMonth.getMonth()-1,1);selectedCalendarDate='';loadStaffCalendar();});
  $('#calendarNext')?.addEventListener('click',()=>{calendarMonth=new Date(calendarMonth.getFullYear(),calendarMonth.getMonth()+1,1);selectedCalendarDate='';loadStaffCalendar();});

  function renderAppointments(items, target, patient=false){if(!items.length){target.innerHTML='<div class="empty-state">No appointments found.</div>';return;} const staffCanApprove=!patient&&currentUser?.role==='Staff'; target.innerHTML=items.map(a=>`<div class="data-row"><div class="date-box"><strong>${esc((a.appointment_date||'').slice(8,10))}</strong><span>${esc((a.appointment_date||'').slice(5,7))}</span></div><div><strong>${esc(a.reason)}</strong><p>${patient?'Assigned clinician: '+esc(a.clinician||'Clinic staff'):esc(a.patient||'Patient')} · ${esc(a.appointment_time)}</p></div>${status(a.status)}${patient&&a.status==='Pending'?`<button class="mini-btn" data-edit-appointment="${Number(a.appointment_id)}">Edit</button>`:''}${staffCanApprove&&a.status==='Pending'?`<button class="mini-btn" data-approve-appointment="${Number(a.appointment_id)}">Approve</button>`:''}</div>`).join(''); if(patient) $$('[data-edit-appointment]',target).forEach(b=>b.onclick=()=>editAppointment(items.find(a=>Number(a.appointment_id)===Number(b.dataset.editAppointment)))); if(staffCanApprove) $$('[data-approve-appointment]',target).forEach(b=>b.onclick=async()=>{b.disabled=true;try{await api('appointment_approve',{method:'POST',body:JSON.stringify({appointment_id:Number(b.dataset.approveAppointment)})});await loadStaff();}catch(e){b.disabled=false;alert(e.message);}});}
  function editAppointment(a){if(!a)return;openModal('Review or edit appointment',`<p>Your assigned clinician is <strong>${esc(a.clinician||'Clinic staff')}</strong>. Update the date, time or reason while the request is pending.</p><label class="mv-modal-label" for="appointmentDate">Date</label><input id="appointmentDate" class="mv-modal-input" type="date" min="${new Date().toISOString().slice(0,10)}" value="${esc(a.appointment_date)}" required><label class="mv-modal-label" for="appointmentTime">Time</label><input id="appointmentTime" class="mv-modal-input" type="time" min="09:00" max="16:30" step="1800" value="${esc((a.appointment_time||"").slice(0,5))}" required><label class="mv-modal-label" for="appointmentReason">Reason for visit</label><input id="appointmentReason" class="mv-modal-input" maxlength="255" value="${esc(a.reason)}" required>`,async()=>{const appointment_date=$('#appointmentDate').value,appointment_time=$('#appointmentTime').value,reason=$('#appointmentReason').value.trim();if(!appointment_date||!appointment_time||!reason)throw new Error('Please complete the date, time and reason.');await api('appointment_update',{method:'POST',body:JSON.stringify({appointment_id:Number(a.appointment_id),appointment_date,appointment_time,reason})});await loadPatient();});}
  function renderRecords(items,target){if(!items.length){target.innerHTML='<div class="empty-state">No medical records found.</div>';return;} target.innerHTML=items.map(r=>`<div class="record-row"><div><strong>${esc(r.record_type)}</strong><p>${date(r.record_date)} · ${esc(r.diagnosis)}</p><small>${esc(r.notes)}</small></div>${status('Reviewed')}</div>`).join('');}
  function renderMeds(items,target){if(!items.length){target.innerHTML='<div class="empty-state">No medications found.</div>';return;} target.innerHTML=items.map(m=>`<div class="record-row"><div><strong>${esc(m.name)}</strong><p>${esc(m.dosage)} · ${esc(m.frequency)}</p><small>${date(m.start_date)}${m.end_date?' – '+date(m.end_date):''}</small></div>${status(m.status)}</div>`).join('');}

  async function loadPatient(){try{const [a,r,m]=await Promise.all([api('appointments'),api('records'),api('medications')]); const ap=a.data.appointments, rec=r.data.records, meds=m.data.medications; $('#patientApptCount').textContent=ap.length;$('#patientRecordCount').textContent=rec.length;$('#patientMedCount').textContent=meds.filter(x=>x.status==='Active').length; renderAppointments(ap.slice(0,5),$('#patientAppointmentsList'),true);renderAppointments(ap,$('#patientAppointmentsFull'),true);renderRecords(rec.slice(0,5),$('#patientRecordsList'));renderRecords(rec,$('#patientRecordsFull'));renderMeds(meds,$('#patientMedicationFull'));}catch(e){alert(e.message);}}

  async function loadStaff(){try{const [p,a,r]=await Promise.all([api('patients'),api('appointments'),api('records')]); const patients=p.data.patients, apps=a.data.appointments, records=r.data.records; $('#totalPatients').textContent=patients.length;$('#totalAppointments').textContent=apps.length;$('#totalRecords').textContent=records.length; renderAppointments(apps.slice(0,5),$('#staffAppointmentsList')); renderAppointments(apps,$('#staffAppointmentsFull')); renderStaffRecords(records,$('#staffRecordsFull')); renderPatientTable(patients,$('#patientTable')); renderPatientResults(patients,$('#patientResults')); await loadStaffCalendar();}catch(e){alert(e.message);}}
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
  function staffModal(s={}){const editing=!!s.staff_id;openModal(editing?'Update Staff Member':'Add Staff Member',`${editing?'':`<div class="two-col"><div><label>First name</label><input id="asFirst"></div><div><label>Last name</label><input id="asLast"></div></div><div class="two-col"><div><label>Email</label><input id="asEmail" type="email"></div><div><label>Phone</label><input id="asPhone"></div></div><label>Temporary password (minimum 8 characters)</label><input id="asPassword" type="password">`}<label>Job title</label><input id="asTitle" value="${esc(s.job_title||'Clinical Staff')}"><label>Department</label><input id="asDepartment" value="${esc(s.department||'General Practice')}">${editing?`<label>Account status</label><select id="asStatus"><option ${s.status==='Active'?'selected':''}>Active</option><option ${s.status==='Inactive'?'selected':''}>Inactive</option></select>`:''}`,async()=>{const payload={job_title:$('#asTitle').value.trim(),department:$('#asDepartment').value.trim()};if(editing){payload.staff_id=Number(s.staff_id);payload.status=$('#asStatus').value;}else{payload.first_name=$('#asFirst').value.trim();payload.last_name=$('#asLast').value.trim();payload.email=$('#asEmail').value.trim();payload.phone=$('#asPhone').value.trim();payload.password=$('#asPassword').value;}await api('admin_staff_save',{method:'POST',body:JSON.stringify(payload)});await loadAdmin();});}
  function renderAdminData(data){const staff=data.staff||[],apps=data.appointments||[],audit=data.audit||[],logins=data.login_audit||[];$('#adminStaffTable').innerHTML=staff.length?'<table><thead><tr><th>Staff member</th><th>Employee ID</th><th>Title / department</th><th>Status</th><th>Actions</th></tr></thead><tbody>'+staff.map(s=>`<tr><td>${esc(s.first_name+' '+s.last_name)}<br><small>${esc(s.email)}</small></td><td>${esc(s.employee_number)}</td><td>${esc(s.job_title)} · ${esc(s.department)}</td><td>${status(s.status)}</td><td><button class="mini-btn" data-edit-staff="${Number(s.staff_id)}">Edit</button></td></tr>`).join('')+'</tbody></table>':'<div class="empty-state">No staff accounts are registered.</div>';$$('[data-edit-staff]').forEach(b=>b.onclick=()=>staffModal(staff.find(s=>Number(s.staff_id)===Number(b.dataset.editStaff))));
    $('#adminAppointmentsTable').innerHTML=apps.length?'<table><thead><tr><th>Date &amp; time</th><th>Patient</th><th>Clinician</th><th>Reason</th><th>Status</th><th>Actions</th></tr></thead><tbody>'+apps.map(a=>`<tr><td>${date(a.appointment_date)}<br>${esc((a.appointment_time||'').slice(0,5))}</td><td>${esc(a.patient)}<br><small>${esc(a.patient_number)}</small></td><td>${esc(a.clinician)}</td><td>${esc(a.reason)}</td><td>${status(a.status)}</td><td><select data-admin-status="${Number(a.appointment_id)}"><option ${a.status==='Pending'?'selected':''}>Pending</option><option ${a.status==='Confirmed'?'selected':''}>Confirmed</option><option ${a.status==='Completed'?'selected':''}>Completed</option><option ${a.status==='Cancelled'?'selected':''}>Cancelled</option></select><select data-admin-clinician="${Number(a.appointment_id)}"><option value="0">Keep clinician</option>${staff.filter(s=>s.status==='Active').map(s=>`<option value="${Number(s.staff_id)}" ${Number(s.staff_id)===Number(a.staff_id)?'selected':''}>Assign: ${esc(s.first_name+' '+s.last_name)}</option>`).join('')}</select></td></tr>`).join('')+'</tbody></table>':'<div class="empty-state">No appointments found.</div>';$$('[data-admin-status]').forEach(sel=>sel.onchange=async()=>{try{const clinician=$(`[data-admin-clinician="${sel.dataset.adminStatus}"]`);await api('admin_appointment_update',{method:'POST',body:JSON.stringify({appointment_id:Number(sel.dataset.adminStatus),status:sel.value,staff_id:Number(clinician.value)})});await loadAdmin();}catch(e){alert(e.message);await loadAdmin();}});$$('[data-admin-clinician]').forEach(sel=>sel.onchange=async()=>{if(!Number(sel.value))return;try{const statusSelect=$(`[data-admin-status="${sel.dataset.adminClinician}"]`);await api('admin_appointment_update',{method:'POST',body:JSON.stringify({appointment_id:Number(sel.dataset.adminClinician),status:statusSelect.value,staff_id:Number(sel.value)})});await loadAdmin();}catch(e){alert(e.message);await loadAdmin();}});
    $('#adminAuditTable').innerHTML=audit.length?'<table><thead><tr><th>When</th><th>Administrator</th><th>Action</th><th>Details</th></tr></thead><tbody>'+audit.map(a=>`<tr><td>${esc(a.created_at)}</td><td>${esc(a.actor||'Unknown')}</td><td>${esc(a.action)}</td><td>${esc(a.details)}</td></tr>`).join('')+'</tbody></table>':'<div class="empty-state">No administrative changes recorded yet.</div>';$('#adminLoginAuditTable').innerHTML=logins.length?'<table><thead><tr><th>When</th><th>Identifier</th><th>Result</th><th>IP</th></tr></thead><tbody>'+logins.map(a=>`<tr><td>${esc(a.created_at)}</td><td>${esc(a.identifier_used)}</td><td>${esc(a.login_status)}</td><td>${esc(a.ip_address)}</td></tr>`).join('')+'</tbody></table>':'<div class="empty-state">No login history available.</div>';}
  async function loadAdmin(){try{const [r,p]=await Promise.all([api('admin_data'),api('patients')]);renderAdminData(r.data);renderAdminPatients(p.data.patients);}catch(e){alert(e.message);}}
  $('#adminSearchPatients').onclick=async()=>{try{const res=await fetch('api.php?action=patients&search='+encodeURIComponent($('#adminPatientSearch').value));const d=await res.json();if(!d.success)throw new Error(d.message);renderAdminPatients(d.data.patients);}catch(e){alert(e.message);}};
  $('#adminPatientSearch').onkeydown=e=>{if(e.key==='Enter')$('#adminSearchPatients').click();};
  $('#adminNewPatient').onclick=()=>openPatientModal();
  $('#adminNewStaff')?.addEventListener('click',()=>staffModal());$('#adminRefresh')?.addEventListener('click',loadAdmin);

  // For admin screens, refresh table and attach delete permissions are already role-protected server-side.
  window.addEventListener('click',e=>{const b=e.target.closest('[data-view]'); if(b && !b.dataset.bound){b.dataset.bound='1';}});

  // Attempt to restore an existing authenticated session on refresh.
  (async()=>{try{const r=await api('session'); if(r.data.user){currentUser=r.data.user; routeUser();}}catch{}})();
})();
