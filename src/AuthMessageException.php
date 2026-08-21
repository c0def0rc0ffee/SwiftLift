<?php
declare(strict_types=1);

namespace SwiftLift;

/**
 * <summary>
 * An exception whose message is safe to show the end user verbatim.
 * </summary>
 * <remarks>
 * The OAuth callbacks bounce back to the SPA with a fixed, generic
 * message for anything they catch, so internal exception text can never
 * land in a user-visible URL. That is the right default, but it also
 * swallowed the deliberately-worded outcomes ("this account has been
 * suspended", "an account already exists for that email") that the user
 * genuinely needs to read in order to act.
 *
 * Throwing this subclass marks a message as intentionally user-facing.
 * The callbacks pass these straight through and keep the generic
 * fallback for every other Throwable.
 * </remarks>
 */
final class AuthMessageException extends \RuntimeException
{
}
