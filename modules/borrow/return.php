<?php
require_once __DIR__ . '/../../config/app.php';
requireLogin();
$pageTitle = 'Return Book';
$pdo = getDB();
$id = (int)($_GET['id'] ?? 0);

// Allowed condition values — whitelist
const CONDITION_ENUM = ['Perfect', 'Good', 'Mildly Torn', 'Torn', 'Damaged'];

// Load record with book copy info and borrow-time condition
$stmt = $pdo->prepare("SELECT br.*, b.title, b.book_id book_code, b.id bid,
    b.available_copies, b.total_copies, b.condition_status book_condition,
    bo.full_name borrower_name, bo.borrower_id borrower_code, bo.category borrower_cat
    FROM borrow_records br
    JOIN books b ON br.book_id=b.id
    JOIN borrowers bo ON br.borrower_id=bo.id
    WHERE br.id=?");
$stmt->execute([$id]);
$record = $stmt->fetch();

if (!$record) { flash('error','Record not found.'); redirect(BASE_URL.'/modules/borrow/index.php'); }
if ($record['status'] === 'Returned') { flash('error','This book has already been returned.'); redirect(BASE_URL.'/modules/borrow/index.php'); }

$errors = [];
$daysBorrowed = daysBorrowed($record['borrow_date'], null);
$isOverdueNow = isOverdue($record['borrow_date'], null, 'Not Returned');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $returnDate      = trim($_POST['return_date'] ?? date('Y-m-d'));
    $notes           = trim($_POST['notes'] ?? '');
    $conditionReturn = trim($_POST['condition_on_return'] ?? 'Good');
    // Truncate and sanitize condition remarks to 500 chars before DB insert
    $condRemarks     = mb_substr(trim($_POST['condition_remarks'] ?? ''), 0, 500);

    if (!$returnDate) $errors[] = 'Return date is required.';
    if ($returnDate < $record['borrow_date']) $errors[] = 'Return date cannot be before borrow date.';

    // Whitelist-validate condition value
    if (!in_array($conditionReturn, CONDITION_ENUM, true)) {
        $errors[] = 'Invalid condition value submitted.';
    }

    if (!$errors) {
        $userId = currentUser()['id'] ?? null;

        $pdo->beginTransaction();
        try {
            // Mark borrow record as returned, capturing condition_on_return.
            // Append condition remarks to existing notes field.
            $notesFinal = $notes;
            if ($condRemarks !== '') {
                $notesFinal = ($notes !== '' ? $notes . ' | ' : '')
                    . 'Condition note: ' . sanitize($condRemarks);
            }
            $upd = $pdo->prepare("UPDATE borrow_records
                SET status='Returned', return_date=?, notes=?, condition_on_return=?
                WHERE id=?");
            $upd->execute([$returnDate, $notesFinal, $conditionReturn, $id]);

            // trg_condition_escalate fires AFTER the UPDATE above and
            // automatically escalates books.condition_status if needed.

            // Increment available_copies — trg_books_sync_status sets availability status
            $pdo->prepare("UPDATE books SET available_copies = available_copies + 1 WHERE id=?")
                ->execute([$record['bid']]);

            // Append to condition history log (append-only)
            $pdo->prepare("INSERT INTO book_condition_log
                (book_id, record_id, event_type, condition_noted, noted_by, remarks)
                VALUES (?, ?, 'Returned', ?, ?, ?)")
                ->execute([$record['bid'], $id, $conditionReturn, $userId,
                           $condRemarks !== '' ? sanitize($condRemarks) : null]);

            $pdo->commit();
            auditLog('return', 'borrow_records', $id,
                "Returned book: {$record['book_code']}, condition:{$conditionReturn}");

            // Warn if returned in degraded condition (Torn or Damaged)
            $degraded = in_array($conditionReturn, ['Torn', 'Damaged'], true);
            if ($degraded) {
                flash('warning',
                    "⚠️ Book returned in degraded condition (<strong>{$conditionReturn}</strong>). "
                    . "Book record has been updated automatically.");
            } else {
                flash('success',
                    "Book <strong>{$record['title']}</strong> returned successfully on "
                    . date('d M Y', strtotime($returnDate)) . ".");
            }
            redirect(BASE_URL.'/modules/borrow/index.php');
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = 'Transaction failed: ' . $e->getMessage();
        }
    }
}

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>
<div id="main">
  <div class="topbar">
    <button class="sidebar-toggle"><i class="bi bi-list"></i></button>
    <span class="topbar-title">Return Book</span>
    <a href="index.php" class="btn btn-outline-secondary btn-sm ms-auto"><i class="bi bi-arrow-left me-1"></i>Back</a>
  </div>
  <div class="page-content">
    <?php if ($errors): ?>
      <div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <div class="row g-4 justify-content-center">
      <div class="col-lg-7">

        <?php if ($isOverdueNow): ?>
        <div class="alert alert-danger d-flex gap-2 align-items-center mb-4">
          <i class="bi bi-clock-history fs-4"></i>
          <div>
            <strong>Overdue Notice</strong><br>
            This book was due on <strong><?= date('d M Y',strtotime($record['due_date'])) ?></strong>.
            It has been out for <strong><?= $daysBorrowed ?> days</strong>
            (<?= $daysBorrowed - BORROW_DAYS ?> days overdue).
          </div>
        </div>
        <?php endif; ?>

        <!-- Record summary -->
        <div class="card mb-4">
          <div class="card-header"><i class="bi bi-receipt"></i> Borrow Details</div>
          <div class="card-body">
            <div class="row g-3">
              <div class="col-sm-6">
                <label class="form-label">Book</label>
                <div class="fw-semibold"><?= sanitize($record['title']) ?></div>
                <div class="text-muted small">
                  <code><?= sanitize($record['book_code']) ?></code>
                  &nbsp;—&nbsp;
                  <?= (int)$record['available_copies'] ?>/<?= (int)$record['total_copies'] ?> copies available
                </div>
              </div>
              <div class="col-sm-6">
                <label class="form-label">Borrower</label>
                <div class="fw-semibold"><?= sanitize($record['borrower_name']) ?></div>
                <div class="text-muted small"><code><?= sanitize($record['borrower_code']) ?></code> — <?= sanitize($record['borrower_cat']) ?></div>
              </div>
              <div class="col-sm-4">
                <label class="form-label">Record ID</label>
                <div><code><?= sanitize($record['record_id']) ?></code></div>
              </div>
              <div class="col-sm-4">
                <label class="form-label">Borrow Date</label>
                <div><?= date('d M Y',strtotime($record['borrow_date'])) ?></div>
              </div>
              <div class="col-sm-4">
                <label class="form-label">Due Date</label>
                <div class="<?= $isOverdueNow ? 'text-danger fw-bold' : '' ?>">
                  <?= date('d M Y',strtotime($record['due_date'])) ?>
                  <?= $isOverdueNow ? '<span class="badge bg-danger ms-1">Overdue</span>' : '' ?>
                </div>
              </div>
              <div class="col-sm-4">
                <label class="form-label">Days Borrowed</label>
                <div class="<?= $isOverdueNow ? 'text-danger fw-bold' : '' ?>"><?= $daysBorrowed ?> days</div>
              </div>
              <!-- Read-only badge showing condition when borrowed -->
              <div class="col-sm-8">
                <label class="form-label">Condition When Borrowed</label>
                <div><?= badge($record['condition_on_borrow'] ?? 'Good') ?>
                  <span class="text-muted small ms-2">This was the condition recorded at issue time.</span>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Return form -->
        <div class="card">
          <div class="card-header text-success"><i class="bi bi-arrow-return-left"></i> Process Return</div>
          <div class="card-body">
            <form method="POST">
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">Return Date *</label>
                  <input type="date" name="return_date" class="form-control" required
                         value="<?= $_POST['return_date'] ?? date('Y-m-d') ?>"
                         min="<?= $record['borrow_date'] ?>" max="<?= date('Y-m-d') ?>">
                </div>
                <!-- Condition at return — defaults to the condition it was issued in -->
                <div class="col-md-6">
                  <label class="form-label">Book Condition at Return *</label>
                  <select name="condition_on_return" class="form-select">
                    <?= conditionOptions($_POST['condition_on_return'] ?? ($record['condition_on_borrow'] ?? 'Good')) ?>
                  </select>
                </div>
                <div class="col-12">
                  <label class="form-label">Condition Remarks <span class="text-muted small">(optional, max 500 chars)</span></label>
                  <textarea name="condition_remarks" class="form-control" rows="2" maxlength="500"
                            placeholder="e.g. Pages 10–15 torn, cover intact…"><?= sanitize($_POST['condition_remarks'] ?? '') ?></textarea>
                </div>
                <div class="col-12">
                  <label class="form-label">General Notes (optional)</label>
                  <textarea name="notes" class="form-control" rows="2"
                            placeholder="Any other return notes…"><?= sanitize($record['notes'] ?? '') ?></textarea>
                </div>
                <div class="col-12 d-flex gap-2">
                  <button type="submit" class="btn btn-success">
                    <i class="bi bi-check-circle me-1"></i>Confirm Return
                  </button>
                  <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
                </div>
              </div>
            </form>
          </div>
        </div>

      </div>
    </div>
  </div>
</div>
<?php require_once '../../includes/footer.php'; ?>
