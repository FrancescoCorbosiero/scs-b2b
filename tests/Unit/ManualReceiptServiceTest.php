<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Repository\ManualReceiptRepository;
use App\Repository\ProductRepository;
use App\Repository\UserRepository;
use App\Repository\VatRateRepository;
use App\Service\ManualReceiptService;
use App\Service\OrderMailer;
use App\Service\ReceiptService;
use App\Service\VatService;
use App\Support\Config;
use App\Support\Lang;
use App\Support\SmtpMailer;
use App\Support\TwigExtension;
use App\Tests\Support\TestDb;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Pro-forma manuali (/admin/proforma): validazione del form, totali e IVA
 * come nelle richieste d'ordine, numerazione condivisa con le ricevute degli
 * ordini, PDF/email e ricerca SKU senza costi del fornitore.
 */
final class ManualReceiptServiceTest extends TestCase
{
    private const IBAN = 'IT60X0542811101000000123456';

    private PDO $pdo;
    private Lang $lang;
    private Environment $twig;
    private ReceiptService $receiptService;
    private ManualReceiptRepository $repository;
    private ManualReceiptService $service;

    protected function setUp(): void
    {
        $this->pdo = TestDb::create();
        $this->service = $this->makeService(chargeVat: false);
    }

    private function makeService(bool $chargeVat): ManualReceiptService
    {
        $root = dirname(__DIR__, 2);
        // SMTP volutamente NON configurato: l'invio deve fallire in modo esplicito
        $config = new Config([
            'ROOT_PATH' => $root,
            'BANK_ACCOUNT_HOLDER' => 'SHOES & CLOTHING RESELLING',
            'BANK_NAME' => 'Banca di Prova',
            'BANK_IBAN' => self::IBAN,
        ]);
        $this->lang = new Lang($root);
        $this->twig = new Environment(new FilesystemLoader($root . '/templates'), ['autoescape' => 'html']);
        $this->twig->addExtension(new TwigExtension($this->lang));
        $vat = new VatService(new VatRateRepository($this->pdo), $chargeVat);
        $this->receiptService = new ReceiptService($this->pdo, $this->twig, $this->lang, $config, $vat);
        $this->repository = new ManualReceiptRepository($this->pdo);

        return new ManualReceiptService(
            $this->repository,
            $this->receiptService,
            $vat,
            new OrderMailer($config, $this->twig, $this->lang, $this->receiptService, new SmtpMailer($config)),
            new ProductRepository($this->pdo),
            new UserRepository($this->pdo),
            $this->lang,
            $this->pdo,
            new NullLogger(),
        );
    }

    /**
     * Corpo POST valido: due righe vere e una vuota in mezzo (ignorata).
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function input(array $overrides = []): array
    {
        return $overrides + [
            'customer_name' => 'Mario Rossi',
            'company' => 'Rossi Sneakers',
            'email' => 'mario.rossi@example.it',
            'phone' => '+393401234567',
            'address_street' => 'Via Roma 1',
            'address_city' => 'Milano',
            'address_zip' => '20121',
            'country' => 'IT',
            'vat_number' => 'it 01234567890',
            'locale' => 'it',
            'shipping' => '10,00',
            'notes' => "Ritiro in sede\r\nGrazie!",
            'show_bank' => '1',
            'lines' => [
                ['sku' => 'NK1001', 'name' => 'Nike Dunk Low Panda', 'size_eu' => '42', 'size_us' => '8.5', 'barcode' => '0195866123456', 'qty' => '2', 'unit_price' => '89,90'],
                ['sku' => '', 'name' => '', 'size_eu' => '', 'size_us' => '', 'barcode' => '', 'qty' => '1', 'unit_price' => ''],
                ['sku' => '', 'name' => 'Lacci di ricambio', 'size_eu' => '', 'size_us' => '', 'barcode' => '', 'qty' => '3', 'unit_price' => '4.5'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validData(array $input): array
    {
        $result = $this->service->validate($input);
        self::assertTrue($result['ok'], implode("\n", $result['errors']));
        self::assertNotNull($result['data']);

        return $result['data'];
    }

    /** @return array<string, mixed> */
    private function created(array $overrides = []): array
    {
        $id = $this->service->create($this->validData(self::input($overrides)));
        $receipt = $this->repository->find($id);
        self::assertNotNull($receipt);

        return $receipt;
    }

