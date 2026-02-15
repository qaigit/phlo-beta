<?php
function phlo_build($release = false, $return = false){
	$builder = phlo_builder(true, $release, $return);
	return $return ? $builder->output : debug();
}

function phlo_builder($build = false, $release = false, $return = false){
	return new phlo_builder(phlo_sources(), $build, $release, $return);
}

function phlo_build_file(string $file, $comments = false){
	return (new phlo_file($file))->buildPHP($comments);
}

function phlo_lib($lib, $canDisable = false){
	$JSON = json_read($file = data.'app.json', true);
	if (($index = array_search($lib, build['libs'])) === false){
		$JSON['build']['libs'][] = $lib;
		natcasesort($JSON['build']['libs']);
	}
	elseif ($canDisable) unset($JSON['build']['libs'][$index]);
	else return;
	$JSON['build']['libs'] = array_values($JSON['build']['libs']);
	return json_write($file, $JSON);
}

class phlo_builder {
	public $files = [];
	public $output;
	public function __construct(array $sources, bool $build = false, public readonly bool $release = false, public readonly bool $return = false){
		debug('Builder started');
		$this->read($sources);
		$this->applyMods();
		$build && $this->build();
	}
	private function setting(string $name, $default = null){
		return $this->release ? (release[$name] ?? $default) : (build[$name] ?? $default);
	}
	private function read($sources){
		foreach ($sources AS $file){
			$source = new phlo_file($file);
			$this->files[$source->class] ??= $source;
		}
	}
	private function applyMods(){
		foreach ($this->files AS $file){
			foreach ($file->nodes AS $name => $node){
				if (str_starts_with($name, '%') && strpos($name, dot)){
					[$class, $name] = explode(dot, substr($name, 1), 2);
					$node->name = $name;
					isset($this->files[$class]) && $this->files[$class]->nodes[$name] = $node;
				}
			}
		}
	}
	private function build(){
		$functions = $this->buildFunctions();
		$routes = $this->setting('routes', true) ? $this->buildRoutes() : null;
		$this->buildApp($functions, $routes);
		$this->buildAssets();
	}
	public function buildFunctions():string {
		$functions = void;
		$exclude = $this->setting('exclude', []);
		foreach ($this->files AS $class => $file){
			if (in_array($class, $exclude)) continue;
			foreach ($file->functions AS $node){
				!$this->release && $node->comments && $functions .= '// '.strtr($node->comments, [lf => "\n// "]).lf;
				$functions .= $node->renderFunction($class).($this->release ? void : lf);
			}
		}
		return $functions;
	}
	public function buildRoutes($scope = 'app'){
		$routes = [];
		$exclude = $this->setting('exclude', []);
		foreach ($this->files AS $class => $file){
			if (in_array($class, $exclude)) continue;
			foreach ($file->nodes AS $node){
				if ($node->node !== 'route') continue;
				$routes[] = $node->renderRoute($class);
			}
		}
		return (new phlo_node(node: 'static', name: 'route', operator: 'method', body: indent(implode(lf, $routes))))->renderMethod($scope);
	}
	private function buildApp($functions, $routes){
		$path = $this->release ? release['php'] : php;
		$classes = void;
		$comments = $this->setting('comments', !$this->release);
		$exclude = $this->setting('exclude', []);
		foreach ($this->files AS $class => $file){
			if (in_array($class, $exclude)) continue;
			if (!$PHP = $file->buildPHP($comments, $class === 'app' ? $functions : null, $class === 'app' ? $routes : null)) continue;
			$filename = strtr($class, [us => dot]).'.php';
			$classes .= "\t'$class' => '$filename',\n";
			if ($this->return) $this->output['files'][$class] = $PHP;
			else $this->write($path.$filename, $PHP, true) && function_exists('opcache_invalidate') && opcache_invalidate($path.$filename, true);
		}
		$this->write($file = $path.'classmap.php', "<?php return [\n$classes];\n") && function_exists('opcache_invalidate') && opcache_invalidate($file, true);
	}
	private function buildAssets(){
		$style = [];
		$script = ['app' => []];
		$minJS = $this->setting('minifyJS', $this->release);
		$exclude = $this->setting('exclude', []);
		$files = $this->files;
		uasort($files, fn($a, $b) => str_starts_with($b->file, __DIR__.'/libs/') <=> str_starts_with($a->file, __DIR__.'/libs/'));
		foreach ($files AS $class => $file){
			if (in_array($class, $exclude)) continue;
			foreach ($file->assets AS $asset){
				if ($asset->node === 'script' && !$minJS) $asset->body = "/* $file->file */\n$asset->body";
				foreach (explode(comma, $asset->ns ?? build['defaultNS'] ?? 'app') AS $ns) ${$asset->node}[$ns][] = $asset->body;
			}
		}
		$path = $this->release ? release['www'] : www;
		if (build['buildCSS'] ?? true){
			$icons = isset(build['icons']) ? $this->buildIcons(build['icons'], $path) : void;
			$minCSS = $this->setting('minifyCSS', $this->release);
			$iconNS = build['iconNS'] ?? 'app';
			foreach ($style AS $ns => $items){
				if ($icons && (!$iconNS || in_array($ns, explode(comma, $iconNS)))) $items[] = $icons;
				$CSS = phlo_css(implode(lf, $items), $minCSS);
				if ($this->return) $this->output['assets']["$ns.css"] = $CSS;
				else $this->write("$path$ns.css", $CSS);
			}
		}
		if (build['buildJS'] ?? true){
			$phloJS = build['phloJS'] ?? false;
			$phloNS = (array)(build['phloNS'] ?? 'app');
			foreach ($script AS $ns => $JS){
				$inNS = in_array($ns, $phloNS);
				if (($phloJS && !$inNS) || (!$phloJS && $inNS)) $JS = [$engine ??= rtrim(file_get_contents(__DIR__.'/phlo.js')), ...$JS, "'https://',phlo.tech,'/'"];
				$JS = implode(lf.lf, $JS).lf;
				if ($minJS) $JS = preg_replace('/\n}/', '}', preg_replace('/\n\\s+/', lf, preg_replace('#(^\s*//.*?$)|(/\*.*?\*/)#ms', void, $JS)));
				if ($this->return) $this->output['assets']["$ns.js"] = $JS;
				else $this->write("$path$ns.js", $JS);
			}
		}
	}
	private function buildIcons(string|array $folders, string $buildPath = www){
		if (!$files = files($folders, '*.png')) return;
		$images = [];
		$width = 0;
		$height = 0;
		$topX = [];
		$topY = [];
		foreach ($files AS $file){
			$img = imagecreatefrompng($file);
			$ix = imagesx($img);
			$iy = imagesy($img);
			@$topX[$ix]++;
			@$topY[$iy]++;
			$width += $ix;
			$height = max($height, $iy);
			$images[basename($file, '.png')] = $img;
		}
		arsort($topX);
		arsort($topY);
		$topX = key($topX);
		$topY = key($topY);
		$version = trim($this->files['app']->nodes['version']->body ?? '1.0', '\'"');
		$CSS = ".icon {\n".
		"\tbackground-image: url(/icons.png?$version);\n".
		"\tbackground-position-y: bottom;\n".
		"\tdisplay: inline-block;\n".
		"\toverflow: hidden;\n".
		"\tpadding: 0;\n".
		"\twidth: {$topX}px;\n".
		"\theight: {$topY}px;\n}";
		$icons = imagecreatetruecolor($width, $height);
		imagefill($icons, 0, 0, imagecolorallocatealpha($icons, 0, 0, 0, 127));
		imagealphablending($icons, false);
		imagesavealpha($icons, true);
		$left = 0;
		foreach (array_reverse($images, true) AS $name => $img){
			imagecopy($icons, $img, $left, $height - imagesy($img), 0, 0, imagesx($img), imagesy($img));
			if ($match = regex('/(.+)\.(.+)$/', $name)) $selector = "body.$match[2] .icon.$match[1]";
			else $selector = '.icon.'.$name;
			$il = $left ? tab.'background-position-x: '.($left ? dash.$left : '0').'px;'.lf : void;
			$iw = ($ix = imagesx($img)) === $topX ? void : "\twidth: {$ix}px;\n";
			$ih = ($iy = imagesy($img)) === $topY ? void : "\theight: {$iy}px;\n";
			($il || (!$match && $iw || $ih)) && $CSS .= lf.$selector.' {'.lf.$il.($match ? void : $iw.$ih).'}';
			$left += imagesx($img);
		}
		$filename = 'icons.png';
		$tempFile = tempnam(sys_get_temp_dir(), void);
		$iconFile = "$buildPath$filename";
		imagepng($icons, $tempFile);
		if (is_file($iconFile) && md5_file($tempFile) === md5_file($iconFile)) unlink($tempFile);
		else {
			rename($tempFile, $iconFile);
			debug("$filename written");
		}
		return $CSS;
	}
	private function write(string $file, string $content, bool $touch = false){
		if (file_exists($file) && md5_file($file) === md5($content)) return $touch && touch($file);
		if (file_put_contents($file, $content) !== false) debug('Written: '.basename($file).space.size_human(filesize($file)));
		else error("Build Error: Couldn't write $file");
		return true;
	}
}

