<?php
require_once __DIR__ . '/../../config/app.php';
requireLogin();
$pageTitle = 'Edit Book';
$pdo = getDB();
$id = (int)($_GET['id'] ?? 0);
$book = $pdo->prepare("SELECT * FROM books WHERE id=?");
$book->execute([$id]);
$book = $book->fetch();
if (!$book) { flash('error','Book not found.'); redirect(BASE_URL.'/modules/books/index.php'); }

$errors = []; $data = $book;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'book_id'          => strtoupper(trim($_POST['book_id'] ?? '')),
        'title'            => trim($_POST['title'] ?? ''),
        'author'           => trim($_POST['author'] ?? ''),
        'category'         => trim($_POST['category'] ?? ''),
        'shelf_location'   => trim($_POST['shelf_location'] ?? ''),
        'condition_status' => $_POST['condition_status'] ?? 'Good',
        'total_copies'     => (int)($_POST['total_copies'] ?? 1),
        'status_mode'      => $_POST['status_mode'] ?? 'auto',
    ];

    if (!$data['book_id'])            $errors[] = 'Book ID is required.';
    if (!$data['title'])              $errors[] = 'Title is required.';
    if (!$data['author'])             $errors[] = 'Author is required.';
    if (!$data['category'])           $errors[] = 'Category is required.';
    if ($data['total_copies'] < 1)    $errors[] = 'Total copies must be at least 1.';
    if ($data['total_copies'] > 9999) $errors[] = 'Total copies cannot exceed 9999.';
    if (!in_array($data['condition_status'], ['Perfect', 'Good', 'Mildly Torn', 'Torn', 'Damaged'], true)) {
        $errors[] = 'Invalid condition selected.';
    }
    if (!in_array($data['status_mode'], ['auto', 'Missing', 'Not Available'], true)) {
        $errors[] = 'Invalid status mode selected.';
    }

    // Unique check (exclude self)
    $check = $pdo->prepare("SELECT id FROM books WHERE book_id=? AND id!=?");
    $check->execute([$data['book_id'], $id]);
    if ($check->fetch()) $errors[] = "Book ID '{$data['book_id']}' already used by another record.";

    if (!$errors) {
        // Count how many copies are currently checked out (not yet returned)
        $outStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM borrow_records WHERE book_id=? AND status='Not Returned'"
        );
        $outStmt->execute([$id]);
        $copiesOut = (int)$outStmt->fetchColumn();

        // Guard: new total_copies cannot be less than the number currently borrowed
        if ($data['total_copies'] < $copiesOut) {
            $errors[] = "Cannot reduce total copies below the number currently borrowed ({$copiesOut} " .
                        ($copiesOut === 1 ? 'copy' : 'copies') . " out).";
        }
    }

    if (!$errors) {
        // Recalculate available_copies = new_total - copies_currently_out
        $outStmt2 = $pdo->prepare(
            "SELECT COUNT(*) FROM borrow_records WHERE book_id=? AND status='Not Returned'"
        );
        $outStmt2->execute([$id]);
        $copiesOut = (int)$outStmt2->fetchColumn();
        $newAvailable = $data['total_copies'] - $copiesOut;

        // Determine new status based on status_mode and copy counts
        if ($data['status_mode'] === 'Missing') {
            $newStatus = 'Missing';
        } elseif ($data['status_mode'] === 'Not Available') {
            $newStatus = 'Not Available';
        } else {
            // Auto mode
            if ($newAvailable === 0) {
                $newStatus = 'All Issued';
            } elseif ($newAvailable < $data['total_copies']) {
                $newStatus = 'Borrowed';
            } else {
                $newStatus = 'Available';
            }
        }

        $stmt = $pdo->prepare("UPDATE books
            SET book_id=?, title=?, author=?, category=?, shelf_location=?,
                condition_status=?, total_copies=?, available_copies=?, status=?
            WHERE id=?");
        $stmt->execute([
            sanitize($data['book_id']),
            sanitize($data['title']),
            sanitize($data['author']),
            sanitize($data['category']),
            sanitize($data['shelf_location']),
            sanitize($data['condition_status']),
            $data['total_copies'],
            $newAvailable,
            $newStatus,
            $id,
        ]);
        auditLog('update', 'books', $id,
            "Book updated: {$data['title']} (total_copies: {$data['total_copies']}, status: {$newStatus})");
        flash('success', 'Book updated successfully!');
        redirect(BASE_URL . '/modules/books/index.php');
    }
}

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>
<div id="main">
  <div class="topbar">
    <button class="sidebar-toggle"><i class="bi bi-list"></i></button>
    <span class="topbar-title">Edit Book</span>
    <a href="index.php" class="btn btn-outline-secondary btn-sm ms-auto"><i class="bi bi-arrow-left me-1"></i>Back</a>
  </div>
  <div class="page-content">
    <?php if ($errors): ?>
      <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <div class="card" style="max-width:680px;margin:0 auto">
      <div class="card-header"><i class="bi bi-pencil"></i> Edit: <?= sanitize($book['title']) ?></div>
      <div class="card-body">
        <form method="POST">
          <div class="row g-3">
            <div class="col-md-4">
              <label class="form-label">Book ID *</label>
              <input type="text" name="book_id" class="form-control" required value="<?= sanitize($data['book_id']) ?>">
            </div>
            <div class="col-md-8">
              <label class="form-label">Title *</label>
              <input type="text" name="title" class="form-control" required value="<?= sanitize($data['title']) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Author *</label>
              <input type="text" name="author" class="form-control" required value="<?= sanitize($data['author']) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label">Category *</label>
              <input type="text" name="category" class="form-control" required list="cat-list" value="<?= sanitize($data['category']) ?>">
              <datalist id="cat-list">
                <?php foreach ($pdo->query("SELECT DISTINCT category FROM books ORDER BY category")->fetchAll(PDO::FETCH_COLUMN) as $c): ?>
                <option value="<?= sanitize($c) ?>">
                <?php endforeach; ?>
              </datalist>
            </div>
            <div class="col-md-4">
              <label class="form-label">Shelf Location</label>
              <input type="text" name="shelf_location" class="form-control" value="<?= sanitize($data['shelf_location'] ?? '') ?>">
            </div>
            <!-- Total Copies: placed after shelf_location as specified -->
            <div class="col-md-4">
              <label class="form-label">Total Copies *</label>
              <input type="number" name="total_copies" class="form-control"
                     min="1" max="9999" required
                     value="<?= (int)$data['total_copies'] ?>">
              <?php
              // Show how many are currently checked out so admin knows the minimum allowed
              $outCount = (int)$pdo->prepare("SELECT COUNT(*) FROM borrow_records WHERE book_id=? AND status='Not Returned'")
                ->execute([$id]) ? $pdo->query("SELECT COUNT(*) FROM borrow_records WHERE book_id={$id} AND status='Not Returned'")->fetchColumn() : 0;
              ?>
              <div class="form-text">
                Currently out: <strong><?= $outCount ?></strong>.
                Available: <strong><?= (int)$book['available_copies'] ?></strong>.
                Min allowed: <strong><?= $outCount ?></strong>.
              </div>
            </div>
            <div class="col-md-4">
              <label class="form-label">Condition *</label>
              <select name="condition_status" class="form-select" required>
                <?= conditionOptions($data['condition_status'] ?? 'Good') ?>
              </select>
            </div>
            <!-- Availability Status Dropdown: Auto, Missing, or Not Available -->
            <div class="col-md-6">
              <label class="form-label">Availability Status *</label>
              <select name="status_mode" class="form-select" required>
                <?php
                $curr = $book['status'];
                $isAuto = in_array($curr, ['Available', 'Borrowed', 'All Issued'], true);
                ?>
                <option value="auto" <?= $isAuto ? 'selected' : '' ?>>Auto-Manage (based on copy counts)</option>
                <option value="Missing" <?= $curr === 'Missing' ? 'selected' : '' ?>>Missing (Manual Override)</option>
                <option value="Not Available" <?= $curr === 'Not Available' ? 'selected' : '' ?>>Not Available (Manual Override)</option>
              </select>
              <div class="form-text mt-1">
                Current live status: <?= badge($curr) ?>
              </div>
            </div>
            <div class="col-12 d-flex gap-2">
              <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Update Book</button>
              <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
<?php require_once '../../includes/footer.php'; ?>
