<?php

declare(strict_types=1);

/**
 * QR scanner: assets/scan.js opens the camera and goes to the box page of a Studbook QR code.
 */
?>
<h1><?= e(t('scan.title')) ?></h1>
<div id="scan" class="scan"
     data-box-path="<?= e(url('/b/')) ?>"
     data-jsqr-url="<?= e(url('/assets/vendor/jsqr/jsQR.js')) ?>"
     data-t-not-studbook="<?= e(t('scan.not_studbook')) ?>"
     data-t-no-camera="<?= e(t('scan.no_camera')) ?>"
     data-t-opening="<?= e(t('scan.opening')) ?>">
    <video id="scan-video" class="scan-video" playsinline muted hidden></video>
    <p id="scan-status" class="hint" role="status" aria-live="polite"><?= e(t('scan.needs_js')) ?></p>
    <button type="button" id="scan-start" class="button button-primary" hidden><?= e(t('scan.start')) ?></button>
</div>
<p class="hint"><?= e(t('scan.hint')) ?></p>
<script src="<?= e(url('/assets/scan.js')) ?>" defer></script>
