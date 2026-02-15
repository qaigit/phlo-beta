<?php
class phlo_dashboard extends obj {
	protected $files = [];
	public function __construct(public string $base, public string $phlo, $req = void, $dev = false){
		$req === 'logo.png' && output(file: $phlo.'assets/logo.png');
		$req === 'manifest.json' && [header('Content-Type: application/json'), die(file_get_contents($phlo.'assets/manifest.json'))];
		($user = static::auth()) ? (phlo('session')->authLog ??= auth_log($user)) : die('401 Unauthorized');
		$req === 'logout' && !phlo('session')->__unset('authLog') && (method_exists('app', 'dashboardLogout') ? app::dashboardLogout() : [header('HTTP/1.0 401 Unauthorized'), die(DOM('Logged out'))]);
		if (str_starts_with("$req/", "ide/") && is_file($file = ($dev ? php.'phlo.' : $phlo).'ide.php')) return [require($file), new phlo_ide($this, substr($req, 4))];
		$arg = null;
		ini_set('highlight.default', '#4778d0');
		ini_set('highlight.keyword', '#5fd05f');
		if (strpos($req, slash)) list($section, $arg) = explode(slash, $req, 2);
		elseif ($req) $section = $req;
		else $section = 'home';
		if (in_array($section, ['home', 'config', 'errors', 'libs', 'lib', 'nodes', 'source', 'engine', 'build', 'release', 'csser'])) $this->$section($arg);
		die('Invalid request');
	}
	public static function auth(){
		if (is_file(data.'creds.ini') && phlo_exists('creds') && $creds = phlo('creds')->dashboard){
			if (isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']) && $_SERVER['PHP_AUTH_USER'] === $creds->user && $_SERVER['PHP_AUTH_PW'] === $creds->password) return $_SERVER['PHP_AUTH_USER'];
	    header('WWW-Authenticate: Basic realm="Phlo Dashboard - '.id.'"');
			return false;
		}
		return method_exists('app', 'dashboardAuth') ? app::dashboardAuth() : false;
	}
	protected function apply(...$cmds){
		header('Content-Type: application/json');
		header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
		header('Pragma: no-cache');
		header('X-Content-Type-Options: nosniff');
		die(json_encode($cmds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}
	protected function home(){
		return $this->render(tag('h1', __FILE__), 'home');
	}
	protected function csser(){
		return $this->render(tag('h1', 'Phlo CSS\'er'), 'PhloCSS');
	}
	protected function errors(){
		$errors = json_read(data.'errors.json');
		$output = void;
		foreach ($errors AS $error){
			$output .= "<tr><td class=\"info\">$error->lastOccured<br>$error->file</td><td>$error->msg</td><td>$error->count</td></tr>\n";
		}
		$this->render(tag('h1', 'Errors').lf.tag('table', "\n$output", class: 'errors'), 'errors');
	}
	protected function config(){
		return $this->render(tag('pre', file_get_contents(data.'app.json')), 'config');
	}
	protected function libs(){
		$rootFiles = files($this->phlo.'libs/', '*.phlo');
		$root = create($rootFiles, fn($f) => $f, fn($f) => basename($f, '.phlo'));
		natcasesort($root);
		$groups = [];
		$dirs = dirs($this->phlo.'libs/');
		foreach ($dirs AS $dir){
			$list = files($dir, '*.phlo');
			if (!$list) continue;
			$list = create($list, fn($file) => $file, fn($file) => strtr($file, [$this->phlo.'libs/' => void, '.phlo' => void]));
			natcasesort($list);
			$groups[basename($dir)] = $list;
		}
		$folders = array_keys($groups);
		natcasesort($folders);
		$sections = [];
		foreach ($folders AS $folder) $sections[] = [$folder, $groups[$folder]];
		$sections[] = [null, $root];
		$content = '<p>Select your libraries</p>'.lf;
		foreach ($sections AS [$title, $list]){
			if ($title) $content .= "\t<details>\n\t\t<summary>".file_get_contents($this->phlo.'libs/'.$title.'/description.txt')." (".count($list).")</summary>\n";
			foreach ($list AS $file => $lib){
				$file = new phlo_file($file);
				$active = in_array($lib, build['libs']) ? 'X' : '_';
				$content .= ($title ? "\t\t" : "\t")."<div class=\"padded\"><a href=\"/$this->base/lib/$lib\">[$active] $file->class</a>".(($desc = $file->meta['description'] ?? null) ? " - <small>$desc</small>" : void)."</div>\n";
			}
			if ($title) $content .= "\t</details>\n";
		}
		$this->render($content, 'libs');
	}
	protected function lib($lib){
		phlo_lib($lib, true);
		is_file($app = app.'app.phlo') && touch($app);
		location("/$this->base/libs");
	}
	protected function nodes(){
		$builder = phlo_builder();
		$output = [];
		$types = ['route' => '🟦', 'function' => '🟧', 'static' => '🟨', 'const' => '🟩', 'method' => '🟪', 'prop' => '🟫', 'readonly' => '🟫', 'view' => '🟥', 'script' => '🟡', 'style' => '🟠'];
		$sourceFiles = $builder->files;
		ksort($sourceFiles, SORT_NATURAL | SORT_FLAG_CASE);
		foreach ($sourceFiles AS $name => $file){
			$this->files[] = $file->file;
			$items = [];
			foreach ($file->meta AS $key => $value) $items[] = tag('div', inner: "&nbsp;@ $key: $value");
			foreach ($file->functions AS $key => $node) $items[] = tag('div', inner: $types[$node->node]." $node->node $key".($node->args ? " ($node->args)" : void));
			foreach ($file->nodes AS $key => $node) $items[] = tag('div', inner: $types[$node->node]." $node->node $key".($node->args ? " ($node->args)" : void));
			foreach ($file->assets AS $node) $items[] = tag('div', inner: $types[$node->node]." $node->node ns:".($node->ns ?? 'app'));
			$output[$name] = tag('fieldset', id: basename($file->file), inner: tag('legend', $file->file).lf.implode(lf, $items));
		}
		$this->render(implode(lf, $output), 'nodes');
	}
	protected function source(){
		return $this->render(loop($this->files = phlo_sources(), fn($file) => $this->fieldset($file), lf), 'source');
	}
	protected function engine(){
		$code = file_get_contents($this->phlo.'phlo.php');
		$pattern = '/^\s*function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/m';
		$this->subs = void;
		if (preg_match_all($pattern, $code, $matches, PREG_OFFSET_CAPTURE)){
			foreach ($matches[1] as [$name, $offset]){
				$line = substr_count(substr($code, 0, $offset), "\n") + 1;
				$this->subs .= tab.tab.'<a href="#l'.$line.'">'.$name.'()</a>'.lf;
			}
		}
		$this->render($this->fieldset($this->phlo.'phlo.php', true), 'engine');
	}
	protected function build($arg = null){
		if ($arg === 'run') return $this->buildRun;
		if ($arg === 'flush') return $this->buildFlush;
		$this->files = glob(php.'*.php');
		natcasesort($this->files);
		$content = "<p><a href=\"/$this->base/build/run\" class=\"async\">Build!</a> <a href=\"/$this->base/build/flush\" class=\"async\">Flush!</a></p>\n";
		$content .= loop($this->files, fn($file) => !$arg || $file === php.$arg ? $this->fieldset($file, true) : void, lf);
		$assets = array_merge(glob(www.'*.js'), glob(www.'*.css'));
		$content .= loop($assets, fn($file) => $this->fieldset($file), lf);
		$this->files = array_merge($this->files, $assets);
		$this->render($content, 'build');
	}
	protected function buildRun(){
		$builder = phlo_build();
		$content = '<p>Built!</p>'.lf.loop(debug(), fn($item) => tag('div', inner: $item).lf, void);
		$this->render($content, 'build');
	}
	protected function buildFlush(){
		$content = last(loop(glob(php.'*.php'), fn($file) => unlink($file)), '<p>Done</p>');
		$this->render($content, 'build');
	}
	protected function release($arg = null){
		if (!defined('release')) return;
		if ($arg === 'run') return $this->releaseRun;
		$this->files = glob(release['php'].'*.php');
		natcasesort($this->files);
		$content = '<p><a href="/'.$this->base.'/release/run" class=\"async\">Release!</a> - paths: '.release['php'].' and '.release['www'].'</p>'.lf;
		$content .= loop($this->files, fn($file) => $this->fieldset($file, true), lf);
		$assets = array_merge(glob(release['www'].'*.js'), glob(release['www'].'*.css'));
		$content .= loop($assets, fn($file) => $this->fieldset($file), lf);
		$this->files = array_merge($this->files, $assets);
		$this->render($content, 'release');
	}
	protected function releaseRun(){
		phlo_build(true);
		$content = '<p>Release built!</p>'.lf.loop(debug(), fn($item) => tag('div', inner: $item).lf, void);
		$this->render($content, 'release');
	}
	protected function fieldset($file, $PHP = false){
		$contents = rtrim(file_get_contents($file));
		$contents = $PHP ? highlight_PHP($contents) : esc($contents);
		return tag('fieldset', id: basename($file), inner: lf.tab.tag('legend', $file).lf.indent(linenumber(explode(lf, $contents))).lf);
	}
	protected function render($content, $section){
		$items = ['config', 'errors', 'libs', 'nodes', 'source', 'engine', 'build'];
		defined('release') && $items[] = 'release';
		$menu = tab.'<a href="/" target="_site">Site</a>'.lf.(is_file($this->phlo.'ide.php') ? tab.'<a href="/'.$this->base.'/ide">IDE</a>'.lf : void);
		foreach ($items AS $item){
			if ($item === $section){
				$menu .= tab.'<a href="#top" class="active">'.ucfirst($item).'</a>'.lf;
				if ($this->subs) $menu .= tab.'<div class="nodes">'.lf.$this->subs.lf.'</div>'.lf;
				if ($this->files){
					$menu .= tab.'<div class="nodes">'.lf;
					foreach ($this->files AS $file){
						$basename = basename($file);
						$menu .= "\t\t<a href=\"#$basename\">$basename</a>\n";
					}
					$menu .= "\t</div>\n";
				}
			}
			else $menu .= "\t<a href=\"/$this->base/$item\" class=\"async\">".ucfirst($item)."</a>\n";
		}
		$menu .= "\t<a href=\"/$this->base/logout\">Logout</a>\n";
		if (async) $this->apply(uri: req, inner: arr(nav: $menu), main: tag('main', $content), trans: true, scroll: 0);
		die(DOM(tag('nav', lf.$menu).lf.tag('main', lf.$content.lf).lf.tag('script', lf.file_get_contents($this->phlo.'phlo.js')."\n$this->JS\n'https://',phlo.tech,'/'\n"), tag('title', "$section - Phlo Dashboard - ".host.' - '.duration()).lf.tag('style', lf.phlo_css($this->CSS.lf.debug_css_highlight(), false).lf).lf));
	}
	protected function JS():string {
		$phloView = [];
		$phloView[] = "on('click', 'a.async', (a, e) => {";
		$phloView[] = "\tif (e.ctrlKey || e.shiftKey) return";
		$phloView[] = "\te.preventDefault()";
		$phloView[] = "\tapp.get(a.attributes.href.value.substr(1))";
		$phloView[] = "})";
		$phloView[] = "app.updates.push(() => objects('fieldset[id$=\".phlo\"]').forEach(el => el.querySelector('ol').innerHTML = highlight_Phlo([...el.querySelector('ol').querySelectorAll('li')].map(li=>li.textContent).join('\\n')).split('\\n').map((l,i)=>'<li id=\"l'+(i + 1)+'\">'+(l || '')+'</li>').join('')))";
		$phloView[] = "".file_get_contents($this->phlo.'assets/highlight.js')."";
		return implode(lf, $phloView);
	}
	protected function CSS():string {
		$phloView = [];
		$phloView[] = "* {";
		$phloView[] = "\tbox-sizing: border-box";
		$phloView[] = "\tscrollbar-color: #52585b #15191a";
		$phloView[] = "\tscroll-behavior: smooth";
		$phloView[] = "}";
		$phloView[] = "body {";
		$phloView[] = "\tbackground-color: black";
		$phloView[] = "\tcolor: white";
		$phloView[] = "\tdisplay: flex";
		$phloView[] = "\tgap: .5rem";
		$phloView[] = "\tfont-family: Tahoma";
		$phloView[] = "\tfont-size: 1.33em";
		$phloView[] = "\tmargin: 0";
		$phloView[] = "}";
		$phloView[] = "a: color: white";
		$phloView[] = "nav {";
		$phloView[] = "\tbackground-color: #2345c8";
		$phloView[] = "\tmin-width: fit-content";
		$phloView[] = "\theight: 100dvh";
		$phloView[] = "\tpadding: 1rem 0";
		$phloView[] = "\toverflow-x: hidden";
		$phloView[] = "\toverflow-y: auto";
		$phloView[] = "\tposition: sticky";
		$phloView[] = "\ttop: 0";
		$phloView[] = "\ta {";
		$phloView[] = "\t\tdisplay: block";
		$phloView[] = "\t\ttext-decoration: none";
		$phloView[] = "\t}";
		$phloView[] = "\ta.active {";
		$phloView[] = "\t\tborder-bottom: 1px solid silver";
		$phloView[] = "\t\tcolor: #f39f9f";
		$phloView[] = "\t}";
		$phloView[] = "\t> a: padding: 0 1rem";
		$phloView[] = "\t.nodes {";
		$phloView[] = "\t\tborder-bottom: 1px solid silver";
		$phloView[] = "\t\tfont-size: .75em";
		$phloView[] = "\t\tmargin-bottom: .5rem";
		$phloView[] = "\t\tpadding: 0 .25rem";
		$phloView[] = "\t}";
		$phloView[] = "}";
		$phloView[] = "main: flex: 1";
		$phloView[] = "fieldset {";
		$phloView[] = "\tbackground: #111518";
		$phloView[] = "\tborder: 1px solid white";
		$phloView[] = "\tcolor: white";
		$phloView[] = "\tmargin: 6px 0";
		$phloView[] = "\tli {";
		$phloView[] = "\t\tline-height: 1.33em";
		$phloView[] = "\t\ttab-size: 2";
		$phloView[] = "\t\twhite-space: pre";
		$phloView[] = "\t}";
		$phloView[] = "}";
		$phloView[] = "summary: cursor: pointer";
		$phloView[] = "table.errors {";
		$phloView[] = "\tborder-collapse: collapse";
		$phloView[] = "\ttd {";
		$phloView[] = "\t\tborder: 1px solid white";
		$phloView[] = "\t\tpadding: 0 3px";
		$phloView[] = "\t}";
		$phloView[] = "\ttd.info: font-size: .8em";
		$phloView[] = "}";
		$phloView[] = ".padded: padding-left: 1rem";
		return implode(lf, $phloView);
	}
}
