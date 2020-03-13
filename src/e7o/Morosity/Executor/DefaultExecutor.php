<?php

namespace e7o\Morosity\Executor;

use \e7o\Morosity\Parser\Tokenizer;
use \e7o\Morosity\Parser\Tokens;

class DefaultExecutor implements ExecutionContext
{
	// Data accessor
	protected $context;
	protected $commandHandler;
	protected $environment;
	
	// Execution
	private $parsed;
	private $currentLoops;
	// Array for remembering line numbers to which line we have to jump back
	private $jumpBack;
	private $ifHadMatch;
	private $macros;
	private $includedMacros;
	
	public function __construct(VariableContext $context, Environment $env, array $commandHandler)
	{
		$this->context = $context;
		$this->environment = $env;
		$this->commandHandler = $commandHandler;
	}
	
	/**
	 * This method renders a template. THIS METHOD IS NOT TRHEAD-SAFE OR SIMILAR.
	 * It can be executed just once at a time. Create a new instance of that object
	 * to render while another rendering is in progress.
	 */
	public function render($template)
	{
		$this->parsed = Tokenizer::parse($template);
		
		$this->jumpBack = array();
		$this->currentLoops = array();
		$this->ifHadMatch = [];
		$this->variables = [];
		$this->macros = [];
		$this->includedMacros = [];
		
		return $this->renderFromTo(0, count($this->parsed) - 1);
	}
	
