<?php

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


        /* =========================================================
           REGISTER
           ========================================================= */

        case 'register':

            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                json_response(false, 'Invalid request.', [], 405);
            }

            $input = json_decode(
                file_get_contents('php://input'),
                true
            ) ?: $_POST;

            $first = clean((string)($input['first_name'] ?? ''));
            $last = clean((string)($input['last_name'] ?? ''));
            $email = strtolower(clean((string)($input['email'] ?? '')));
            $phone = clean((string)($input['phone'] ?? ''));
            $password = (string)($input['password'] ?? '');
            $confirm = (string)($input['confirm_password'] ?? '');

            $role = ucfirst(
                strtolower(
                    clean((string)($input['role'] ?? 'Patient'))
                )
            );

            if (
                $first === '' ||
                $last === '' ||
                $email === '' ||
                $phone === '' ||
                $password === '' ||
                $confirm === ''
            ) {
                json_response(
                    false,
                    'All required fields must be completed.',
                    [],
                    422
                );
            }

            if (!valid_email($email)) {
                json_response(
                    false,
                    'Please enter a valid email address.',
                    [],
                    422
                );
            }

            if (!valid_phone($phone)) {
                json_response(
                    false,
                    'Please enter a valid phone number.',
                    [],
                    422
                );
            }

            if (strlen($password) < 8) {
                json_response(
                    false,
                    'Password must contain at least 8 characters.',
                    [],
                    422
                );
            }

            if ($password !== $confirm) {
                json_response(
                    false,
                    'Passwords do not match.',
                    [],
                    422
                );
            }

            if (
                !in_array(
                    $role,
                    ['Patient', 'Staff', 'Administrator'],
                    true
                )
            ) {
                json_response(
                    false,
                    'Invalid user role.',
                    [],
                    422
                );
            }

            $db = db();

            $stmt = $db->prepare(
                'SELECT user_id
                 FROM users
                 WHERE email = ? OR phone = ?
                 LIMIT 1'
            );

            $stmt->bind_param(
                'ss',
                $email,
                $phone
            );

            $stmt->execute();

            if ($stmt->get_result()->fetch_assoc()) {
                json_response(
                    false,
                    'An account with that email or phone already exists.',
                    [],
                    409
                );
            }

            $hash = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            $stmt = $db->prepare(
                'INSERT INTO users
                (first_name, last_name, email, phone, password_hash, role)
                VALUES (?, ?, ?, ?, ?, ?)'
            );

            $stmt->bind_param(
                'ssssss',
                $first,
                $last,
                $email,
                $phone,
                $hash,
                $role
            );

            $stmt->execute();

            $uid = $stmt->insert_id;


            /* PATIENT */

            if ($role === 'Patient') {

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

                $patientNo =
                    'MV-' .
                    str_pad(
                        (string)$uid,
                        5,
                        '0',
                        STR_PAD_LEFT
                    );

                $dob =
                    $input['date_of_birth'] ?? null;

                $gender =
                    $input['gender'] ??
                    'Prefer not to say';

                $address =
                    $input['address'] ?? '';

                $emergency =
                    $input['emergency_contact'] ?? '';

                $stmt->bind_param(
                    'isssss',
                    $uid,
                    $patientNo,
                    $dob,
                    $gender,
                    $address,
                    $emergency
                );

                $stmt->execute();
            }


            /* STAFF */

            elseif ($role === 'Staff') {

                $stmt = $db->prepare(
                    'INSERT INTO staff
                    (
                        user_id,
                        employee_number,
                        job_title,
                        department,
                        permissions
                    )
                    VALUES (?, ?, ?, ?, ?)'
                );

                $emp =
                    'ST-' .
                    str_pad(
                        (string)$uid,
                        5,
                        '0',
                        STR_PAD_LEFT
                    );

                $title = 'Clinical Staff';

                $dept = 'General Practice';

                $perm =
                    'PATIENT_READ,APPOINTMENT_MANAGE,RECORD_MANAGE';

                $stmt->bind_param(
                    'issss',
                    $uid,
                    $emp,
                    $title,
                    $dept,
                    $perm
                );

                $stmt->execute();
            }


            /* ADMINISTRATOR */

            else {

                $stmt = $db->prepare(
                    'INSERT INTO administrators
                    (
                        user_id,
                        admin_number,
                        permissions
                    )
                    VALUES (?, ?, ?)'
                );

                $admin =
                    'AD-' .
                    str_pad(
                        (string)$uid,
                        5,
                        '0',
                        STR_PAD_LEFT
                    );

                $perm =
                    'USER_MANAGE,REPORT_VIEW,SYSTEM_MANAGE';

                $stmt->bind_param(
                    'iss',
                    $uid,
                    $admin,
                    $perm
                );

                $stmt->execute();
            }

            json_response(
                true,
                'Account created successfully. You can now log in.'
            );

            break;


        /* =========================================================
           LOGIN
           ========================================================= */

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


                /* -------------------------------------------------
                   If no staff member was selected, use the first
                   available staff member.
                   ------------------------------------------------- */

                if ($staffId < 1) {

                    $staffResult =
                        $db->query(
                            'SELECT staff_id
                             FROM staff
                             ORDER BY staff_id ASC
                             LIMIT 1'
                        );

                    $staff =
                        $staffResult->fetch_assoc();

                    if (!$staff) {
                        json_response(
                            false,
                            'No staff members are currently available.',
                            [],
                            409
                        );
                    }

                    $staffId =
                        (int)$staff['staff_id'];
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

                } else {

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
                         ORDER BY
                            a.appointment_date,
                            a.appointment_time"
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