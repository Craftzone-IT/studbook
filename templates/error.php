<?php

declare(strict_types=1);

/**
 * @var int $status
 * @var string $title
 * @var string|null $details
 */
?>
<section class="card">
    <h1><?= e($title) ?></h1>
    <p><a href="<?= e(url('/')) ?>"><?= e(t('error.back_home')) ?></a></p>
    <?php if ($details !== null) : ?>
        <pre class="error-details"><?= e($details) ?></pre>
    <?php endif; ?>
</section>
