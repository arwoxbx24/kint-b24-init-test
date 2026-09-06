<?php

declare(strict_types=1);

namespace KintB24;

/** API-level error from Bitrix24 (error field in response). Never retried. */
class B24Exception extends \RuntimeException {}
