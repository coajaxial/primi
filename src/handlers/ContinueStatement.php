<?php

namespace Smuuf\Primi\Handlers;

use \Smuuf\Primi\Context;
use \Smuuf\Primi\ContinueException;
use \Smuuf\Primi\Helpers\SimpleHandler;

class ContinueStatement extends SimpleHandler {

	public static function handle(array $node, Context $context) {
		throw new ContinueException;
	}

}
