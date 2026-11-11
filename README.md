# MediVault e-Clinic System — Term 3

This version implements the MediVault UI plus the Term 3 database/backend requirements.

## Technology
- HTML/CSS/JavaScript frontend
- PHP 8.2 backend
- MySQL 8 (existing MySQL80 service)
- Apache from XAMPP
- mysqli prepared statements

## Installation
1. Keep the XAMPP Apache service running.
2. Keep the Windows `MySQL80` service running. Do **not** start XAMPP's MariaDB/MySQL module if port 3306 is occupied by MySQL80.
3. Copy this folder to `C:\xampp\htdocs\MediVault_T3_Final` (or use the existing project folder name).
4. Check `config.php`. Default settings are `127.0.0.1`, port `3306`, user `root`, blank password, database `medivault`.
5. Visit `http://localhost/MediVault_T3_Final/setup.php` once. This creates and seeds the database.
6. Open `http://localhost/MediVault_T3_Final/`.

## Demo accounts
Password for all seeded accounts: `Password123!`
- Patient: `john.doe@medivault.local`
- Staff: `mpho.mokoena@medivault.local`
- Administrator: `admin1@medivault.local`

## Implemented Term 3 functionality
- Database connection to MySQL80
- Registration with required-field, email, phone, password and duplicate validation
- Password hashing with `password_hash()` / `password_verify()`
- Role-based login and protected server-side endpoints
- Login audit records
- Patient search
- Patient INSERT, UPDATE and DELETE (DELETE is administrator-only)
- Patient appointments, medical records and medication retrieval
- Staff appointment/record views
- Administrator patient management
- Foreign keys, unique constraints and enumerated status/role fields
- Seed data with at least five records in each main table

## Evidence note
Do not mark a test as Pass until you have actually executed it and captured the required screenshot. The supplied testing template requires test ID, feature, input, expected result, actual result, pass/fail and screenshot evidence.
