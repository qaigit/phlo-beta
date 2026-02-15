<?php
class phlo_ide extends obj {
	protected function _base(){
		return $this->dashboard->base.'/ide';
	}
	protected function manifest():string {
		return "<link rel=\"manifest\" href=\"/".$this->dashboard->base."/manifest.json\">";
	}
	public function __construct(protected phlo_dashboard $dashboard, string $req = void){
		!$req && !async && die(DOM($this.lf.tag('script', lf.file_get_contents($dashboard->phlo.'phlo.js')."\n$this->JS\n'https://',phlo.tech,'/'\n"), tag('title', $this->title)."\n$this->manifest\n".tag('style', lf.phlo_css($this->CSS.lf.debug_css_highlight(), false).lf).lf));
		if (!async) return;
		if (str_starts_with($req, 'file') && $currentPos = regex('/@([0-9]+)$/', $req)){
			$req = substr($req, 0, -strlen($currentPos[0]));
			$this->settings->open[$this->settings->active] = $currentPos[1];
		}
		method === 'GET' && str_starts_with($req, 'file/') && $this->getFile(substr($req, 4));
		method === 'GET' && str_starts_with($req, 'filemeta/') && $this->getFile(substr($req, 8), true);
		method === 'PUT' && $req === 'files' && [$this->saveSelected(phlo('payload')->open), exit];
		method === 'PUT' && $req === 'save' && [$this->saveFile(phlo('payload')->file, phlo('payload')->contents), exit];
		method === 'POST' && $req === 'question' && $this->answer(phlo('payload')->question);
	}
	protected function answer($question){
		chunk(remove: '#answer', append: arr(body: tag('div', void, id: 'answer')));
		$a = phlo('OpenAI')->stream(user: $q = 'Ik wil graag het volgende:'.lf.$question.lf.lf.'Hier is m\'n Phlo code:'.lf.lf.$this->file.colon.lf.dash.dash.lf.$this->contents.lf.lf.'Hier volgt wat extra uitleg over Phlo:'.lf.lf.file_get_contents($this->dashboard->phlo.'assets/AI.instructions.txt').lf.lf.'Denk goed na over de vraag en geef kort antwoord. Gebruik geen Markdown of andere opmaaktekens in je antwoord.', cb: fn($text) => last(in_array($text, [null, void]) || chunk(append: ['#answer' => esc($text)]), $text));
		file_put_contents(data.'AI.input.log', $q);
		file_put_contents(data.'AI.output.log', $a);
	}
	protected function saveSelected(array $files){
		return [$this->settings->open = create($files, fn($file) => $file, fn($file) => $this->settings->open[$file] ?? 1), $this->dashboard->apply(inner: ['#files' => $this->files])];
	}
	protected function saveFile(string $file, string $contents){
		(!is_file($file) || !str_ends_with($file, '.phlo')) && error('Invalid '.$file);
		$output = file_get_contents($file) === $contents ? 'Gelijk' : 'Anders';
		file_put_contents($file, $contents) && die($output);
		//$this->getFile($file, true);
	}
	protected function getFile($file, $meta = false){
		(!is_file($file) || !str_ends_with($file, '.phlo')) && error('Invalid '.$file);
		$this->settings->active = $file;
		$this->dashboard->apply (
			title: $this->title,
			class: ['#files div.active' => '-active', '#files div[data-file="'.$file.'"]' => 'active'],
			inner: array_filter([
				'#title' => $this->filename,
				'main' => $meta ? null : $this->highlighted,
				'#nodes' => $this->nodes,
				'footer' => $this->footer,
			]),
			caret: $this->settings->open[$file],
		);
	}
	protected function getPhloFunctions(){
		$functions = [];
		foreach (['phlo.php', 'debug.php', 'build.php'] AS $filename){
			$code = file_get_contents($file = $this->dashboard->phlo.$filename);
			$pattern = '/^function\s*&?\s*([A-Za-z_]\w*)\s*\(([^)]*)\)\s*(?:\:\s*([^{]+?))?\s*(?=\{)/m';
			foreach (regex_all($pattern, $code, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) AS $m){
				$name = $m[1][0];
				$line = substr_count(substr($code, 0, $m[0][1]), lf) + 1;
				$row = [];
				if ($m[2][0] !== void) $row['args'] = trim($m[2][0]);
				if (isset($m[3]) && $m[3][1] >= 0 && trim($m[3][0]) !== void) $row['return'] = trim($m[3][0]);
				$functions[$name] = $row + ['file' => $file, 'line' => $line];
			}
		}
		return $functions;
	}
	protected function getPHPfunctions(){
		$functions = [];
		$all = get_defined_functions();
		foreach ($all['internal'] AS $name){
			if (str_contains($name, '\\')) continue;
			try {
				$rf = new \ReflectionFunction($name);
				$params = [];
				foreach ($rf->getParameters() AS $p){
					$type = $this->typeToString($p->getType());
					$ref = $p->isPassedByReference() ? '&' : void;
					$var = $p->isVariadic() ? '...' : void;
					$param = trim(($type !== void ? "$type " : void).$ref.$var.'$'.$p->getName());
					if ($p->isOptional() && !$p->isVariadic() && $p->isDefaultValueAvailable()){
						try { $def = $this->valToCode(@$p->getDefaultValue()); }
						catch (\Throwable) { $def = 'null'; }
						$param = "$param = $def";
					}
					$params[] = $param;
				}
				$args = implode(', ', $params);
				$return = $this->returnTypeString($rf);
				$row = [];
				if ($args !== void) $row['args'] = $args;
				if ($return !== void) $row['return'] = $return;
				$functions[$name] = $row;
			}
			catch (\Throwable) {}
		}
		ksort($functions, SORT_NATURAL | SORT_FLAG_CASE);
		return $functions;
	}
	protected function typeToString($t){
		if (!$t) return void;
		if ($t instanceof \ReflectionUnionType){
			$parts = [];
			foreach ($t->getTypes() AS $p){
				$s = $this->typeToString($p);
				if ($s !== void) $parts[] = $s;
			}
			$S = implode('|', array_unique($parts));
			return $t->allowsNull() && !str_contains($S, 'null') ? "null|$S" : $S;
		}
		if ($t instanceof \ReflectionIntersectionType){
			$parts = [];
			foreach ($t->getTypes() AS $p){
				$s = $this->typeToString($p);
				if ($s !== void) $parts[] = $s;
			}
			return implode('&', $parts);
		}
		return ($t->allowsNull() ? '?' : void).ltrim((string)$t, '?');
	}
	protected function valToCode($v){
		return match(true){
			is_null($v) => 'null',
			is_bool($v) => $v ? 'true' : 'false',
			is_string($v) => $this->quoteString($v),
			is_array($v) => '[]',
			is_object($v) => 'null',
			default => (string)$v,
		};
	}
	protected function quoteString($s){
		$out = void;
		for ($i = 0, $n = strlen($s); $i < $n; $i++){
			$c = $s[$i];
			$ord = ord($c);
			if ($c === "\\") $out .= "\\\\";
			elseif ($c === "'") $out .= "\\'";
			elseif ($c === "\n") $out .= "\\n";
			elseif ($c === "\r") $out .= "\\r";
			elseif ($c === "\t") $out .= "\\t";
			elseif ($c === "\v") $out .= "\\v";
			elseif ($c === "\f") $out .= "\\f";
			elseif ($ord === 0) $out .= "\\0";
			else $out .= $c;
		}
		return "'$out'";
	}
	protected function returnTypeString($rf){
		$r = $rf->getReturnType();
		$s = $this->typeToString($r);
		if ($s === void && method_exists($rf, 'getTentativeReturnType')){
			$tr = $rf->getTentativeReturnType();
			$s = $this->typeToString($tr);
		}
		return $s === void ? void : $s;
	}
	protected function schema($flags = jsonFlags){
		$functions = [...$this->getPhloFunctions, ...$this->getPHPfunctions];
		$objs = [];
		$files = [...loop(files(app, '*.phlo'), fn($file) => new phlo_file($file)), ...loop(build['libs'], fn($fn) => new phlo_file($this->dashboard->phlo."libs/$fn.phlo"))];
		foreach ($files AS $phlo){
			foreach ($phlo->functions AS $name => $function){
				$data = [];
				$function->args && $data['args'] = $function->args;
				$function->type && $data['return'] = $function->type;
				$functions[$name] = arr(...$data, file: $phlo->file, line: $function->line);
			}
			$nodes = [];
			$args = null;
			foreach ($phlo->nodes AS $name => $node){
				if ($name === '__construct') $node->args && $args = $node->args;
				if ($name === 'controller' || strpos($name, dot) || str_starts_with($name, '__') || in_array($node->node, ['route', 'script', 'style'])) continue;
				$isDyn = in_array($node->operator, ['arrow', 'method']);
				$data = ['type' => $node->node];
				($node->args || ($node->node === 'static' && $isDyn)) && $data['args'] = $node->args ?: void;
				$node->type && $data['return'] = $node->type;
				$data['line'] = $node->line;
				$node->comments && $data['comments'] = $node->comments;
				$operator = in_array($node->node, ['static', 'const']) ? '::' : '->';
				$nodes[$operator][($node->node === 'static' && !$isDyn ? '$' : void).$name] = $data;
			}
			if ($args || $nodes){
				$ref = ['file' => $phlo->file];
				$args && $ref['args'] = $args;
				$nodes && $ref = [...$ref, ...$nodes];
				$objs[$phlo->class] = $ref;
			}
		}
		return json_encode(arr(functions: $functions, objects: $objs), $flags);
	}
	protected function _title(){
		return basename($this->settings->active).' - Phlo IDE - '.host.' - '.duration();
	}
	protected function _settings(){
		return phlo('JSON', 'ide', assoc: true);
	}
	protected function _filename(){
		return basename($this->settings->active);
	}
	protected function _file(){
		return $this->settings->active;
	}
	protected function _contents(){
		return is_file($this->file) ? file_get_contents($this->file) : void;
	}
	protected function _highlighted(){
		return $this->settings->active ? linenumber(explode(lf, debug_normalize(esc(file_get_contents($this->settings->active)))), true) : void;
	}
	protected function _caret(){
		return $this->settings->open[$this->settings->active] ?? 0;
	}
	protected function _files(){
		$return = [tag('div', 'App', class: 'header'), ...loop($this->fileList(app, '*.phlo'), [$this, 'fileLink'])];
		foreach ([$path = $this->dashboard->phlo.'libs/', ...dirs($path)] AS $dir){
			if (in_array($name = basename($dir), ['Loaders', 'Themes', 'Transitions'])) continue;
			$open = array_filter(loop(array_keys($this->settings->open ?? []), fn($file) => str_starts_with($file, $dir)));
			$return = [...$return, tag('div', ucfirst($name), class: 'header'.($open ? ' open' : void)), ...loop($this->fileList($dir, '*.phlo'), [$this, 'fileLink'])];
		}
		return implode(lf, $return);
	}
	protected function fileList($paths, $ext){
		return last($files = files($paths, $ext), natcasesort($files), $files);
	}
	protected function fileLink($file){
		return tag('div', data_file: $file, class: 'file'.($file === $this->settings->active ? ' active' : void).(isset($this->settings->open[$file]) ? ' open' : void), inner: basename($file));
	}
	protected function nodes(){
		return is_file($this->file) ? loop(last($phlo = new phlo_file($this->file), [...$phlo->functions, ...$phlo->nodes, ...$phlo->assets]), fn($node) => tag('div', data_line: $node->line, inner: tag('span', $node->node, class: 'hl-node').space.($node->node === 'route' ? $node->mode.space.$node->method.space.$node->path : $node->name ?? ($node->node === 'view' ? 'view' : void))), lf) : void;
	}
	protected function footer(){
		return tag('div', 'Length: '.number_format(filesize($this->file), thousands_separator: dot)).tag('div', 'changed: '.time_human(filemtime($this->file)).' ago').tag('div', (substr_count($this->contents, lf) + 1).' lines');
	}
	protected function view():string {
		$phloView = [];
		$phloView[] = "<header>";
		$phloView[] = "\t<div><span class=\"box\">+</span> <b>Phlo</b> IDE - ".id."</div>";
		$phloView[] = "\t<div id=\"title\">$this->filename</div>";
		$phloView[] = "\t<select id=\"nav\"><option>Nav...<option value=\"./\">Home<option value=\"./config\">Config<option value=\"./errors\">Errors<option value=\"./manual\">Manual<option value=\"./build\">Build<option value=\"./release\">Release</select>";
		$phloView[] = "</header>";
		$phloView[] = "<aside id=\"files\">";
		$phloView[] = "\t".indentView($this->files )."";
		$phloView[] = "</aside>";
		$phloView[] = "<main data-caret=\"$this->caret\">";
		$phloView[] = "\t".indentView($this->highlighted )."";
		$phloView[] = "</main>";
		$phloView[] = "<aside id=\"helpers\">";
		$phloView[] = "\t<div id=\"nodes\">";
		$phloView[] = "\t\t".indentView($this->nodes , 2)."";
		$phloView[] = "\t</div>";
		$phloView[] = "\t<form id=\"AI\">";
		$phloView[] = "\t\t<textarea id=\"question\" rows=\"4\" placeholder=\"Ask AI...\"></textarea>";
		$phloView[] = "\t\t<button id=\"send\">Send</button>";
		$phloView[] = "\t</form>";
		$phloView[] = "</aside>";
		$phloView[] = "<footer>$this->footer</footer>";
		$phloView[] = "<script>";
		$phloView[] = "window.PHLO_INDEX = ".($this->schema(0))."";
		$phloView[] = "</script>";
		return implode(lf, $phloView);
	}
	protected function JS(){
		return file_get_contents($this->dashboard->phlo.'assets/highlight.js').file_get_contents($this->dashboard->phlo.'assets/ide.js');
	}
	protected function CSS(){
		return file_get_contents($this->dashboard->phlo.'assets/ide.css');
	}
}
