<?php
require_once(__DIR__.'/constants.php');

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

class PhloException extends Exception {
	public function __construct(string $message, int $code = 0, public array $data = []){ parent::__construct($message, $code); }
	public function payload():array { return ['error' => $this->getMessage(), 'code' => $this->getCode(), 'type' => static::class, 'data' => $this->data ?: null]; }
}

function phlo_exception(Throwable $e):never {
	$msg = $e->getMessage();
	static $retried = false;
	if (build && debug && preg_match('/^Call to undefined function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $msg, $m) && !$retried && ($lib = phlo_find_lib('function', $m[1])) && phlo_activate_lib($lib)) location(slash.req);
	$code = (int)$e->getCode();
	$payload = $e instanceof PhloException ? $e->payload() : ['error' => $msg, 'code' => ($code ?: 500), 'type' => get_class($e), 'data' => ['file' => $e->getFile(), 'line' => $e->getLine()]];
	if (phlo('app')->hasMethod('errorPage')) phlo('app')->errorPage($msg, (int)($payload['code'] ?? 500));
	$d = is_array($payload['data'] ?? null) ? $payload['data'] : [];
	$file = $d['file'] ?? $e->getFile();
	$line = (int)($d['line'] ?? $e->getLine());
	$short = shortpath($file).colon.$line;
	phlo_error_log($short, $msg);
	if (debug) debug_error($e);
	if (cli || async){
		$text = ($payload['type'] ?? 'Error').colon.space.$msg;
		if (async) apply(error: $text);
		fwrite(STDERR, $text.lf);
		exit(1);
	}
	http_response_code($code = $payload['code'] ?? 500);
	header('X-Content-Type-Options: nosniff');
	$title = "Phlo $code Error";
	$CSS = 'body{background:black;color:white;font-family:sans-serif;text-align:center;margin-top:18dvh}pre{white-space:pre-wrap}';
	$body = '<h1>'.esc($title).'</h1><pre>'.esc(($payload['type'] ?? 'Error').colon.space.$msg).'</pre>';
	print(DOM($body, tag('title', $title).lf.tag('style', $CSS)));
	exit(1);
}

function phlo_error_log(string $path, string $msg):int|false {
	$file = data.'errors.json';
	$now = date('j-n-Y G:i:s');
	$id = md5($path.preg_replace('/\s+/', void, trim(preg_replace('~(?:[A-Za-z]:)?[\\/](?:[^\s:/\\\\]+[\\/])*(?:([^\s:/\\\\]+\.[A-Za-z0-9]{1,8})|[^\s:/\\\\]+)(?::\d+)?~', '$1', $msg))));
	$map = is_file($file) ? (json_read($file, true) ?: []) : [];
	$row = $map[$id] ?? [];
	$row['file'] = $path;
	$row['req'] = req;
	$row['msg'] = $msg;
	$row['count'] = ($map[$id]['count'] ?? 0) + 1;
	$row['lastOccured'] = $now;
	unset($map[$id]);
	$map = [...[$id => $row], ...$map];
	return json_write($file, $map);
}

function phlo_app_jsonfile(string $app, string $file){ phlo_app(...json_decode(strtr(file_get_contents($file), ['"%app/' => dq.$app]), true), app: $app); }
function phlo_app(...$args){
	$args['build'] ??= false;
	$args['debug'] ??= false;
	$args['data'] ??= "$args[app]data/";
	$args['php'] ??= $args['app'].($args['build'] ?? null ? 'php/' : void);
	$args['www'] ??= "$args[app]www/";
	foreach ($args AS $key => $value) define($key, $value);
	spl_autoload_register(static function($class){
		static $map;
		$map ??= is_file($file = php.'classmap.php') ? require($file) : [];
		if (isset($map[$class])) return require_once(php.$map[$class]);
		if ($map) return build && debug && ($lib = phlo_find_lib('class', $class)) && phlo_activate_lib($lib) && location(slash.req);
		if (is_file($file = php.strtr($class, [us => dot]).'.php')) return require_once($file);
	});
	defined('composer') && require_once(composer.'vendor/autoload.php');
	define('req', cli ? implode(slash, $cli = array_slice($_SERVER['argv'], 1)) : rawurldecode(substr(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), 1)));
	debug && require(__DIR__.'/debug.php');
	debug && build && !cli && defined('dashboard') && str_starts_with(req.slash, dashboard.slash) && is_file($file = __DIR__.'/dashboard.php') && [require_once(__DIR__.'/build.php'), require($file), new phlo_dashboard(dashboard, __DIR__.slash, substr(req, strlen(dashboard) + 1))];
	debug && build && !cli && (build['auth'] ?? false) && (is_file($file = __DIR__.'/dashboard.php') && last(require($file), phlo_dashboard::auth()) || die('Unauth'));
	set_error_handler(static function($severity, $message, $file = null, $line = 0){
		if (!(error_reporting() & $severity)) return false;
		throw new PhloException($message, $severity, ['file' => $file, 'line' => $line]);
	});
	set_exception_handler('phlo_exception');
	try {
		build && phlo_build_check() && (build['auto'] ?? true) && [require_once(__DIR__.'/build.php'), phlo_build()];
		phlo('app');
		cli && print(json_encode(array_shift($cli)(...$cli), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
	}
	catch (Throwable $e){ phlo_exception($e); }
}

function phlo(?string $phloName = null, ...$args):mixed {
	static $list = [];
	if (is_null($phloName)) return array_keys($list);
	$phloName = strtr($phloName, [slash => us]);
	$handle = method_exists($phloName, '__handle') ? $phloName::__handle(...$args) : ($args ? null : $phloName);
	if ($handle === true){
		if (isset($list[$phloName])) return $list[$phloName]->objImport(...$args);
		$handle = $phloName;
	}
	elseif ($handle && isset($list[$handle])) return $list[$handle];
	$phlo = new $phloName(...$args);
	if ($handle) $list[$handle] = $phlo;
	if ($phlo->hasMethod('controller') && (!cli || $phloName !== 'app')) $phlo->controller();
	return $phlo;
}

function phlo_sync(string $cb, ...$args):mixed { return last($cmd = '/usr/bin/php '.escapeshellarg(rtrim(www, slash).'/app.php').space.escapeshellarg($cb).loop($args, fn($a) => space.escapeshellarg((string)$a), void), exec($cmd, $r, $code), $out = implode(lf, $r), $j = json_decode($out, true), $code !== 0 ? error('Could not execute "'.esc($cb).'" via CLI') : (json_last_error() === JSON_ERROR_NONE ? $j : $out)); }
function phlo_async(string $cb, ...$args):bool { return last($cmd = '/usr/bin/php '.escapeshellarg(rtrim(www, slash).'/app.php').space.escapeshellarg($cb).loop($args, fn($a) => space.escapeshellarg((string)$a), void).' > /dev/null 2>&1 & echo $!', exec($cmd, $r), isset($r[0]) && ctype_digit($r[0]) && (int)$r[0] > 0); }

function phlo_exists(string $obj):bool { return is_file(php.strtr($obj, [us => dot]).'.php'); }
function phlo_build_check():bool { return !is_file($app = php.'app.php') || filemtime($app) < array_reduce(phlo_sources(), fn($a, $f) => max($a, @filemtime($f)), 0); }
function phlo_sources():array {
	$sources = files(isset(build['sources']) ? build['sources'] : app, '*.phlo');
	foreach (build['libs'] AS $lib) $sources[] = is_file($file = __DIR__."/libs/$lib.phlo") ? $file : error('Build Error: Library not found '.esc($lib));
	natcasesort($sources);
	return $sources;
}

function view(?string $body = null, ?string $title = null, array|string $css = [], array|string $js = [], array|string $defer = [], array|string $options = [], array $settings = [], ?string $ns = null, bool|string $uri = req, bool $inline = false, string $bodyAttrs = void, string $htmlAttrs = void, ...$cmds):void {
	if (cli) die($body ?? void);
	!async && !is_bool($uri) && $uri !== req && location("/$uri");
	$app = phlo('app');
	$title && title($title);
	$css = array_merge((array)$css, (array)$app->css);
	$js = array_merge((array)$js, (array)$app->js);
	$defer = array_merge((array)$defer, (array)$app->defer);
	$options = implode(space, array_merge((array)$options, (array)$app->options, debug ? ['debug'] : []));
	$settings = array_merge($settings, (array)$app->settings);
	if (async){
		$uri !== false && $cmds['uri'] = $uri;
		$cmds['trans'] ??= $app->trans ?? true;
		$cmds['title'] = title();
		$css && $cmds['css'] = $css;
		$js && $cmds['js'] = $js;
		$defer && $cmds['defer'] = $defer;
		$cmds['options'] = $options;
		$cmds['settings'] = $settings;
		!is_null($body) && $cmds['inner']['body'] = $body;
		apply(...$cmds);
	}
	$body ??= $cmds['main'] ?? void;
	$version = $app->version ?? '.1';
	$ns ??= $app->ns ?? 'app';
	$link = $app->link ?: [];
	$nonce = $app->nonce ? ' nonce="'.$app->nonce.'"' : void;
	$head = '<title>'.title().'</title>'.lf;
	$head .= '<meta name="viewport" content="'.($cmds['viewport'] ?? $app->viewport ?? 'width=device-width').'">'.lf;
	$app->description && $head .= "<meta name=\"description\" content=\"$app->description\">\n";
	$app->themeColor && $head .= "<meta name=\"theme-color\" content=\"$app->themeColor\">\n";
	$app->nonce && $head .= "<meta name=\"nonce\" content=\"$app->nonce\">\n";
	is_file(www.$filename = 'icons.png') && $link[] = "</$filename?$version>; rel=preload; as=image";
	is_file(www.$filename = "$ns.css") && $css[] = "/$filename";
	is_file(www.$filename = "$ns.js")  && $defer[] = "/$filename";
	$app->head && $head .= $app->head.lf;
	is_file(www.$filename = 'favicon.ico') && $head .= "<link rel=\"icon\" href=\"/$filename?$version\">\n";
	is_file(www.$filename = 'manifest.json') && $head .= "<link rel=\"manifest\" href=\"/$filename?$version\">\n";
	foreach ($css AS $item){
		if ($inline && !is_absolute_url($item)) $head .= '<style'.$nonce.'>'.lf.file_get_contents(www.substr($item, 1)).lf.'</style>'.lf;
		else $head .= '<link rel="stylesheet" href="'.esc($item).qm.$version.'">'.lf;
		if (!$inline && !is_absolute_url($item)) $link[] = "<$item?$version>; rel=preload; as=style";
	}
	foreach ($js AS $item){
		if ($inline && !is_absolute_url($item)) $head .= '<script'.$nonce.'>'.lf.file_get_contents(www.substr($item, 1)).'</script>'.lf;
		else $head .= '<script src="'.esc($item).qm.$version.'"'.$nonce.'></script>'.lf;
		if (!$inline && !is_absolute_url($item)) $link[] = "<$item?$version>; rel=preload; as=script";
	}
	foreach ($defer AS $item){
		if ($inline && !is_absolute_url($item)) $body .= lf.'<script'.$nonce.'>'.lf.file_get_contents(www.substr($item, 1)).'</script>';
		else $head .= '<script src="'.esc($item).qm.$version.'" defer'.$nonce.'></script>'.lf;
		if (!$inline && !is_absolute_url($item)) $link[] = "<$item?$version>; rel=preload; as=script";
	}
	!build && $link && header('Link: '.implode(comma, $link), false);
	debug && $body .= lf.debug_render();
	$options && $bodyAttrs .= " class=\"$options\"";
	$settings && $bodyAttrs .= loop($settings, fn($value, $key) => ' data-'.$key.'="'.esc($value).'"', void);
	die(DOM($body, $head, $cmds['lang'] ?? $app->lang ?? 'en', $bodyAttrs, $htmlAttrs));
}
function apply(...$cmds):never {
	cli || headers_sent() || phlo('app')->streaming || [header('Content-Type: application/json'), header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0'), header('Pragma: no-cache'), header('X-Content-Type-Options: nosniff')];
	debug && $cmds = debug_apply($cmds);
	die(json_encode($cmds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}
function chunk(...$cmds):void {
	static $header;
	phlo('app')->streaming = true;
	$header ??= first(true, cli || headers_sent() || [http_response_code(206), header('Content-Type: text/event-stream'), header('Cache-Control: no-store'), header('X-Content-Type-Options: nosniff')]);
	echo json_encode($cmds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).lf;
	cli || [@ob_flush(), flush()];
}
function DOM(string $body = void, string $head = void, string $lang = 'en', string $bodyAttrs = void, string $htmlAttrs = void):string { return "<!DOCTYPE html>\n<html lang=\"$lang\"$htmlAttrs>\n<head>\n$head</head>\n<body$bodyAttrs>\n$body\n</body>\n</html>"; }
function output(?string $content = null, ?string $filename = null, ?bool $attachment = null, ?string $file = null):never {
	header('Content-Type: '.mime($filename ?? basename($file ?? req)));
	header('Content-Length: '.($file ? filesize($file) : strlen($content)));
	if (is_bool($attachment) || $filename) header('Content-Disposition: '.($attachment ? 'attachment' : 'inline').';filename='.rawurlencode($filename ?? basename($file ?? req)));
	$file ? readfile($file) : print($content);
	exit;
}
function tag(string $tagName, ?string $inner = null, ...$args):string { return "<$tagName".loop(array_filter($args, fn($value) => !is_null($value)), fn($value, $key) => space.strtr($key, [us => dash]).($value === true ? void : '="'.esc($value).'"'), void).'>'.(is_null($inner) ? void : "$inner</$tagName>"); }
function title(?string $title = null, string $implode = ' - '):string {
	static $titles = [];
	return $title ? ($titles[] = $title) : implode($implode, [...$titles, phlo('app')->title ?: 'Phlo '.phlo]);
}

function phlo_css(string $input, bool $compact = true){
	require_once(__DIR__.'/css.php');
	return phlo_css::parse($input, $compact);
}

function css_phlo(string $input){
	require_once(__DIR__.'/css.php');
	return phlo_css::clean($input);
}

function json_read(string $file, ?bool $assoc = null):mixed { return json_decode(file_get_contents($file), $assoc) ?? error('Error reading '.esc($file)); }
function json_write(string $file, $data, $flags = null):int|false { return file_put_contents($file, json_encode($data, $flags ?? jsonFlags), LOCK_EX); }

function HTTP(string $url, array $headers = [], bool $JSON = false, $POST = null, $PUT = null, $PATCH = null, bool $DELETE = false, ?string $agent = null):string|false {
	$curl = curl_init($url);
	if ($POST !== null || $PUT !== null || $PATCH !== null){
		if (!is_null($POST)) [$method = 'POST', $content = $POST];
		elseif (!is_null($PUT)) [$method = 'PUT', $content = $PUT];
		elseif (!is_null($PATCH)) [$method = 'PATCH', $content = $PATCH];
		curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
		if ($JSON) [!is_string($content) && $content = json_encode($content), array_push($headers, 'Content-Type: application/json', 'Content-Length: '.strlen($content))];
		curl_setopt($curl, CURLOPT_POSTFIELDS, $content);
	}
	elseif ($DELETE) curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'DELETE');
	$agent && curl_setopt($curl, CURLOPT_USERAGENT, $agent === true ? $_SERVER['HTTP_USER_AGENT'] : $agent);
	curl_setopt_array($curl, [CURLOPT_COOKIEFILE => data.'cookies.txt', CURLOPT_COOKIEJAR => data.'cookies.txt', CURLOPT_HTTPHEADER => $headers, CURLOPT_FOLLOWLOCATION => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15, CURLOPT_ENCODING => void]);
	$res = curl_exec($curl);
	return $res;
}

function active(bool $cond, string $classList = void):string { return $cond || $classList ? ' class="'.$classList.($cond ? ($classList ? space : void).'active' : void).'"' : void; }
function age(int $time):int { return time() - $time; }
function age_human(int $age):string { return time_human(time() - $age); }
function apcu($key, $cb, int $duration = 3600, bool $log = true):mixed { return first($value = apcu_entry($key, $cb, $duration), $log && debug('C: '.(strlen($key) > 58 ? substr($key, 0, 55).'...' : $key).(is_array($value) ? ' ('.count($value).')' : (is_numeric($value) ? ":$value" : (is_string($value) ? ':string:'.strlen($value) : colon.gettype($value)))))); }
function arr(...$array):array { return $array; }
function auth_log(string $user):int|false { return file_put_contents(data.'access.log', date('j-n-Y H:i:s')." - $user - $_SERVER[REMOTE_ADDR]\n", FILE_APPEND); }
function camel(string $text):string { return lcfirst(str_replace(space, void, ucwords(lcfirst($text)))); }
function create(iterable $items, Closure $keyCb, ?Closure $valueCb = null):array { return array_combine(loop($items, $keyCb), $valueCb ? loop($items, $valueCb) : $items); }
function debug(?string $msg = null){
	if (!debug) return;
	static $debug = [];
	if (is_null($msg)) return $debug;
	$debug[] = $msg;
}
function dirs(string $path):array|false { return glob("$path*", GLOB_MARK | GLOB_ONLYDIR); }
function duration(int $decimals = 5, bool $float = false):string|float { return last($d = microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)), $float ? round($d, $decimals) : rtrim(rtrim(sprintf("%.{$decimals}f", $d), '0'), dot).'s'.($d > 0 && $d < .5 ? ' ('.round(1 / $d).'/s)' : void)); }
function esc($string):string { return htmlspecialchars((string)$string, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function error(string $msg, int $code = 500):never { throw new PhloException($msg, $code); }
function files(string|array $paths, string $ext = '*.*'):array { return array_merge(...loop((array)$paths, fn($path) => glob("$path$ext"))); }
function first(...$args):mixed { return current($args); }
function indent(string $string, int $depth = 1):string { return ($tab = str_repeat(tab, $depth)).rtrim(strtr($string, [lf => lf.$tab]), tab); }
function indentView(string $string, int $depth = 1):string { return last($tab = str_repeat(tab, $depth), rtrim(preg_replace('/\n(\t*)</', "\n$1$tab<", $string), tab)); }
function is_absolute_url(string $url):bool { return str_starts_with($url, 'http://') || str_starts_with($url, 'https://'); }
function last(...$args):mixed { return end($args); }
function location(?string $location = null):never { async ? apply(location: $location ?? true) : [header('Location: '.($location ?? ($_SERVER['HTTP_REFERER'] ?? slash))), exit]; }
function loop(iterable $data, closure|array $cb, ?string $implode = null):mixed {
	$return = [];
	$isArray = is_array($cb);
	foreach ($data AS $key => $value) $return[$key] = $isArray ? $cb[0]->{$cb[1]}($value, $key) : $cb($value, $key);
	return is_null($implode) ? $return : implode($implode, $return);
}
function mime(string $filename):string { return ['html' => 'text/html', 'css' => 'text/css', 'gif' => 'image/gif', 'ico' => 'image/x-icon', 'ini' => 'text/plain', 'js' => 'application/javascript', 'json' => 'application/json', 'jpg' => 'image/jpeg', 'jpe' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'jfif' => 'image/jpeg', 'ogg' => 'audio/ogg', 'mp3' => 'audio/mpeg', 'mp4' => 'video/mp4', 'pdf' => 'application/pdf', 'phlo' => 'application/phlo', 'php' => 'application/x-httpd-php', 'png' => 'image/png', 'svg' => 'image/svg+xml', 'txt' => 'text/plain', 'webp' => 'image/webp', 'xml' => 'text/xml'][pathinfo($filename, PATHINFO_EXTENSION)] ?? (is_file($filename) ? mime_content_type($filename) : 'application/octet-stream'); }
function obj(...$data):obj { return new obj(...$data); }
function regex(string $pattern, string $subject, int $flags = 0, int $offset = 0):array { return preg_match($pattern, $subject, $match, $flags, $offset) ? $match : []; }
function regex_all(string $pattern, string $subject, int $flags = 0, int $offset = 0):array { return preg_match_all($pattern, $subject, $matches, $flags, $offset) ? $matches : []; }
function req(int $index, ?int $length = null):mixed {
	static $parts;
	$parts ??= explode(slash, req);
	return is_null($length) ? ($parts[$index] ?? null) : (implode(slash, array_slice($parts, $index, $length < 0 ? null : $length)) ?: null);
}
function route(?string $method = null, string $path = void, ?bool $async = null, ?string $data = null, ?string $cb = null){
	if ($method && $method !== method) return;
	if (!is_null($async) && $async !== async) return;
	if ($data && phlo('payload')->objKeys !== explode(comma, $data)) return;
	$req = array_filter(explode(slash, req));
	$cbArgs = [];
	$index = -1;
	foreach (array_filter(explode(space, $path)) AS $index => $item){
		$reqItem = req($index);
		if (strpos($item, '$') === 0){
			$item = substr($item, 1);
			if (str_ends_with($item, '=*')){
				$cbArgs[substr($item, 0, -2)] = implode(slash, array_slice($req, $index));
				$index = count($req) - 1;
				break;
			}
			elseif (str_ends_with($item, qm)){
				$item = substr($item, 0, -1);
				if ($reqItem && $item !== $reqItem) return;
				$reqItem = $item === $reqItem;
			}
			elseif (str_contains($item, eq)){
				list ($item, $default) = explode(eq, $item, 2);
				$default = $default ?: null;
			}
			elseif (is_null($reqItem)) return;
			if (str_contains($item, dot) && (list($item, $length) = explode(dot, $item, 2)) && strlen($reqItem) != $length) return false;
			if (str_contains($item, colon)){
				(list ($item, $list) = explode(colon, $item, 2)) && $list = explode(comma, $list);
				if (!$reqItem || in_array($reqItem, $list)) $cbArgs[$item] = $reqItem ?: $default ?? null;
				else return;
			}
			else $cbArgs[$item] = $reqItem ?? $default;
		}
		elseif ($item !== $reqItem) return;
	}
	if (isset($req[$index + 1])) return;
	if (!$cb) return obj(...$cbArgs);
	if ($cb(...$cbArgs) === false) return;
	exit;
}
function shortpath(?string $file):string {
	if (!$file) return 'unknown';
	$p = explode(slash, str_replace(bs, slash, $file));
	$n = count($p);
	return $n >= 2 ? $p[$n - 2].slash.$p[$n - 1] : end($p);
}
function size_human(int $size, int $precision = 0):string {
	foreach (['b', 'Kb', 'Mb', 'Gb', 'Tb'] AS $range){
		if ($size / 1024 < 1) break;
		$size /= 1024;
	}
	return round($size, $precision).$range;
}
function slug(string $text):string { return trim(preg_replace('/[^a-z0-9]+/', dash, strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text))), dash); }
function time_human(?int $time = null):string {
	static $labels;
	$labels ??= last($labels = arr(seconds: 60, minutes: 60, hours: 24, days: 7, weeks: 4, months: 13, years: 1), defined('tsLabels') && $labels = array_combine(tsLabels, $labels), $labels);
	$age = time() - $time;
	foreach ($labels AS $range => $multiplier){
		if ($age / $multiplier < 1.6583) break;
		$age /= $multiplier;
	}
	return round($age)." $range";
}
function token(int $length = 8, ?string $input = null, ?string $sha1 = null):string {
	$sha1 ??= sha1($input ?? random_int(date('Y'), PHP_INT_MAX), true);
	$token = void;
	for ($i = 0; strlen($token) < $length; $i++) $token .= chr(ord('a') + (ord($sha1[$i % 20]) % 26));
	return $token;
}
function wsCast($wsTarget = 'broadcast', $wsHost = null, $wsEndpoint = null, ...$data){ return $wsTarget ? HTTP($wsEndpoint ?? 'http://0.0.0.0:3001/message', JSON: true, POST: arr(host: $wsHost ?? host, target: $wsTarget, data: $data)) : null; }
