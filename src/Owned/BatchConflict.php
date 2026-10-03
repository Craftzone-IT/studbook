<?php

declare(strict_types=1);

namespace Studbook\Owned;

/** A batch cannot be reverted; the message is a reason code (`changed_later`, `already_reverted`). */
final class BatchConflict extends \RuntimeException
{
}