class phlo_file {
	public $class;
	public $meta = [];
	public $functions = [];
	public $nodes = [];
	public $assets = [];
	public function __construct(public string $file){
		!is_file($file) && error('Phlo file does not exist: '.esc($file));
		$this->class = strtr(basename($file, '.phlo'), [dot => us]);
		$vis = '(public|protected|private)?\s*';
		$name = '(%?[A-Za-z0-9_]+(?:\.[A-Za-z0-9_]+)?)';
		$typename = '(?:\??[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)?(?:\[\])?)';
		$type = "\s*(?::($typename(?:\|$typename)*))?";
		$args = '\s*(?:\(([^\)]*)\))?';
		$patterns = [
			"/^(route)\s*(sync|async|both)?\s*(GET|POST|PUT|PATCH|DELETE)(?:\s+([^@{>]*?[^@\s{>]))?(?:\s*@([A-Za-z,]+))?\s*(\{|\=\>)\s*(.*)/",
			"/^(function)\s*$name$args$type\s*(\{|\=\>)\s*(.*)/",
			"/^(function)\s*$name$args$type\s*()()/",
			"/^$vis(static|method|prop)\s*$name$args$type\s*(\{|\=\>)\s*(.*)/",
			"/^$vis(view)\s*$name$args\s*((?::(?=\s|$))|\{|\=\>)\s*(.*)/",
			"/^$vis(view)\s*$args\s*((?::(?=\s|$))|\{|\=\>)\s*(.*)/",
			"/^$vis(prop|readonly)\s*$name$type$/",
			"/^$vis(prop|readonly)\s*$name$type\s*(\=\>|\=)\s*(.*)/",
			"/^$vis(prop)\s*$name$args$type\s*((?::(?=\s))|\{|\=\>)\s*(.*)/",
			"/^$vis(static|const)\s*$name\s*(=)\s*(.*)/",
			"/^$vis(static)\s*$name$type$/",
			"/^$vis(method|prop)\s*$name$args$type\s*()()$/",
			"/^<(script|style)(?:\s+ns=([^>]+))?>$/",
		];
		$operators = ['{' => 'method', '=>' => 'arrow', ':' => 'view', '=' => 'value'];
		$translate = ['[' => ']', '(' => ')', '{' => '}'];
		$fp = fopen($file, 'r');
		$lineIndex = 0;
		$controller = [];
		$controllerLine = null;
		$comments = [];
		while (($line = fgets($fp)) !== false){
			$lineIndex++;
			$line = rtrim($line);
			$trim = ltrim($line);
			if (str_starts_with($trim, '@')){
				list($key, $value) = explode(': ', trim(substr($trim, 1)), 2);
				$this->meta[$key] = ltrim($value);
				if ($key === 'class') $this->class = ltrim($value);
				continue;
			}
			if (!$trim || str_starts_with($trim, '//') || str_starts_with($trim, '#')){
				($comment = ltrim($trim, '/# 	')) && $comments[] = $comment;
				continue;
			}
			$node = null;
			foreach ($patterns as $pattern){
				if (!preg_match($pattern, $trim, $node)) continue;
				$node = array_slice($node, 1);
				$count = count($node);
				$keys = match (true){
					$count === 7 && $node[0] === 'route' => ['node','mode','method','path','data','operator','body'],
					$count === 7 && in_array($node[1], ['static','method','prop']) => ['visibility','node','name','args','type','operator','body'],
					$count === 7 => ['visibility','node','name','type','args','operator','body'],
					$count === 6 && $node[0] === 'function' => ['node','name','args','type','operator','body'],
					$count === 6 && $node[1] === 'view' => ['visibility','node','name','args','operator','body'],
					$count === 6 && in_array($node[1], ['method','prop']) && isset($node[3]) && str_starts_with($node[3] ?? '', '(') => ['visibility','node','name','args','type','operator','body'],
					$count === 6 => ['visibility','node','name','type','operator','body'],
					$count === 5 && $node[0] === 'function' => ['node','name','args','operator','body'],
					$count === 5 && $node[1] === 'view' => ['visibility','node','args','operator','body'],
					$count === 5 => ['visibility','node','name','operator','body'],
					$count === 4 => ['visibility','node','name','type'],
					$count === 2 => ['node','ns'],
					$count === 1 => ['node'],
					default => dx('Unknown node', $node),
				};
				$data = array_filter(array_combine($keys, $node), fn($v) => !is_null($v) && $v !== void);
				$node = new phlo_node(...$data);
				$node->line = $lineIndex;
				$node->operator && $node->operator = $operators[$node->operator];
				if ($comments){
					$node->comments = implode(lf, $comments);
					$comments = [];
				}
				$asset = in_array($node->node, ['style','script']);
				$find = null;
				$end = void;
				if ($node->operator === 'view'){
					if ($node->body) $node->body = ltrim($node->body);
					else $find = void;
				}
				elseif ($node->body && isset($translate[substr($node->body, -1)]) && $found = array_filter(str_split($node->body), fn($c) => isset($translate[$c]))){
					$node->body .= lf;
					$end = $find = strtr(implode($found), $translate);
				}
				elseif ($node->operator === 'method') $find = '}';
				elseif ($asset) $find = "</$node->node>";
				if (!is_null($find)){
					$node->body ??= void;
					while (($line = fgets($fp)) !== false){
						$lineIndex++;
						if (($line = rtrim($line)) === $find) break;
						$node->body .= $line.lf;
					}
					$node->body = rtrim($node->body.$end);
				}
				if ($node->node === 'function') $this->functions[$node->name] = $node;
				elseif ($asset) $this->assets[] = $node;
				else {
					if ($node->node === 'route') $name = $node->name = ucfirst(camel(implode(space, [$node->mode, $node->method, ...($node->path ? regex_all('/(?:^| |\$)([A-Za-z0-9_]+)/', $node->path)[1] : ['home']), $node->data ? strtr($node->data, [comma => space]) : null])));
					else $name = $node->name ?? 'view';
					if (isset($this->nodes[$name])) error("Build Error: node \"$name\" exists in $this->file");
					$this->nodes[$name] = $node;
				}
				break;
			}
			if (!$node){
				$controller[] = $line;
				$controllerLine ??= $lineIndex;
			}
		}
		fclose($fp);
		if ($controller){
			$controller = new phlo_node(node: 'method', name: 'controller', operator: 'method', body: implode(lf, $controller), line: $controllerLine);
			$this->nodes = array_merge(['controller' => $controller], $this->nodes);
		}
	}
	public function buildPHP($comments = true, $functions = null, $routes = null){
		$PHP = "<?php\n";
		if ($comments){
			$meta = array_merge(['source' => $this->file, 'phlo' => phlo], $this->meta);
			$metaFind = $meta;
			uksort($metaFind, fn($a, $b) => strlen($b) <=> strlen($a));
			$maxLength = strlen(array_keys($metaFind)[0]);
			foreach ($meta AS $key => $value) $PHP .= '// '.$key.colon.str_repeat(space, $maxLength - strlen($key))." $value\n";
		}
		$type = $this->meta['type'] ?? 'class';
		if (isset($this->meta['namespace'])) $PHP .= 'namespace '.$this->meta['namespace'].";\n";
		if ($functions) $PHP .= $functions;
		if ($extends = $this->meta['extends'] ?? build['extends'] ?? 'obj') $extends = " extends $extends";
		$PHP .= "$type $this->class$extends {\n";
		$body = void;
		foreach ($this->nodes AS $key => $node){
			if (str_starts_with($key, '%')) continue;
			$comments && $node->comments && $body .= "\t// ".strtr($node->comments, [lf => "\n\t// "]).lf;
			if ($node->node !== 'method' && (!$node->operator || $node->operator === 'value')) $body .= $node->renderValue();
			elseif (!$node->shortRoute()){
				$method = $node->renderMethod($this->class);
				if ($node->name === '__handle') $method = strtr($method, ['static function __handle(){' => 'static function __handle('.(($args = $this->nodes['__construct']->args ?? null) ? str_replace(['public ', 'protected ', 'private ', 'readonly '], void, $args) : '...$data').'){']);
				$body .= $method;
			}
			if ($routes && $key === 'controller') [$body .= $routes, $routes = null];
		}
		if (!$body) return;
		$PHP = $PHP.$routes.rtrim($body)."\n}\n";
		return $PHP;
	}
}