	private function renderFromTo($from, $to)
	{
		$result = '';
		$ignoreTillNextIfKeyword = [];
		$ignoreIfMode = false;
		$ignoreDeeperIfKeywords = 0;
		$ifDeep = 0;
		$ignoreTillNextSwitchKeyword = [];
		$ignoreSwitchMode = false;
		$ignoreDeeperSwitchKeywords = 0;
		$switchDeep = 0;
		$switchVar = [];
		$executor = null;
		// Set to $from-1 that the first increment doesn't ignore the first command
		$position = $from - 1;
		while ($position++ < $to) {
			list($commandType, $commandParams, $commandPlain) = $this->parsed[$position];
			
			// todo: check in parser for type and allow multiple spaces etc.
			if (
				$ignoreIfMode
				&& $commandType != Tokens::CONDITION_IF
				&& $commandType != Tokens::CONDITION_ELSE
				&& $commandType != Tokens::CONDITION_ELSEIF
				&& $commandType != Tokens::CONDITION_END
				||
				$ignoreSwitchMode
				&& $commandType != Tokens::SWITCH_START
				&& $commandType != Tokens::SWITCH_CASE
				&& $commandType != Tokens::SWITCH_END
			) {
				continue;
			}
			// Check type
			if ($commandType == Tokens::VARIABLE) {
				$value = $this->evaluateExpression($commandParams);
				$result .= $value;
			} else if ($commandType == Tokens::PLAIN_TEXT) {
				// No special code, simply add it
				$result .= $commandParams;
			} else if ($commandType == Tokens::COMMENT) {
				// Ignore
			} else {
				switch ($commandType) {
					case Tokens::LOOP_START:
						$this->handleLoopStart($position, $to, $commandParams);
						break;
					case Tokens::LOOP_END:
						$this->handleLoopEnd($position);
						break;
					case Tokens::CONDITION_IF:
						if ($ignoreIfMode) {
							// Ignore this one but count it
							$ignoreDeeperIfKeywords++;
						} else {
							// Add new IF to stack
							$ifDeep++;
							$this->ifHadMatch[$ifDeep] = false;
							$ignoreTillNextIfKeyword[$ifDeep] = false;
							$ignoreIfMode = false;
						}
					case Tokens::CONDITION_ELSEIF:
					case Tokens::CONDITION_ELSE:
						if ($ignoreDeeperIfKeywords <= 0) {
							// Set ignore to true in advance
							$ignoreTillNextIfKeyword[$ifDeep] = true;
							$ignoreIfMode = true;
							// When this if not matched yet
							if (!$this->ifHadMatch[$ifDeep]) {
								// Process conditions
								$match = $this->checkConditions($commandParams);
								// Match :)
								if ($match) {
									// We had a match, remember this
									$this->ifHadMatch[$ifDeep] = true;
									// Don't ignore this block
									$ignoreTillNextIfKeyword[$ifDeep] = false;
									$ignoreIfMode = false;
								}
							}
						}
						break;
					case Tokens::CONDITION_END:
						if ($ignoreDeeperIfKeywords > 0) {
							$ignoreDeeperIfKeywords--;
						} else {
							// Remove from stack
							$this->ifHadMatch[$ifDeep] = null;
							$ignoreTillNextIfKeyword[$ifDeep] = null;
							$ifDeep--;
							if (!isset($ignoreTillNextIfKeyword[$ifDeep])) {
								$ignoreIfMode = false;
							} else {
								$ignoreIfMode = !empty($ignoreTillNextIfKeyword) && $ignoreTillNextIfKeyword[$ifDeep];
							}
						}
						break;
					case Tokens::SWITCH_START:
						// CASE should come directly after, so we ignore from here all
						// content (in best case, only spaces in template)
						if ($ignoreSwitchMode) {
							$ignoreDeeperSwitchKeywords++;
						} else {
							$ignoreSwitchMode = true;
							$switchDeep++;
							$this->switchHadMatch[$switchDeep] = false;
							$ignoreTillNextSwitchKeyword[$switchDeep] = false;
							$switchVar[$switchDeep] = $this->evaluateExpression($commandParams);
						}
						// No fall-through here, as opposite to our if handling, as the
						// condition will follow in a case
						break;
					case Tokens::SWITCH_CASE:
						if ($ignoreDeeperSwitchKeywords <= 0) {
							$ignoreTillNextSwitchKeyword[$switchDeep] = true;
							$ignoreSwitchMode = true;
							if (!$this->switchHadMatch[$switchDeep]) {
								// Process conditions
								if ($commandParams == '*') {
									$match = true;
								} else {
									$match = false;
									// TODO: Needs ParamParser functionality instead of a stupid explode
									$all = explode(',', $commandParams);
									foreach ($all as $one) {
										$expr = $this->evaluateExpression($one);
										if ($expr == $switchVar[$switchDeep]) {
											$match = true;
											break;
										}
									}
								}
								// Match :)
								if ($match) {
									// We had a match, remember this
									$this->switchHadMatch[$switchDeep] = true;
									// Don't ignore this block
									$ignoreTillNextSwitchKeyword[$switchDeep] = false;
									$ignoreSwitchMode = false;
								}
							}
						}
						break;
					case Tokens::SWITCH_END:
						if ($ignoreDeeperSwitchKeywords) {
							$ignoreDeeperSwitchKeywords--;
						} else {
							// Remove from stack
							$this->switchHadMatch[$switchDeep] = null;
							$ignoreTillNextSwitchKeyword[$switchDeep] = null;
							$switchDeep--;
							if (!isset($ignoreTillNextSwitchKeyword[$switchDeep])) {
								$ignoreSwitchMode = false;
							} else {
								$ignoreSwitchMode =
									!empty($ignoreTillNextSwitchKeyword)
									&& $ignoreTillNextSwitchKeyword[$switchDeep]
								;
							}
						}
						break;
					case Tokens::VAR_SET:
						// Define a variable/array
						$parts = explode('=', $commandParams, 2);
						$this->context->addValue(trim($parts[0]), $this->evaluateExpression(trim($parts[1])));
						break;
					case Tokens::TEMPLATE_INCLUDE:
						if (empty($executor)) {
							$executor = new DefaultExecutor($this->context, $this->environment, $this->commandHandler);
						}
						$names = $this->evaluateExpression($commandParams);
						if (!is_array($names)) {
							$names = [$names];
						}
						$template = '';
						foreach ($names as $tryName) {
							$template = $this->environment->getLoader()->load($tryName);
							if (!empty($template)) {
								break;
							}
						}
						$result .= $executor->render($template);
						break;
					case Tokens::FUNCTION_START:
						$start = $position;
						$end = false;
						$pos = strpos($commandParams, '(');
						$params = [];
						if ($pos !== false) {
							$name = trim(substr($commandParams, 0, $pos));
							$param = substr($commandParams, $pos + 1);
							$param = substr($param, 0, strpos($param, ')'));
							foreach (explode(',', $param) as $param) {
								$params[] = trim($param);
							}
						} else {
							$name = trim($commandParams);
						}
						while ($position++ < $to) {
							if ($this->parsed[$position][0] == Tokens::FUNCTION_END) {
								$end = $position;
								break;
							}
						}
						if ($end === false) {
							throw new \Exception('Cannot find end of macro ' . $name);
						}
						$this->macros[$name] = [$start + 1, $end - 1, $params];
						break;
					case Tokens::TEMPLATE_IMPORT:
						$pos = strpos($commandParams, ' as ');
						if ($pos !== false) {
							$file = trim(substr($commandParams, 0, $pos));
							$alias = trim(substr($commandParams, $pos + 3));
						} else {
							$file = trim($commandParams);
							$alias = null;
						}
						if ($file === '_self') {
							$newExecutor = $this;
						} else {
							$file = $this->evaluateExpression($file);
							$newExecutor = new DefaultExecutor($this->context, $this->environment, $this->commandHandler);
							// todo: dirty workaround here ;)
							$newExecutor->render($this->environment->getLoader()->load($file));
						}
						$this->includedMacros[$alias] = $newExecutor;
						break;
					default:
						if (isset($this->commandHandler[$commandPlain])) {
							$result .= $this->commandHandler[$commandPlain]
								->handleCommand($this->context, $commandPlain, $commandParams)
							;
						} else {
							throw new \Exception('Unknown command: ' . $commandPlain);
						}
				}
			}
		}
		
		return $result;
	}
	
