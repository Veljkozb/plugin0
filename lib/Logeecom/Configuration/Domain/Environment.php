<?php

namespace Logeecom\Configuration\Domain;

/**
 * The provider environment the credentials belong to. The value is what the form sends and what is stored.
 *
 * The set is closed: a PSPID, an API key and a secret are issued for one environment and are not valid in the
 * other, which is why changing it drops the connection.
 */
enum Environment: string
{
    case Test = 'test';
    case Live = 'live';
}
