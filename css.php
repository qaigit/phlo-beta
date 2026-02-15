<?php
class phlo_css {
	public static function parse(string $input, bool $compact){
		$tok = static::pre($input, false);
		list($headers, $tree) = static::build(static::lines($tok));
		static::sort($tree);
		return static::renderCss($headers, $tree, $compact);
	}
	public static function clean(string $source){
		$tok = static::pre($source, true);
		list($headers, $tree) = static::build(static::lines($tok));
		static::sort($tree);
		$tree = static::nestOverlap($tree);
		return static::renderPhlo($headers, $tree);
	}
	private static function pre(string $src, bool $stripLine){
		$src = str_replace([nl, cr], [lf, lf], $src);
		$out = void;
		$len = strlen($src);
		$i = 0;
		$inS = false;
		$inD = false;
		$inC = false;
		$par = 0;
		while ($i < $len){
			$c = $src[$i];
			$n = $i + 1 < $len ? $src[$i + 1] : null;
			if ($inC){
				if ($c === '*' && $n === slash){
					$inC = false;
					$i += 2;
					continue;
				}
				$i++;
				continue;
			}
			if (!$inS && !$inD && $c === slash && $n === '*'){
				$inC = true;
				$i += 2;
				continue;
			}
			if ($stripLine && !$inS && !$inD && $c === slash && $n === slash){
				while ($i < $len && $src[$i] !== lf) $i++;
				continue;
			}
			if ($c === sq && !$inD){
				$out .= $c;
				$inS = !$inS;
				$i++;
				continue;
			}
			if ($c === dq && !$inS){
				$out .= $c;
				$inD = !$inD;
				$i++;
				continue;
			}
			if (!$inS && !$inD){
				if ($c === '(') $par++;
				elseif ($c === ')') $par = max(0, $par - 1);
				if ($c === '{' || $c === '}'){
					$out .= lf.$c.lf;
					$i++;
					continue;
				}
				if ($c === semi && $par === 0){
					$out .= lf.$c.lf;
					$i++;
					continue;
				}
			}
			$out .= $c;
			$i++;
		}
		return $out;
	}
	private static function lines(string $src){
		$lines = [];
		foreach (explode(lf, $src) as $raw){
			$line = trim($raw);
			if ($line === void) continue;
			if ($line === '{' || $line === '}' || str_ends_with($line, '{')){
				$lines[] = $line;
				continue;
			}
			if (str_contains($line, '{') && str_contains($line, '}') && str_contains($line, colon)){
				$seg = static::explodeOutside($line, '{}');
				foreach ($seg as $s){
					$t = trim($s);
					if ($t === void) continue;
					if ($t === '{' || $t === '}'){
						$lines[] = $t;
						continue;
					}
					if (str_contains($t, '{') || str_contains($t, '}')){
						$lines[] = $t;
						continue;
					}
					if (str_ends_with($t, semi) && !str_starts_with($t, '@')){
						$decls = static::splitDeclarations($t);
						foreach ($decls as $d) if ($d !== void) $lines[] = $d;
					}
					else $lines[] = $t;
				}
				continue;
			}
			if (str_ends_with($line, semi) && !str_starts_with($line, '@')){
				$decls = static::splitDeclarations($line);
				foreach ($decls as $d) if ($d !== void) $lines[] = $d;
				continue;
			}
			$lines[] = $line;
		}
		$out = [];
		$sel = void;
		foreach ($lines as $line){
			if ($sel){
				if (str_ends_with($line, comma) && !str_contains($line, colon)){
					$sel .= space.trim($line);
					continue;
				}
				$out[] = $sel.space.trim($line);
				$sel = void;
				continue;
			}
			if (str_ends_with($line, comma) && !str_contains($line, colon)){
				$sel = trim($line);
				continue;
			}
			$out[] = $line;
		}
		return $out;
	}
	private static function explodeOutside(string $s, string $chars){
		$out = [];
		$cur = void;
		$inS = false;
		$inD = false;
		$len = strlen($s);
		for ($i = 0; $i < $len; $i++){
			$c = $s[$i];
			if ($c === sq && !$inD) $inS = !$inS;
			elseif ($c === dq && !$inS) $inD = !$inD;
			if (!$inS && !$inD && str_contains($chars, $c)){
				if ($cur !== void){
					$out[] = $cur;
					$cur = void;
				}
				$out[] = $c;
				continue;
			}
			$cur .= $c;
		}
		if ($cur !== void) $out[] = $cur;
		return $out;
	}
	private static function splitDeclarations(string $line){
		$parts = [];
		$cur = void;
		$depth = 0;
		$inS = false;
		$inD = false;
		$len = strlen($line);
		for ($i = 0; $i < $len; $i++){
			$c = $line[$i];
			$cur .= $c;
			if ($c === sq && !$inD) $inS = !$inS;
			elseif ($c === dq && !$inS) $inD = !$inD;
			elseif (!$inS && !$inD){
				if ($c === '(') $depth++;
				elseif ($c === ')') $depth = max(0, $depth - 1);
				elseif ($c === semi && $depth === 0){
					$parts[] = rtrim(substr($cur, 0, -1));
					$cur = void;
				}
			}
		}
		if (trim($cur) !== void) $parts[] = trim($cur);
		return $parts;
	}
	private static function splitPropertyValue(string $s){
		$inS = false;
		$inD = false;
		$depth = 0;
		$len = strlen($s);
		for ($i = 0; $i < $len; $i++){
			$c = $s[$i];
			if ($c === sq && !$inD) $inS = !$inS;
			elseif ($c === dq && !$inS) $inD = !$inD;
			elseif (!$inS && !$inD){
				if ($c === '(') $depth++;
				elseif ($c === ')') $depth = max(0, $depth - 1);
				elseif ($c === colon && $depth === 0){
					return [trim(substr($s, 0, $i)), trim(substr($s, $i + 1))];
				}
			}
		}
		return [null, null];
	}
	private static function splitLastColon(string $s){
		$inS = false;
		$inD = false;
		$depth = 0;
		for ($i = strlen($s) - 1; $i >= 0; $i--){
			$c = $s[$i];
			if ($c === dq && !$inS) $inD = !$inD;
			elseif ($c === sq && !$inD) $inS = !$inS;
			elseif (!$inS && !$inD){
				if ($c === ')') $depth++;
				elseif ($c === '(') $depth = max(0, $depth - 1);
				elseif ($c === colon && $depth === 0){
					return [trim(substr($s, 0, $i)), trim(substr($s, $i + 1))];
				}
			}
		}
		return [null, null];
	}
	private static function splitOuterFirst(string $s){
		$inS = false;
		$inD = false;
		$depth = 0;
		$len = strlen($s);
		for ($i = 0; $i < $len - 1; $i++){
			$c = $s[$i];
			$n = $s[$i + 1];
			if ($c === sq && !$inD){
				$inS = !$inS;
				continue;
			}
			if ($c === dq && !$inS){
				$inD = !$inD;
				continue;
			}
			if (!$inS && !$inD){
				if ($c === '(') $depth++;
				elseif ($c === ')') $depth = max(0, $depth - 1);
				elseif ($c === colon && $n === space && $depth === 0){
					return [trim(substr($s, 0, $i)), trim(substr($s, $i + 2))];
				}
			}
		}
		return [null, null];
	}
	private static function splitChain(string $s){
		list($left, $value) = static::splitLastColon($s);
		if ($left === null) return [null, null, null];
		list($chainPart, $prop) = static::splitLastColon($left);
		if ($chainPart === null) return [null, null, null];
		$chain = array_map('trim', explode(colon.space, $chainPart));
		return [$chain, trim($prop), trim($value)];
	}
	private static function normalizeKey(string $k){
		return str_starts_with($k, '$') ? '--'.substr($k, 1) : $k;
	}
	private static function normalizeVal(string $v){
		$v = rtrim($v, semi);
		$v = ltrim($v);
		return preg_replace('/\$([A-Za-z0-9-]+)/', 'var(--$1)', $v);
	}
	private static function phloKey(string $k){
		return str_starts_with($k, '--') ? '$'.substr($k, 2) : $k;
	}
	private static function phloVal(string $v){
		return preg_replace('/var\(--([A-Za-z0-9-]+)\)/', '$$1', $v);
	}
	private static function isHeaderAt(string $line){
		return str_starts_with($line, '@import') || str_starts_with($line, '@charset') || str_starts_with($line, '@namespace');
	}
	private static function build(array $lines){
		$headers = [];
		$tree = [];
		$stack = [];
		$brace = [];
		$buffer = [];
		$pending = null;
		$n = count($lines);
		for ($i = 0; $i < $n; $i++){
			$line = trim($lines[$i]);
			if ($line === void) continue;
			if (str_starts_with($line, '@') && str_ends_with($line, semi) && static::isHeaderAt(rtrim($line, semi))){
				$headers[] = rtrim($line, semi);
				continue;
			}
			$next = null;
			for ($j = $i + 1; $j < $n; $j++){
				$t = trim($lines[$j]);
				if ($t !== void){
					$next = $t;
					break;
				}
			}
			$isOpen = str_ends_with($line, '{') && $line !== '{';
			if ($line === '{' || $line === '}'){
				if ($line === '{'){
					if ($buffer){
						static::flush($tree, $stack, $buffer);
						$buffer = [];
					}
					if ($pending !== null){
						if (str_starts_with($pending, '@')){
							list($at, $right) = static::splitOuterFirst($pending);
							if ($at !== null){
								$stack[] = $at;
								if ($right !== void){
									$stack[] = $right;
									$brace[] = true;
								}
								else $brace[] = false;
							}
							else {
								$stack[] = $pending;
								$brace[] = false;
							}
						}
						elseif (strpos($pending, colon.space) !== false){
							$parts = explode(colon.space, $pending, 2);
							$stack[] = $parts[0];
							$stack[] = rtrim($parts[1]);
							$brace[] = true;
						}
						else {
							$stack[] = $pending;
							$brace[] = false;
						}
						$pending = null;
					}
					else $brace[] = false;
				}
				else {
					if ($buffer){
						static::flush($tree, $stack, $buffer);
						$buffer = [];
					}
					$double = array_pop($brace) ?: false;
					if ($double){
						array_pop($stack);
						array_pop($stack);
					}
					else array_pop($stack);
				}
				continue;
			}
			if ($isOpen){
				if ($buffer){
					static::flush($tree, $stack, $buffer);
				}
				$buffer = [];
				$sel = rtrim(substr($line, 0, -1));
				if (str_starts_with($sel, '@')){
					list($at, $right) = static::splitOuterFirst($sel);
					if ($at !== null){
						$stack[] = $at;
						if ($right !== void){
							$stack[] = rtrim($right);
							$brace[] = true;
						}
						else $brace[] = false;
						$pending = null;
						continue;
					}
				}
				if (strpos($sel, colon.space) !== false && !str_starts_with($sel, '@')){
					$parts = explode(colon.space, $sel, 2);
					$stack[] = $parts[0];
					$stack[] = rtrim($parts[1]);
					$brace[] = true;
					$pending = null;
					continue;
				}
				$stack[] = rtrim($sel);
				$brace[] = false;
				$pending = null;
				continue;
			}
			if (str_starts_with($line, '@') && !str_contains($line, '{') && !str_contains($line, '}')){
				list($at, $right) = static::splitOuterFirst($line);
				if ($at !== null){
					if ($right !== null && strpos($right, colon.space) === false){
						$pending = $line;
						continue;
					}
					$decls = static::splitDeclarations($right ?? void);
					$handled = false;
					foreach ($decls as $d){
						list($chain, $prop, $val) = static::splitChain($d);
						if ($chain){
							static::put($tree, [$at], $stack, $chain, [static::normalizeKey($prop) => static::normalizeVal($val)]);
							$handled = true;
							continue;
						}
						list($pk, $pv) = static::splitPropertyValue($d);
						if ($pk !== null){
							static::put($tree, [$at], $stack, [], [static::normalizeKey($pk) => static::normalizeVal($pv)]);
							$handled = true;
						}
					}
					if (!$handled) $pending = $line;
					continue;
				}
			}
			if (strpos($line, colon.space) !== false && str_contains($line, '{')){
				list($childSel, $decl) = explode(colon.space, $line, 2);
				$outProps = [];
				foreach (static::splitDeclarations($decl) as $d){
					list($k, $v) = static::splitPropertyValue($d);
					if ($k !== null) $outProps[static::normalizeKey($k)] = static::normalizeVal($v);
				}
				static::put($tree, [], $stack, [$childSel], $outProps);
				continue;
			}
			if (!str_starts_with($line, '@') && substr_count($line, colon.space) >= 2 && !str_contains($line, '{') && !str_contains($line, '}')){
				$parts = explode(colon.space, $line);
				$val = array_pop($parts);
				$prop = array_pop($parts);
				static::put($tree, [], $stack, $parts, [static::normalizeKey($prop) => static::normalizeVal($val)]);
				continue;
			}
			if (!str_contains($line, '{') && !str_contains($line, '}') && str_contains($line, colon.space)){
				if ($next === '{'){
					$pending = $line;
					continue;
				}
			}
			if (!str_contains($line, '{') && !str_contains($line, '}') && str_contains($line, colon) && !str_contains($line, colon.space)){
				if ($next === '{'){
					$pending = $line;
					continue;
				}
			}
			if (str_contains($line, '{') && !str_contains($line, colon.space)){
				$pending = rtrim($line, '{ '.tab);
				continue;
			}
			if (str_contains($line, colon)){
				list($k, $v) = static::splitPropertyValue($line);
				if ($k === null){
					$pending = $line;
					continue;
				}
				$buffer[static::normalizeKey($k)] = static::normalizeVal($v);
				continue;
			}
			$pending = $line;
		}
		if ($buffer) static::flush($tree, $stack, $buffer);
		return [$headers, $tree];
	}
	private static function flush(array &$tree, array $stack, array $buffer){
		if (!$buffer) return;
		$ats = [];
		$sels = [];
		foreach ($stack as $s){
			if (str_starts_with($s, '@')) $ats[] = $s;
			else $sels[] = $s;
		}
		if (!$ats && !$sels) return;
		if ($ats && !$sels){
			$ref = &$tree;
			foreach ($ats as $a){
				if ($a === '@font-face'){
					if (!isset($ref[$a])) $ref[$a] = [];
					$ref[$a][] = ['__decls' => $buffer];
					return;
				}
				if (!isset($ref[$a])) $ref[$a] = [];
				$ref = &$ref[$a];
			}
			$key = '__decls';
			if (!isset($ref[$key])) $ref[$key] = [];
			foreach ($buffer as $k => $v) $ref[$key][$k] = $v;
			return;
		}
		$selector = static::selector($sels);
		if ($ats){
			$ref = &$tree;
			foreach ($ats as $a){
				if (!isset($ref[$a])) $ref[$a] = [];
				$ref = &$ref[$a];
			}
			if (!isset($ref[$selector])) $ref[$selector] = [];
			foreach ($buffer as $k => $v) $ref[$selector][$k] = $v;
		}
		else {
			if (!isset($tree[$selector])) $tree[$selector] = [];
			foreach ($buffer as $k => $v) $tree[$selector][$k] = $v;
		}
	}
	private static function put(array &$tree, array $forcedAts, array $stack, array $chain, array $props){
		$ats = $forcedAts;
		foreach ($stack as $s) if (str_starts_with($s, '@')) $ats[] = $s;
		$base = [];
		foreach ($stack as $s) if (!str_starts_with($s, '@')) $base[] = $s;
		$selector = static::selector(array_merge($base, $chain));
		if ($ats){
			$ref = &$tree;
			foreach ($ats as $a){
				if (!isset($ref[$a])) $ref[$a] = [];
				$ref = &$ref[$a];
			}
			if (!isset($ref[$selector])) $ref[$selector] = [];
			foreach ($props as $k => $v) $ref[$selector][$k] = $v;
		}
		else {
			if (!isset($tree[$selector])) $tree[$selector] = [];
			foreach ($props as $k => $v) $tree[$selector][$k] = $v;
		}
	}
	private static function selector(array $selectors){
		$collection = [];
		foreach ($selectors as $selList){
			$selList = explode(comma, $selList);
			$collector = [];
			foreach ($collection ?: [void] as $sels){
				foreach ($selList as $sel){
					$sel = trim($sel);
					$collector[] = $sels.(str_starts_with($sel, bs) ? substr($sel, 1) : (strlen($sels) ? space : void).$sel);
				}
			}
			$collection = $collector;
		}
		return implode(comma, $collection);
	}
	private static function sort(array &$node){
		uksort($node, function($a, $b){
			$order = [colon, '*', 'a-z', '#', dot, '@'];
			$priority = function($k) use ($order){
				foreach ($order as $i => $p) if (($p === 'a-z' && preg_match('/^[A-z]/', $k)) || strpos($k, $p) === 0) return $i;
				return count($order);
			};
			return ($priority($a) <=> $priority($b)) ?: strcmp($a, $b);
		});
		foreach ($node as $k => &$v) if (is_array($v)) static::sort($v);
	}
	private static function bspace(string $s){
		$t = rtrim($s);
		return $t !== void && substr($t, -1) === ')' ? void : space;
	}
	private static function renderCss(array $headers, array $tree, bool $compact){
		$out = void;
		foreach ($headers as $h){
			$out .= $compact ? $h.';' : $h.semi.lf;
		}
		$out .= static::renderCssNode($tree, $compact, 0);
		return rtrim($out);
	}
	private static function renderCssNode(array $node, bool $compact, int $depth){
		$out = void;
		foreach ($node as $key => $val){
			if ($key === '@font-face' && isset($val[0]) && is_array($val[0])){
				foreach ($val as $v) $out .= static::renderCssNode(['@font-face' => $v], $compact, $depth);
				continue;
			}
			if (str_starts_with($key, '@')){
				if (isset($val['__decls'])){
					$decls = $val['__decls'];
					$innerDecl = void;
					$first = true;
					foreach ($decls as $k => $v){
						$innerDecl .= $compact ? ($first ? void : semi).$k.colon.$v : str_repeat(tab, $depth + 1).$k.colon.space.$v.semi.lf;
						$first = false;
					}
					$rest = $val;
					unset($rest['__decls']);
					$inner = $rest ? static::renderCssNode($rest, $compact, $depth + 1) : void;
					if ($compact) {
						$out .= $key.'{'.$innerDecl;
						if ($inner) $out .= lf.rtrim($inner, lf);
						$out .= '}'.lf;
					}
					else {
						$out .= str_repeat(tab, $depth).$key.static::bspace($key).'{'.lf.$innerDecl;
						if ($inner) $out .= $inner;
						$out .= str_repeat(tab, $depth).'}'.lf;
					}
				}
				else {
					$inner = static::renderCssNode($val, $compact, $depth + 1);
					if ($compact){
						$out .= $key.'{'.lf.rtrim($inner, lf).'}'.lf;
					} else {
						$out .= str_repeat(tab, $depth).$key.static::bspace($key).'{'.lf.$inner.str_repeat(tab, $depth).'}'.lf;
					}
				}
				continue;
			}
			if (!is_array($val)) continue;
			if ($compact) $out .= $key.'{';
			else {
				if (str_contains($key, comma)){
					$parts = array_map('trim', explode(comma, $key));
					$out .= str_repeat(tab, $depth).implode(comma.lf.str_repeat(tab, $depth), $parts).static::bspace($key).'{'.lf;
				}
				else $out .= str_repeat(tab, $depth).$key.static::bspace($key).'{'.lf;
			}
			$first = true;
			foreach ($val as $k => $v){
				if (!is_array($v)){
					$out .= $compact ? ($first ? void : semi).$k.colon.$v : str_repeat(tab, $depth + 1).$k.colon.space.$v.semi.lf;
					$first = false;
				}
			}
			$out .= $compact ? '}'.lf : str_repeat(tab, $depth).'}'.lf;
		}
		return $out;
	}
	private static function isSimpleSelector(string $sel){
		if (str_contains($sel, comma)) return false;
		if (preg_match('/[>+~\[\]:]/', $sel)) return false;
		$parts = preg_split('/\s+/', trim($sel));
		if (!$parts) return false;
		foreach ($parts as $p) if (!preg_match('/^[A-Za-z][A-Za-z0-9-]*$/', $p)) return false;
		return true;
	}
	private static function nestOverlap(array $tree){
		$nested = $tree;
		$keys = array_keys($tree);
		foreach ($keys as $child){
			if (str_starts_with($child, '@')) continue;
			if (!isset($nested[$child])) continue;
			if (!static::isSimpleSelector($child)) continue;
			foreach ($keys as $parent){
				if ($child === $parent) continue;
				if (str_starts_with($parent, '@')) continue;
				if (!static::isSimpleSelector($parent)) continue;
				if (str_starts_with($child, $parent.space)){
					$suffix = substr($child, strlen($parent) + 1);
					if ($suffix === void || (str_contains($suffix, space) && !static::isSimpleSelector($suffix))) continue;
					if (!isset($nested[$parent][$suffix])) $nested[$parent][$suffix] = [];
					foreach ($nested[$child] as $k => $v) if (!is_array($v)) $nested[$parent][$suffix][$k] = $v;
					unset($nested[$child]);
					break;
				}
			}
		}
		foreach ($tree as $k => $v){
			if (str_starts_with($k, '@') && is_array($v)) $nested[$k] = static::nestOverlap($v);
		}
		return $nested;
	}
	private static function oneAtInline(array $node){
		if (count($node) !== 1) return null;
		$sel = array_key_first($node);
		$props = $node[$sel];
		$simple = [];
		foreach ($props as $k => $v) if (!is_array($v)) $simple[$k] = $v;
		if (count($simple) === 1) return [$sel, array_key_first($simple), $simple[array_key_first($simple)]];
		return null;
	}
	private static function renderPhlo(array $headers, array $tree){
		$out = [];
		foreach ($headers as $h) $out[] = rtrim($h, semi);
		foreach ($tree as $key => $val){
			if ($key === '@font-face' && isset($val[0]) && is_array($val[0])){
				foreach ($val as $v){
					$block = static::renderPhlo([], ['@font-face' => $v]);
					$out[] = $block;
				}
				continue;
			}
			if (str_starts_with($key, '@')){
				if ($key === '@media' || str_starts_with($key, '@media ') || str_starts_with($key, '@supports ') || str_starts_with($key, '@container ')){
					$inner = $val;
					if (isset($inner['__decls'])){
						$out[] = $key.static::bspace($key).'{';
						foreach ($inner['__decls'] as $k => $v) $out[] = tab.static::phloKey($k).colon.space.static::phloVal($v);
						$out[] = '}';
						unset($inner['__decls']);
						foreach ($inner as $k => $v){
							if ($k === '@font-face' && isset($v[0]) && is_array($v[0])){
								foreach ($v as $face) $out[] = static::renderPhloAtBlock($key, $k, $face, 0);
							} else {
								$out[] = static::renderPhloAtBlock($key, $k, $v, 0);
							}
						}
						continue;
					}
					$one = static::oneAtInline($inner);
					if ($one){
						list($s, $k, $v) = $one;
						$out[] = $key.colon.space.$s.colon.space.static::phloKey($k).colon.space.static::phloVal($v);
						continue;
					}
					$out[] = $key.static::bspace($key).'{';
					foreach ($inner as $s => $pp){
						if ($s === '__decls') continue;
						static::renderPhloBlock($out, $s, $pp, 1);
					}
					$out[] = '}';
					continue;
				}
				$out[] = $key.static::bspace($key).'{';
				if (isset($val['__decls'])){
					foreach ($val['__decls'] as $k => $v) $out[] = tab.static::phloKey($k).colon.space.static::phloVal($v);
					unset($val['__decls']);
				}
				foreach ($val as $innerK => $innerV){
					if (str_starts_with($innerK, '@')){
						$block = static::renderPhlo([$innerK], [$innerK => $innerV]);
						foreach (explode(lf, $block) as $ln) if ($ln !== void) $out[] = tab.$ln;
					}
					else static::renderPhloBlock($out, $innerK, $innerV, 1);
				}
				$out[] = '}';
				continue;
			}
			static::renderPhloBlock($out, $key, $val, 0);
		}
		return strtr(implode(lf, $out), [' 0.' => ' .']);
	}
	private static function renderPhloAtBlock(string $at, string $sel, array $props, int $depth){
		$tabs = str_repeat(tab, $depth);
		$lines = [];
		$simple = [];
		foreach ($props as $k => $v) if (!is_array($v)) $simple[$k] = $v;
		if (count($simple) === 1){
			$k = array_key_first($simple);
			$lines[] = $tabs.$at.colon.space.$sel.colon.space.static::phloKey($k).colon.space.static::phloVal($simple[$k]);
		}
		else {
			$lines[] = $tabs.$at.static::bspace($at).'{';
			$lines[] = $tabs.tab.$sel.static::bspace($sel).'{';
			foreach ($simple as $k => $v) $lines[] = $tabs.tab.tab.static::phloKey($k).colon.space.static::phloVal($v);
			$lines[] = $tabs.tab.'}';
			$lines[] = $tabs.'}';
		}
		return implode(lf, $lines);
	}
	private static function renderPhloBlock(array &$out, string $selector, array $props, int $depth){
		$tabs = str_repeat(tab, $depth);
		$simple = [];
		$nested = [];
		foreach ($props as $k => $v){
			if (is_array($v)) $nested[$k] = $v;
			else $simple[$k] = $v;
		}
		if (count($simple) === 1 && !$nested){
			$k = array_key_first($simple);
			$out[] = $tabs.$selector.colon.space.static::phloKey($k).colon.space.static::phloVal($simple[$k]);
			return;
		}
		$out[] = $tabs.$selector.static::bspace($selector).'{';
		foreach ($simple as $k => $v) $out[] = $tabs.tab.static::phloKey($k).colon.space.static::phloVal($v);
		foreach ($nested as $k => $v){
			if ($k === '@font-face' && isset($v[0]) && is_array($v[0])){
				foreach ($v as $face) static::renderPhloBlock($out, $k, $face, $depth + 1);
				continue;
			}
			if (str_starts_with($k, '@')){
				$inner = static::oneAtInline($v);
				if ($inner){
					list($s2, $k2, $v2) = $inner;
					$out[] = $tabs.tab.$k.colon.space.$s2.colon.space.static::phloKey($k2).colon.space.static::phloVal($v2);
				}
				else {
					$out[] = $tabs.tab.$k.static::bspace($k).'{';
					if (isset($v['__decls'])){
						foreach ($v['__decls'] as $kk => $vv) $out[] = $tabs.tab.tab.static::phloKey($kk).colon.space.static::phloVal($vv);
						unset($v['__decls']);
					}
					foreach ($v as $kk => $vv){
						if (str_starts_with($kk, '@')){
							$blk = static::renderPhlo([$kk], [$kk => $vv]);
							foreach (explode(lf, $blk) as $ln) if ($ln !== void) $out[] = $tabs.tab.$ln;
						}
						else static::renderPhloBlock($out, $kk, $vv, $depth + 2);
					}
					$out[] = $tabs.tab.'}';
				}
			}
			else static::renderPhloBlock($out, $k, $v, $depth + 1);
		}
		$out[] = $tabs.'}';
	}
}
