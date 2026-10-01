<?php

namespace Tests\Unit;

use App\Notifications\Push\MinishlinkWebPushSender;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Minishlink\WebPush\MessageSentReport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// #96 — classement de la réponse du service push : ce qui purge l'abonnement (404/410), ce qui se retente.
class WebPushResultTest extends TestCase
{
    /** @return array<string, array{int, bool}> */
    public static function refus(): array
    {
        return [
            // Revue du 01/10 : une erreur de configuration VAPID répond aussi 401/403, pour TOUS
            // les appareils — purger vidait les abonnements du club en un passage de drain.
            '401 clé VAPID refusée' => [401, false],
            '403 clé VAPID refusée' => [403, false],
            '404 abonnement inconnu' => [404, true],
            '410 abonnement expiré' => [410, true],
            '429 limite de débit' => [429, false],
            '500 panne du service' => [500, false],
        ];
    }

    #[DataProvider('refus')]
    public function test_refusal_classification(int $status, bool $expired): void
    {
        $report = new MessageSentReport(new Request('POST', 'https://push.example/abc'), new Response($status), false, 'refus');

        $result = MinishlinkWebPushSender::resultFor($report);

        $this->assertFalse($result->delivered);
        $this->assertSame($expired, $result->expired);
    }

    public function test_network_failure_without_response_is_retried(): void
    {
        $report = new MessageSentReport(new Request('POST', 'https://push.example/abc'), null, false, 'timeout');

        $result = MinishlinkWebPushSender::resultFor($report);

        $this->assertFalse($result->expired);
        $this->assertFalse($result->delivered);
    }

    public function test_accepted_push_is_delivered(): void
    {
        $report = new MessageSentReport(new Request('POST', 'https://push.example/abc'), new Response(201));

        $this->assertTrue(MinishlinkWebPushSender::resultFor($report)->delivered);
    }
}