	private function handleLoopStart(&$position, $maxPosition, $params)
	{
		$doLoop = $this->initLoop($params, $position);
		if ($doLoop == false) {
			// Fast-forward to stuff after the loop
			$ignoreTillNextEndfor = 1;
			while ($position++ < $maxPosition) {
				if ($this->parsed[$position][0] == Tokens::LOOP_START) {
					$ignoreTillNextEndfor++;
				} else if ($this->parsed[$position][0] == Tokens::LOOP_END) {
					$ignoreTillNextEndfor--;
					$end = $position;
					if ($ignoreTillNextEndfor <= 0) {
						break;
					}
				}
			}
			if ($ignoreTillNextEndfor >= 1) {
				throw new \Exception('Cannot find end of loop: ' . $params);
			}
		}
	}
	
	private function handleLoopEnd(&$position)
	{
		// Read current loop information from stack
		end($this->currentLoops);
		$loop = &$this->currentLoops[key($this->currentLoops)];
		// Check if counter is smaller than the maximum loop count
		if ($loop[ExecutionContext::LOOP_CURRENT_INDEX] < $loop[ExecutionContext::LOOP_COUNT]) {
			// Yes: Just increase the counter and go back to start of the loop
			$loop[ExecutionContext::LOOP_CURRENT_INDEX]++;
			next($loop[ExecutionContext::LOOP_ARRAY]);
			$position = $loop[ExecutionContext::LOOP_JUMPBACK];
			// ... and set variables
			// todo: use stack feature
			if (!empty($loop[ExecutionContext::LOOP_VARS][0])){
				$key = key($loop[ExecutionContext::LOOP_ARRAY]);
				$this->context->addValue($loop[ExecutionContext::LOOP_VARS][0][0], $key);
			}
			if (!empty($loop[ExecutionContext::LOOP_VARS][1])){
				$value = current($loop[ExecutionContext::LOOP_ARRAY]);
				$this->context->addValue($loop[ExecutionContext::LOOP_VARS][1][0], $value);
			}
		} else {
			// No: Clean up ...
			$this->restoreValues($this->currentLoops[key($this->currentLoops)][ExecutionContext::LOOP_VARS]);
			// ... and remove from stack
			unset($this->currentLoops[key($this->currentLoops)]);
		}
	}
	
	public function getLoop($above = 0)
	{
		$this->scrollBackInArray($this->currentLoops, $above);
		return current($this->currentLoops);
	}
	
	private function scrollBackInArray(&$array, $above)
	{
		end($array);
		for ($i = 0; $i < $above; $i++) {
			prev($array);
		}
	}
	
	private function checkConditions($conditionString)
	{
		if (strlen($conditionString) == 0) {
			return true;
		}
		
		// for AND by default. TODO, needs rework, also to support operators etc.
		$mode = false;
		
		$conditions = explode(';', $conditionString);
		foreach ($conditions as $cond) {
			if ($this->checkSingleCondition($cond) == $mode) {
				return $mode;
			}
		}
		return !$mode;
	}
	
	private function checkSingleCondition($condition)
	{
		// Detect not condition
		if ($condition[0] == '!') {
			$condition = substr($condition, 1);
			$not = true;
		} else {
			$not = false;
		}
		// Check operators; we need to ensure the correct order of the operators
		// (> behind >= to prevent false detections)
		$operators = array('==', '!=', '<=', '>=', '<', '>', ' in ', ' notin ', ' is');
		$found = false;
		foreach ($operators as $op) {
			if (strpos($condition, $op) !== false) {
				$found = true;
				break;
			}
		}
		if ($found) {
			$v1 = trim(substr($condition, 0, strpos($condition, $op)));
			$v2 = trim(substr($condition, strpos($condition, $op) + strlen($op)));
		} else {
			$v1 = trim($condition);
			$op = '__has_value';
			$v2 = null;
		}
		
		if ($v1 !== null) {
			$v1 = $this->evaluateExpression($v1);
		}
		
		if ($op === 'is') {
			switch ($v2) {
				case 'empty':
					return empty($v1);
				case 'not empty':
					return !empty($v1);
				case 'odd':
					return ($v1 % 2 == 1) ^ $not;
				case 'even':
					return ($v1 % 2 == 0) ^ $not;
				case 'numeric':
					return (is_numeric($v1)) ^ $not;
				default:
					// todo, unknown comparision
			}
		} else {
			if ($v2 !== null) {
				$v2 = $this->evaluateExpression($v2);
			}
			
			switch (trim($op)) {
				case '==':
					return (bool)($v1 == $v2) ^ $not;
				case '!=':
					return (bool)($v1 != $v2) ^ $not;
				case '<=':
					return (bool)($v1 <= $v2) ^ $not;
				case '>=':
					return (bool)($v1 >= $v2) ^ $not;
				case '<':
					return (bool)($v1 < $v2) ^ $not;
				case '>':
					return (bool)($v1 > $v2) ^ $not;
				case 'in':
					if (is_array($v2)) {
						return in_array($v1, $v2) ^ $not;
					} else {
						return (strpos($v2, $v1) !== false) ^ $not;
					}
				case 'notin':
					if (is_array($v2)) {
						return !in_array($v1, $v2) ^ $not;
					} else {
						return (strpos($v2, $v1) === false) ^ $not;
					}
				case '__has_value': // Internal keyword
					$hasValue =
						is_array($v1)
						|| is_string($v1) && strlen($v1) > 0
						|| is_object($v1)
						|| is_numeric($v1)
						|| $v1 === true
					;
					return $hasValue ^ $not;
				default:
					return false ^ $not;
			}
		}
	}
	
