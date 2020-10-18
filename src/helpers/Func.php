<?php

declare(strict_types=1);

namespace Smuuf\Primi\Helpers;

use \Smuuf\Primi\Context;
use \Smuuf\Primi\HandlerFactory;
use \Smuuf\Primi\Ex\TypeError;
use \Smuuf\Primi\Ex\EngineError;
use \Smuuf\Primi\Structures\Value;
use \Smuuf\Primi\Structures\NumberValue;

abstract class Func {

	/**
	 * Pair of regexes to match zeroes at the beginning and at the end of a
	 * string, if they're not the last zeroes on that side of decimal point.
	 *
	 * @const string[]
	 */
	private const DECIMAL_TRIMMING_REGEXES = [
		['#^0+(\d)#', '#(\.0+$)|((\.\d+?[1-9]?)0+$)#'],
		['\1', '\3']
	];

	/**
	 * Returns a generator yielding `[primi key, primi value]` tuples from some
	 * PHP array. If the key or the value is not an instance of `Value` object,
	 * it will be converted automatically to a `Value` object.
	 */
	public static function php_array_to_dict_pairs(array $array): \Generator {
		foreach (Func::iterator_as_tuples($array) as [$key, $value]) {
			yield [Value::buildAutomatic($key),	Value::buildAutomatic($value)];
		}
	}

	/**
	 * Returns a generator yielding `[key, value]` tuples from some iterator.
	 */
	public static function iterator_as_tuples(iterable $iter): \Generator {
		foreach ($iter as $key => $value) {
			yield [$key, $value];
		}
	}

	/**
	 * Returns false if the passed array has contignuous numeric keys starting
	 * from 0 (i.e. it is a "list"). Returns true otherwise (i.e. it is a
	 * "dictionary).
	 *
	 * The solution was chosen based on 'array_list_dict' phpcb benchmark.
	 */
	public static function is_array_dict(array $input): bool {

		// Let's say that empty PHP array is not a dictionary.
		if (!$input) {
			return \false;
		}

		$c = 0;
		foreach ($input as $i => $_) {
			if ($c++ !== $i) {
				return \true;
			}
		}

		return \false;

	}

	public static function is_round_int(string $input): bool {

		if (!\is_numeric($input)) {
			return \false;
		}

		return \round((float) $input) == $input; // Intentionally ==

		// Regex solution chosen based on 'numeric_int' phpcb benchmark results.
		// PHP can cast very large or very small numbers to scientific notation,
		// so the regex must be able to deal with that - integers can only
		// have 'E+' in them, otherwise it's a float.
		//return (bool) \preg_match("#^[+-]?\d+(\.\d+E\+\d+)?$#", (string) $input);

	}

	public static function is_decimal(string $input): bool {

		//return is_numeric($input);
		return (bool) \preg_match('#^[+-]?\d+(\.\d+)?$#', $input);

		// PHP can cast very large or very small numbers to scientific notation,
		// so the regex must be able to deal with that.
		//return (bool) \preg_match('#^[+-]?\d+(\.\d+(E[+-]\d+)?)?$#', (string) $input);
		//return (bool) \preg_match('#^[+-]?\d+(\.\d+(E[+-]\d+)?)?$#', (string) $input);

	}

	public static function object_hash($o): string {
		return \substr(\md5(\spl_object_hash($o)), 0, 6);
	}

	/**
	 * Return true if the value passed as first argument is any of the types
	 * passed as the rest of variadic arguments.
	 *
	 * We're using this helper e.g. in value methods for performing easy
	 * checks against allowed set of types of values. If PHP ever supports union
	 * types, I guess this helper method might become unnecessary (?).
	 *
	 */
	public static function is_any_of_types(?Value $value, string ...$types): bool {

		// If any of the "instanceof" checks is true,
		// the type is allowed - return without throwing exception.
		foreach ($types as $type) {
			if ($value instanceof $type) {
				return \true;
			}
		}

		return \false;

	}

	public static function normalize_decimal(string $decimal): string {
		return \preg_replace(
			self::DECIMAL_TRIMMING_REGEXES[0],
			self::DECIMAL_TRIMMING_REGEXES[1],
			$decimal
		);
	}

	public static function scientific_to_decimal(string $number): string {

		// If not even with decimal point, just return the original.
		if (!\preg_match("#^([+-]?\d+\.\d+)(?:E([+-]\d+))?$#", $number, $matches)) {
			return $number;
		}

		// If there's no exponent, just return the original.
		if (!isset($matches[2])) {
			return $number;
		}

		// Otherwise, take the base and multiply it by the exponent.
		$decimal = $matches[1];
		$exp = $matches[2];
		return \bcmul(
			$decimal,
			\bcpow('10', $exp, NumberValue::PRECISION),
			NumberValue::PRECISION
		);

	}

