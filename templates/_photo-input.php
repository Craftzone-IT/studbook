<?php

declare(strict_types=1);

/**
 * Photo field: takes a picture on phones, picks a file elsewhere. assets/photo.js
 * shrinks large photos in the browser before upload.
 *
 * @var string $photoLabel
 */
?>
<label for="photo"><?= e($photoLabel) ?></label>
<input id="photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp" capture="environment" required
       data-photo-input>
<img class="photo-preview" alt="" hidden data-photo-preview>
<script src="<?= e(url('/assets/photo.js')) ?>" defer></script>
