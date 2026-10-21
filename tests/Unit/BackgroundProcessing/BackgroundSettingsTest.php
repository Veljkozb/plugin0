<?php

namespace Tests\Unit\BackgroundProcessing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugin0\BackgroundProcessing\AutoCapture\AutoCapturePaymentTask;
use Plugin0\BackgroundProcessing\AutoCapture\AutoCaptureSetting;
use Plugin0\BackgroundProcessing\LogCleanup\LogCleaner;
use Plugin0\BackgroundProcessing\LogCleanup\LogRetentionSetting;

/**
 * The pure parts of the background settings and tasks: how the two Settings fields map onto the Core values, how the
 * cleanup cutoff follows the retention in force, and that a capture task keeps its payment through the queue.
 */
final class BackgroundSettingsTest extends TestCase
{
    public function testAutoCaptureOnIsTheShortestCoreDelayAndOffIsNever(): void
    {
        self::assertSame(60, AutoCaptureSetting::coreValue(true));
        self::assertSame(-1, AutoCaptureSetting::coreValue(false));
        self::assertTrue(AutoCaptureSetting::isOn(60));
        // A longer delay set outside the Settings page still reads as "on".
        self::assertTrue(AutoCaptureSetting::isOn(1440));
        self::assertFalse(AutoCaptureSetting::isOn(-1));
    }

    public function testLogRetentionOffersOneTwoSevenAndFourteenDaysWithFourteenAsDefault(): void
    {
        self::assertSame([1, 2, 7, 14], LogRetentionSetting::CHOICES);
        self::assertSame(14, LogRetentionSetting::DEFAULT_DAYS);
        self::assertTrue(LogRetentionSetting::isValid(7));
        self::assertFalse(LogRetentionSetting::isValid(3));
        self::assertFalse(LogRetentionSetting::isValid(0));
        self::assertFalse(LogRetentionSetting::isValid(30));
    }

    public function testFormAlsoShowsAStoredValueThatIsNotOffered(): void
    {
        self::assertSame([1, 2, 7, 14], LogRetentionSetting::choicesWith(7));
        self::assertSame([1, 2, 3, 7, 14], LogRetentionSetting::choicesWith(3));
    }

    /** @return array<string, array{int, int}> */
    public static function retentions(): array
    {
        return [
            '1 day' => [1, 86400],
            '2 days' => [2, 172800],
            '7 days' => [7, 604800],
            '14 days' => [14, 1209600],
        ];
    }

    #[DataProvider('retentions')]
    public function testCutoffIsTheRetentionInForceBeforeNow(int $days, int $seconds): void
    {
        $now = 1792498658;

        self::assertSame($now - $seconds, LogCleaner::cutoff($now, $days));
    }

    public function testPsLogConditionTouchesOnlyPluginLinesOlderThanTheCutoff(): void
    {
        self::assertSame(
            "message LIKE 'WORLDLINE [%' AND date_add < '2026-10-06 14:17:38'",
            LogCleaner::psLogCondition('2026-10-06 14:17:38')
        );
    }

    public function testPsLogConditionRejectsAnythingButADate(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        LogCleaner::psLogCondition("2026-10-06' OR '1'='1");
    }

    public function testCaptureTaskKeepsItsPaymentThroughSerialization(): void
    {
        $task = new AutoCapturePaymentTask('1', '10301114880_0', '19');

        $copy = AutoCapturePaymentTask::fromArray($task->toArray());

        self::assertInstanceOf(AutoCapturePaymentTask::class, $copy);
        self::assertSame('1', $copy->getStoreId());
        self::assertSame('10301114880_0', $copy->getPaymentId());
        self::assertSame('19', $copy->getMerchantReference());
        // Core finds tasks by their short class name; it must not collide with the Core tasks it replaces.
        self::assertSame('AutoCapturePaymentTask', AutoCapturePaymentTask::getClassName());
    }
}