class phlo_node extends stdClass {
	public function __construct(...$node){ foreach ($node AS $key => $value) $this->$key = $value; }
	public function __get($key){}
	public function renderFunction($class){
		$type = $this->type ? colon.$this->type.space : void;
		if ($this->operator === 'view'){
			$body = indent($this->buildView());
			$type = ':string ';
		}
		elseif ($this->operator === 'arrow') $body = $this->parsePHP($this->buildArrow());
		else $body = $this->body ? $this->parsePHP($this->parseObjects(strtr($this->body, ['$this' => "%$class"]))) : void;
		$body && $body = "\n$body\n";
		return "function $this->name($this->args)$type{".$body."}\n";
	}
	public function renderRoute(string $class){
		$args = [];
		$pathArg = ($last = $this->method) ? void : 'path: ';
		$asyncArg = ($last = $last && $this->path) ? void : 'async: ';
		$dataArg = ($last = $last && $this->mode) ? void : 'data: ';
		$cbArg = (($last = $last && $this->args) ? void : 'cb: ').sq.($this->shortRoute() ? $this->body : "$class::$this->name").sq;
		$args[] = sq.$this->method.sq;
		if ($this->path) $args[] = $pathArg.sq.strtr(trim($this->path), [sq => bs.sq]).sq;
		if ($this->mode === 'async') $args[] = $asyncArg.'true';
		elseif (in_array($this->mode, [void, 'sync'])) $args[] = $asyncArg.'false';
		if ($this->data) $args[] = "$dataArg'$this->data'";
		$args[] = $cbArg;
		$args = implode(', ', $args);
		return "route($args)";
	}
	public function shortRoute(){ return $this->node === 'route' && preg_match('/^[A-Za-z0-9_]+(?:::[A-Za-z0-9_]+)?$/', $this->body); }
	public function renderMethod(string $class){
		$static = $this->node === 'static' || $this->node === 'route' ? ' static' : void;
		$name = ($this->node === 'prop' ? us : void).($this->name ?: 'view');
		$visibility = $this->visibility ?: ($static || str_starts_with($name, '__') || str_starts_with($name, 'obj') ? 'public' : 'protected');
		$type = $this->type ? colon.$this->type.space : void;
		$args = $this->node === 'route' ? ($this->path ? implode(', ', regex_all('/\$[A-z0-9_]+/', $this->path)[0] ?? []) : void) : $this->args;
		if ($this->operator === 'view'){
			$body = indent($this->buildView());
			$type = ':string ';
		}
		elseif ($this->operator === 'arrow') $body = $this->parsePHP($this->buildArrow());
		else $body = rtrim($this->body ?? void) ? $this->parsePHP($this->parseObjects($this->body)) : void;
		($this->node === 'route' || $this->node === 'static') && $body && $body = strtr($body, ['$this' => "phlo('$class')"]);
		$this->name === 'controller' && $body && $body = indent($body);
		$body && $body = lf.indent($body).lf.tab;
		return "\t$visibility$static function $name($args)$type{".$body."}\n";
	}
	public function renderValue(){
		$vis = $this->visibility ?: 'public';
		$const = $this->node === 'const' ? ' const' : void;
		$static = $this->node === 'static' ? ' static' : void;
		$readonly = $this->node === 'readonly' ? ' readonly' : void;
		$name = ($const ? void : '$').$this->name;
		$type = $this->type ? space.$this->type : void;
		if (isset($this->body)){
			$body = $this->body;
			if (strpos($body, lf)) $body = ltrim(indent($body));
			$body = " = $body";
		}
		else $body = void;
		return "\t$vis$const$static$readonly$type $name$body;\n";
	}
	private function parseObjects($code, $createBlock = false){
		$replace = [];
		$matches = regex_all('/%([A-z0-9_]+)(?:\((.*)\))?/', $code, PREG_SET_ORDER);
		foreach ($matches AS $match){
			if (strlen($match[1]) <= 2 && !array_filter(phlo_sources(), fn($source) => basename($source, '.phlo') === $match[1])) continue;
			$replace[$match[0]] = "phlo('$match[1]'".(isset($match[2]) ? ', '.$this->parseObjects($match[2]) : void).')';
		}
		$code = strtr($code, $replace);
		if ($createBlock) $code = "{{ $code }}";
		return $code;
	}
	private function parsePHP(string $PHP){
		return str_replace ([lf, '\\;'.lf, '(;', '[;', '{;', '};', ',;', '.;', ';;', lf.';'.lf], [';'.lf, lf, '(', '[', '{', '}', ',', '.', ';', lf.lf], $PHP.';');
	}
	private function buildArrow(){
		$body = $this->parseObjects($this->body);
		$body = strpos($body, lf) ? ltrim(indent($body)) : $body;
		if (!str_starts_with($body, 'apply') && !str_starts_with($body, 'echo ') && !str_starts_with($body, 'unset(') && !str_starts_with($body, 'yield ')) $body = "return $body";
		return "\t$body";
	}
	private function buildView(){
		$blockDepth = 0;
		$view = [];
		$lines = [];
		foreach (explode(lf, preg_replace('/{\(\s*(.*?)\s*\)}/s', '{{ ($1) }}', $this->body)) AS $line){
			$pad = regex('/^\s*/', $line)[0] ?? '';
			$tabs = substr_count($pad, "\t");
			$spaces = strlen(str_replace("\t", '', $pad));
			$tabWidth = 2;
			$depth = $tabs + intdiv($spaces, $tabWidth);
			$trim = ltrim($line);
			if (str_starts_with($trim, '<foreach ')) [$blockDepth++, $lines && $view[] = $lines, $lines = [], $view[] = str_repeat(tab, $blockDepth - 1).'foreach ('.$this->parseObjects(trim(substr($trim, 9, -1))).'){'];
			elseif ($trim === '</foreach>') [$lines && $view[] = $lines, $lines = [], $view[] = str_repeat(tab, $blockDepth - 1).'}', $blockDepth--];
			elseif (str_starts_with($trim, '<if ')) [$blockDepth++, $lines && $view[] = $lines, $lines = [], $view[] = str_repeat(tab, $blockDepth - 1).'if ('.$this->parseObjects(trim(substr($trim, 4, -1))).'){'];
			elseif (str_starts_with($trim, '<elseif ')) [$lines && $view[] = $lines, $lines = [], $view[] = str_repeat(tab, $blockDepth - 1).'}'.lf.str_repeat(tab, $blockDepth - 1).'elseif ('.$this->parseObjects(trim(substr($trim, 8, -1))).'){'];
			elseif ($trim === '<else>') [$lines && $view[] = $lines, $lines = [], $view[] = str_repeat(tab, $blockDepth - 1).'}'.lf.str_repeat(tab, $blockDepth - 1).'else {'];
			elseif ($trim === '</if>') [$lines && $view[] = $lines, $lines = [], $view[] = str_repeat(tab, $blockDepth - 1).'}', $blockDepth--];
			else {
				$matches = regex_all('/{\s*([a-z]{2}):\s*(.*?)\s*(?:\(\s*(?=[^)]*[$%\'"\d])(.+?)\s*\))?\s*}/is', $trim, PREG_SET_ORDER);
				foreach ($matches AS $match) $trim = str_replace($match[0], "{{ $match[1]('".strtr(rtrim($match[2]), [sq => bs.sq])."'".(isset($match[3]) ? ", $match[3]" : void).') }}', $trim);
				$matches = regex_all('/(\h*)\{\{\h*(?>(?:[^{}"\']+|"[^"]*"|\'[^\']*\'|\{(?-1)\})*)\h*\}\}/u', $line, PREG_SET_ORDER);
				foreach ($matches AS $match){
					$indentDepth = ($depth - $blockDepth);
					$inner = regex('/^\h*\{\{\h*(.*)\h*\}\}\h*$/s', $match[0])[1] ?? void;
					if ($indentDepth && $match[1] !== void && trim($match[0]) === $trim) $trim = '{{ indentView('.$this->parseObjects($inner).($indentDepth === 1 ? void : ", $indentDepth").') }}';
					else $trim = str_replace(ltrim($match[0]), $this->parseObjects($inner, true), $trim);
				}
				if (strpos($trim, '<') !== false){
					foreach (regex_all('/(<[a-z][\w-]*)(#[A-Za-z][\w-]*)?((?:\.[A-Za-z][\w-]*)+)?/', $trim, PREG_SET_ORDER) AS $match){
						if (count($match) < 3) continue;
						$replace = $match[1];
						$match[2] && $replace .= ' id="'.substr($match[2], 1).'"';
						isset($match[3]) && $replace .= ' class="'.strtr(substr($match[3], 1), [dot => space]).'"';
						$trim = str_replace($match[0], $replace, $trim);
					}
					$trim = preg_replace('/<([a-z][\w-]*)([^<>]*?)\/>/', "<$1$2></$1>", $trim);
					foreach (regex_all('/\s([A-Za-z_:][\w:.-]*)=([^\s"\'=<>`]+)(?=[\s>])/', $trim, PREG_SET_ORDER) AS $match) $trim = str_replace($match[0], space.$match[1].'="'.strtr($match[2], ['+' => space]).'"', $trim);
				}
				foreach (regex_all('/%([A-Za-z_]\w*(?:->\w+)*)/', $trim, PREG_SET_ORDER) AS $match){
					$full = $match[1];
					$base = explode('->', $full)[0];
					if ($base === 's') continue;
					$chain = strstr($full, '->') ?: void;
					$trim = str_replace($match[0], "{{ phlo('$base')".$chain.' }}', $trim);
				}
				$trim = substr(loop(preg_split('/{{|}}/', $trim), fn($part) => str_contains($trim, "{{$part}}") ? trim($part) : dq.strtr($part, [bs.dq => bs.bs.bs.dq, dq => bs.dq]).dq, dot), 1, -1);
				$indent = max(0, $depth - $blockDepth);
				$lines[] = [$blockDepth, str_repeat('\t', $indent).$trim];
			}
		}
		$lines && $view[] = $lines;
		$output = void;
		if (count($view) === 1 && is_array($view[0]) && count($view[0]) === 1) return 'return "'.$view[0][0][1].'";';
		$output .= '$phloView = [];'.lf;
		foreach ($view AS $index => $chunk){
			if (is_array($chunk)) foreach ($chunk AS $i => $line) $output .= str_repeat(tab, $line[0]).'$phloView[] = "'.$line[1].'";'.lf;
			else $output .= $chunk.lf;
		}
		$output = preg_replace('/(\n\t*)""\./', '$1', $output);
		return $output.'return implode(lf, $phloView);';
	}
}
