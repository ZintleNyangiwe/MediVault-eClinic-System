<?php

$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
ini_set('session.gc_maxlifetime', (string)(30 * 24 * 60 * 60));
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();

require_once __DIR__ . '/config.php';

$action = $_GET['action'] ?? '';

try {

    switch ($action) {

        /* =========================================================
           SESSION
           ========================================================= */

        case 'session':

            json_response(
                true,
                'Session retrieved.',
                ['user' => $_SESSION['user'] ?? null]
            );

            break;

        case 'admin_data':
            require_role(['Administrator']);
            $db = db();
            $db->query("CREATE TABLE IF NOT EXISTS admin_audit (audit_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, actor_user_id INT UNSIGNED NULL, action VARCHAR(100) NOT NULL, details VARCHAR(500) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_admin_audit_created (created_at), CONSTRAINT fk_admin_audit_actor_user FOREIGN KEY (actor_user_id) REFERENCES users(user_id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $staff = $db->query("SELECT s.staff_id,s.employee_number,s.job_title,s.department,u.user_id,u.first_name,u.last_name,u.email,u.phone,u.status FROM staff s JOIN users u ON u.user_id=s.user_id ORDER BY u.last_name,u.first_name")->fetch_all(MYSQLI_ASSOC);
            $appointments = $db->query("SELECT a.appointment_id,a.appointment_date,a.appointment_time,a.reason,a.status,p.patient_number,CONCAT(pu.first_name,' ',pu.last_name) patient,s.staff_id,CONCAT(su.first_name,' ',su.last_name) clinician FROM appointments a JOIN patients p ON p.patient_id=a.patient_id JOIN users pu ON pu.user_id=p.user_id JOIN staff s ON s.staff_id=a.staff_id JOIN users su ON su.user_id=s.user_id ORDER BY a.appointment_date DESC,a.appointment_time DESC LIMIT 300")->fetch_all(MYSQLI_ASSOC);
            $audit = $db->query("SELECT aa.action,aa.details,aa.created_at,CONCAT(u.first_name,' ',u.last_name) actor FROM admin_audit aa LEFT JOIN users u ON u.user_id=aa.actor_user_id ORDER BY aa.created_at DESC LIMIT 100")->fetch_all(MYSQLI_ASSOC);
            $loginAudit = $db->query("SELECT identifier_used,login_status,ip_address,created_at FROM login_audit ORDER BY created_at DESC LIMIT 50")->fetch_all(MYSQLI_ASSOC);
            json_response(true, 'Administration data loaded.', ['staff'=>$staff,'appointments'=>$appointments,'audit'=>$audit,'login_audit'=>$loginAudit]);
            break;

        case 'admin_staff_save':
            $actor = require_role(['Administrator']);
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(false,'Invalid request.',[],405);
            $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $db = db();
            $staffId = (int)($input['staff_id'] ?? 0);
            $title = clean((string)($input['job_title'] ?? 'Clinical Staff'));
            $department = clean((string)($input['department'] ?? 'General Practice'));
            if ($title === '' || $department === '') json_response(false,'Job title and department are required.',[],422);
            if ($staffId > 0) {
                $statusValue = ($input['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';
                $exists = $db->prepare('SELECT staff_id FROM staff WHERE staff_id=?'); $exists->bind_param('i',$staffId); $exists->execute();
                if (!$exists->get_result()->fetch_assoc()) json_response(false,'Staff member not found.',[],404);
                $stmt = $db->prepare("UPDATE staff s JOIN users u ON u.user_id=s.user_id SET s.job_title=?,s.department=?,u.status=? WHERE s.staff_id=?");
                $stmt->bind_param('sssi',$title,$department,$statusValue,$staffId); $stmt->execute();
                $detail = "Updated staff #$staffId ($statusValue)";
            } else {
                $first=clean((string)($input['first_name']??'')); $last=clean((string)($input['last_name']??'')); $email=strtolower(clean((string)($input['email']??''))); $phone=clean((string)($input['phone']??'')); $password=(string)($input['password']??'');
                if (!$first || !$last || !valid_email($email) || !valid_phone($phone) || strlen($password)<8) json_response(false,'Enter a name, valid email and phone number, and a password of at least 8 characters.',[],422);
                $db->begin_transaction();
                try {
                    $stmt=$db->prepare("INSERT INTO users(first_name,last_name,email,phone,password_hash,role,status) VALUES(?,?,?,?,?,'Staff','Active')"); $hash=password_hash($password,PASSWORD_DEFAULT); $stmt->bind_param('sssss',$first,$last,$email,$phone,$hash); $stmt->execute(); $uid=$db->insert_id;
                    $employee='ST-'.str_pad((string)$uid,5,'0',STR_PAD_LEFT); $permissions='PATIENT_READ,APPOINTMENT_MANAGE,RECORD_MANAGE';
                    $stmt=$db->prepare('INSERT INTO staff(user_id,employee_number,job_title,department,permissions) VALUES(?,?,?,?,?)'); $stmt->bind_param('issss',$uid,$employee,$title,$department,$permissions); $stmt->execute(); $db->commit();
                } catch (Throwable $e) { $db->rollback(); throw $e; }
                $detail="Created staff account $email";
            }
            $db->query("CREATE TABLE IF NOT EXISTS admin_audit (audit_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, actor_user_id INT UNSIGNED NULL, action VARCHAR(100) NOT NULL, details VARCHAR(500) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_admin_audit_created (created_at), CONSTRAINT fk_admin_audit_actor_user FOREIGN KEY (actor_user_id) REFERENCES users(user_id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $stmt=$db->prepare('INSERT INTO admin_audit(actor_user_id,action,details) VALUES(?,?,?)'); $action='staff_manage'; $stmt->bind_param('iss',$actor['user_id'],$action,$detail); $stmt->execute();
            json_response(true,'Staff account saved.');
            break;

        case 'admin_appointment_update':
            $actor = require_role(['Administrator']);
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(false,'Invalid request.',[],405);
            $input=json_decode(file_get_contents('php://input'),true) ?: $_POST; $id=(int)($input['appointment_id']??0); $newStatus=(string)($input['status']??''); $newStaffId=(int)($input['staff_id']??0);
            if ($id<1 || !in_array($newStatus,['Pending','Confirmed','Completed','Cancelled'],true)) json_response(false,'Choose a valid appointment and status.',[],422);
            $db=db(); $check=$db->prepare('SELECT staff_id,appointment_date,appointment_time FROM appointments WHERE appointment_id=?');$check->bind_param('i',$id);$check->execute();$old=$check->get_result()->fetch_assoc();if(!$old)json_response(false,'Appointment not found.',[],404);
            if($newStaffId>0 && $newStaffId!==(int)$old['staff_id'] && in_array($newStatus,['Pending','Confirmed'],true)){$check=$db->prepare("SELECT appointment_id FROM appointments WHERE staff_id=? AND appointment_date=? AND appointment_time=? AND appointment_id<>? AND status IN ('Pending','Confirmed') LIMIT 1");$check->bind_param('issi',$newStaffId,$old['appointment_date'],$old['appointment_time'],$id);$check->execute();if($check->get_result()->fetch_assoc())json_response(false,'That clinician already has an appointment at this time.',[],409);$check=$db->prepare("SELECT staff_id FROM staff s JOIN users u ON u.user_id=s.user_id WHERE s.staff_id=? AND u.status='Active'");$check->bind_param('i',$newStaffId);$check->execute();if(!$check->get_result()->fetch_assoc())json_response(false,'Select an active clinician.',[],422);}
            if($newStaffId>0){$stmt=$db->prepare('UPDATE appointments SET status=?,staff_id=? WHERE appointment_id=?');$stmt->bind_param('sii',$newStatus,$newStaffId,$id);}else{$stmt=$db->prepare('UPDATE appointments SET status=? WHERE appointment_id=?');$stmt->bind_param('si',$newStatus,$id);} $stmt->execute();
            $db->query("CREATE TABLE IF NOT EXISTS admin_audit (audit_id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, actor_user_id INT UNSIGNED NULL, action VARCHAR(100) NOT NULL, details VARCHAR(500) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_admin_audit_created (created_at), CONSTRAINT fk_admin_audit_actor_user FOREIGN KEY (actor_user_id) REFERENCES users(user_id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $detail="Changed appointment #$id to $newStatus"; $action='appointment_manage'; $stmt=$db->prepare('INSERT INTO admin_audit(actor_user_id,action,details) VALUES(?,?,?)');$stmt->bind_param('iss',$actor['user_id'],$action,$detail);$stmt->execute();
            json_response(true,'Appointment status updated.');
            break;


        /* =========================================================
           REGISTER
           ========================================================= */

        case 'register':

            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                json_response(false, 'Invalid request.', [], 405);
            }

            $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            $first = clean((string)($input['first_name'] ?? ''));
            $last = clean((string)($input['last_name'] ?? ''));
            $email = strtolower(clean((string)($input['email'] ?? '')));
            $phone = clean((string)($input['phone'] ?? ''));
            $password = (string)($input['password'] ?? '');
            $confirm = (string)($input['confirm_password'] ?? '');
            $role = ucfirst(strtolower(clean((string)($input['role'] ?? 'Patient'))));
            $dob = trim((string)($input['date_of_birth'] ?? ''));
            $gender = clean((string)($input['gender'] ?? ''));
            $address = clean((string)($input['address'] ?? ''));
            $emergency = clean((string)($input['emergency_contact'] ?? ''));

            if ($role !== 'Patient') {
                json_response(false, 'Staff and administrator accounts must be created by an administrator.', [], 403);
            }

            if ($first === '' || $last === '' || $email === '' || $phone === '' ||
                $password === '' || $confirm === '' || $dob === '' || $gender === '') {
                json_response(false, 'Complete all required account and patient fields.', [], 422);
            }

            if (!valid_email($email)) {
                json_response(false, 'Please enter a valid email address.', [], 422);
            }
            if (!valid_phone($phone)) {
                json_response(false, 'Please enter a valid phone number.', [], 422);
            }
            if (strlen($password) < 8) {
                json_response(false, 'Password must contain at least 8 characters.', [], 422);
            }
            if ($password !== $confirm) {
                json_response(false, 'Passwords do not match.', [], 422);
            }

            $validGenders = ['Female', 'Male', 'Other', 'Prefer not to say'];
            if (!in_array($gender, $validGenders, true)) {
                json_response(false, 'Choose a valid gender option.', [], 422);
            }

            $dobDate = DateTime::createFromFormat('!Y-m-d', $dob);
            $dateErrors = DateTime::getLastErrors();
            if (!$dobDate || $dobDate->format('Y-m-d') !== $dob ||
                ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) ||
                $dobDate > new DateTime('today')) {
                json_response(false, 'Enter a valid date of birth that is not in the future.', [], 422);
            }

            $db = db();
            $stmt = $db->prepare('SELECT user_id FROM users WHERE email = ? OR phone = ? LIMIT 1');
            $stmt->bind_param('ss', $email, $phone);
            $stmt->execute();
            if ($stmt->get_result()->fetch_assoc()) {
                json_response(false, 'An account with that email or phone already exists.', [], 409);
            }

            $db->begin_transaction();
            try {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $role = 'Patient';
                $stmt = $db->prepare(
                    'INSERT INTO users
                     (first_name, last_name, email, phone, password_hash, role)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $stmt->bind_param('ssssss', $first, $last, $email, $phone, $hash, $role);
                $stmt->execute();

                $uid = (int)$db->insert_id;
                if ($uid < 1) {
                    throw new RuntimeException('The account record was not created.');
                }

                $patientNo = 'MV-' . str_pad((string)$uid, 5, '0', STR_PAD_LEFT);
                $stmt = $db->prepare(
                    'INSERT INTO patients
                     (user_id, patient_number, date_of_birth, gender, address, emergency_contact)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $stmt->bind_param('isssss', $uid, $patientNo, $dob, $gender, $address, $emergency);
                $stmt->execute();

                $db->commit();
            } catch (Throwable $e) {
                $db->rollback();
                throw $e;
            }

            json_response(true, 'Patient account and profile created successfully. You can now log in.');
            break;

        case 'login':

            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                json_response(false, 'Invalid request.', [], 405);
            }

            $input = json_decode(
                file_get_contents('php://input'),
                true
            ) ?: $_POST;

            $method =
                ($input['method'] ?? 'email') === 'phone'
                ? 'phone'
                : 'email';

            $identifier =
                clean((string)($input['identifier'] ?? ''));

            $password =
                (string)($input['password'] ?? '');

            if (
                $identifier === '' ||
                $password === ''
            ) {
                json_response(
                    false,
                    'Email/phone and password are required.',
                    [],
                    422
                );
            }

            $db = db();

            $field =
                $method === 'phone'
                ? 'phone'
                : 'email';

            $stmt = $db->prepare(
                "SELECT
                    user_id,
                    first_name,
                    last_name,
                    email,
                    phone,
                    password_hash,
                    role,
                    status
                 FROM users
                 WHERE $field = ?
                 LIMIT 1"
            );

            $stmt->bind_param(
                's',
                $identifier
            );

            $stmt->execute();

            $user =
                $stmt->get_result()->fetch_assoc();

            $success =
                $user &&
                $user['status'] === 'Active' &&
                password_verify(
                    $password,
                    $user['password_hash']
                );

            $ip =
                $_SERVER['REMOTE_ADDR'] ??
                'unknown';

            $audit = $db->prepare(
                'INSERT INTO login_audit
                (
                    user_id,
                    identifier_used,
                    login_status,
                    ip_address
                )
                VALUES (?, ?, ?, ?)'
            );

            $uid =
                $user['user_id'] ?? null;

            $status =
                $success
                ? 'SUCCESS'
                : 'FAILED';

            $audit->bind_param(
                'isss',
                $uid,
                $identifier,
                $status,
                $ip
            );

            $audit->execute();

            if (!$success) {
                json_response(
                    false,
                    'Invalid credentials or inactive account.',
                    [],
                    401
                );
            }

            session_regenerate_id(true);
            if (!empty($input['remember'])) {
                setcookie(session_name(), session_id(), [
                    'expires' => time() + (30 * 24 * 60 * 60),
                    'path' => '/',
                    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]);
            }

            $_SESSION['user'] = [
                'user_id' =>
                    (int)$user['user_id'],

                'first_name' =>
                    $user['first_name'],

                'last_name' =>
                    $user['last_name'],

                'email' =>
                    $user['email'],

                'phone' =>
                    $user['phone'],

                'role' =>
                    $user['role']
            ];

            $db->query(
                'UPDATE users
                 SET last_login_at = NOW()
                 WHERE user_id = ' .
                (int)$user['user_id']
            );

            json_response(
                true,
                'Login successful.',
                [
                    'user' =>
                        $_SESSION['user']
                ]
            );

            break;


        /* =========================================================
           LOGOUT
           ========================================================= */

        case 'logout':

            setcookie(session_name(), '', [
                'expires' => time() - 3600,
                'path' => '/',
                'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
            $_SESSION = [];

            session_destroy();

            json_response(
                true,
                'Logged out successfully.'
            );

            break;


        /* =========================================================
           PATIENT LIST / SEARCH
           ========================================================= */

        case 'patients':

            $user =
                require_role(
                    ['Staff', 'Administrator']
                );

            $db = db();

            $search =
                clean(
                    (string)(
                        $_GET['search'] ?? ''
                    )
                );

            if ($search !== '') {

                $like =
                    '%' . $search . '%';

                $stmt = $db->prepare(
                    "SELECT
                        p.patient_id,
                        p.patient_number,
                        p.date_of_birth,
                        p.gender,
                        p.address,
                        p.emergency_contact,
                        u.first_name,
                        u.last_name,
                        u.email,
                        u.phone
                     FROM patients p
                     JOIN users u
                       ON u.user_id = p.user_id
                     WHERE
                        p.patient_number LIKE ?
                        OR u.first_name LIKE ?
                        OR u.last_name LIKE ?
                        OR u.email LIKE ?
                        OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?
                     ORDER BY p.patient_id DESC"
                );

                $stmt->bind_param(
                    'sssss',
                    $like,
                    $like,
                    $like,
                    $like,
                    $like
                );

            } else {

                $stmt = $db->prepare(
                    "SELECT
                        p.patient_id,
                        p.patient_number,
                        p.date_of_birth,
                        p.gender,
                        p.address,
                        p.emergency_contact,
                        u.first_name,
                        u.last_name,
                        u.email,
                        u.phone
                     FROM patients p
                     JOIN users u
                       ON u.user_id = p.user_id
                     ORDER BY p.patient_id DESC"
                );
            }

            $stmt->execute();

            json_response(
                true,
                'Patients loaded.',
                [
                    'patients' =>
                        $stmt
                        ->get_result()
                        ->fetch_all(MYSQLI_ASSOC)
                ]
            );

            break;


        /* =========================================================
           CREATE PATIENT
           ========================================================= */

        case 'patient_create':

            require_role(
                ['Staff', 'Administrator']
            );

            $input =
                json_decode(
                    file_get_contents('php://input'),
                    true
                ) ?: $_POST;

            $required = [
                'first_name',
                'last_name',
                'email',
                'phone',
                'date_of_birth',
                'gender'
            ];

            foreach ($required as $field) {

                if (
                    clean(
                        (string)(
                            $input[$field] ?? ''
                        )
                    ) === ''
                ) {
                    json_response(
                        false,
                        'All required patient fields must be completed.',
                        [],
                        422
                    );
                }
            }

            if (!valid_email($input['email'])) {
                json_response(
                    false,
                    'Invalid email address.',
                    [],
                    422
                );
            }

            if (!valid_phone($input['phone'])) {
                json_response(
                    false,
                    'Invalid phone number.',
                    [],
                    422
                );
            }

            $db = db();

            $db->begin_transaction();

            try {

                $hash =
                    password_hash(
                        'TempPass123!',
                        PASSWORD_DEFAULT
                    );

                $role = 'Patient';

                $stmt = $db->prepare(
                    'INSERT INTO users
                    (
                        first_name,
                        last_name,
                        email,
                        phone,
                        password_hash,
                        role
                    )
                    VALUES (?, ?, ?, ?, ?, ?)'
                );

                $stmt->bind_param(
                    'ssssss',
                    $input['first_name'],
                    $input['last_name'],
                    $input['email'],
                    $input['phone'],
                    $hash,
                    $role
                );

                $stmt->execute();

                $uid =
                    $stmt->insert_id;

                $num =
                    'MV-' .
                    str_pad(
                        (string)$uid,
                        5,
                        '0',
                        STR_PAD_LEFT
                    );

                $stmt = $db->prepare(
                    'INSERT INTO patients
                    (
                        user_id,
                        patient_number,
                        date_of_birth,
                        gender,
                        address,
                        emergency_contact
                    )
                    VALUES (?, ?, ?, ?, ?, ?)'
                );

                $address =
                    $input['address'] ?? '';

                $emergency =
                    $input['emergency_contact'] ?? '';

                $stmt->bind_param(
                    'isssss',
                    $uid,
                    $num,
                    $input['date_of_birth'],
                    $input['gender'],
                    $address,
                    $emergency
                );

                $stmt->execute();

                $newPatientId =
                    $stmt->insert_id;

                $db->commit();

                json_response(
                    true,
                    'Patient added successfully.',
                    [
                        'patient_id' =>
                            $newPatientId
                    ]
                );

            } catch (Throwable $e) {

                $db->rollback();

                throw $e;
            }

            break;


        /* =========================================================
           UPDATE PATIENT
           ========================================================= */

        case 'patient_update':

            require_role(
                ['Staff', 'Administrator']
            );

            $input =
                json_decode(
                    file_get_contents('php://input'),
                    true
                ) ?: $_POST;

            $id =
                (int)(
                    $input['patient_id'] ?? 0
                );

            if ($id < 1) {
                json_response(
                    false,
                    'Invalid patient ID.',
                    [],
                    422
                );
            }

            $db = db();

            $stmt = $db->prepare(
                'SELECT user_id
                 FROM patients
                 WHERE patient_id = ?'
            );

            $stmt->bind_param(
                'i',
                $id
            );

            $stmt->execute();

            $row =
                $stmt
                ->get_result()
                ->fetch_assoc();

            if (!$row) {
                json_response(
                    false,
                    'Patient not found.',
                    [],
                    404
                );
            }

            $uid =
                (int)$row['user_id'];

            $stmt = $db->prepare(
                'UPDATE users
                 SET
                    first_name = ?,
                    last_name = ?,
                    email = ?,
                    phone = ?
                 WHERE user_id = ?'
            );

            $stmt->bind_param(
                'ssssi',
                $input['first_name'],
                $input['last_name'],
                $input['email'],
                $input['phone'],
                $uid
            );

            $stmt->execute();

            $stmt = $db->prepare(
                'UPDATE patients
                 SET
                    date_of_birth = ?,
                    gender = ?,
                    address = ?,
                    emergency_contact = ?
                 WHERE patient_id = ?'
            );

            $stmt->bind_param(
                'ssssi',
                $input['date_of_birth'],
                $input['gender'],
                $input['address'],
                $input['emergency_contact'],
                $id
            );

            $stmt->execute();

            json_response(
                true,
                'Patient updated successfully.'
            );

            break;


        /* =========================================================
           DELETE PATIENT
           ========================================================= */

       case 'patient_delete':

    require_role(
        ['Staff', 'Administrator']
    );

    $input =
        json_decode(
            file_get_contents('php://input'),
            true
        ) ?: $_POST;

    $id =
        (int)(
            $input['patient_id'] ?? 0
        );

    $db = db();

    $stmt = $db->prepare(
        'SELECT user_id
         FROM patients
         WHERE patient_id = ?'
    );

    $stmt->bind_param(
        'i',
        $id
    );

    $stmt->execute();

    $row =
        $stmt
        ->get_result()
        ->fetch_assoc();

    if (!$row) {
        json_response(
            false,
            'Patient not found.',
            [],
            404
        );
    }

    $uid =
        (int)$row['user_id'];

    $stmt = $db->prepare(
        'DELETE FROM users
         WHERE user_id = ?'
    );

    $stmt->bind_param(
        'i',
        $uid
    );

    $stmt->execute();

    json_response(
        true,
        'Patient deleted successfully.'
    );

    break;


        /* =========================================================
           APPOINTMENTS
           GET  = LOAD APPOINTMENTS
           POST = BOOK APPOINTMENT
           ========================================================= */

        case 'clinicians':

            require_role(['Patient']);
            if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
                json_response(false, 'Invalid request.', [], 405);
            }
            $appointmentDate = trim((string)($_GET['date'] ?? ''));
            $appointmentTime = trim((string)($_GET['time'] ?? ''));
            if (($appointmentDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $appointmentDate)) || ($appointmentTime !== '' && !preg_match('/^\d{2}:\d{2}$/', $appointmentTime))) {
                json_response(false, 'Choose a valid appointment date and time.', [], 422);
            }
            if ($appointmentDate !== '' && $appointmentTime !== '' && !is_clinic_slot($appointmentDate, $appointmentTime)) {
                json_response(false, 'Clinic slots are weekdays from 09:00 to 16:30 in 30-minute intervals.', [], 422);
            }
            if ($appointmentDate !== '' && $appointmentDate < date('Y-m-d')) {
                json_response(false, 'Choose a future appointment date.', [], 422);
            }
            $db = db();
            $stmt = $db->prepare(
                "SELECT s.staff_id, CONCAT(u.first_name, ' ', u.last_name) AS clinician,
                        s.job_title, s.department,
                        CASE
                            WHEN ? = '' OR ? = '' THEN NULL
                            WHEN EXISTS (
                                SELECT 1 FROM appointments a
                                WHERE a.staff_id = s.staff_id
                                  AND a.appointment_date = ?
                                  AND a.appointment_time = ?
                                  AND a.status IN ('Pending', 'Confirmed')
                            ) THEN 0
                            ELSE 1
                        END AS available
                 FROM staff s
                 JOIN users u ON u.user_id = s.user_id
                 WHERE u.status = 'Active'
                 ORDER BY u.last_name, u.first_name"
            );
            $stmt->bind_param('ssss', $appointmentDate, $appointmentTime, $appointmentDate, $appointmentTime);
            $stmt->execute();
            json_response(true, 'Clinicians and appointment availability loaded.', [
                'clinicians' => $stmt->get_result()->fetch_all(MYSQLI_ASSOC)
            ]);
            break;

        case 'staff_calendar':

            $user = require_role(['Staff']);
            if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
                json_response(false, 'Invalid request.', [], 405);
            }
            $month = trim((string)($_GET['month'] ?? date('Y-m')));
            $monthDate = DateTime::createFromFormat('!Y-m', $month);
            if (!$monthDate || $monthDate->format('Y-m') !== $month) {
                json_response(false, 'Choose a valid calendar month.', [], 422);
            }
            $monthStart = $monthDate->format('Y-m-01');
            $nextMonth = (clone $monthDate)->modify('first day of next month')->format('Y-m-d');
            $db = db();
            $staffQuery = $db->prepare('SELECT staff_id FROM staff WHERE user_id = ? LIMIT 1');
            $staffQuery->bind_param('i', $user['user_id']);
            $staffQuery->execute();
            $staff = $staffQuery->get_result()->fetch_assoc();
            if (!$staff) {
                json_response(false, 'This staff account has no clinician profile.', [], 404);
            }
            $staffId = (int)$staff['staff_id'];
            $stmt = $db->prepare(
                "SELECT a.appointment_id, a.appointment_date, a.appointment_time,
                        a.reason, a.status,
                        CONCAT(pu.first_name, ' ', pu.last_name) AS patient
                 FROM appointments a
                 JOIN patients p ON p.patient_id = a.patient_id
                 JOIN users pu ON pu.user_id = p.user_id
                 WHERE a.staff_id = ? AND a.appointment_date >= ? AND a.appointment_date < ?
                 ORDER BY a.appointment_date, a.appointment_time"
            );
            $stmt->bind_param('iss', $staffId, $monthStart, $nextMonth);
            $stmt->execute();
            json_response(true, 'Staff calendar loaded.', [
                'staff_id' => $staffId,
                'appointments' => $stmt->get_result()->fetch_all(MYSQLI_ASSOC)
            ]);
            break;

        case 'appointment_approve':

            $user = require_role(['Staff']);
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                json_response(false, 'Invalid request.', [], 405);
            }
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $appointmentId = (int)($input['appointment_id'] ?? 0);
            if ($appointmentId < 1) {
                json_response(false, 'Invalid appointment.', [], 422);
            }
            $db = db();
            $stmt = $db->prepare(
                "UPDATE appointments a
                 JOIN staff s ON s.staff_id = a.staff_id
                 SET a.status = 'Confirmed'
                 WHERE a.appointment_id = ? AND s.user_id = ? AND a.status = 'Pending'"
            );
            $stmt->bind_param('ii', $appointmentId, $user['user_id']);
            $stmt->execute();
            if ($stmt->affected_rows !== 1) {
                json_response(false, 'This pending appointment was not found in your schedule.', [], 404);
            }
            json_response(true, 'Appointment approved.');
            break;

        case 'appointment_update':

            $user = require_login();
            if ($user['role'] !== 'Patient' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
                json_response(false, 'Only patients can update their appointments.', [], 403);
            }
            $input = json_decode(file_get_contents('php://input'), true) ?: [];
            $id = (int)($input['appointment_id'] ?? 0);
            $appointmentDate = trim((string)($input['appointment_date'] ?? ''));
            $appointmentTime = trim((string)($input['appointment_time'] ?? ''));
            $reason = clean((string)($input['reason'] ?? ''));
            if ($id < 1 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $appointmentDate) || !preg_match('/^\d{2}:\d{2}$/', $appointmentTime) || $reason === '' || $appointmentDate < date('Y-m-d') || !is_clinic_slot($appointmentDate, $appointmentTime)) {
                json_response(false, 'Choose a future weekday slot between 09:00 and 16:30, and enter a reason.', [], 422);
            }
            $db = db();
            $check = $db->prepare("SELECT a.staff_id FROM appointments a JOIN patients p ON p.patient_id = a.patient_id WHERE a.appointment_id = ? AND p.user_id = ? AND a.status = 'Pending'");
            $check->bind_param('ii', $id, $user['user_id']);
            $check->execute();
            $booking = $check->get_result()->fetch_assoc();
            if (!$booking) json_response(false, 'This pending appointment could not be found.', [], 404);
            $conflict = $db->prepare("SELECT appointment_id FROM appointments WHERE staff_id = ? AND appointment_id <> ? AND appointment_date = ? AND appointment_time = ? AND status IN ('Pending', 'Confirmed') LIMIT 1");
            $conflict->bind_param('iiss', $booking['staff_id'], $id, $appointmentDate, $appointmentTime);
            $conflict->execute();
            if ($conflict->get_result()->fetch_assoc()) json_response(false, 'That clinician is already booked at this time.', [], 409);
            $stmt = $db->prepare("UPDATE appointments a JOIN patients p ON p.patient_id = a.patient_id SET a.appointment_date = ?, a.appointment_time = ?, a.reason = ? WHERE a.appointment_id = ? AND p.user_id = ? AND a.status = 'Pending'");
            $stmt->bind_param('sssii', $appointmentDate, $appointmentTime, $reason, $id, $user['user_id']);
            $stmt->execute();
            if ($stmt->affected_rows < 0) {
                json_response(false, 'Unable to update this appointment.', [], 409);
            }
            json_response(true, 'Appointment updated successfully.');
            break;
        case 'appointments':

            $user =
                require_login();

            $db = db();


            /* -----------------------------------------------------
               BOOK APPOINTMENT
               ----------------------------------------------------- */

            if ($_SERVER['REQUEST_METHOD'] === 'POST') {

                if ($user['role'] !== 'Patient') {
                    json_response(
                        false,
                        'Only patients can book appointments.',
                        [],
                        403
                    );
                }

                $input =
                    json_decode(
                        file_get_contents('php://input'),
                        true
                    ) ?: $_POST;

                $appointmentDate =
                    trim(
                        (string)(
                            $input['appointment_date'] ?? ''
                        )
                    );

                $appointmentTime =
                    trim(
                        (string)(
                            $input['appointment_time'] ?? ''
                        )
                    );

                $reason =
                    clean(
                        (string)(
                            $input['reason'] ?? ''
                        )
                    );

                $staffId =
                    (int)(
                        $input['staff_id'] ?? 0
                    );


                /* Required fields */

                if (
                    $appointmentDate === '' ||
                    $appointmentTime === '' ||
                    $reason === ''
                ) {
                    json_response(
                        false,
                        'Appointment date, time and reason are required.',
                        [],
                        422
                    );
                }


                /* Validate date */

                $dateObject =
                    DateTime::createFromFormat(
                        'Y-m-d',
                        $appointmentDate
                    );

                if (
                    !$dateObject ||
                    $dateObject->format('Y-m-d')
                    !== $appointmentDate
                ) {
                    json_response(
                        false,
                        'Please enter a valid appointment date.',
                        [],
                        422
                    );
                }


                /* Validate time */

                $timeObject =
                    DateTime::createFromFormat(
                        'H:i',
                        $appointmentTime
                    );

                if (
                    !$timeObject ||
                    $timeObject->format('H:i')
                    !== $appointmentTime
                ) {
                    json_response(
                        false,
                        'Please enter a valid appointment time.',
                        [],
                        422
                    );
                }


                /* Do not allow past dates */

                if (
                    $appointmentDate <
                    date('Y-m-d')
                ) {
                    json_response(
                        false,
                        'Appointment date cannot be in the past.',
                        [],
                        422
                    );
                }

                if (!is_clinic_slot($appointmentDate, $appointmentTime)) {
                    json_response(
                        false,
                        'Clinic slots are weekdays from 09:00 to 16:30 in 30-minute intervals.',
                        [],
                        422
                    );
                }


                /* Find logged-in patient's patient_id */

                $stmt = $db->prepare(
                    'SELECT patient_id
                     FROM patients
                     WHERE user_id = ?
                     LIMIT 1'
                );

                $stmt->bind_param(
                    'i',
                    $user['user_id']
                );

                $stmt->execute();

                $patient =
                    $stmt
                    ->get_result()
                    ->fetch_assoc();

                if (!$patient) {
                    json_response(
                        false,
                        'Patient record not found.',
                        [],
                        404
                    );
                }

                $patientId =
                    (int)$patient['patient_id'];


                if ($staffId < 1) {
                    json_response(false, 'Select an available clinician before booking.', [], 422);
                }


                /* Verify staff exists */

                $stmt = $db->prepare(
                    'SELECT staff_id
                     FROM staff
                     WHERE staff_id = ?
                     LIMIT 1'
                );

                $stmt->bind_param(
                    'i',
                    $staffId
                );

                $stmt->execute();

                if (
                    !$stmt
                    ->get_result()
                    ->fetch_assoc()
                ) {
                    json_response(
                        false,
                        'Selected staff member was not found.',
                        [],
                        404
                    );
                }


                /* Prevent duplicate appointment */

                $stmt = $db->prepare(
                    'SELECT appointment_id
                     FROM appointments
                     WHERE
                        staff_id = ?
                        AND appointment_date = ?
                        AND appointment_time = ?
                        AND status IN
                        ("Pending", "Confirmed")
                     LIMIT 1'
                );

                $stmt->bind_param(
                    'iss',
                    $staffId,
                    $appointmentDate,
                    $appointmentTime
                );

                $stmt->execute();

                if (
                    $stmt
                    ->get_result()
                    ->fetch_assoc()
                ) {
                    json_response(
                        false,
                        'That appointment time is already booked. Please choose another time.',
                        [],
                        409
                    );
                }


                /* Create appointment */

                $stmt = $db->prepare(
                    'INSERT INTO appointments
                    (
                        patient_id,
                        staff_id,
                        appointment_date,
                        appointment_time,
                        reason,
                        status
                    )
                    VALUES (?, ?, ?, ?, ?, "Pending")'
                );

                $stmt->bind_param(
                    'iisss',
                    $patientId,
                    $staffId,
                    $appointmentDate,
                    $appointmentTime,
                    $reason
                );

                $stmt->execute();

                $appointmentId =
                    $stmt->insert_id;

                json_response(
                    true,
                    'Appointment booked successfully.',
                    [
                        'appointment_id' =>
                            $appointmentId
                    ]
                );
            }


            /* -----------------------------------------------------
               LOAD APPOINTMENTS
               ----------------------------------------------------- */

            else {

                if ($user['role'] === 'Patient') {

                    $stmt = $db->prepare(
                        "SELECT
                            a.appointment_id,
                            a.appointment_date,
                            a.appointment_time,
                            a.reason,
                            a.status,
                            CONCAT(
                                u.first_name,
                                ' ',
                                u.last_name
                            ) AS clinician
                         FROM appointments a
                         JOIN patients p
                           ON p.patient_id = a.patient_id
                         JOIN staff s
                           ON s.staff_id = a.staff_id
                         JOIN users u
                           ON u.user_id = s.user_id
                         WHERE p.user_id = ?
                         ORDER BY
                            a.appointment_date,
                            a.appointment_time"
                    );

                    $stmt->bind_param(
                        'i',
                        $user['user_id']
                    );

                } elseif ($user['role'] === 'Staff') {

                    $stmt = $db->prepare(
                        "SELECT
                            a.appointment_id,
                            a.appointment_date,
                            a.appointment_time,
                            a.reason,
                            a.status,
                            CONCAT(
                                pu.first_name,
                                ' ',
                                pu.last_name
                            ) AS patient,
                            CONCAT(
                                su.first_name,
                                ' ',
                                su.last_name
                            ) AS clinician
                         FROM appointments a
                         JOIN patients p
                           ON p.patient_id = a.patient_id
                         JOIN users pu
                           ON pu.user_id = p.user_id
                         JOIN staff s
                           ON s.staff_id = a.staff_id
                         JOIN users su
                           ON su.user_id = s.user_id
                         WHERE s.user_id = ?
                         ORDER BY
                            a.appointment_date,
                            a.appointment_time"
                    );
                    $stmt->bind_param('i', $user['user_id']);

                } else {

                    $stmt = $db->prepare(
                        "SELECT
                            a.appointment_id,
                            a.appointment_date,
                            a.appointment_time,
                            a.reason,
                            a.status,
                            CONCAT(pu.first_name, ' ', pu.last_name) AS patient,
                            CONCAT(su.first_name, ' ', su.last_name) AS clinician
                         FROM appointments a
                         JOIN patients p ON p.patient_id = a.patient_id
                         JOIN users pu ON pu.user_id = p.user_id
                         JOIN staff s ON s.staff_id = a.staff_id
                         JOIN users su ON su.user_id = s.user_id
                         ORDER BY a.appointment_date, a.appointment_time"
                    );
                }

                $stmt->execute();

                json_response(
                    true,
                    'Appointments loaded.',
                    [
                        'appointments' =>
                            $stmt
                            ->get_result()
                            ->fetch_all(
                                MYSQLI_ASSOC
                            )
                    ]
                );
            }

            break;


        /* =========================================================
           MEDICAL RECORDS
           ========================================================= */

        case 'records':

            $user =
                require_login();

            $db = db();

            if ($user['role'] === 'Patient') {

                $stmt = $db->prepare(
                    "SELECT
                        r.record_id,
                        r.record_date,
                        r.record_type,
                        r.diagnosis,
                        r.notes,
                        CONCAT(
                            u.first_name,
                            ' ',
                            u.last_name
                        ) AS clinician
                     FROM medical_records r
                     JOIN patients p
                       ON p.patient_id = r.patient_id
                     JOIN staff s
                       ON s.staff_id = r.staff_id
                     JOIN users u
                       ON u.user_id = s.user_id
                     WHERE p.user_id = ?
                     ORDER BY r.record_date DESC"
                );

                $stmt->bind_param(
                    'i',
                    $user['user_id']
                );

            } else {

                $stmt = $db->prepare(
                    "SELECT
                        r.record_id,
                        r.record_date,
                        r.record_type,
                        r.diagnosis,
                        r.notes,
                        CONCAT(
                            pu.first_name,
                            ' ',
                            pu.last_name
                        ) AS patient,
                        CONCAT(
                            su.first_name,
                            ' ',
                            su.last_name
                        ) AS clinician
                     FROM medical_records r
                     JOIN patients p
                       ON p.patient_id = r.patient_id
                     JOIN users pu
                       ON pu.user_id = p.user_id
                     JOIN staff s
                       ON s.staff_id = r.staff_id
                     JOIN users su
                       ON su.user_id = s.user_id
                     ORDER BY r.record_date DESC"
                );
            }

            $stmt->execute();

            json_response(
                true,
                'Medical records loaded.',
                [
                    'records' =>
                        $stmt
                        ->get_result()
                        ->fetch_all(
                            MYSQLI_ASSOC
                        )
                ]
            );

            break;


        /* =========================================================
           MEDICATIONS
           ========================================================= */

        case 'medications':

            $user =
                require_login();

            $db = db();

            if ($user['role'] === 'Patient') {

                $stmt = $db->prepare(
                    "SELECT
                        m.medication_id,
                        m.name,
                        m.dosage,
                        m.frequency,
                        m.start_date,
                        m.end_date,
                        m.status
                     FROM medications m
                     JOIN patients p
                       ON p.patient_id = m.patient_id
                     WHERE p.user_id = ?
                     ORDER BY
                        m.status,
                        m.start_date DESC"
                );

                $stmt->bind_param(
                    'i',
                    $user['user_id']
                );

            } else {

                $stmt = $db->prepare(
                    "SELECT
                        m.medication_id,
                        m.name,
                        m.dosage,
                        m.frequency,
                        m.start_date,
                        m.end_date,
                        m.status,
                        CONCAT(
                            pu.first_name,
                            ' ',
                            pu.last_name
                        ) AS patient
                     FROM medications m
                     JOIN patients p
                       ON p.patient_id = m.patient_id
                     JOIN users pu
                       ON pu.user_id = p.user_id
                     ORDER BY
                        m.start_date DESC"
                );
            }

            $stmt->execute();

            json_response(
                true,
                'Medications loaded.',
                [
                    'medications' =>
                        $stmt
                        ->get_result()
                        ->fetch_all(
                            MYSQLI_ASSOC
                        )
                ]
            );

            break;


        /* =========================================================
           UNKNOWN ACTION
           ========================================================= */

        default:

            json_response(
                false,
                'Unknown action.',
                [],
                404
            );

            break;
    }


} catch (mysqli_sql_exception $e) {

    json_response(
        false,
        'Database error: ' . $e->getMessage(),
        [],
        500
    );

} catch (Throwable $e) {

    json_response(
        false,
        'Application error: ' . $e->getMessage(),
        [],
        500
    );
}
