<?php
require_once __DIR__ . '/../../config/app.php';
requireLogin();
$pageTitle = 'Issue Book';
$pdo = getDB();
$errors = []; $data = [];

// Allowed condition values — single source of truth for whitelist
const CONDITION_ENUM = ['Perfect', 'Good', 'Mildly Torn', 'Torn', 'Damaged'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bookId          = (int)($_POST['book_id'] ?? 0);
    $borrowerId      = (int)($_POST['borrower_id'] ?? 0);
    $borrowDate      = trim($_POST['borrow_date'] ?? '');
    $notes           = trim($_POST['notes'] ?? '');
    $conditionBorrow = trim($_POST['condition_on_borrow'] ?? 'Good');

    if (!$bookId)     $errors[] = 'Please select a book.';
    if (!$borrowerId) $errors[] = 'Please select a borrower.';
    if (!$borrowDate) $errors[] = 'Borrow date is required.';

    // Whitelist-validate condition value
    if (!in_array($conditionBorrow, CONDITION_ENUM, true)) {
        $errors[] = 'Invalid condition value submitted.';
    }

    if (!$errors) {
        // Check book availability using available_copies and status overrides
        $book = $pdo->prepare("SELECT * FROM books WHERE id=?");
        $book->execute([$bookId]);
        $book = $book->fetch();

        if (!$book) {
            $errors[] = 'Book not found.';
        } elseif (in_array($book['status'], ['Missing', 'Not Available'], true)) {
            $errors[] = 'This book catalog is marked as ' . $book['status'] . ' and cannot be issued.';
        } elseif ((int)$book['available_copies'] <= 0) {
            $errors[] = 'No copies of this book are currently available.';
        } else {
            // Check borrower is active
            $borrower = $pdo->prepare("SELECT * FROM borrowers WHERE id=? AND status='Active'");
            $borrower->execute([$borrowerId]);
            $borrower = $borrower->fetch();
            if (!$borrower) $errors[] = 'Borrower not found or is inactive.';
        }
    }

    if (!$errors) {
        $dueDate  = date('Y-m-d', strtotime($borrowDate . ' +' . BORROW_DAYS . ' days'));
        $recordId = 'REC-' . str_pad((int)$pdo->query("SELECT COUNT(*)+1 FROM borrow_records")->fetchColumn(), 4, '0', STR_PAD_LEFT);
        $userId   = currentUser()['id'] ?? null;

        $pdo->beginTransaction();
        try {
            // Insert borrow record with condition_on_borrow captured
            $ins = $pdo->prepare("INSERT INTO borrow_records
                (record_id, book_id, borrower_id, borrow_date, due_date, notes, status, condition_on_borrow)
                VALUES (?, ?, ?, ?, ?, ?, 'Not Returned', ?)");
            $ins->execute([$recordId, $bookId, $borrowerId, $borrowDate, $dueDate, $notes, $conditionBorrow]);
            $newId = (int)$pdo->lastInsertId();

            // Update books.condition_status to this borrow-time inspection value
            // (most recent physical check-up; does not affect available_copies)
            $pdo->prepare("UPDATE books SET condition_status=? WHERE id=?")
                ->execute([$conditionBorrow, $bookId]);

            // Decrement available_copies — trg_books_sync_status sets status automatically
            $pdo->prepare("UPDATE books SET available_copies = available_copies - 1 WHERE id=?")
                ->execute([$bookId]);

            // Append to condition history log (append-only — never updated or deleted)
            $pdo->prepare("INSERT INTO book_condition_log
                (book_id, record_id, event_type, condition_noted, noted_by)
                VALUES (?, ?, 'Borrowed', ?, ?)")
                ->execute([$bookId, $newId, $conditionBorrow, $userId]);

            $pdo->commit();
            auditLog('borrow', 'borrow_records', $newId,
                "Issued book ID:{$bookId} to borrower ID:{$borrowerId}, condition:{$conditionBorrow}");
            flash('success',
                "Book issued successfully! Record ID: <strong>{$recordId}</strong>. Due: "
                . date('d M Y', strtotime($dueDate)));
            redirect(BASE_URL.'/modules/borrow/index.php');
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Transaction failed: ' . $e->getMessage();
        }
    }
}

// Books with at least one available copy (available_copies > 0)
// and not manually overridden to Missing or Not Available.
$availableBooks = $pdo->query(
    "SELECT id, book_id, title, author, available_copies, total_copies, condition_status
     FROM books
     WHERE available_copies > 0 AND status NOT IN ('Missing', 'Not Available')
     ORDER BY title"
)->fetchAll();

// Active borrowers
$activeBorrowers = $pdo->query(
    "SELECT id, borrower_id, full_name, category, department
     FROM borrowers WHERE status='Active' ORDER BY full_name"
)->fetchAll();

// Build a JS map of book_id → condition_status for dynamic default selection
$bookConditionMap = [];
foreach ($availableBooks as $b) {
    $bookConditionMap[$b['id']] = $b['condition_status'];
}

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>
<div id="main">
  <div class="topbar">
    <button class="sidebar-toggle"><i class="bi bi-list"></i></button>
    <span class="topbar-title">Issue Book</span>
    <a href="index.php" class="btn btn-outline-secondary btn-sm ms-auto"><i class="bi bi-arrow-left me-1"></i>Back to Records</a>
  </div>
  <div class="page-content">
    <?php if ($errors): ?>
      <div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $e): ?><li><?= $e ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <div class="row g-4">
      <div class="col-lg-7">
        <div class="card">
          <div class="card-header"><i class="bi bi-book-fill"></i> Issue New Book</div>
          <div class="card-body">
            <form method="POST" id="issueForm">
              <div class="mb-3">
                <label class="form-label">Select Book *</label>
                <select name="book_id" id="bookSelect" class="form-select" required>
                  <option value="">— Choose an available book —</option>
                  <?php foreach ($availableBooks as $b): ?>
                  <option value="<?= $b['id'] ?>"
                          data-id="<?= sanitize($b['book_id']) ?>"
                          data-author="<?= sanitize($b['author']) ?>"
                          data-copies="<?= (int)$b['available_copies'] ?>/<?= (int)$b['total_copies'] ?>"
                          data-condition="<?= sanitize($b['condition_status']) ?>"
                          <?= isset($_POST['book_id']) && $_POST['book_id'] == $b['id'] ? 'selected' : '' ?>>
                    [<?= sanitize($b['book_id']) ?>] <?= sanitize($b['title']) ?>
                    (<?= (int)$b['available_copies'] ?>/<?= (int)$b['total_copies'] ?> available)
                  </option>
                  <?php endforeach; ?>
                </select>
                <div id="bookInfo" class="form-text mt-1"></div>
              </div>

              <!-- Condition at borrow — pre-populated from book's current condition_status -->
              <div class="mb-3">
                <label class="form-label">Book Condition at Issue *</label>
                <select name="condition_on_borrow" id="conditionBorrowSelect" class="form-select">
                  <?= conditionOptions($_POST['condition_on_borrow'] ?? 'Good') ?>
                </select>
                <div class="form-text">Confirm or update the physical condition of this copy at the time of issue.</div>
              </div>

              <div class="mb-3">
                <label class="form-label">Select Borrower *</label>
                <select name="borrower_id" id="borrowerSelect" class="form-select" required>
                  <option value="">— Choose a borrower —</option>
                  <?php foreach ($activeBorrowers as $b): ?>
                  <option value="<?= $b['id'] ?>"
                          data-id="<?= sanitize($b['borrower_id']) ?>"
                          data-cat="<?= sanitize($b['category']) ?>"
                          data-dept="<?= sanitize($b['department'] ?? '') ?>"
                          <?= isset($_POST['borrower_id']) && $_POST['borrower_id'] == $b['id'] ? 'selected' : '' ?>>
                    [<?= sanitize($b['borrower_id']) ?>] <?= sanitize($b['full_name']) ?> (<?= $b['category'] ?>)
                  </option>
                  <?php endforeach; ?>
                </select>
                <div id="borrowerInfo" class="form-text mt-1"></div>
              </div>
              <div class="row g-3 mb-3">
                <div class="col-md-6">
                  <label class="form-label">Borrow Date *</label>
                  <input type="date" name="borrow_date" class="form-control" required
                         value="<?= $_POST['borrow_date'] ?? date('Y-m-d') ?>"
                         max="<?= date('Y-m-d') ?>">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Due Date (auto)</label>
                  <input type="text" id="dueDateDisplay" class="form-control" readonly
                         style="background:#f8f6f2" value="<?= date('d M Y', strtotime('+'.BORROW_DAYS.' days')) ?>">
                </div>
              </div>
              <div class="mb-4">
                <label class="form-label">Notes (optional)</label>
                <textarea name="notes" class="form-control" rows="2" placeholder="Any additional notes…"><?= sanitize($_POST['notes'] ?? '') ?></textarea>
              </div>
              <button type="submit" class="btn btn-primary">
                <i class="bi bi-arrow-right-circle me-1"></i>Issue Book
              </button>
            </form>
          </div>
        </div>
      </div>

      <div class="col-lg-5">
        <div class="card mb-3">
          <div class="card-header"><i class="bi bi-info-circle"></i> Loan Policy</div>
          <div class="card-body">
            <ul class="list-unstyled mb-0 small">
              <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i>Loan period: <strong><?= BORROW_DAYS ?> days</strong></li>
              <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i>Books with <strong>available copies &gt; 0</strong> can be issued</li>
              <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-2"></i>Borrower must be <strong>Active</strong></li>
              <li class="mb-2"><i class="bi bi-exclamation-triangle-fill text-warning me-2"></i>Books not returned after <?= BORROW_DAYS ?> days are <strong>Overdue</strong></li>
            </ul>
          </div>
        </div>
        <div class="card">
          <div class="card-header"><i class="bi bi-bar-chart"></i> Quick Stats</div>
          <div class="card-body">
            <?php
            $qs = $pdo->query("SELECT
                (SELECT COUNT(*) FROM books WHERE available_copies > 0) avail,
                (SELECT COUNT(*) FROM borrowers WHERE status='Active') actBorr,
                (SELECT COUNT(*) FROM borrow_records WHERE status='Not Returned') activeRec,
                (SELECT COUNT(*) FROM borrow_records WHERE status='Not Returned' AND DATEDIFF(CURDATE(),borrow_date)>".BORROW_DAYS.") overdue")->fetch();
            ?>
            <div class="row g-2 text-center small">
              <div class="col-6 p-2 rounded" style="background:#d1f0e0">
                <div class="fw-bold fs-5"><?= $qs['avail'] ?></div><div class="text-muted">Books w/ Copies</div>
              </div>
              <div class="col-6 p-2 rounded" style="background:#e8f0f5">
                <div class="fw-bold fs-5"><?= $qs['actBorr'] ?></div><div class="text-muted">Active Borrowers</div>
              </div>
              <div class="col-6 p-2 rounded" style="background:#fef3c7">
                <div class="fw-bold fs-5"><?= $qs['activeRec'] ?></div><div class="text-muted">Books Out</div>
              </div>
              <div class="col-6 p-2 rounded" style="background:#fde8e8">
                <div class="fw-bold fs-5"><?= $qs['overdue'] ?></div><div class="text-muted">Overdue</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php require_once '../../includes/footer.php'; ?>
<script>
const BORROW_DAYS = <?= BORROW_DAYS ?>;

// When a book is selected, update the info text AND set the condition dropdown
// to the book's last recorded condition_status
document.getElementById('bookSelect').addEventListener('change', function() {
    const opt = this.options[this.selectedIndex];
    const condSel = document.getElementById('conditionBorrowSelect');
    if (opt.value) {
        document.getElementById('bookInfo').innerHTML =
            '<i class="bi bi-book me-1"></i>ID: <strong>'+opt.dataset.id+'</strong> &nbsp;|&nbsp; Author: '+opt.dataset.author
            + ' &nbsp;|&nbsp; Available: <strong>'+opt.dataset.copies+'</strong>';
        // Pre-select condition matching this book's current recorded condition
        for (let i = 0; i < condSel.options.length; i++) {
            condSel.options[i].selected = (condSel.options[i].value === opt.dataset.condition);
        }
    } else {
        document.getElementById('bookInfo').innerHTML = '';
    }
});
// Show borrower details
document.getElementById('borrowerSelect').addEventListener('change', function() {
    const opt = this.options[this.selectedIndex];
    if (opt.value) {
        document.getElementById('borrowerInfo').innerHTML =
            '<i class="bi bi-person me-1"></i>ID: <strong>'+opt.dataset.id+'</strong> | '+opt.dataset.cat+' | '+opt.dataset.dept;
    } else { document.getElementById('borrowerInfo').innerHTML = ''; }
});
// Auto calculate due date
document.querySelector('[name="borrow_date"]').addEventListener('change', function() {
    if (this.value) {
        const d = new Date(this.value);
        d.setDate(d.getDate() + BORROW_DAYS);
        document.getElementById('dueDateDisplay').value = d.toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'});
    }
});
</script>
