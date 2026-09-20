<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Contracts\HttpPostForm;

/** A form POST over PHP streams; no cURL dependency, like StreamHttpGet. */
final class StreamHttpPostForm implements HttpPostForm
{
    /**
     * @param array<string,string> $form
     * @return array{status:int,body:string}
     */
    public function post(string $url, array $form, int $timeoutSeconds): array
    {
        $context = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
            'content' => http_build_query($form),
            'timeout' => $timeoutSeconds,
            /* Read the body of a 4xx: the provider's own reason for refusing
               is the useful part of it. */
            'ignore_errors' => true,
        ]]);

        $body = @file_get_contents($url, false, $context);

        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return ['status' => $status, 'body' => is_string($body) ? $body : ''];
    }
}
