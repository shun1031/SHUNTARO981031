        </div><!-- /.main-wrapper -->
    </main><!-- /.app-main -->
</div><!-- /.app-layout -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/marked@12.0.0/marked.min.js"></script>
<script src="<?= BASE_PATH ?>/public/assets/js/main.js?v=<?= time() ?>"></script>
<?php if (!empty($extraJs)): ?>
    <?php foreach ($extraJs as $js): ?>
        <?php
        if (str_starts_with($js, 'http') || str_starts_with($js, '/')) {
            $jsUrl = $js;
        } elseif (str_contains($js, '/')) {
            $jsUrl = BASE_PATH . '/public/' . $js;
        } else {
            $jsUrl = BASE_PATH . '/public/assets/js/' . $js;
        }
        ?>
        <script src="<?= h($jsUrl) ?>?v=<?= file_exists($_SERVER['DOCUMENT_ROOT'] . parse_url($jsUrl, PHP_URL_PATH)) ? filemtime($_SERVER['DOCUMENT_ROOT'] . parse_url($jsUrl, PHP_URL_PATH)) : time() ?>"></script>
    <?php endforeach; ?>
<?php endif; ?>
<?php if (!empty($inlineJs)): ?>
    <?php /* $inlineJs はサーバサイドで組み立てた静的JSのみ受け入れる。ユーザー入力を直接埋め込んではならない */ ?>
    <script>
        <?= $inlineJs ?>
    </script>
<?php endif; ?>
</body>
</html>
