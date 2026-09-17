<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The sign-in was verified, but it is not yet clear which person it belongs to,
 * so the user has to say.
 *
 *  - kind "claim":   a first sign-in with the default password; the choices are
 *                    people without a login who have this email or phone. One
 *                    choice is still shown, to confirm.
 *  - kind "account": the password opened more than one existing login reached
 *                    through a shared email or phone; the choices are those logins.
 *
 * Each choice is {id, name}: a person id for "claim", an account id for
 * "account". The controller keeps the offer server-side and accepts only an id
 * from it.
 */
final class LoginChoiceRequired extends RuntimeException
{
    /**
     * @param 'claim'|'account' $kind
     * @param list<array{id:int,name:string}> $choices
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $identifier,
        public readonly array $choices,
    ) {
        parent::__construct($kind === 'claim'
            ? 'Choose your Church Portal account.'
            : 'More than one account matches. Choose yours.');
    }
}
