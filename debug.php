<?php
function debug_elements(){
	$elements = phlo();
	natcasesort($elements);
	$classes = [];
	foreach ($elements AS $el){
		if (strpos($el, slash)){
			list($class, $handle) = explode(slash, $el, 2);
			$classes[$class] = [...($classes[$class] ?? []), $handle];
		}
		else $classes[$el] = [];
	}
	return array_values(loop($classes, fn($handles, $el) => $el.($handles ? '['.implode(comma, $handles).']' : void)));
}

function debug_render($contentLength = null){
	$out = "console.log('%c[".id." ".(phlo('app')->version ?? '.1')."] [Phlo ".phlo."] [".size_human(memory_get_peak_usage()).']'.($contentLength ? ' [DOM: '.size_human($contentLength).']' : void).' ['.ltrim(duration(), 0)."]','color:lime')";
	$els = debug_elements();
	if ($c = count($els)) $out .= ";console.log('%cphlo ($c)','font-weight:bold','\\n".strtr(implode(space, $els), [sq => bs.sq])."')";
	if ($dc = count($dbg = debug())) $out .= ";console.log('%cdebug ($dc)','font-weight:bold','\\n".strtr(implode(lf, $dbg), [lf => '\n', sq => bs.sq])."')";
	$out .= ";document.getElementById('debugScript').remove()";
	return '<script id="debugScript"'.(($nonce = phlo('app')->nonce) ? " nonce=\"$nonce\"" : void).'>'.$out.'</script>';
}

function debug_apply($args){
	$args['phlo'] = debug_elements();
	$args['debug'] = [...($args['debug'] ?? []), ...((array)debug()), ltrim(number_format((float)duration(4, true), 4).'s', '0')];
	return $args;
}

function debug_css(){ return file_get_contents(__DIR__.'/assets/debug.css').debug_css_highlight(); }
function debug_css_highlight(){ return file_get_contents(__DIR__.'/assets/highlight.css'); }
function debug_js(){
	$nonce = phlo('app')->nonce;
	$hl = file_get_contents(__DIR__.'/assets/highlight.js');
	$js = file_get_contents(__DIR__.'/assets/debug.js');
	return '<script'.($nonce ? ' nonce="'.$nonce.'"' : void).'>'.$hl.$js.'</script>';
}

function debug_fn($who){
	if (!$who) return null;
	$p = strrpos($who, '::');
	if ($p !== false) return substr($who, $p + 2);
	$p = strrpos($who, '->');
	if ($p !== false) return substr($who, $p + 2);
	return $who;
}

