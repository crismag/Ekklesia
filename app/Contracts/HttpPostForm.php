<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * A form POST to another service.
 *
 * The counterpart of the geocoders' HttpGet, and here for the same reason: the
 * OpenID Connect code exchange is the step that must be got right, and a test
 * has to be able to produce Google's failures — a refused code, a reply with
 * no token, a token for another application — which a live service will not
 * produce on request.
 */
interface HttpPostForm
{
    /**
     * @param array<string,string> $form
     * @return array{status:int,body:string}
     */
    public function post(string $url, array $form, int $timeoutSeconds): array;
}
