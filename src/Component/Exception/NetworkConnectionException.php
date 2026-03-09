<?php

declare(strict_types=1);

namespace racacax\XmlTv\Component\Exception;

/**
 * Thrown when a network connection attempt fails (no response from server).
 * Distinct from data errors where the server responded but returned invalid/empty content.
 */
class NetworkConnectionException extends \RuntimeException
{
}