function debug_error($e){
	$type = 'Phlo '.get_class($e);
	$msg = $e->getMessage();
	$codeShown = ((int)$e->getCode() ?: 500);
	$file = $e instanceof PhloException && isset($e->data['file']) ? $e->data['file'] : $e->getFile();
	$line = $e instanceof PhloException && isset($e->data['line']) ? $e->data['line'] : $e->getLine();
	$trace = debug_frames($e->getTrace());
	$frames = debug_frames($codeShown === 404 ? $e->getTrace() : array_merge([['file'=>$file, 'line'=>$line]], $e->getTrace()));
	$useCall = ($trace && debug_fn($trace[0][2] ?? null) === 'error');
	if ($useCall) [$vf, $vl] = [$trace[0][0], (int)$trace[0][1]];
	elseif ($codeShown === 404){
		$a = $frames[0] ?? [$file, (int)$line, null];
		[$vf, $vl] = [$a[0], (int)$a[1]];
	}
	else [$vf, $vl] = [$file, (int)$line];
	if (cli || async){
		$codeLine = file_line($vf, $vl);
		$plain = get_class($e).lf.$msg.lf.lf.basename($vf).colon.$vl.($codeLine ? lf.$codeLine : void);
		if (async) apply(error: $plain);
		fwrite(STDERR, $plain.lf);
		exit(1);
	}
	if (phlo('app')->streaming){
		$codeLine = file_line($vf, $vl);
		echo json_encode(arr(error: get_class($e).lf.$msg.lf.lf.basename($vf).colon.$vl.($codeLine ? lf.$codeLine : void)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).lf;
		exit(1);
	}
	$phloFile = phlo_source($vf);
	$stack = implode(void, array_map(fn($fr) => debug_frame($fr[0], $fr[1], $fr[2] ?? null, $fr[0] === $vf && (int)$fr[1] === $vl), $frames));
	$tabs = '<button class="tab-link active" data-tab="tab-php">'.esc(basename($vf)).'</button>'.($phloFile ? '<button class="tab-link" data-tab="tab-phlo">'.esc(basename($phloFile)).'</button>' : void);
	$phpView = '<div id="tab-php" class="tab-content active">'.debug_view_php($vf, $vl).'</div>';
	$phloView = $phloFile ? '<div id="tab-phlo" class="tab-content">'.debug_view_phlo($phloFile).'</div>' : void;
	$head = tag('title', 'Phlo '.(int)$codeShown.' Error - '.id).lf.'<style>'.strtr(debug_css(), [' {' => '{', ': ' => colon, tab => void, lf => void, semi.lf.'}' => '}']).'</style>'.lf;
	$header = debug_header_errorpage($type, $msg, req, $codeShown);
	$left = '<div class="stack-panel"><div class="call-stack">'.$stack.'</div></div>';
	$right = '<div class="code-panel"><div class="tabs">'.$tabs.'</div><div class="code-view">'.$phpView.$phloView.'</div></div>';
	headers_sent() || http_response_code($codeShown < 100 ? 500 : $codeShown);
	print(DOM('<div class="container">'.$header.'<main class="content-grid">'.$left.$right.'</main></div>'.debug_js(), $head));
	exit(1);
}

function debug_strip(string $html){
	$html = preg_replace('~</?pre\b[^>]*>~i', void, $html);
	$html = preg_replace('~</?code\b[^>]*>~i', void, $html);
	return $html;
}

function debug_normalize(string $html){
	return preg_replace_callback('/style="([^"]+)"/', function($m){
		$s = $m[1];
		$s = preg_replace('/:\s+/', ':', $s);
		$s = str_ireplace(['color:#0000BB', 'color:#007700', 'color:#DD0000', 'color:#000000'], ['color:#8fb2ff', 'color:#7fd17a', 'color:#ffb3b3', 'color:#e5e7eb'], $s);
		return 'style="'.$s.'"';
	}, $html);
}

function debug_snippet(string $file, int $line){
	$ln = file_line($file, $line);
	if ($ln === null) return '<pre class="snippet"><code></code></pre>';
	$html = highlight_PHP_line($ln);
	$html = debug_strip($html);
	$html = debug_normalize($html);
	return '<pre class="snippet"><code>'.$html.'</code></pre>';
}

function debug_view_php(string $file, int $line){
	$phpHTML = highlight_PHP(file_get_contents($file));
	$phpHTML = debug_normalize($phpHTML);
	$phpHTML = linenumber(explode(lf, $phpHTML));
	$phpHTML = preg_replace('/id="l'.preg_quote((string)$line, slash).'"/', 'id="hl"', $phpHTML, 1);
	return debug_file($file, $line).$phpHTML;
}

function debug_view_phlo(string $file){
	$src = file_get_contents($file);
	$phloHTML = esc($src);
	$phloHTML = linenumber(explode(lf, $phloHTML), prefix: 'p');
	return debug_file($file).$phloHTML;
}

function debug_links($file, $line = null){
	if (!$file) return [null, null];
	$bn = basename($file);
	$phpLink = null;
	if (defined('dashboard')){
		if ($bn === 'phlo.php' && $line) $phpLink = slash.dashboard.'/engine#l'.$line;
		elseif (defined('php') && is_string(php) && str_starts_with($file, php)) $phpLink = slash.dashboard.'/build/'.$bn.'#l'.$line;
	}
	$phloLink = null;
	if (defined('dashboard')){
		if (str_ends_with($file, '.phlo')) $phloLink = slash.dashboard.'/source#'.basename($file);
		else {
			$src = phlo_source($file);
			$phloLink = $src ? slash.dashboard.'/source#'.basename($src) : null;
		}
	}
	return [$phpLink, $phloLink];
}

function debug_frame(string $f, int $l, ?string $who = null, bool $active = false){
	$ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
	[$phpLink] = debug_links($f, $l);
	$left = $ext === 'php' && $phpLink ? '<a class="file-path link" href="'.esc($phpLink).'">'.esc(shortpath($f).':'.$l).'</a>' : '<span class="file-path">'.esc(shortpath($f).':'.$l).'</span>';
	$whoHtml = $who ? '<span class="function-name">'.esc($who).'</span>' : void;
	$info = '<div class="frame-info">'.$left.$whoHtml.'</div>';
	$snip = $ext === 'php' ? '<div class="code-snippet">'.debug_snippet($f, $l).'</div>' : void;
	$target = ($ext === 'phlo' ? 'tab-phlo' : 'tab-php');
	return '<div class="stack-frame'.($active ? ' active' : void).'" data-target-file="'.esc($target).'" data-target-line="'.(int)$l.'">'.$info.$snip.'</div>';
}

function debug_frames(array $trace){
	$frames = [];
	foreach ($trace AS $fr){
		$cl = $fr['class'] ?? null;
		$fn = $fr['function'] ?? null;
		$f  = $fr['file'] ?? null;
		$l  = $fr['line'] ?? null;
		if ($cl === 'obj' || $fn === 'phlo') continue;
		if ($f && basename($f) === 'phlo.php' && $cl && $fn && method_exists($cl, $fn)){
			try {
				$rm = new ReflectionMethod($cl, $fn);
				$rf = $rm->getFileName();
				$rl = $rm->getStartLine();
				if ($rf && $rl){
					$f = $rf;
					$l = $rl;
				}
			}
			catch (Throwable $x) {}
		}
		if ($f && $l) $frames[] = [$f, (int)$l, ($fn ? ($cl ? $cl.($fr['type'] ?? '::').$fn : $fn) : null)];
	}
	return $frames;
}

function debug_header(){ return '<div class="stats"><span>✶ '.esc(size_human(memory_get_peak_usage())).'</span><span>⏱ '.esc(duration(4)).'</span></div>'; }
function debug_header_errorpage(string $type, string $msg, string $req, int $code){ return '<header class="error-header"><div><div class="error-type">'.esc($type).'</div><h1>'.esc($msg).'</h1><div class="request-info prominent"><span class="method">/'.esc(method).'</span><span class="path">'.esc($req).'</span></div>'.debug_header().'</div><div class="error-code">'.(int)$code.'</div></header>'; }
function debug_header_debugpage(){ return '<header class="error-header"><div><div class="error-type">Phlo Debug</div><div class="request-info prominent"><span class="method">'.esc(method).'</span><span class="path">/'.esc(req).'</span></div>'.debug_header().'</div><div class="error-code"></div></header>'; }

function debug_file(string $file, ?int $line = null){
	[$phpLink, $phloLink] = debug_links($file, $line);
	$link = str_ends_with($file, '.php') ? $phpLink : $phloLink;
	return $link ? '<div class="file-location"><a href="'.esc($link).'">'.esc($file).'</a></div>' : '<div class="file-location">'.esc($file).'</div>';
}

function phlo_find_lib(string $type, string $name){
	if (!build) return;
	$paths = array_merge([__DIR__.'/libs/'], dirs(__DIR__.'/libs/'));
	$list = files($paths, '*.phlo');
	require_once(__DIR__.'/build.php');
	foreach ($list AS $f){
		$pf = new phlo_file($f);
		if ($type === 'class'){
			$base = basename($f, '.phlo');
			$guess = strtr($base, [dot => us]);
			$class = $pf->class ?? $guess;
			if ($class === $name) return strtr($f, [__DIR__.'/libs/' => void, '.phlo' => void]);
		}
		else {
			if (isset($pf->functions[$name])) return strtr($f, [__DIR__.'/libs/' => void, '.phlo' => void]);
			$src = @file_get_contents($f);
			if ($src && preg_match('/\bfunction\s+'.preg_quote($name, slash).'\b/u', $src)) return strtr($f, [__DIR__.'/libs/' => void, '.phlo' => void]);
		}
	}
	return;
}

function phlo_activate_lib(string $lib): bool {
	$libPhlo = __DIR__.'/libs/'.strtr($lib, [us => dot]).'.phlo';
	if (!is_file($libPhlo)) return false;
	phlo_lib($lib);
	debug("activated $libPhlo");
	if (is_file($appFile = php.'app.php')) @touch($appFile, max(0, filemtime($appFile) - 1));
	return true;
}

function phlo_source(string $phpFile){
	if (!build) return null;
	$lines = @file($phpFile) ?: [];
	foreach (range(0, 16) AS $i) if (isset($lines[$i]) && str_starts_with($lines[$i], '// source: ')) return trim(substr($lines[$i], 10));
	return null;
}

function highlight_PHP(string $code){
	$html = highlight_string($code, true);
	$html = preg_replace('/<\/?pre\b[^>]*>/i', void, $html);
	$html = preg_replace('/<\/?code\b[^>]*>/i', void, $html);
	$html = strtr($html, ['<br />' => "\n", '&nbsp;&nbsp;&nbsp;&nbsp;' => "\t", '&nbsp;' => space, '<span style="color: ' => '<span style="color:']);
	$html = preg_replace('/([\s\t]+)<\/span>/', '</span>$1', $html);
	$html = strtr($html, ["\n".'</span>' => '</span>'."\n"]);
	return $html;
}

function highlight_PHP_line(string $line){
	$html = highlight_string('<?php'.lf.trim($line), true);
	$html = strtr($html, ['&lt;?php'.lf => void]);
	return $html;
}

function file_line($file, $line){
	$lines = @file($file) ?: [];
	return isset($lines[$line-1]) ? ltrim(rtrim($lines[$line-1], nl)) : void;
}

function linenumber(array $lines, $editable = false, $prefix = void){ return '<ol'.($editable ? ' contenteditable spellcheck="false"' : void).' class="code">'.lf.loop($lines, fn($line, $index) => '<li id="'.$prefix.'l'.($index + 1).'">'.$line.'</li>', lf).lf.'</ol>'; }

function dr(...$data){
	ob_start();
	var_dump(...$data);
	return trim(ob_get_clean());
}

function debug_head($f, $l, $who = null){
	$line = trim((string)file_line($f, $l));
	return shortpath($f).colon.$l.($who ? space.$who : void).($line ? lf.$line : void);
}

function debug_out(string $text){
	if (cli) return print($text.lf);
	if (async){ debug($text); return true; }
	return false;
}

function d(...$data){
	$t = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 96);
	$frames = debug_frames($t);
	$last = $frames[0] ?? null;
	$out = $last ? debug_head($last[0], $last[1], $last[2] ?? null).str_repeat(lf, 2).dr(...$data) : dr(...$data);
	if (debug_out($out)) return;
	if (!$last){
		echo '<div style="display:inline-block;vertical-align:top;background:#1a1a1a;border:1px solid #333;border-radius:6px;padding:6px 8px;margin:6px 0;color:#fff;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12.5px;line-height:1.5"><pre style="margin:0;background:#1a1a1a;color:#fff;white-space:pre-wrap;word-break:break-word">'.esc($out).'</pre></div>';
		return;
	}
	[$f, $l, $who] = $last;
	[$phpLink] = debug_links($f, $l);
	$label = $phpLink ? '<a href="'.esc($phpLink).'" style="color:#bef264;text-decoration:none;border-bottom:1px dotted #bef264">'.esc(shortpath($f).':'.$l).'</a>' : esc(shortpath($f).':'.$l);
	$whoHtml = $who ? ' <span style="color:#bef264;font-family:ui-monospace,Menlo,Consolas,monospace">'.esc($who).'</span>' : void;
	$code = file_line($f, $l);
	$lineHtml = $code ? debug_strip(debug_normalize(highlight_PHP_line($code))) : void;
	$wrap = 'display:inline-block;vertical-align:top;background:#1a1a1a;border:1px solid #333;border-radius:6px;padding:6px 8px;margin:6px 0;color:#fff;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12.5px;line-height:1.5';
	$codecss = 'margin:.25rem 0 0 0;background:#2f3440;color:#e5e7eb;padding:.25rem .5rem;border-radius:4px;white-space:pre;overflow:auto;min-width:max-content';
	$pre = 'margin:.35rem 0 0 0;background:#1a1a1a;color:#fff;white-space:pre-wrap;word-break:break-word';
	echo '<div style="'.$wrap.'">'.$label.$whoHtml.($lineHtml ? '<pre style="'.$codecss.'"><code>'.$lineHtml.'</code></pre>' : void).'<pre style="'.$pre.'">'.esc(dr(...$data)).'</pre></div>';
}

