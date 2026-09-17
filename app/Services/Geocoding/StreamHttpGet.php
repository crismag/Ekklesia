<?php

declare(strict_types=1);

namespace App\Services\Geocoding;

/** HTTP over PHP streams; no cURL dependency. */
final class StreamHttpGet implements HttpGet
{
    /**
     * @param array<string,string> $headers
     * @return array{status:int,body:string,headers:array<string,string>}
     */
    public function get(string $url, array $headers, int $timeoutSeconds): array
    {
        $lines = '';
        foreach ($headers as $name => $value) {
            $lines .= $name . ': ' . $value . "\r\n";
        }
        $ctx = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => $lines,
            'timeout' => $timeoutSeconds,
            // Read the body of a 429 or 503 rather than throwing it away: the
            // status and Retry-After are the whole point of those responses.
            'ignore_errors' => true,
        ]]);

        $body = @file_get_contents($url, false, $ctx);
        $status = 0;
        $out = [];
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                $status = (int) $m[1];
                continue;
            }
            $parts = explode(':', $header, 2);
            if (count($parts) === 2) {
                $out[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }

        return [
            'status' => $status,
            'body' => is_string($body) ? $body : '',
            'headers' => $out,
        ];
    }
}
