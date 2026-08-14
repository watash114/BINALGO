</div> <!-- end wrapper -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="<?= BASE_URL ?>/assets/js/script.js"></script>
<?php if (($_role ?? null) === 'staff'): ?>
<script src="<?= BASE_URL ?>/assets/js/staff.js"></script>
<?php endif; ?>
</body>
</html>
