<?php

namespace BoringO11y\Httptheus\Recording;

use BoringO11y\Httptheus\Httptheus;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;

class LabelResolver
{
    /**
     * curl error numbers worth telling apart, mapped to the `reason` label.
     *
     * Guzzle's cURL handler passes $easy->errno through as the transfer stats'
     * handler error data, which is the only place the distinction survives —
     * a status class of "error" cannot say whether the name did not resolve,
     * the certificate expired, or the call simply ran out of time.
     */
    private const CURL_REASONS = [
        5 => 'dns',                 // CURLE_COULDNT_RESOLVE_PROXY
        6 => 'dns',                 // CURLE_COULDNT_RESOLVE_HOST
        7 => 'connection_refused',  // CURLE_COULDNT_CONNECT
        28 => 'timeout',            // CURLE_OPERATION_TIMEDOUT
        35 => 'tls',                // CURLE_SSL_CONNECT_ERROR
        51 => 'tls',                // CURLE_PEER_FAILED_VERIFICATION
        52 => 'network',            // CURLE_GOT_NOTHING
        55 => 'network',            // CURLE_SEND_ERROR
        56 => 'network',            // CURLE_RECV_ERROR
        58 => 'tls',                // CURLE_SSL_CERTPROBLEM
        60 => 'tls',                // CURLE_SSL_CACERT
        83 => 'tls',                // CURLE_SSL_ISSUER_ERROR
    ];

    /**
     * The label names carried by the duration histogram, in declared order.
     *
     * Order is part of the contract: the Prometheus client matches label values
     * positionally, and the text renderer emits them in the order given here.
     *
     * @return list<string>
     */
    public function names(): array
    {
        $names = [];

        foreach (['host', 'service', 'method', 'endpoint'] as $label) {
            if (config("httptheus.labels.{$label}")) {
                $names[] = $label;
            }
        }

        if (config('httptheus.labels.status_class')) {
            $names[] = 'status_class';
        }

        return $names;
    }

    /**
     * The error counter's labels: the histogram's, with `reason` in place of
     * `status_class`. A transfer that produced no response has no status to
     * classify, and `reason` is what replaces it.
     *
     * @return list<string>
     */
    public function errorNames(): array
    {
        $names = array_values(array_diff($this->names(), ['status_class']));
        $names[] = 'reason';

        return $names;
    }

    /**
     * @return list<string>
     */
    public function values(RequestInterface $request, ?int $status, bool $failed): array
    {
        $uri = $request->getUri();
        $values = [];

        foreach ($this->names() as $name) {
            $values[] = match ($name) {
                'host' => $uri->getHost(),
                'service' => $this->service($uri->getHost()),
                'method' => strtoupper($request->getMethod()),
                'endpoint' => $this->endpoint($request),
                'status_class' => $this->statusClass($status, $failed),
                // names() and this match have to stay in step. Falling through
                // silently would hand the Prometheus client the wrong number of
                // values, and the arity error it throws is caught and reported
                // by the recorder — leaving no metrics and no obvious cause.
                default => throw new InvalidArgumentException("Unknown httptheus label [{$name}]."),
            };
        }

        return $values;
    }

    /**
     * Derive the error counter's values from the histogram's, so the two can
     * never disagree about the host or endpoint of the same transfer.
     *
     * @param  list<string>  $durationValues
     * @return list<string>
     */
    public function errorValues(array $durationValues, string $reason): array
    {
        $combined = array_combine($this->names(), $durationValues);

        unset($combined['status_class']);
        $combined['reason'] = $reason;

        return array_values($combined);
    }

    public function ignoresHost(string $host): bool
    {
        $patterns = (array) config('httptheus.ignore_hosts', []);

        return $patterns !== [] && Str::is($patterns, $host);
    }

    public function statusClass(?int $status, bool $failed): string
    {
        if ($failed || $status === null) {
            return 'error';
        }

        return intdiv($status, 100) . 'xx';
    }

    /**
     * Classify a transfer that never produced a response.
     */
    public function reason(mixed $handlerErrorData): string
    {
        // Guzzle's cURL handler reports an int errno here, but a mock handler
        // or a custom one may hand back a string or a Throwable instead, and
        // neither can be classified any further than "something went wrong".
        if (! is_int($handlerErrorData)) {
            return 'other';
        }

        return self::CURL_REASONS[$handlerErrorData] ?? 'other';
    }

    public function service(string $host): string
    {
        /** @var array<string, array<int, string>|string> $services */
        $services = (array) config('httptheus.services', []);

        foreach ($services as $service => $patterns) {
            if (Str::is((array) $patterns, $host)) {
                return $service;
            }
        }

        return (string) config('httptheus.default_service', 'unknown');
    }

    public function endpoint(RequestInterface $request): string
    {
        $uri = $request->getUri();

        if (Httptheus::$endpointResolver !== null) {
            $resolved = (Httptheus::$endpointResolver)($uri, $request);

            // Null falls through to the built-in normaliser, so a resolver can
            // special-case one API and leave every other host alone.
            if (is_string($resolved) && $resolved !== '') {
                return $this->truncate($resolved);
            }
        }

        return $this->truncate($this->normalizePath($uri->getPath()));
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path, '/');

        if ($path === '') {
            return '/';
        }

        $maxSegments = max(1, (int) config('httptheus.endpoints.max_segments', 3));
        $segments = explode('/', $path);
        $dropped = count($segments) > $maxSegments;

        $segments = array_map(
            fn (string $segment) => $this->normalizeSegment($segment),
            array_slice($segments, 0, $maxSegments),
        );

        // The trailing `*` is not cosmetic: it says the label is a prefix, so
        // nobody reads /v1/users/:id as the endpoint that was actually called.
        $normalized = '/' . implode('/', $segments) . ($dropped ? '/*' : '');

        return $this->applyPatterns($normalized);
    }

    private function normalizeSegment(string $segment): string
    {
        if ($segment === '') {
            return $segment;
        }

        $identifierShaped = preg_match('/^\d+$/', $segment)
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $segment)
            || preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $segment)
            || preg_match('/^[0-9a-f]{32}$|^[0-9a-f]{40}$|^[0-9a-f]{64}$/i', $segment);

        if ($identifierShaped) {
            return ':id';
        }

        // A segment nobody would name by hand is an identifier we did not
        // recognise — a signed token, a base64 blob, an encoded filename.
        return strlen($segment) > (int) config('httptheus.endpoints.max_segment_length', 40)
            ? ':id'
            : $segment;
    }

    /**
     * The only hard bound on endpoint cardinality.
     *
     * Opt-in, because a default allow-list would silently relabel every
     * endpoint of everyone who never configured one. When it is set, anything
     * unrecognised collapses into a single series instead of minting its own.
     */
    private function applyPatterns(string $endpoint): string
    {
        $patterns = (array) config('httptheus.endpoints.patterns', []);

        if ($patterns === [] || Str::is($patterns, $endpoint)) {
            return $endpoint;
        }

        return (string) config('httptheus.endpoints.other_label', 'other');
    }

    private function truncate(string $value): string
    {
        $max = (int) config('httptheus.endpoints.max_length', 120);

        return strlen($value) <= $max ? $value : substr($value, 0, $max);
    }
}
