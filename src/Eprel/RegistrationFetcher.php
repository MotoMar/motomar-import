<?php

declare(strict_types=1);

namespace App\Eprel;

/**
 * Rejestracje po numerze z otwartego API EPREL (bez klucza, limit 50 tys. żądań
 * na 5 minut), równolegle przez `curl_multi`.
 *
 * Bez nagłówka `Referer` wskazującego stronę produktu API odmawia każdego żądania
 * tak, jakby rejestracji nie było — dlatego go wysyłamy.
 *
 * Błąd połączenia, 429 i 5xx idą do ponowienia w kolejnej rundzie, po
 * odczekaniu; po ostatniej rundzie zostają w wyniku takie, jakie były. Gołe 403
 * nie jest ponawiane, a gdy pierwsze numery nie dają żadnej rozstrzygniętej
 * odpowiedzi, przebieg się przerywa (patrz `PROBE`).
 */
final class RegistrationFetcher
{
    public const string BASE_URL = 'https://eprel.ec.europa.eu';

    /** Bez `User-Agent` CloudFront EPREL-u odpowiada 403 na każde żądanie. */
    public const string USER_AGENT = 'motomar-import (sprawdzanie cennika opon)';

    /** Tyle numerów idzie najpierw; gdy żaden nie da rozstrzygniętej odpowiedzi, reszta nie idzie wcale. */
    private const int PROBE = 20;

    /** Co tyle odpowiedzi postęp z tempem; przy ~50 tys. numerów inaczej nie widać, czy coś idzie. */
    private const int PROGRESS_EVERY = 5000;

    /** @var \Closure(int): void */
    private \Closure $sleep;

    /** @var \Closure(list<int>, \Closure(int, Answer): void): void */
    private \Closure $transport;

    /**
     * @param list<int>                                                  $retryWaits sekundy przed kolejnymi rundami ponowień
     * @param (\Closure(int): void)|null                                 $sleep      do testów; domyślnie `sleep()`
     * @param (\Closure(list<int>, \Closure(int, Answer): void): void)|null $transport do testów; domyślnie HTTP przez `curl_multi`
     */
    public function __construct(
        private readonly ?ResponseCache $cache,
        private readonly int $concurrency = 6,
        private readonly array $retryWaits = [10, 30, 90, 180],
        ?\Closure $sleep = null,
        ?\Closure $transport = null,
    ) {
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
        $this->transport = $transport ?? $this->round(...);
    }

    /**
     * Odpowiedź trafia do cache'u w chwili, gdy przychodzi — przerwany przebieg
     * nie traci tego, co już pobrał.
     *
     * @param list<int>                      $numbers
     * @param (callable(string): void)|null $log
     *
     * @return array<int, Answer>
     */
    public function fetch(array $numbers, ?callable $log = null): array
    {
        $log ??= static function (string $message): void {
        };
        $answers = [];
        $pending = [];

        foreach (array_values(array_unique($numbers)) as $number) {
            $cached = $this->cache?->get($number);

            if (null !== $cached) {
                $answers[$number] = $cached;
            } else {
                $pending[] = $number;
            }
        }

        $log(sprintf('z cache %d, do pobrania %d', count($answers), count($pending)));
        $started = microtime(true);
        $received = 0;
        $receive = function (int $number, Answer $answer) use (&$answers, &$received, $started, $log): void {
            $answers[$number] = $answer;

            if ($answer->isDecisive()) {
                $this->cache?->put($number, $answer);
            }

            if (0 === ++$received % self::PROGRESS_EVERY) {
                $log(sprintf('pobrane %d, %.0f/s', $received, $received / max(0.001, microtime(true) - $started)));
            }
        };

        if (count($pending) > self::PROBE) {
            $probe = array_slice($pending, 0, self::PROBE);
            ($this->transport)($probe, $receive(...));
            $decided = array_values(array_filter($probe, static fn (int $n): bool => $answers[$n]->isDecisive()));

            if ([] === $decided) {
                $first = $answers[$probe[0]];

                throw new \RuntimeException(sprintf(
                    'EPREL nie rozstrzygnął żadnego z %d pierwszych numerów (np. HTTP %d %s) — blokada albo zmiana API; przerywam, zanim ruch pogłębi blokadę.',
                    count($probe),
                    $first->status,
                    $first->apiCode() ?: $first->error,
                ));
            }

            $pending = array_values(array_diff($pending, $decided));
        }

        foreach ([0, ...$this->retryWaits] as $round => $wait) {
            if ([] === $pending) {
                break;
            }

            if ($round > 0) {
                $log(sprintf('ponawiam %d po %d s', count($pending), $wait));
                ($this->sleep)($wait);
            }

            ($this->transport)($pending, $receive(...));
            $pending = array_values(array_filter($pending, static fn (int $n): bool => $answers[$n]->isRetryable()));
        }

        return $answers;
    }

    public static function pageUrl(int $number): string
    {
        return self::BASE_URL . "/screen/product/tyres/{$number}";
    }

    /**
     * Jedna runda: wszystkie numery, najwyżej `$concurrency` żądań naraz.
     *
     * @param list<int>                    $numbers
     * @param \Closure(int, Answer): void $receive
     */
    private function round(array $numbers, \Closure $receive): void
    {
        $multi = curl_multi_init();
        $next = 0;
        $inFlight = [];

        try {
            while ($next < count($numbers) || [] !== $inFlight) {
                while ($next < count($numbers) && count($inFlight) < $this->concurrency) {
                    $number = $numbers[$next++];
                    $handle = curl_init(self::BASE_URL . "/api/products/tyres/{$number}");
                    curl_setopt_array($handle, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_CONNECTTIMEOUT => 10,
                        CURLOPT_TIMEOUT => 30,
                        CURLOPT_ENCODING => '',
                        CURLOPT_USERAGENT => self::USER_AGENT,
                        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Referer: ' . self::pageUrl($number)],
                    ]);
                    curl_multi_add_handle($multi, $handle);
                    $inFlight[spl_object_id($handle)] = $number;
                }

                do {
                    $code = curl_multi_exec($multi, $running);
                } while (CURLM_CALL_MULTI_PERFORM === $code);

                if ($running > 0) {
                    curl_multi_select($multi, 1.0);
                }

                while (false !== ($done = curl_multi_info_read($multi))) {
                    $handle = $done['handle'];
                    $number = $inFlight[spl_object_id($handle)];
                    $body = curl_multi_getcontent($handle);
                    unset($inFlight[spl_object_id($handle)]);
                    curl_multi_remove_handle($multi, $handle);
                    $receive($number, new Answer(
                        (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                        is_string($body) ? $body : '',
                        CURLE_OK === $done['result'] ? '' : curl_error($handle),
                    ));
                }
            }
        } finally {
            curl_multi_close($multi);
        }
    }
}