	private function initLoop($command, $position)
	{
		$parts = explode(' in ', $command, 2);
		
		// Get variable names
		$vars = explode(',', $parts[0]);
		switch (count($vars)) {
			case 1:
				$loopVarNames = [null, [trim($vars[0]), null]];
				break;
			case 2:
				$loopVarNames = [[trim($vars[0]), null], [trim($vars[1]), null]];
				break;
			default:
				throw new Exception('Invalid number of variables in for loop: ' . $parts[0]);
		}
		
		// Get array to loop over
		// TODO: If not set throw syntax error
		$loopArr = $this->evaluateExpression($parts[1]);
		// Converting objects if known
		if ($loopArr instanceof \PDOStatement) {
			$newLoopArr = array();
			while ($loopVal = $loopArr->fetch()) {
				$newLoopArr[] = $loopVal;
			}
			$loopArr = $newLoopArr;
		}
		
		// Not doing anything on an empty array
		if (empty($loopArr)) {
			return false;
		}
		
		// Init loop
		$this->storeVariables($loopVarNames);
		if (!is_array($loopArr) && !($loopArr instanceof \Traversable)) {
			// Unknown variable
			throw new \Exception('Bad loop initialisator: ' . $command);
		} else {
			// Set info
			reset($loopArr);
			$loopInfo = [
				self::LOOP_CURRENT_INDEX => 0,
				self::LOOP_COUNT => count($loopArr) - 1,
				self::LOOP_ARRAY => &$loopArr,
				self::LOOP_JUMPBACK => $position,
				self::LOOP_VARS => $loopVarNames,
			];
			if (count($loopArr) > 0) {
				if (!empty($loopVarNames[0])) {
					$this->context->addValue($loopVarNames[0][0], key($loopArr));
				}
				if (!empty($loopVarNames[1])) {
					$this->context->addValue($loopVarNames[1][0], current($loopArr));
				}
			}
		}
		// Set nesting loops information - put loop id on stack
		$this->currentLoops[] = $loopInfo;
		
		return true;
	}
	
	public function hasMacro($name)
	{
		$name = explode('.', $name);
		if (count($name) == 2) {
			return isset($this->includedMacros[$name[0]]) && $this->includedMacros[$name[0]]->hasMacro($name[1]);
		} else {
			return isset($this->macros[$name[0]]);
		}
	}
	
	public function callMacro($name, $args)
	{
		$name = explode('.', $name);
		if (count($name) == 2) {
			$rendered = $this->includedMacros[$name[0]]->callMacro($name[1], $args);
		} else {
			$macro = &$this->macros[$name[0]];
			$frame = [];
			$l = count($macro[2]);
			for ($i = 0; $i < $l; $i++) {
				$frame[$macro[2][$i]] = $args[$i] ?? null;
			}
			$this->context->pushStack($frame);
			$rendered = $this->renderFromTo($macro[0], $macro[1]);
			$this->context->popStack();
		}
		return $rendered;
	}
	
	private function storeVariables(&$arr)
	{
		foreach ($arr as &$val) {
			if ($this->context->hasValue($val[0])) {
				$val[1] = $this->context->getValue($val[0]);
			}
		}
	}
	
	private function restoreValues(&$arr)
	{
		foreach ($arr as $val) {
			$this->context->addValue($val[0], $val[1]);
		}
	}
	
	private function evaluateExpression($expression)
	{
		return $this->context->evaluateExpression($expression, $this);
	}
}
