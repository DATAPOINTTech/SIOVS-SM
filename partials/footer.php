</main>
</div>

<!-- ── Ctrl+K Spotlight ──────────────────────────────────────────────────── -->
<div class="spotlight-backdrop" id="spotlight" role="dialog" aria-modal="true" aria-label="Module search">
    <div class="spotlight-box">
        <div class="spotlight-input-wrap">
            <span class="spotlight-icon">⌕</span>
            <input type="search" id="spotlight-input" placeholder="Jump to module…" autocomplete="off" spellcheck="false">
            <kbd class="spotlight-esc">Esc</kbd>
        </div>
        <ul class="spotlight-list" id="spotlight-list"></ul>
    </div>
</div>

<script>
<?php if (isset($sparkJson)): ?>
window.SIOVS_SPARK = <?= $sparkJson ?>;
<?php endif; ?>
window.SIOVS_EXPIRY = <?= json_encode($expiry_map ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="assets/app.js?v=<?= filemtime(__DIR__ . '/../assets/app.js') ?>"></script>
</body>
</html>
