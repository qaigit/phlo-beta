function highlight_Phlo(source){
  const esc = s => s.replace(/[&<>]/g, c => c==='&'?'&amp;':c==='<'?'&lt;':'&gt;');
  const sp  = (c,s) => `<span class="${c}">${s}</span>`;
  const CONSTS = new Set(['cli','async','method','jsonFlags','br','bs','bt','colon','comma','cr','dash','dot','dq','eq','lf','nl','perc','pipe','qm','semi','slash','space','sq','tab','us','void','debug','build','new','true','false','null','app','php','www','data','both']);
  const PHP_FUNCS = new Set(['array_merge','count','in_array','strlen','strpos','str_replace','json_encode','preg_match','preg_replace','explode']);
  const PHLO_FUNCS = new Set(['view','apply','token','duration','error','phlo','dx']);
  const NODES = new Set(['route','view','method','prop','readonly','const','static','function']);
  const highlightTag = raw => {
    let i=0,n=raw.length,out='';
    if(raw[i]!=='<') return esc(raw);
    const closing = (i+1<n && raw[i+1]==='/');
    out += sp('hl-operator', esc(closing?'</':'<')); i += closing?2:1;
    if(i>=n || !/^[A-Za-z]$/.test(raw[i])) return out+esc(raw.slice(i));
    let s=i; i++; while(i<n && /[A-Za-z0-9:-]/.test(raw[i])) i++;
    const name = raw.slice(s,i), base = (name.split(/[.#]/,2)[0]||'');
    out += sp('hl-tag', esc(base));
    for(let p=base.length; p<name.length; ){
      const sym=name[p], q=name.indexOf(sym==='.'?'#':'.', p+1), end=q===-1?name.length:q;
      out += sp('hl-operator', esc(sym)) + sp('hl-attr-name', esc(name.slice(p+1,end)));
      p=end;
    }
    while(i<n){
      if(raw[i]==='/' && i+1<n && raw[i+1]==='>'){ out+=sp('hl-operator','/'); out+=sp('hl-operator','>'); return out; }
      if(raw[i]==='>'){ out+=sp('hl-operator','>'); return out; }
      if(/\s/.test(raw[i])){ let w=i; while(i<n && /\s/.test(raw[i])) i++; out+=raw.slice(w,i); continue; }
      let a0=i; while(i<n && /[\w:@-]/.test(raw[i])) i++;
      if(i>a0){
        out+=sp('hl-attr-name', esc(raw.slice(a0,i)));
        let w=i; while(i<n && /\s/.test(raw[i])) i++; out+=raw.slice(w,i);
        if(i<n && raw[i]==='='){
          out+=sp('hl-operator','='); i++;
          w=i; while(i<n && /\s/.test(raw[i])) i++; out+=raw.slice(w,i);
          if(i<n && (raw[i]==='"' || raw[i]==="'")){
            const q=raw[i]; let v0=i; i++; while(i<n && raw[i]!==q) i++; if(i<n) i++;
            out+=sp(q==='"'?'hl-string-dq':'hl-string-sq', esc(raw.slice(v0,i)));
          } else {
            let v0=i; while(i<n && !/[\s>]/.test(raw[i])) i++;
            out+=sp('hl-attr-value', esc(raw.slice(v0,i)));
          }
        }
        continue;
      }
      out+=esc(raw[i]); i++;
    }
    return out;
  };
  const rx = /<\/?[A-Za-z][^>]*>|\{\{|\}\}|\{\(|\)\}|::|->|"([^"\\]|\\.)*"|'([^'\\]|\\.)*'|%[A-Za-z_]\w*|\$[A-Za-z_]\w*|\b\d+\.\d+\b|\b\d+\b|\B\.\d+\b|=>|<=|>=|===|==|!=|&&|\|\||(?:\(|\)|\[|\]|\{|\}|;|,|\.|=|:|-|\+|\*|\/|\||&|<|>|\?)|\s+|[.%A-Za-z0-9_-]+(?=:\s)|[A-Za-z_]\w*/gu;
  let out='', lines=source.split(/\r?\n/);
  for(let li=0; li<lines.length; li++){
    const line=lines[li], m=line.match(/^\s*/u), lead=m?m[0].length:0;
    let painted=false, nextMember=false, res='', last=0, cm=line.slice(lead);
    if(cm.startsWith('#')||cm.startsWith('//')){ out+=esc(line.slice(0,lead))+sp('hl-cmt',esc(line.slice(lead)))+(li<lines.length-1?'\n':''); continue; }
    rx.lastIndex=0; let it;
    while((it=rx.exec(line))){
      const tok=it[0], ofs=it.index;
      if(ofs>last) res+=esc(line.slice(last,ofs));
      last=ofs+tok.length;
      if(tok[0]==='<'){ res+=highlightTag(tok); continue; }
      if(ofs===lead && !painted && /^[A-Za-z_]\w*$/.test(tok) && NODES.has(tok)){ res+=sp('hl-node',esc(tok)); painted=true; continue; }
      if(tok==='->'){ res+=sp('hl-objop',esc(tok)); nextMember=true; continue; }
      if(tok==='::'){ res+=sp('hl-classop',esc(tok)); nextMember=true; continue; }
      if(tok==='{{'||tok==='}}'||tok==='{('||tok===')}'){ res+=sp('hl-operator',esc(tok)); continue; }
      if(tok[0]==='"'||tok[0]==="'"){ res+=sp(tok[0]==='"'?'hl-string-dq':'hl-string-sq', esc(tok)); continue; }
      if(tok[0]==='%' && /^%[A-Za-z_]\w*$/.test(tok)){ res+=sp('hl-obj',esc(tok)); continue; }
      if(tok[0]==='$' && /^\$[A-Za-z_]\w*$/.test(tok)){ res+=sp('hl-var',esc(tok)); continue; }
      if(/^\d/.test(tok)||/^\.\d/.test(tok)){ res+=sp('hl-number',esc(tok)); continue; }
      if(['(',')','[',']','{','}',';',',','.',"=",'=>','<=','>=','===','==','!=','&&','||',':','-','+','*','/','|','&','<','>','?'].includes(tok)){ res+=sp('hl-operator',esc(tok)); continue; }
      if(/^\s+$/.test(tok)){ res+=tok; continue; }
      if(/^[.%A-Za-z0-9_-]+$/.test(tok) && last<line.length && line.slice(last,last+2)===': ' && (ofs===0 || /\s/.test(line[ofs-1]||''))){ res+=sp('hl-key',esc(tok)); continue; }
      if(/^[A-Za-z_]\w*$/.test(tok)){
        if(nextMember){ res+=sp('hl-member',esc(tok)); nextMember=false; continue; }
        const lower=tok.toLowerCase();
        if(CONSTS.has(lower)){ res+=sp('hl-const',esc(tok)); continue; }
        if(/^\s*\(/u.test(line.slice(last))){
          if(PHP_FUNCS.has(tok)){ res+=sp('hl-php-func',esc(tok)); continue; }
          if(PHLO_FUNCS.has(lower)){ res+=sp('hl-phlo-func',esc(tok)); continue; }
        }
        res+=esc(tok); continue;
      }
      res+=esc(tok);
    }
    if(last<line.length) res+=esc(line.slice(last));
    out+=res+(li<lines.length-1?'\n':'');
  }
  return out;
}
