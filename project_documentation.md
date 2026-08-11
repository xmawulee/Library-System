# Library Management System (LBS) - Project Documentation

## 1. Overview
The **Library Management System (LBS)** is a modern, responsive web application designed for librarians and administrators to manage physical library operations. It tracks catalog inventory, patron records, borrowing and returning workflows, and provides analytical insights into library usage.

## 2. Technology Stack
- **Backend:** PHP 8.x (Vanilla, No Framework)
- **Database:** MySQL 5.7+ / MariaDB (using PDO)
- **Frontend:** HTML5, CSS3, JavaScript
- **UI Framework:** Bootstrap 5 (with Bootstrap Icons)
- **Charts:** Chart.js
- **Deployment:** Docker & Docker Compose (or local XAMPP/MAMP environment)

## 3. Directory Structure
```
/
├── assets/          # Static assets (CSS, images)
├── auth/            # Authentication logic (login, logout)
├── config/          # Global configuration (app.php, database.php)
├── includes/        # Shared partials (header, sidebar, footer, helpers.php)
├── modules/         # Core application features
│   ├── books/       # Book CRUD and condition tracking
│   ├── borrow/      # Circulation (Issue, Return, Overdue tracking)
│   ├── borrowers/   # Patron CRUD
│   └── reports/     # Data export and analytical reports
├── dashboard.php    # Main landing page with statistics and charts
├── database.sql     # Complete database schema and seed data
└── docker-compose.yml # Docker configuration for rapid deployment
```

## 4. Core Features
### 4.1. Dashboard & Analytics
- Real-time statistics on total books, copies currently issued, and active borrowers.
- Data visualization via Chart.js (monthly borrow trends, books by category).
- Quick alerts for top overdue books and most borrowed items.

### 4.2. Catalog Management (Books Module)
- Complete CRUD operations for books.
- **Copy Tracking:** Manages both `total_copies` and `available_copies` dynamically as books are borrowed and returned.
- **Physical Condition Tracking:** Tracks the physical condition (`Perfect`, `Good`, `Mildly Torn`, `Torn`, `Damaged`) of a book.
- **Auto-Status Sync:** The status automatically switches to *All Issued* when copies run out.

### 4.3. Patron Management (Borrowers Module)
- Register and manage students, teachers, and staff members.
- Auto-generation of intelligent Borrower IDs (e.g., `STU-001`, `TCH-002`).
- Track active vs inactive statuses.

### 4.4. Circulation (Borrowing & Returning)
- Issue books to active borrowers with an automatically calculated due date.
- **Condition at Issue/Return:** The system forces the librarian to log the book's physical condition at both checkout and return.
- **Auto-escalation:** If a book is returned in a worse condition than it was issued in, the system automatically downgrades the catalog's physical condition status.

### 4.5. Reporting
- Dedicated pages for Top Borrowed Books, Most Active Borrowers, and Overdue items.
- CSV Export functionality for external reporting.

### 4.6. Security & Auditing
- **Audit Logger:** Every critical action (adding a book, editing a borrower, returning a book) is logged into the `audit_log` table with the user ID, target, and IP address.
- **Prepared Statements:** 100% PDO prepared statements to prevent SQL Injection.
- **Secure Passwords:** Uses PHP's native `password_hash` (`bcrypt`).

---

## 5. Database Schema
The database uses InnoDB and relies heavily on foreign key constraints for referential integrity.

### Primary Tables:
1. **`users`**: System administrators and librarians.
2. **`books`**: The main catalog. Tracks metadata, copies, and condition.
3. **`borrowers`**: The patrons allowed to borrow books.
4. **`borrow_records`**: Transactional table linking a `book_id` to a `borrower_id` with checkout and return dates.
5. **`book_condition_log`**: Append-only ledger recording the physical condition of a book every time it changes hands.
6. **`audit_log`**: Administrative tracking ledger for accountability.

### Database Triggers:
The system uses automated MySQL triggers to guarantee data integrity without relying solely on PHP logic:
- `trg_books_sync_status`: Automatically switches a book's status to 'All Issued' when available copies hit zero, and back to 'Available' when returned.
- `trg_condition_escalate`: Automatically updates the `books` table condition if a book is returned in a degraded state.

---

## 6. Deployment & Installation

### Option A: Docker (Recommended)
The project includes a robust `docker-compose.yml` that provisions an Apache/PHP web server and a MySQL database.
```bash
docker-compose up -d --build
```
*The app will be accessible at `http://localhost:8000`.*

### Option B: Local Server (XAMPP / MAMP)
1. Clone the project into `htdocs` (or `www`).
2. Create a MySQL database (e.g., `library_db`).
3. Import the `database.sql` file.
4. Update `config/database.php` with your local database credentials.
5. Update the `BASE_URL` in `config/app.php` to match your local path.

**Default Login:**
- Username: `admin`
- Password: `Library@2024`

---

## 7. Recent System Patches Applied
*Note: A recent comprehensive audit resolved several latent issues to stabilize the system.*
- **SQL Strict Mode Compatibility:** Queries were patched to aggregate correctly, resolving `ONLY_FULL_GROUP_BY` crashes on MySQL 5.7+ servers.
- **Schema Parity:** The `database.sql` file was updated to include missing tables (`book_condition_log`, `audit_log`) and missing ENUM fields, resolving "Data truncated" PDO errors when tracking book conditions.
- **Session Security:** Session fixation vulnerabilities were patched by enforcing session ID regeneration upon successful authentication.
