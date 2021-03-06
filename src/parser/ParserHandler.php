<?php

namespace Smuuf\Primi;

use \Smuuf\Primi\HandlerFactory;
use \Smuuf\Primi\Helpers\Common;

class ParserHandler extends CompiledParser {

	private $source;
	protected $tree = [];

	public function __construct($source) {

		$source = self::sanitizeSource($source);

		parent::__construct($source);
		$this->source = $source;

	}

	public function run(): array {

		$result = $this->match_Program();
		if ($result['text'] !== $this->source) {

			// $this->pos is an internal PEG Parser position counter.
			$this->error('Syntax error', $this->pos);

		}

		return self::processAST($result, $this->source);

	}

	protected function error($msg, $position = \false) {

		$line = \false;
		$pos = \false;

		if ($position !== \false) {
			[$line, $pos] = Common::getPositionEstimate($this->source, $position);
		}

		throw new SyntaxErrorException($msg, $line, $pos);

	}

	protected static function sanitizeSource(string $s) {

		// Unify new-lines.
		$s = str_replace("\r\n", "\n", $s);

		// Ensure newline at the end (parser needs this to be able to correctly
		// parse comments in one line source codes.)
		return rtrim($s) . "\n";

	}

	protected static function processAST(array $ast, string $source): array {

		$ast = self::preprocessNode($ast);
		$ast = self::reduceNode($ast);
		$ast = self::addPositions($ast, $source);

		return $ast;

	}
	/**
	 * Go recursively through each of the nodes and strip unecessary data
	 * in the abstract syntax tree.
	 */
	protected static function preprocessNode(array $node) {

		// If node has "skip" node defined, replace the whole node with the
		// "skip" subnode.
		foreach ($node as $key => &$item) {
			if ($key === 'skip') {
				return self::preprocessNode($item);
			}
			if (\is_array($item)) {
				$item = self::preprocessNode($item);
			}
		}

		return $node;

	}

	/**
	 * Go recursively through each of the nodes and strip unecessary data
	 * in the abstract syntax tree.
	 */
	protected static function reduceNode(array $node) {

		while ($inner = ($node['skip'] ?? \false)) {
			$node = $inner;
		}

		foreach ($node as $key => &$item) {
			if (\is_array($item)) {
				$item = self::reduceNode($item);
			}
		}

		if (
			($name = $node['name'] ?? \false)
			&& ($handler = HandlerFactory::get($name, \false))
		) {

			// Remove text from nodes that don't need it.
			if (!$handler::NODE_NEEDS_TEXT) {
				unset($node['text']);
			}

			// If a handler knows how to reduce its node, let it.
			$reduced = $handler::reduce($node);
			// If anything changed, reduce that node further.
			$node = $reduced !== \null && $reduced !== $node
				? self::reduceNode($reduced)
				: $node;

		}

		unset($node['_matchrule']);
		return $node;

	}

	/**
	 * Recursively iterate the node and its children and add information about
	 * the node's offset (line & position) for later (e.g. error messages).
	 */
	protected static function addPositions(array $node, string $source): array {

		if (isset($node['offset'])) {

			[$line, $pos] = Common::getPositionEstimate(
				$source, $node['offset']
			);

			$node['_l'] = $line;
			$node['_p'] = $pos;

			// Offset no longer necessary.
			unset($node['offset']);

		}

		foreach ($node as &$item) {
			if (\is_array($item)) {
				$item = self::addPositions($item, $source);
			}
		}

		return $node;

	}

}
