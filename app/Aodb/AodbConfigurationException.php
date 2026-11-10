<?php

namespace App\Aodb;

use RuntimeException;

/**
 * The AODB client cannot be used as configured. Messages name the setting,
 * never its value.
 */
final class AodbConfigurationException extends RuntimeException {}
