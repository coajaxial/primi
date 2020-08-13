<?php

namespace Smuuf\Primi\Structures;

use \Smuuf\Primi\Helpers\CircularDetector;
use \Smuuf\Primi\Helpers\Common;

use \Smuuf\Primi\ISupportsLength;
use \Smuuf\Primi\ISupportsAddition;
use \Smuuf\Primi\ISupportsIteration;
use \Smuuf\Primi\ISupportsKeyAccess;
use \Smuuf\Primi\ISupportsMultiplication;
use \Smuuf\Primi\Ex\IndexError;
use \Smuuf\Primi\Ex\RuntimeError;

class ListValue extends Value implements
	ISupportsIteration,
	ISupportsKeyAccess,
	ISupportsAddition,
	ISupportsMultiplication,
	ISupportsLength
{

	const TYPE = "list";

	public function reindex(): void {
		$this->value = \array_values($this->value);
	}

	public function __construct(array $items) {
		$this->value = $items;
		$this->reindex();
	}

	public function __clone() {

		// List is really a PHP array of other Primi value objects,
		// so we need to do deep copy.
		\array_walk($this->value, function(&$item) {
			$item = clone $item;
		});

	}

	public function getLength(): int {
		return \count($this->value);
	}

	public function isTruthy(): bool {
		return (bool) $this->value;
	}

	public function getStringRepr(CircularDetector $cd = \null): string {

		// If this is a root method of getting a string value, create instance
		// of circular references detector, which we will from now on pass
		// to all deeper methods.
		if (!$cd) {
			$cd = new CircularDetector;
		}

		return self::convertToString($this, $cd);

	}

	private static function convertToString(
		$self,
		CircularDetector $cd
	): string {

		// Track current value object with circular detector.
		$cd->add(\spl_object_hash($self));

		$return = "[";
		foreach ($self->value as $item) {
			// This avoids infinite loops with self-nested structures by
			// checking whether circular detector determined that we
			// would end up going in (infinite) circles.
			$hash = \spl_object_hash($item);
			$str = $cd->has($hash)
				? \sprintf("*recursion (%s)*", Common::objectHash($item))
				: $item->getStringRepr($cd);

			$return .= \sprintf("%s, ", $str);

		}

		return \rtrim($return, ', ') . "]";

	}

	public function getIterator(): \Iterator {
		return new \ArrayIterator($this->value);
	}

	public function arrayGet(string $index): Value {

		if ($index === \null) {
			throw new RuntimeError("List index must be integer");
		}

		$normalized = $this->protectedIndex((int) $index);
		return $this->value[$normalized];

	}

	/**
	 * Used only via self::getInsertionProxy().
	 */
	public function arraySet(?string $index, Value $value) {

		if ($index === null) {
			$this->value[] = $value;
			return;
		}

		if (!Common::isNumericInt($index)) {
			throw new RuntimeError("List index must be integer");
		}

		$normalized = $this->protectedIndex((int) $index);
		$this->value[$normalized] = $value;

	}

	public function getInsertionProxy(?string $index): InsertionProxy {
		return new InsertionProxy($this, $index);
	}

	public function doAddition(Value $right): Value {

		// Lists can only be added to lists.
		if (!$right instanceof self) {
			return null;
		}

		return new self(array_merge($this->value, $right->value));

	}

	public function doMultiplication(Value $right): Value {

		// Lists can only be multiplied by a number...
		if (!$right instanceof NumberValue) {
			return null;
		}

		// ... and that number must be an integer.
		if (!Common::isNumericInt((string) $right->value)) {
			return new RuntimeError("List can be only multiplied by an integer.");
		}

		// Helper contains at least one empty array, so array_merge doesn't
		// complain about empty arguments for PHP<7.4
		$helper = [[]];

		// Multiplying lists by an integer N returns a new list consisting of
		// the original list appended to itself N-1 times.
		$limit = $right->value;
		for ($i = 0; $i++ < $limit;) {
			$helper[] = $this->value;
		}

		// This should be efficient, since a new array (apart from the empty
		// helper) is created only once, using the splat operator on the helper,
		// which contains only references to the original array (and not copies
		// of it).
		return new self(array_merge(...$helper));

	}

	public function isEqualTo(Value $right): ?bool {

		if (!$right instanceof ListValue) {
			return null;
		}

		// Simple comparison of both arrays should be sufficient.
		// PHP manual describes object (which are in these arrays) comparison:
		// Two object instances are equal if they have the same attributes and
		// values (values are compared with ==).
		// See https://www.php.net/manual/en/language.oop5.object-comparison.php.
		return $this->value == $right->value;

	}

	/**
	 * Translate negative indexes to positive index that's a valid index for
	 * this value's internal list/array.
	 *
	 * Throw an exception when it's not possible to do so.
	 * If optional second argument is false, this function returns null instead
	 * of throwing exception.
	 *
	 * For example:
	 * - index 1 for list with 2 items -> index=1
	 * - index 2 for list with 2 items -> exception!
	 * - index -1 for list with 2 items -> index=<max_index> - 1 (=1)
	 * - index -2 for list with 2 items -> index=<max_index> - 2 (=0)
	 * - index -3 for list with 2 items -> exception!
	 */
	public function protectedIndex(float $index, bool $throw = true): ?int {

		if (!Common::isNumericInt($index)) {
			throw new RuntimeError("Index must be integer");
		}

		$max = count($this->value) - 1;
		$normalized = $index < 0
			? $max + $index + 1
			: $index;

		if (!isset($this->value[$normalized])) {
			if ($throw) {
				// $index on purpose - show the value user originally used.
				throw new IndexError($index);
			}
			return null;
		}

		return $normalized;

	}

}