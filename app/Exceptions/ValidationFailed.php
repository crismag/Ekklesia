<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class ValidationFailed extends RuntimeException
{
	/** @var array<string,mixed> */
	private array $errors;

	/**
	 * @param string $message
	 * @param array<string,mixed> $errors
	 */
	public function __construct(string $message = 'Validation failed', array $errors = [])
	{
		parent::__construct($message);
		$this->errors = $errors;
	}

	/** @return array<string,mixed> */
	public function getErrors(): array
	{
		return $this->errors;
	}
}

