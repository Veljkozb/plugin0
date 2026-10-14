<?php

namespace Plugin0\Adapter;

use Logeecom\Settings\Domain\LogLevel;
use Logeecom\Settings\Logging\LogLevelApplierInterface;
use Plugin0\Infrastructure\CoreLogLevel;
use Plugin0\Infrastructure\LoggerService;

/**
 * Demo "stavi nivo logovanja na snagu do kraja zahteva" (u demu app/Logging/StackLogLevelApplier.php nad Monolog
 * kanalima) nad LoggerService-om: on podešeni nivo čita jednom po PHP procesu i pamti ga, pa bi se posle čuvanja
 * podešavanja do kraja zahteva pisalo po starom nivou. SettingsService zove apply() oko zapisa o promeni.
 */
final class LogLevelApplier implements LogLevelApplierInterface
{
    public function apply(LogLevel $level): void
    {
        LoggerService::useMinLogLevel(CoreLogLevel::fromSetting($level));
    }
}