    private static function text(string $html): string
    {
        return (string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    public function testLinesAndTotalsAreComputedLikeAnOrderRequest(): void
    {
        $data = $this->validData(self::input());

        // 2 × 89,90 + 3 × 4,50 = 193,30 di merce + 10,00 di spedizione
        self::assertSame(5, $data['total_items']);
        self::assertSame('193.30', $data['total_amount']);
        self::assertSame('10.00', $data['shipping_amount']);
        // Italia con VAT_ON_ORDER=0: schema domestic, nessuna imposta addebitata
        self::assertSame('domestic', $data['vat_scheme']);
        self::assertSame('0.00', $data['vat_rate']);
        self::assertSame('0.00', $data['vat_amount']);
        self::assertSame('203.30', $data['total_gross']);
        self::assertSame('IT01234567890', $data['vat_number'], 'P.IVA normalizzata come negli ordini');
        self::assertSame("Ritiro in sede\nGrazie!", $data['notes']);
        self::assertTrue($data['show_bank']);

        $lines = json_decode((string) $data['lines_json'], true);
        self::assertCount(2, $lines, 'la riga vuota viene ignorata');
        self::assertSame(['sku' => 'NK1001', 'name' => 'Nike Dunk Low Panda', 'size_eu' => '42', 'size_us' => '8.5',
            'barcode' => '0195866123456', 'qty' => 2, 'unit_price' => '89.90', 'subtotal' => '179.80'], $lines[0]);
        self::assertSame('4.50', $lines[1]['unit_price']);
        self::assertSame('13.50', $lines[1]['subtotal']);
    }

    public function testWithVatOnOrderTheItalianVatIsCharged(): void
    {
        $data = $this->makeService(chargeVat: true)->validate(self::input())['data'];

        self::assertNotNull($data);
        self::assertSame('22.00', $data['vat_rate']);
        self::assertSame('44.73', $data['vat_amount'], '22% di 203,30 (merce + spedizione)');
        self::assertSame('248.03', $data['total_gross']);
    }

    public function testEuCustomersWithVatNumberAreReverseCharge(): void
    {
        $data = $this->makeService(chargeVat: true)->validate(self::input(['country' => 'DE', 'vat_number' => 'DE123456789']))['data'];

        self::assertNotNull($data);
        self::assertSame('reverse_charge', $data['vat_scheme']);
        self::assertSame('0.00', $data['vat_amount']);
        self::assertSame('DE', $data['country_code']);
    }

    public function testInvalidInputListsEveryErrorWithTheRowNumberOnScreen(): void
    {
        $result = $this->service->validate(self::input([
            'customer_name' => '',
            'email' => 'non-una-email',
            'vat_number' => '',
            'shipping' => '-3',
            'lines' => [
                ['name' => 'Ok', 'qty' => '1', 'unit_price' => '10'],
                ['name' => '', 'qty' => '1', 'unit_price' => ''],
                ['sku' => 'X1', 'name' => '', 'qty' => '0', 'unit_price' => 'abc'],
            ],
        ]));

        self::assertFalse($result['ok']);
        self::assertNull($result['data']);
        $errors = implode("\n", $result['errors']);
        self::assertStringContainsString('nome del cliente', $errors);
        self::assertStringContainsString('Email non valida', $errors);
        self::assertStringContainsString('Partita IVA obbligatoria', $errors);
        self::assertStringContainsString('Spedizione', $errors);
        self::assertStringContainsString('Riga 3: la descrizione è obbligatoria', $errors);
        self::assertStringContainsString('Riga 3: quantità', $errors);
        self::assertStringContainsString('Riga 3: prezzo unitario non valido', $errors);
        self::assertStringNotContainsString('Riga 2', $errors, 'la riga vuota non è un errore');
        self::assertCount(3, $result['form']['lines'], 'il form torna con le righe come inviate');
    }

    public function testAtLeastOneLineIsRequired(): void
    {
        $result = $this->service->validate(self::input(['lines' => [['name' => '', 'qty' => '1', 'unit_price' => '']]]));

        self::assertFalse($result['ok']);
        self::assertSame(['Aggiungi almeno una riga.'], $result['errors']);
    }

    public function testAFreeLineAtZeroIsAllowed(): void
    {
        $data = $this->validData(self::input(['lines' => [['name' => 'Omaggio', 'qty' => '1', 'unit_price' => '0']]]));

        self::assertSame('0.00', $data['total_amount']);
    }

    public function testManualReceiptsShareTheNumberSeriesOfOrderReceipts(): void
    {
        $year = date('Y');
        self::assertSame("PF-{$year}-0001", $this->receiptService->assignNumber(), 'ricevuta di un ordine confermato');

        $receipt = $this->created();
        self::assertSame("PF-{$year}-0002", $receipt['receipt_number']);
        self::assertSame('issued', $receipt['status']);

        self::assertSame("PF-{$year}-0003", $this->receiptService->assignNumber(), 'la serie prosegue per gli ordini');
    }

    public function testAFailedInsertDoesNotConsumeANumber(): void
    {
        $year = date('Y');
        // un numero già occupato (es. inserito a mano) fa fallire l'insert…
        $this->repository->insert("PF-{$year}-0001", $this->validData(self::input()));

        try {
            $this->service->create($this->validData(self::input()));
            self::fail('attesa la violazione del vincolo di unicità');
        } catch (\PDOException) {
        }

        // …e il contatore torna com'era: nessun numero bruciato
        $last = $this->pdo->query('SELECT last_number FROM receipt_counters')->fetchColumn();
        self::assertFalse($last);
    }

    public function testEditingKeepsTheNumberAndACancelledReceiptIsFrozen(): void
    {
        $receipt = $this->created();
        $form = $this->service->formFromReceipt($receipt);
        $form['lines'][0]['qty'] = '4';
        $form['show_bank'] = '1';

        self::assertTrue($this->service->update($receipt['id'], $this->validData($form)));
        $updated = $this->repository->find($receipt['id']);
        self::assertNotNull($updated);
        self::assertSame($receipt['receipt_number'], $updated['receipt_number']);
        self::assertSame(7, $updated['total_items']);

        self::assertTrue($this->service->cancel($receipt['id']));
        self::assertFalse($this->service->cancel($receipt['id']), 'già annullata');
        self::assertFalse($this->service->update($receipt['id'], $this->validData(self::input())), 'annullata: non si modifica');
        $cancelled = $this->repository->find($receipt['id']);
        self::assertNotNull($cancelled);
        self::assertSame('cancelled', $cancelled['status']);
        self::assertSame(7, $cancelled['total_items']);
    }

    public function testTheEditFormRoundTripsToTheSameData(): void
    {
        $receipt = $this->created(['show_bank' => '']);
        $form = $this->service->formFromReceipt($receipt);
        self::assertFalse($form['show_bank']);
        self::assertSame('89,90', $form['lines'][0]['unit_price']);
        self::assertSame('10,00', $form['shipping']);

        // il form non manda show_bank quando la casella è spenta: come il browser
        unset($form['show_bank']);
        $again = $this->validData($form);
        $original = $this->validData(self::input(['show_bank' => '']));
        self::assertSame($original, $again);
    }

    public function testThePrefillFromACustomerAccountUsesItsData(): void
    {
        $this->pdo->exec("INSERT INTO users (email, password_hash, name, company, phone, vat_number, address_street,
            address_city, address_zip, country_code, locale, is_active, created_at, updated_at)
            VALUES ('anna@example.de', NULL, 'Anna Schmidt', 'Schmidt GmbH', '+4930123', 'DE123456789',
            'Hauptstr. 5', 'Berlin', '10115', 'DE', 'en', 1, '2026-09-01 10:00:00', '2026-09-01 10:00:00')");
        $user = (new UserRepository($this->pdo))->findByEmail('anna@example.de');
        self::assertNotNull($user);

        $form = $this->service->formFromUser($user);
        self::assertSame('Anna Schmidt', $form['customer_name']);
        self::assertSame('DE', $form['country']);
        self::assertSame('en', $form['locale']);

        $form['lines'] = [['name' => 'Samba OG', 'qty' => '1', 'unit_price' => '100']];
        $data = $this->validData($form);
        self::assertSame((int) $user['id'], $data['user_id']);
        self::assertSame('reverse_charge', $data['vat_scheme']);
    }

    public function testThePdfIsAManualDocumentWithPaymentDetailsAndNotes(): void
    {
        $receipt = $this->created();
        $text = self::text($this->receiptService->renderHtml($receipt, 'it', ['manual' => true]));

        self::assertStringContainsString($receipt['receipt_number'], $text);
        self::assertStringNotContainsString('Richiesta d\'ordine', $text, 'nessuna richiesta d\'ordine dietro');
        self::assertStringContainsString('Via Roma 1, 20121 Milano', $text);
        self::assertStringContainsString('Ritiro in sede', $text);
        self::assertStringContainsString(self::IBAN, $text);
        self::assertStringContainsString('Pro-forma ' . $receipt['receipt_number'], $text, 'causale = numero');
        self::assertStringContainsString('il totale di questo documento sarebbe di 248,03 €', $text, 'Nota IVA del documento');
        self::assertStringContainsString('0195866123456', $text, 'barcode della riga precompilata');

        $pdf = $this->service->pdf($receipt);
        self::assertStringStartsWith('%PDF', $pdf['content']);
        self::assertSame('ricevuta-' . $receipt['receipt_number'] . '.pdf', $pdf['name']);
    }

    public function testThePdfHidesPaymentDetailsWhenNotWantedOrCancelled(): void
    {
        $paid = $this->created(['show_bank' => '', 'lines' => [['name' => 'Omaggio', 'qty' => '1', 'unit_price' => '0']]]);
        $text = self::text($this->receiptService->renderHtml($paid, 'it', ['manual' => true]));
        self::assertStringNotContainsString(self::IBAN, $text);
        self::assertStringNotContainsString('Barcode', $text, 'nessuna riga con barcode: niente colonna');

        $cancelled = $this->created();
        $this->service->cancel($cancelled['id']);
        $cancelled = $this->repository->find($cancelled['id']);
        self::assertNotNull($cancelled);
        $text = self::text($this->receiptService->renderHtml($cancelled, 'en', ['manual' => true]));
        self::assertStringContainsString('Cancelled document', $text);
        self::assertStringNotContainsString(self::IBAN, $text);
    }

    public function testOrderReceiptsAreUnchanged(): void
    {
        $order = $this->created() + [];
        $order['id'] = 4;
        $text = self::text($this->receiptService->renderHtml($order, 'it'));

        self::assertStringContainsString('Richiesta d\'ordine: #4', $text);
        self::assertStringNotContainsString(self::IBAN, $text, 'la ricevuta di un ordine arriva a pagamento avvenuto');
        self::assertStringNotContainsString('Ritiro in sede', $text);
    }

    public function testTheCustomerEmailCarriesTotalsAndPaymentDetailsInTheReceiptLanguage(): void
    {
        $receipt = $this->created(['locale' => 'en']);
        $this->lang->setLocale('en');
        $html = $this->twig->render('emails/customer_proforma.twig', [
            'doc' => $receipt,
            'bank' => ['holder' => 'SHOES & CLOTHING RESELLING', 'name' => '', 'iban' => self::IBAN, 'bic' => ''],
            'company_name' => 'SHOES & CLOTHING RESELLING', 'company_owner' => '', 'contact_email' => 'info@example.com',
            'contact_phone' => '+39 392 772 0691', 'contact_whatsapp' => '',
        ]);
        $text = self::text($html);

        self::assertStringContainsString('Pro-forma no. ' . $receipt['receipt_number'], $text);
        self::assertStringContainsString('Total to pay 203,30 €', $text);
        self::assertStringContainsString(self::IBAN, $text);
        self::assertStringContainsString('Pro-forma ' . $receipt['receipt_number'], $text);
        self::assertStringContainsString('Ritiro in sede', $text);
    }

    public function testSendingNeedsAnIssuedReceiptWithAnEmailAndReportsSmtpFailures(): void
    {
        $receipt = $this->created();
        $result = $this->service->send($receipt);
        self::assertFalse($result['ok']);
        self::assertStringContainsString('SMTP', (string) $result['error']);
        $after = $this->repository->find($receipt['id']);
        self::assertNotNull($after);
        self::assertNull($after['email_sent_at'], 'non risulta inviata');

        $noEmail = $this->created(['email' => '']);
        self::assertStringContainsString('email valida', (string) $this->service->send($noEmail)['error']);

        $this->service->cancel($receipt['id']);
        $cancelled = $this->repository->find($receipt['id']);
        self::assertNotNull($cancelled);
        self::assertStringContainsString('annullata', (string) $this->service->send($cancelled)['error']);
    }

    public function testSkuLookupGivesTheListPriceButNeverTheSupplierCost(): void
    {
        TestDb::seedProduct($this->pdo, 'NK1001', 'Nike Dunk Low Panda', 'Nike', [
            ['size_eu' => '42', 'size_us' => '8.5', 'quantity' => 5, 'offer_price' => '61.11', 'price' => '123.45'],
            ['size_eu' => '43', 'size_us' => '9.5', 'quantity' => 0, 'offer_price' => '61.11', 'price' => '123.45'],
        ]);

        $found = $this->service->lookupSku(' NK1001 ');
        self::assertNotNull($found);
        self::assertSame('Nike Dunk Low Panda', $found['name']);
        self::assertCount(2, $found['sizes']);
        self::assertSame('123.45', $found['sizes'][0]['price']);
        $json = (string) json_encode($found);
        self::assertStringNotContainsString('61.11', $json);
        self::assertStringNotContainsString('offer', $json);

        self::assertNull($this->service->lookupSku('NON-ESISTE'));
        self::assertNull($this->service->lookupSku(''));
    }

    public function testTheListSearchesByNumberCustomerAndEmail(): void
    {
        $first = $this->created();
        $this->created(['customer_name' => 'Luigi Verdi', 'company' => '', 'email' => 'luigi@example.it']);

        self::assertSame(2, $this->repository->paginate(1, 25)['total']);
        $byName = $this->repository->paginate(1, 25, 'verdi');
        self::assertSame(1, $byName['total']);
        self::assertSame('Luigi Verdi', $byName['items'][0]['customer_name']);
        self::assertSame(1, $this->repository->paginate(1, 25, $first['receipt_number'])['total']);
    }
}
