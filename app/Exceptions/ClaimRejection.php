<?php

namespace OGame\Exceptions;

use RuntimeException;

/**
 * Internal control-flow exception thrown by DirectiveService when a Directives
 * reward cannot be claimed (already claimed, not completed yet, unknown id).
 */
class ClaimRejection extends RuntimeException
{
}
