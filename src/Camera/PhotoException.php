<?php

declare(strict_types=1);

namespace Studbook\Camera;

/** A photo cannot be used; the message is a translation key suffix (`camera.error.*`). */
final class PhotoException extends \RuntimeException
{
}
