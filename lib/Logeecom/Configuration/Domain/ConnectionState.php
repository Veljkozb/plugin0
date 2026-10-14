<?php

namespace Logeecom\Configuration\Domain;

/**
 * The two states of Requirement 2.1. Only a verification the provider accepted leads to Connected; disconnecting
 * and changing the environment lead back to NotConnected.
 */
enum ConnectionState: string
{
    case NotConnected = 'not_connected';
    case Connected = 'connected';
}