	/**
	 * Helper for easy type-checking inside Primi extensions.
	 * Checks if a N-th parameter value is of a certain allowed type(s) and
	 * throws a TypeError if it's not.
	 * The exception is handled by Primi's function-invoking logic and converted
	 * into a user-readable error.
	 */
	public static function allow_argument_types(
		int $index,
		Value $arg,
		string ...$allowedTypes
	) {

		// If any of the "instanceof" checks is true,
		// the type is allowed - return without throwing exception.
		foreach ($allowedTypes as $type) {
			if ($arg instanceof $type) {
				return;
			}
		}

		// Convert Primi value classes names to Primi type names.
		$expectedNames = \array_map(function($class) {
			return $class::TYPE;
		}, $allowedTypes);

		throw new TypeError(sprintf(
			"Expected '%s' but got '%s' as argument %d",
			\implode("|", $expectedNames),
			$arg::TYPE,
			$index
		));

	}

	/**
	 * Takes array as reference and ensures its contents are represented in a form
	 * of indexed sub-arrays. This comes handy if we want to be sure that multiple
	 * AST sub-nodes (which PHP-PEG parser returns) are universally iterable.
	 */
	public static function ensure_indexed(array $array): array {
		return !isset($array[0]) ? [$array] : $array;
	}

	public static function hash(...$args): string {
		return \md5(\json_encode($args));
	}

	public static function get_position_estimate(string $string, int $offset): array {

		$substring = \mb_substr($string, 0, $offset);

		// Current line number? Just count the newline characters up to the offset.
		$line = \substr_count($substring, "\n") + 1;

		// Position on the current line? Just count how many characters are there
		// from the substring's end back to the latest newline character. If there
		// were no newline characters (mb_strrchr() returns false), the source code
		// is a single line and in that case the position is determined simply by
		// our substring's length.
		$lastLine = \mb_strrchr($substring, "\n");
		$pos = $lastLine === \false
			? \mb_strlen($substring)
			: \mb_strlen($lastLine);

		return [$line, $pos];

	}

	/**
	 * Parse \ArgumentCountError's message and return a tuple of integers
	 * representing:
	 * 1. Number of arguments passed.
	 * 2. Number of arguments expected.
	 */
	public static function parse_argument_count_error(\ArgumentCountError $e): array {

		$msg = $e->getMessage();

		// ArgumentCountError exception does not provide these numbers itself,
		// so we have to extract it from the internal PHP exception message.
		if (!\preg_match('#(?<passed>\d+)\s+passed.*(?<expected>\d+)\s+expected#', $msg, $m)) {
			return [\null, \null];
		}

		return [$m['passed'], $m['expected']];

	}

	/**
	 * Returns array of Primi value types (PHP class names) of parameters
	 * for a PHP function of which the \ReflectionFunction is provided.
	 *
	 * In another words: This function returns which Primi types a PHP function
	 * expects.
	 */
	public static function check_allowed_parameter_types_of_function(
		\ReflectionFunction $rf
	): array {

		$types = [];
		foreach ($rf->getParameters() as $rp) {

			$type = $rp->getType();

			// See https://github.com/phpstan/phpstan/issues/3886#issuecomment-699599667
			if ($type instanceof \ReflectionNamedType) {
				$typeName = $type->getName();
			} else {
				throw new EngineError("Union parameter types not yet supported");
			}

			// a) No typehint or b) typehint not hinting some Value class
			// means invalid type - gonna throw exception in that case.
			$invalidType = $type === \null
				|| (
					!\is_a($typeName, Value::class, \true)
					&& !\is_a($typeName, Context::class, \true)
				);

			if ($invalidType) {

				$declClass = $rp->getDeclaringClass();
				$class = $declClass
					? $declClass->getName()
					: \null;

				$method = $rp->getDeclaringFunction()->getName();
				$paramName = $rp->getName();
				$paramPosition = $rp->getPosition();
				$fqn = $class ? "{$class}::{$method}()" : "{$method}()";

				$msg = "Parameter {$paramPosition} '\${$paramName}' of type "
					. "'$typeName' at {$fqn} is not an allowed type for Primi "
					. "functions";

				throw new EngineError($msg);

			};

			$types[] = $typeName;

		}

		return $types;

	}

	public static function yield_left_to_right(array $node, Context $context) {

		$operands = $node['operands'];

		$firstOperand = $operands[0];
		$handler = HandlerFactory::get($firstOperand['name']);
		$first = $handler::handle($firstOperand, $context);

		yield $first;

		// Go through each of the operands and yield tuples of
		// [operand 1, operator, operand 2] that go after each other.
		$opCount = \count($operands);
		for ($i = 1; $i < $opCount; $i++) {

			$nextOperand = $operands[$i];
			$handler = HandlerFactory::get($nextOperand['name']);
			$next = $handler::handle($nextOperand, $context);

			// Extract the text of the assigned operator node.
			$op = $node['ops'][$i - 1]['text'];

			yield [$op, $next];

		}

	}

}
