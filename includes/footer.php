<?php if (current_user()): ?>
        </section>
        <footer class="footer">© <?= date('Y') ?> <?= e(setting('institution_name', 'Campus Institution')) ?> <span>·</span> Helpdesk &amp; student support</footer>
    </main>
</div>
<?php else: ?>
</main>
<?php endif; ?>
<?php if (current_user()): ?>
<div class="image-lightbox" data-image-viewer hidden role="dialog" aria-modal="true" aria-label="Full-size chat photo">
    <button class="image-lightbox-close" type="button" data-image-viewer-close aria-label="Close photo">×</button>
    <img data-image-viewer-image alt="">
</div>
<?php endif; ?>
<script src="assets/js/app.js?v=<?= (int) filemtime(__DIR__ . '/../assets/js/app.js') ?>" defer></script>
</body>
</html>
