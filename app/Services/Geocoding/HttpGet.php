<?php

declare(strict_types=1);

namespace App\Services\Geocoding;

/**
 * The smallest HTTP surface the providers need.
 *
 * An interface rather than a direct file_get_contents so the providers can be
 * tested against recorded responses — including the throttling responses,
 * which are the ones that must not be got wrong and which a live service will
 * not produce on demand.
 */
interface HttpGet
{
    /**
     * @param array<string,string> $headers
     * @return array{status:int,body:string,headers:array<string,string>}
     */
    public function get(string $url, array $headers, int $timeoutSeconds): array;
}
