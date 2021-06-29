<?php

namespace SymfonySimpleSite\NestedSets\Exception;

use Throwable;

class NestedSetsMoveUnderSelfException extends NestedSetsException
{
    public const MESSAGE = 'You can not down item under the self';
    public function __construct($message = "", $code = 0, Throwable $previous = null)
    {
        if (empty($message)) {
            $message = self::MESSAGE;
        }
        parent::__construct($message, $code, $previous);
    }
}