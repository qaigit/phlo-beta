<?php
class PhloException extends Exception {
	public function __construct(string $message, int $code = 0, public array $data = []){ parent::__construct($message, $code); }
	public function payload():array { return ['error' => $this->getMessage(), 'code' => $this->getCode(), 'type' => static::class, 'data' => $this->data ?: null]; }
}