function dc(...$data){
	$t = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 96);
	$frames = debug_frames($t);
	$first = $frames[0] ?? null;
	$nonce = phlo('app')->nonce;
	$out = $first ? debug_head($first[0], $first[1]).str_repeat(lf, 2).dr(...$data) : dr(...$data);
	if (debug_out($out)) return;
	echo '<script'.($nonce ? ' nonce="'.$nonce.'"' : void).'>console.log('.json_encode($out, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).');</script>';
}

function ds(...$data){
	$t = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 96);
	$all = debug_frames($t);
	$frames = (cli || async) ? array_slice($all, 0, 1) : $all;
	if (cli || async){
		$fr = $frames[0] ?? null;
		$out = $fr ? debug_head($fr[0], $fr[1], $fr[2] ?? null).str_repeat(lf, 2).dr(...$data) : dr(...$data);
		if (debug_out($out)) return;
	}
	$rows = [];
	foreach ($frames AS $fr){
		[$f, $l, $who] = [$fr[0], $fr[1], $fr[2] ?? null];
		[$phpLink] = debug_links($f, $l);
		$label = $phpLink ? '<a href="'.esc($phpLink).'" style="color:#bef264;text-decoration:none;border-bottom:1px dotted #bef264">'.esc(shortpath($f).':'.$l).'</a>' : esc(shortpath($f).':'.$l);
		$whoHtml = $who ? ' <span style="color:#bef264;font-family:ui-monospace,Menlo,Consolas,monospace">'.esc($who).'</span>' : void;
		$code = file_line($f, $l);
		$lineHtml = $code ? debug_strip(debug_normalize(highlight_PHP_line($code))) : void;
		$rows[] = '<div style="margin:.35rem 0">'.$label.$whoHtml.($lineHtml ? '<pre style="margin:.25rem 0 0 0;background:#2f3440;color:#e5e7eb;padding:.25rem .5rem;border-radius:4px;overflow:auto"><code>'.$lineHtml.'</code></pre>' : void).'</div>';
	}
	$wrap = 'display:inline-block;vertical-align:top;background:#1a1a1a;border:1px solid #333;border-radius:8px;margin:10px 0;color:#fff;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:13px;line-height:1.55;min-width:280px;padding:8px 10px';
	$pre = 'margin:.35rem 0 0 0;background:#1a1a1a;color:#fff;white-space:pre-wrap;word-break:break-word';
	echo '<div style="'.$wrap.'">'.implode(void, $rows).'<pre style="'.$pre.'">'.esc(dr(...$data)).'</pre></div>';
}

