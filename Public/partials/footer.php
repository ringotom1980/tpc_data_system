<?php
declare(strict_types=1);
/**
 * 公用頁尾
 * 預設載入 footer.css 與 footer.js
 */
$BASE = public_base();
?>
<footer class="footer mt-4 py-3 border-top text-center">
  <div class="container">
    <small class="text-muted">&copy; <?= date('Y') ?> 台電苗栗區處材料管理系統</small>
  </div>
</footer>

<!-- Bootstrap Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
        crossorigin="anonymous"></script>
<!-- 其他共用套件 -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<!-- Footer 專屬 JS -->
<script src="<?= $BASE ?>/assets/js/footer.js"></script>
<link rel="stylesheet" href="<?= $BASE ?>/assets/css/footer.css">

</body>
</html>
