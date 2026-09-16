<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Categoria di taglia di un prodotto: "normali" (adulti), GS (grade school,
 * ragazzi) e PS (pre-school, bambini — ci rientrano anche le TD/toddler).
 *
 * Il feed GoldenSneakers non ha un campo dedicato (docs/03): la categoria si
 * deduce, in quest'ordine, da
 *   1. le sigle nel NOME prodotto: "(GS)", "(PS)", "(TD)", "Big Kids",
 *      "Toddler", la "J" finale di adidas ("Gazelle Indoor J")…;
 *   2. il `size_mapper_name`, ma solo quando è univoco: "Adidas MENS/GS"
 *      copre entrambe le scale e non decide nulla, "Nike GS" sì;
 *   3. le taglie EU: fino alla 35 sono per forza da bambino. Il range GS
 *      (35,5–40) si sovrappone all'adulto, quindi senza sigle resta "normali".
 *
 * Il risultato è salvato in `products.size_category` al sync (o al reprice) e
 * alimenta il filtro del catalogo e le regole margine per categoria.
 */
final class SizeCategory
{
    public const ADULT = 'adult';
    public const GS = 'gs';
    public const PS = 'ps';

    /** @var list<string> */
    public const ALL = [self::ADULT, self::GS, self::PS];

    /** Sigle bambino: PS/TD di Nike, più le diciture estese. */
    private const PS_TOKENS = ['ps', 'td', 'tdv', 'psv', 'bt', 'bp'];
    private const PS_PHRASES = ['pre school', 'preschool', 'little kid', 'toddler', 'infant', 'crib'];

    /** Sigle ragazzo: GS di Nike (BG/GG nelle vecchie referenze), "junior". */
    private const GS_TOKENS = ['gs', 'bg', 'gg', 'jr', 'junior', 'juniors'];
    private const GS_PHRASES = ['grade school', 'big kid'];

    /** Sigle adulto: rendono univoco un size_mapper ("Nike MENS"). */
    private const ADULT_TOKENS = ['mens', 'men', 'wmns', 'womens', 'women', 'unisex', 'adult', 'adults'];

    /** Sopra la 35 EU si entra nel range GS/adulto: solo sotto è certo il bambino. */
    private const PS_MAX_EU = 35.0;

    /**
     * @param list<string> $sizesEu taglie EU del prodotto, come arrivano dal feed ("36 2/3")
     */
    public static function classify(string $name, ?string $sizeMapper, array $sizesEu): string
    {
        // le sigle stanno un po' ovunque nel nome ("Dunk Low 'Panda' (GS)",
        // "Dunk Low GS"): si cercano sul nome intero
        $fromName = self::fromMarkers($name);
        if ($fromName !== null) {
            return $fromName;
        }
        // adidas marca i junior con una "J" finale dopo il modello
        if (self::endsWithToken(self::modelPart($name), 'j')) {
            return self::GS;
        }

        $fromMapper = self::fromMarkers((string) $sizeMapper, strict: true);
        if ($fromMapper !== null) {
            return $fromMapper;
        }

        return self::fromSizes($sizesEu);
    }

    /** Etichetta valida per la colonna `size_category` (fallback: adulti). */
    public static function normalize(string $category): string
    {
        return in_array($category, self::ALL, true) ? $category : self::ADULT;
    }

    /**
     * Categoria dalle sigle di una stringa. In modalità `strict` (size_mapper,
     * che elenca le scale supportate) una sigla vale solo se non convive con
     * sigle di categorie diverse: "Adidas MENS/GS" resta indeciso.
     */
    private static function fromMarkers(string $text, bool $strict = false): ?string
    {
        $haystack = self::normalizeText($text);
        if ($haystack === '') {
            return null;
        }
        $tokens = explode(' ', $haystack);

        $found = [];
        foreach ([self::PS => [self::PS_TOKENS, self::PS_PHRASES],
                  self::GS => [self::GS_TOKENS, self::GS_PHRASES],
                  self::ADULT => [self::ADULT_TOKENS, []]] as $category => [$catTokens, $phrases]) {
            foreach ($catTokens as $token) {
                if (in_array($token, $tokens, true)) {
                    $found[$category] = true;
                }
            }
            foreach ($phrases as $phrase) {
                if (str_contains($haystack, $phrase)) {
                    $found[$category] = true;
                }
            }
        }

        if ($found === [] || ($strict && count($found) > 1)) {
            return null;
        }

        // il bambino vince sul ragazzo, il ragazzo sull'adulto: la sigla più
        // specifica è sempre quella "piccola" ("Wmns … (PS)" è comunque PS)
        return isset($found[self::PS]) ? self::PS : (isset($found[self::GS]) ? self::GS : self::ADULT);
    }

    /** Taglie tutte ≤ 35 EU → bambino; altrimenti "normali". */
    private static function fromSizes(array $sizesEu): string
    {
        $max = null;
        foreach ($sizesEu as $size) {
            $value = self::parseEuSize((string) $size);
            if ($value !== null && ($max === null || $value > $max)) {
                $max = $value;
            }
        }

        return $max !== null && $max <= self::PS_MAX_EU ? self::PS : self::ADULT;
    }

    /**
     * Nome senza la colorway tra virgolette: "adidas Gazelle Indoor J 'Better
     * Scarlet'" → "adidas Gazelle Indoor J". Serve solo alla "J" finale di
     * adidas, che altrimenti confonderebbe le collaborazioni ("… 'J Balvin'").
     */
    private static function modelPart(string $name): string
    {
        $cut = preg_split('/["\x{2018}\x{2019}\x{201C}\x{201D}\']/u', $name, 2);

        return is_array($cut) ? trim($cut[0]) : trim($name);
    }

    private static function endsWithToken(string $text, string $token): bool
    {
        $tokens = explode(' ', self::normalizeText($text));

        return end($tokens) === $token;
    }

    /** Minuscolo, punteggiatura → spazi: "(GS)" e "MENS/GS" diventano token isolati. */
    private static function normalizeText(string $text): string
    {
        $clean = preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($text));

        return trim(is_string($clean) ? $clean : '');
    }

    /** "36 2/3" → 36.0, "40.5" → 40.5, "" → null. */
    private static function parseEuSize(string $size): ?float
    {
        if (preg_match('/\d+(?:[.,]\d+)?/', $size, $m) !== 1) {
            return null;
        }

        return (float) str_replace(',', '.', $m[0]);
    }
}