function dx(...$data){
	$t = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 96);
	$f = $t[0]['file'] ?? null;
	$l = (int)($t[0]['line'] ?? 0);
	$phloFile = build && $f ? phlo_source($f) : null;
	$frames = debug_frames($t);
	$out = dr(...$data);
	$title = debug_head($f, $l).str_repeat(lf, 2).$out;
	if (cli) return print($title.lf);
	if (async) return apply(error: $title);
	$stack = implode(void, array_map(fn($fr) => debug_frame($fr[0], $fr[1], $fr[2] ?? null, $fr[0] === $f && (int)$fr[1] === (int)$l), $frames));
	$tabs = '<button class="tab-link active" data-tab="tab-output">output</button>'.($f ? '<button class="tab-link" data-tab="tab-php">'.esc(basename($f)).'</button>' : void).($phloFile ? '<button class="tab-link" data-tab="tab-phlo">'.esc(basename($phloFile)).'</button>' : void);
	$outputView = '<div id="tab-output" class="tab-content active"><pre class="output"><code>'.esc($out).'</code></pre></div>';
	$phpView = $f ? '<div id="tab-php" class="tab-content">'.debug_view_php($f, $l).'</div>' : void;
	$phloView = $phloFile ? '<div id="tab-phlo" class="tab-content">'.debug_view_phlo($phloFile).'</div>' : void;
	$head = tag('title', id.' - Phlo Debug').lf.'<style>'.lf.debug_css().'</style>'.lf;
	$header = debug_header_debugpage();
	$left = '<div class="stack-panel"><div class="call-stack">'.$stack.'</div></div>';
	$right = '<div class="code-panel"><div class="tabs">'.$tabs.'</div><div class="code-view">'.$outputView.$phpView.$phloView.'</div></div>';
	print(DOM('<div class="container">'.$header.'<main class="content-grid">'.$left.$right.'</main></div>'.debug_js(), $head));
	exit(0);
}
