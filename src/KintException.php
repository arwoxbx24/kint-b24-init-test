<?php

declare(strict_types=1);

namespace KintB24;

/** Business-level error from КИНТ API (Success=false). Never retried. */
class KintException extends \RuntimeException {}
