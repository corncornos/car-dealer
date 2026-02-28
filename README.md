# Car Dealer MVP (PHP + MySQL)

Simple vehicle inventory and basic sales tracking built for XAMPP / localhost.

Quick setup:

1. Copy the `car-dealer` folder into your XAMPP `htdocs` directory (e.g., `C:\xampp\htdocs\car-dealer`).
2. Start Apache and MySQL via XAMPP Control Panel.
3. Open a browser and run the installer once: `http://localhost/car-dealer/install.php`.
   - This creates the `car_dealer` database and tables and a default admin user: `admin@local` / `admin123`.
4. Go to `http://localhost/car-dealer/login.php` and login.

Notes:
- DB credentials are in `config.php`. If your MySQL root has a password, update `DB_USER`/`DB_PASS`.
- You can also import `db.sql` in phpMyAdmin instead of running `install.php`.
- This is an MVP: no CSRF tokens, limited validation, basic role checks. For production/hardening, add input validation, CSRF, prepared statements (already used), and stricter access controls.
