<?php
namespace LDLib\Utils\ArrayTools;

class ExploreException extends \Exception {
	public function __construct($msg, $code = 0, $throwable = null) {
		parent::__construct($msg,$code,$throwable);
	}
}

class PrematureEndOfPathException extends ExploreException {
	public array|string $endValue;
	public array $at;

	public function __construct(array|string $endValue, array $at, $throwable=null) {
		$this->endValue = $endValue;
		$this->at = $at;
		parent::__construct("Premature end of path with value : \"$endValue\" at path : ".implode(" --> ", $at).".", 1,$throwable);
	}
}

class KeyNotFoundException extends ExploreException {
	public string $key;
	public array $at;
	
	public function __construct(string $key, array $at, $throwable=null) {
		$this->key = $key;
		$this->at = $at;
		$msg = "Key \"$key\" not found";
		if (count($at) > 0) $msg .= " at path: ".implode(" --> ", $at);
		$msg .= ".";
		parent::__construct($msg,2,$throwable);
	}
}

function array_explore(array|string &$paths, array &$in, bool $skipEndOfPathExceptions = false):array|string {
	$values = [];

	if (is_string($paths)) {
		if (!array_key_exists($paths, $in)) throw new KeyNotFoundException($paths,[]);
		return $in[$paths];
	}
	
	
	foreach ($paths as $k => $v) {
		if (is_int($k)) {
			if (!array_key_exists($v, $in)) throw new KeyNotFoundException($v,[]);
			array_push($values, $in[$v]);
			continue;
		}

		try {
			if (!array_key_exists($k, $in)) throw new KeyNotFoundException($k,[]);
			else if (!is_array($in[$k])) throw new PrematureEndOfPathException($in[$k],[]);
			try {
				array_push($values, array_explore($v, $in[$k]));
			} catch (KeyNotFoundException $pe) {
				$p = $pe->at;
				array_unshift($p, $k);
				throw new KeyNotFoundException($pe->key, $p);
			}
		} catch (PrematureEndOfPathException $pe) {
			if ($skipEndOfPathExceptions) {
				array_push($values, $pe->endValue);
				continue;
			} else {
				$p = $pe->at;
				array_unshift($p, $k);
				throw new PrematureEndOfPathException($pe->endValue, $p);
			}
		}
	}

	return count($values) == 1 ? $values[0] : $values;
}

function array_merge_recursive_distinct(array $a1, array &$a2) {
    foreach ($a2 as $key => &$value) {
        if (is_array($value) && isset($a1[$key]) && is_array($a1[$key])) {
            $a1[$key] = array_merge_recursive_distinct($a1[$key], $value);
        } else {
            $a1[$key] = $value;
        }
    }
    return $a1;
}

function array_if(array $a, callable $f) {
	foreach ($a as $v) if ($f($v)) return true;	
	return false;
}
?>