<?php

namespace Modules\Marketplace\Services;

use RuntimeException;

/**
 * A platform order has products not linked to EXSO variants yet.
 */
class UnlinkedProducts extends RuntimeException {}
