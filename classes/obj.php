<?php
class obj implements IteratorAggregate, JsonSerializable {
	public static array $classProps = [];
	public array $objData = [];
	public array $objClosures = [];
	public array $objProps = [];
	public bool $objChanged = false;
	public function __construct(...$data){ $data && $this->objImport(...$data) && $this->objChanged = false; }
	public function has($key):bool { return $this->hasData($key) || $this->hasMethod($key) || $this->hasProp($key); }
	public function hasClosure($key):bool { return isset($this->objClosures[$key]); }
	public function hasData($key):bool { return isset($this->objData[$key]); }
	public function hasMethod($key):bool { return method_exists($this, $key) || $this->hasClosure($key); }
	public function hasProp($key):string|bool { return method_exists($this, $prop = "_$key") ? $prop : false; }
	public function objClear():obj { return last($this->objData && $this->objChanged = true && $this->objData = [], $this); }
	public function objInfo(){ return array_merge(array_filter(get_object_vars($this), fn($name) => !str_starts_with($name, 'obj'), ARRAY_FILTER_USE_KEY), $this->objData, $this->objProps, $this->objClosures); }
	public function objKeys():array { return array_keys($this->objData); }
	public function objValues():array { return array_values($this->objData); }
	public function objLength():int { return count($this->objData); }
	public function objImport(...$data):obj { return last(loop($data, fn($value, $key) => $this->$key = $value), $this); }
	public static function __callStatic($method, $args){
		if (method_exists(static::class, $prop = "_$method")) return $args ? static::$classProps[static::class][$method][serialize($args)] ??= static::$prop(...$args) : static::$classProps[static::class][$method][void] ??= static::$prop();
		if (property_exists(static::class, $method)) return static::$$method;
		error('Unknown static call '.static::class.'::'.$method);
	}
	public function __call($method, $args){
		if ($this->hasMethod('objCall') && $method !== 'objCall' && !is_null($value = $this->objCall($method, ...$args))) return $value;
		if ($this->hasClosure($method)) return $this->objClosures[$method]->call($this, ...$args);
		if ($this->hasMethod($method)) return $this->$method(...$args);
		if ($prop = $this->hasProp($method)) return $args ? $this->objProps[$method][serialize($args)] ??= $this->$prop(...$args) : $this->objProps[$method][void] ??= $this->$prop();
		error('Unknown call '.static::class.'->'.$method);
	}
	public function &__get($key){
		$ref = null;
		if ($this->hasMethod('objGet') && $key !== 'objGet' && !is_null($value = $this->objGet($key))) $ref = $value;
		elseif ($this->hasData($key)) $ref = &$this->objData[$key];
		elseif ($this->hasClosure($key)) $ref = $this->objClosures[$key]->call($this);
		elseif ($this->hasMethod($key))	$ref = $this->$key();
		elseif ($prop = $this->hasProp($key)) $ref = $this->objProps[$key][void] ??= $this->$prop();
		return $ref;
	}
	public function __set($key, $value){
		if ($this->hasMethod('objSet') && $key !== 'objSet' && !is_null($this->objSet($key, $value))) return;
		if ($value instanceof Closure) return $this->objClosures[$key] = $value;
		if (!$this->objChanged && (!$this->hasData($key) || $this->objData[$key] !== $value)) $this->objChanged = true;
		$this->objData[$key] = $value;
	}
	public function __isset($key){ return $this->has($key); }
	public function __unset($key){
		if (!$this->objChanged && $this->hasData($key)) $this->objChanged = true;
		unset($this->objData[$key], $this->objClosures[$key], $this->objProps[$key]);
	}
	public function __serialize(){ return $this->objData; }
	public function __unserialize(array $data){ $this->objData = $data; }
	public function __toString(){ return $this->hasMethod('view') ? $this->view() : error(static::class.' can\'t be converted to string'); }
	public function __debugInfo(){ return $this->objInfo(); }
	public function getIterator():iterator { return new ArrayIterator($this->objData); }
	public function jsonSerialize():mixed { return $this->objData; }
}
