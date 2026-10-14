<?php

namespace Logeecom\Settings\Domain;

/**
 * The keys under which the settings are stored. The set is closed: Requirement 4.1 names three settings, and
 * a key the application does not know is never read or written, so no string like 'rows_per_page' is typed
 * anywhere else.
 */
enum SettingKey: string
{
    case DefaultCurrency = 'default_currency';
    case RowsPerPage = 'rows_per_page';
    case LogLevel = 'log_level';
}
