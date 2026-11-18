<?php

namespace Linups\LinupsPaypal\Exceptions;

use Exception;
use Throwable;

/**
 * Thrown when a PayPal API call fails.
 *
 * Extends \Exception so existing `catch (\Exception $e)` blocks keep working.
 */
class PaypalException extends Exception
{
    private ?array $response;

    public function __construct(string $message = '', ?array $response = null, int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->response = $response;
    }

    /**
     * Decoded PayPal response body (if any) that caused the failure.
     */
    public function getResponse(): ?array
    {
        return $this->response;
    }
}
