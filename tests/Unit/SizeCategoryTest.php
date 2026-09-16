<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\SizeCategory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Categoria di taglia dedotta dal feed: normali (adulti), GS (ragazzi),
 * PS (bambini). Il feed non la dichiara, quindi si legge dalle sigle nel
 * nome, dal size_mapper quando è univoco e infine dalle taglie.
 */
final class SizeCategoryTest extends TestCase
{
    /** @return list<array{string, string|null, list<string>, string}> */
    public static function productProvider(): array
    {
        return [
            // nome, size_mapper, taglie EU, categoria attesa
            'sigla GS in coda al nome' => ["Nike Dunk Low 'Panda' (GS)", 'Nike MENS/GS', ['36', '37.5', '38'], SizeCategory::GS],
            'sigla GS senza parentesi' => ['Nike Air Force 1 GS White', 'Nike MENS', ['36', '38'], SizeCategory::GS],
            'sigla PS' => ["Nike Dunk Low 'Panda' (PS)", 'Nike MENS/GS', ['28', '30'], SizeCategory::PS],
            'toddler → PS' => ["Jordan 1 Mid 'Black' (TD)", null, ['19', '21'], SizeCategory::PS],
            'dicitura estesa' => ['Nike Air Max Big Kids Shoes', null, ['37.5'], SizeCategory::GS],
            'J finale adidas' => ["adidas Gazelle Indoor J 'Better Scarlet'", 'Adidas MENS/GS', ['35.5', '36'], SizeCategory::GS],
            'J di una collaborazione NON è junior' => ["Jordan 1 Retro High OG 'J Balvin'", 'Nike MENS', ['42', '43'], SizeCategory::ADULT],
            'adulto esplicito' => ["Wmns Nike Dunk Low 'Panda'", 'Nike MENS', ['38', '39'], SizeCategory::ADULT],
            'mapper univoco' => ['Nike Dunk Low Panda', 'Nike GS', ['37', '38'], SizeCategory::GS],
            'mapper ambiguo → taglie' => ["adidas Samba OG 'Cloud White'", 'Adidas MENS/GS', ['40', '41'], SizeCategory::ADULT],
            'solo taglie da bambino' => ['adidas Superstar', 'Adidas KIDS/PS', ['28', '31', '34'], SizeCategory::PS],
            'taglie adulte' => ['Nike Dunk Low Panda', 'Nike MENS', ['40', '44.5'], SizeCategory::ADULT],
            'taglie miste frazionarie' => ['New Balance 550', 'New Balance MENS', ['40 2/3', '41 1/3'], SizeCategory::ADULT],
            'senza dati → normali' => ['', null, [], SizeCategory::ADULT],
        ];
    }

    /** @param list<string> $sizes */
    #[DataProvider('productProvider')]
    public function testClassify(string $name, ?string $mapper, array $sizes, string $expected): void
    {
        self::assertSame($expected, SizeCategory::classify($name, $mapper, $sizes));
    }

    /** La sigla più specifica vince: "(PS)" batte "Wmns" nello stesso nome. */
    public function testSmallestSizeMarkerWins(): void
    {
        self::assertSame(SizeCategory::PS, SizeCategory::classify('Wmns Air Force 1 (PS)', 'Nike MENS', ['32']));
        self::assertSame(SizeCategory::GS, SizeCategory::classify('Wmns Air Force 1 (GS)', 'Nike MENS', ['38']));
    }

    /** La colonna size_category accetta solo le tre categorie note. */
    public function testNormalizeFallsBackToAdult(): void
    {
        self::assertSame(SizeCategory::GS, SizeCategory::normalize('gs'));
        self::assertSame(SizeCategory::ADULT, SizeCategory::normalize(''));
        self::assertSame(SizeCategory::ADULT, SizeCategory::normalize('kids'));
    }
}
