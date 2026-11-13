MediVault e-Clinic System

Responsive PHP, MySQL, HTML, CSS and JavaScript e-clinic application for XAMPP or InfinityFree shared hosting.

## Deploy on InfinityFree

1. Create a MySQL database in the hosting control panel. Select it in phpMyAdmin and import `database.sql`. The schema starts empty; it creates no accounts, appointments, or sample health records.
2. Edit the four `INFINITYFREE_DB_*` constants near the top of `config.php` with the database hostname, database name, username, and password shown in the InfinityFree MySQL Databases panel. Use the assigned `sql...` hostname; `localhost` is not the InfinityFree database host. The hostname and credentials are specific to your hosting account.
3. Upload the project files and the `assets` folder into the domain's `htdocs` directory. Keep `index.php`, `api.php`, `config.php`, `database.sql`, `script.js`, and `styles.css` together at the same level.
4. Use HTTPS for the site. “Remember Me” creates a 30-day, HTTP-only session cookie and marks it Secure when HTTPS is active.
5. Open the site and register accounts. New patient accounts have no appointments until one is booked.

The default values in `config.php` are for local XAMPP (`127.0.0.1`, port `3306`, `root`, blank password, database `medivault`). Replace these values for InfinityFree. Do not publish a configuration file containing a real database password.

## Run locally with XAMPP

1. Start Apache and MySQL.
2. Create a database named `medivault` in phpMyAdmin and import `database.sql`.
3. Keep the local database values in `config.php` and place this project under `htdocs`.
4. Open `http://localhost/MediVault_T3_Final/`.

The application includes patient, staff, and administrator roles; account registration and login; patient records; appointments; medications; and login auditing. Any demo accounts in a pre-existing local database are not created by `database.sql`.

Appointment times use the current demo schedule: weekdays, 09:00–17:00, in 30-minute slots. Staff can approve pending appointments assigned to them and view their own schedule in the calendar.
