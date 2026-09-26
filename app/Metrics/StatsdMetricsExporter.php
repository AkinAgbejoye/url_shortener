<?php

namespace App\Metrics;

use App\Contracts\MetricsExporter;
use Closure;
use RuntimeException;

class StatsdMetricsExporter implements MetricsExporter
{
    /** @param null|Closure(string): void $sender */
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $prefix,
        private readonly float $timeout,
        private readonly ?Closure $sender = null,
    ) {}

    public function increment(string $name, array $labels = []): void
    {
        $this->send($this->payload($name, '1|c', $labels));
    }

    public function timing(string $name, float $milliseconds, array $labels = []): void
    {
        $this->send($this->payload($name, number_format($milliseconds, 3, '.', '').'|ms', $labels));
    }

    /** @param array<string, string> $labels */
    private function payload(string $name, string $value, array $labels): string
    {
        ksort($labels);
        $tags = array_map(
            fn (string $key, string $label): string => $this->sanitize($key).':'.$this->sanitize($label),
            array_keys($labels),
            array_values($labels),
        );

        return sprintf(
            '%s.%s:%s%s',
            $this->sanitize($this->prefix),
            $this->sanitize($name),
            $value,
            $tags === [] ? '' : '|#'.implode(',', $tags),
        );
    }

    private function sanitize(string $value): string
    {
        return preg_replace('/[^a-zA-Z0-9_.-]/', '_', $value) ?? '';
    }

    private function send(string $payload): void
    {
        if ($this->sender !== null) {
            ($this->sender)($payload);

            return;
        }

        $socket = stream_socket_client(
            "udp://{$this->host}:{$this->port}",
            $errorCode,
            $errorMessage,
            $this->timeout,
        );

        if ($socket === false) {
            throw new RuntimeException("Unable to connect to StatsD: {$errorMessage}", $errorCode);
        }

        try {
            if (fwrite($socket, $payload) === false) {
                throw new RuntimeException('Unable to write the StatsD metric.');
            }
        } finally {
            fclose($socket);
        }
    }
}
