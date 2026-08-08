<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Support\SmtpMailer;
use PHPUnit\Framework\TestCase;

final class SmtpMailerTest extends TestCase
{
    public function testSingleAddressPassesThrough(): void
    {
        self::assertSame(['info@example.com'], SmtpMailer::recipients('info@example.com'));
    }

    public function testCommaListWithSpacesAndSemicolons(): void
    {
        self::assertSame(
            ['info@example.com', 'collab@example.com', 'boss@example.com'],
            SmtpMailer::recipients(' info@example.com, collab@example.com ; boss@example.com '),
        );
    }

    public function testInvalidEntriesAreDroppedWithoutLosingTheOthers(): void
    {
        self::assertSame(
            ['info@example.com'],
            SmtpMailer::recipients('info@example.com, refuso-senza-chiocciola, ,'),
        );
    }

    public function testDuplicatesAreRemoved(): void
    {
        self::assertSame(
            ['info@example.com'],
            SmtpMailer::recipients('info@example.com,info@example.com'),
        );
    }

    public function testEmptyOrAllInvalidGivesEmptyList(): void
    {
        self::assertSame([], SmtpMailer::recipients(''));
        self::assertSame([], SmtpMailer::recipients('non-valido, nemmeno-questo'));
    }
}
