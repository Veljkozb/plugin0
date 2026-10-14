<?php

namespace Logeecom\Settings\Domain;

/**
 * The Log level setting of Requirement 4.1: the lowest severity that gets written, not a choice of one type.
 *
 * The four cases are the ones 4.1 names, in its order, and their values are the PSR-3 level names, so a case
 * can be handed to anything that speaks PSR-3 as it is. Which entries a setting lets through is answered by
 * Severity, so this enum stays a plain list like the other enums in src/.
 */
enum LogLevel: string
{
    case Debug = 'debug';
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
}
