<?php
require_once __DIR__ . '/../../config/app.php';
requireLogin();
$pageTitle = 'Condition History';
$pdo = getDB();

$bookId = (int)($_GET['id'] ?? 0);

// Load book details
$bookStmt = $pdo->prepare("SELECT id, book_id, title, author, condition_status FROM books WHERE id=?");
$bookStmt->execute([$bookId]);
$book = $bookStmt->fetch();

if (!$book) {
    flash('error', 'Book not found.');
    redirect(BASE_URL . '/modules/books/index.php');
}

$pageTitle = 'Condition History — ' . $book['title'];

// Fetch condition log with borrower and user names.
// LEFT JOINs handle both manual inspection entries (record_id IS NULL)
// and borrow/return entries (record_id → borrow_records).
$logStmt = $pdo->prepare(
    "SELECT
        bcl.id,
        bcl.event_type,
        bcl.condition_noted,
        bcl.remarks,
        bcl.created_at,
        br.record_id    AS borrow_record_id,
        bo.full_name    AS borrower_name,
        bo.borrower_id  AS borrower_code,
        u.full_name     AS noted_by_name
     FROM book_condition_log bcl
     LEFT JOIN borrow_records br ON bcl.record_id = br.id
     LEFT JOIN borrowers bo      ON br.borrower_id = bo.id
     LEFT JOIN users u           ON bcl.noted_by   = u.id
     WHERE bcl.book_id = ?
     ORDER BY bcl.created_at DESC"
);
$logStmt->execute([$bookId]);
$log = $logStmt->fetchAll();

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>
<div id="main">
  <div class="topbar">
    <button class="sidebar-toggle"><i class="bi bi-list"></i></button>
    <span class="topbar-title">Condition History</span>
    <a href="index.php" class="btn btn-outline-secondary btn-sm ms-auto">
      <i class="bi bi-arrow-left me-1"></i>Back to Books
    </a>
  </div>
  <div class="page-content">
    <?= renderFlash() ?>

    <!-- Book summary header -->
    <div class="card mb-4">
      <div class="card-body d-flex align-items-center gap-3 flex-wrap">
        <div>
          <div class="fw-bold fs-5"><?= sanitize($book['title']) ?></div>
          <div class="text-muted small">
            <code><?= sanitize($book['book_id']) ?></code>
            &nbsp;·&nbsp; <?= sanitize($book['author']) ?>
          </div>
        </div>
        <div class="ms-auto text-end">
          <div class="small text-muted mb-1">Current Condition</div>
          <?= badge($book['condition_status']) ?>
        </div>
      </div>
    </div>

    <!-- Condition history table -->
    <div class="card">
      <div class="card-header">
        <i class="bi bi-clock-history"></i> Condition Log
        <span class="badge bg-secondary ms-2"><?= count($log) ?></span>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead>
              <tr>
                <th>Date &amp; Time</th>
                <th>Event</th>
                <th>Condition Noted</th>
                <th>Borrower</th>
                <th>Logged By</th>
                <th>Remarks</th>
              </tr>
            </thead>
            <tbody>
            <?php if ($log): ?>
            <?php foreach ($log as $entry): ?>
            <tr>
              <td class="text-muted small" style="white-space:nowrap">
                <?= date('d M Y H:i', strtotime($entry['created_at'])) ?>
              </td>
              <td>
                <?php
                // Colour-code event type badge
                $evtClass = match($entry['event_type']) {
                    'Borrowed'           => 'bg-warning text-dark',
                    'Returned'           => 'bg-success',
                    'Manual Inspection'  => 'bg-info text-dark',
                    default              => 'bg-secondary',
                };
                ?>
                <span class="badge <?= $evtClass ?>"><?= sanitize($entry['event_type']) ?></span>
                <?php if ($entry['borrow_record_id']): ?>
                <span class="text-muted small ms-1"><code><?= sanitize($entry['borrow_record_id']) ?></code></span>
                <?php endif; ?>
              </td>
              <!-- Condition cell: colour-coded via badge() -->
              <td><?= badge($entry['condition_noted']) ?></td>
              <td>
                <?php if ($entry['borrower_name']): ?>
                  <span class="fw-semibold"><?= sanitize($entry['borrower_name']) ?></span>
                  <span class="text-muted small d-block"><code><?= sanitize($entry['borrower_code']) ?></code></span>
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
              <td>
                <?= $entry['noted_by_name'] ? sanitize($entry['noted_by_name']) : '<span class="text-muted">System</span>' ?>
              </td>
              <td class="small text-muted">
                <?= $entry['remarks'] ? sanitize($entry['remarks']) : '—' ?>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php else: ?>
            <tr>
              <td colspan="6" class="text-center text-muted py-5">
                <i class="bi bi-clipboard-x fs-2 d-block mb-2"></i>
                No condition history recorded for this book.
              </td>
            </tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </div>
</div>
<?php require_once '../../includes/footer.php'; ?>
