<?php

namespace App\Services;

use App\Contracts\RemotePageTransport;
use App\Data\AcquisitionTarget;
use App\Data\RemotePageResponse;
use App\Exceptions\AcquisitionFailure;
use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

class PinnedRemotePageTransport implements RemotePageTransport
{
    public function fetch(AcquisitionTarget $target): RemotePageResponse
    {
        $peerIp = '';
        $maxHeaders = max(1024, (int) config('website_intelligence.budgets.max_header_bytes', 16384));
        $maxBody = max(1024, (int) config('website_intelligence.budgets.max_compressed_bytes', 1000000));
        $address = str_contains($target->connectIp, ':') ? "[{$target->connectIp}]" : $target->connectIp;

        try {
            $response = Http::withHeaders([
                'Accept' => 'text/html, application/xhtml+xml;q=0.9, text/plain;q=0.5',
                'User-Agent' => 'Shortly-Website-Intelligence/1.0 (+https://public.example/crawler)',
            ])->withOptions([
                'allow_redirects' => false,
                'cookies' => false,
                'decode_content' => false,
                'http_errors' => false,
                'proxy' => '',
                'connect_timeout' => max(0.1, (float) config('website_intelligence.budgets.connect_timeout_seconds', 3)),
                'timeout' => max(0.1, (float) config('website_intelligence.budgets.request_timeout_seconds', 10)),
                'curl' => [
                    CURLOPT_RESOLVE => ["{$target->host}:{$target->port}:{$address}"],
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                    CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                ],
                'on_headers' => function ($response) use ($maxHeaders): void {
                    $bytes = strlen($response->getProtocolVersion()) + strlen((string) $response->getStatusCode());
                    foreach ($response->getHeaders() as $name => $values) {
                        foreach ($values as $value) {
                            $bytes += strlen($name) + strlen($value) + 4;
                        }
                    }
                    if ($bytes > $maxHeaders) {
                        throw new AcquisitionFailure('headers_too_large');
                    }
                },
                'progress' => function (int $downloadTotal, int $downloaded) use ($maxBody): void {
                    if ($downloadTotal > $maxBody || $downloaded > $maxBody) {
                        throw new AcquisitionFailure('compressed_body_too_large');
                    }
                },
                'on_stats' => function (TransferStats $stats) use (&$peerIp): void {
                    $candidate = $stats->getHandlerStat('primary_ip');
                    $peerIp = is_string($candidate) ? $candidate : '';
                },
            ])->get($target->url);
        } catch (AcquisitionFailure $exception) {
            throw $exception;
        } catch (ConnectionException) {
            throw new AcquisitionFailure('network_failure');
        } catch (Throwable $exception) {
            for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
                if ($cause instanceof AcquisitionFailure) {
                    throw $cause;
                }
            }

            throw new AcquisitionFailure('network_failure');
        }

        $headers = [];
        foreach ($response->headers() as $name => $values) {
            $headers[strtolower($name)] = array_values($values);
        }

        return new RemotePageResponse(
            status: $response->status(),
            headers: $headers,
            body: $response->body(),
            peerIp: $peerIp,
            elapsedMilliseconds: max(0, (float) ($response->handlerStats()['total_time'] ?? 0) * 1000),
        );
    }
}
