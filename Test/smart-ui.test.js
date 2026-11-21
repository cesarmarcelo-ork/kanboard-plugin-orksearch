'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
class Element {
  constructor(tag) {
    this.tagName=tag; this.children=[]; this.dataset={}; this.style={}; this.attrs={};
    this.hidden=false; this.value=''; this.textContent=''; this.listeners={};
    this._rect={left:200,bottom:42}; this.className='';
    this.classList={toggle:()=>{}};
  }
  appendChild(el) { this.children.push(el); return el; }
  replaceChildren(...children) { this.children=children; }
  setAttribute(name,value) { this.attrs[name]=value; }
  addEventListener(name,fn) { this.listeners[name]=fn; }
  getBoundingClientRect() { return this._rect; }
  contains(el) { return el===this || this.children.includes(el); }
  focus() {}
  querySelector(selector) {
    const cls=selector.slice(1);
    return this.children.find(e=>e.className===cls) || null;
  }
}
const anchor=new Element('div'); anchor.dataset.projectId='41'; anchor.className='ofss-anchor';
const launch=new Element('a'); launch.className='ofss-launch';
const label=new Element('span'); label.className='ofss-launch-label';
anchor.children=[launch,label]; anchor.querySelector=(sel)=>sel==='.ofss-launch'?launch:label;
const native=new Element('div');
const all=[];
const doc={readyState:'complete', documentElement:{lang:'pt-BR'}, body:new Element('body'),
  createElement:(tag)=>{const e=new Element(tag);all.push(e);return e},
  createTextNode:(text)=>({textContent:text}),
  querySelector:(sel)=>sel.includes('data-project-id')?anchor:sel.includes('input-addon')?native:null,
  addEventListener:()=>{}};
const fetches=[];
const storage=new Map();
const sessionStorage={getItem:k=>storage.get(k)||null,setItem:(k,v)=>storage.set(k,v),removeItem:k=>storage.delete(k)};
const scope={
  document:doc,navigator:{language:'pt-BR'},
  window:{innerWidth:1366,innerHeight:768,location:{pathname:'/'},addEventListener:()=>{}},
  AbortController:global.AbortController,URLSearchParams:global.URLSearchParams,
  fetch:async(url)=>{
    const p=new URL(url,'https://localhost.test').searchParams;
    const mode=p.get('mode'); const offset=Number(p.get('offset')||0);
    fetches.push({mode,offset,status:p.get('status'),q:p.get('q'),priority:p.get('priority'),complexity:p.get('complexity'),swimlane:p.get('swimlane')});
    const facets={
      swimlane:[{value:'9',label:'Receitas',count:30}],
      priority:[{value:'0',label:'0',count:22},{value:'1',label:'1',count:100},{value:'2',label:'2',count:15}],
      complexity:[{value:'0',label:'0 (Not specified)',count:70},{value:'3',label:'3',count:12}],
      status:[{value:'1',label:'Open',count:110},{value:'0',label:'Closed',count:27}]
    };
    let data;
    if (mode==='summary') data={mode,total:137,hits:[],facets:{},limit:20};
    else if (mode==='facets') data={mode,total:137,hits:[],facets,limit:20};
    else {
      const total=p.get('q')==='plano'?43:p.get('status')==='0'?27:137;
      const hits=Array.from({length:Math.min(20,Math.max(0,total-offset))},(_,i)=>({
        id:offset+i+1,title:'Card '+(offset+i+1),status:1,url:'#task',match_field:'title',snippet:'plano',
        ...(p.get('q')==='plano' && i===0 ? {match_count:4,matches:[
          {field:'title',snippet:'plano',score:12000},
          {field:'attachment_content',filename:'teste.docx',snippet:'plano extraído',score:6900},
          {field:'attachment_content',filename:'teste.txt',snippet:'plano extraído',score:6900},
          {field:'attachment_content',filename:'teste.md',snippet:'plano extraído',score:6900},
        ]} : {})
      }));
      data={mode,total,hits,facets,limit:20,has_more:offset+hits.length<total};
    }
    return {ok:true,json:async()=>data};
  },
  setTimeout:global.setTimeout,clearTimeout:global.clearTimeout,console,sessionStorage
};
const wait=()=>new Promise(r=>setTimeout(r,30));
const find=(cls)=>all.find(e=>e.className===cls);
const hits=()=>find('ofss-results').children.filter(e=>e.className==='ofss-hit');
(async()=>{
  vm.runInNewContext(fs.readFileSync('Assets/orksearch-v1.0.0.js','utf8'),scope);
  assert.equal(label.textContent,'Busca Inteligente');
  launch.listeners.click({preventDefault(){}});
  const pin=all.find(e=>e.className==='ofss-pin');
  assert.ok(pin, 'pinning control exists');
  pin.listeners.click();
  assert.equal(pin.attrs['aria-pressed'],'true');
  assert.equal(JSON.parse(sessionStorage.getItem('ofss:search:v1:project:41')).open,true);
  await wait();
  assert.equal(fetches[0].mode,'summary');
  assert.equal(find('ofss-summary').textContent,'137 cartões disponíveis');
  assert.equal(hits().length,0);
  assert.equal(find('ofss-intro').hidden,false);
  find('ofss-view-all').listeners.click();
  await wait();
  assert.equal(hits().length,20);
  assert.equal(find('ofss-page-status').textContent,'Exibindo 20 de 137');
  const more=find('ofss-foot').children.find(e=>e.className==='ofss-load-more');
  more.listeners.click();
  await wait();
  assert.equal(hits().length,40);
  assert.equal(fetches.at(-1).offset,20);
  const input=find('ofss-input');
  input.value='plano';
  input.listeners.input();
  await new Promise(r=>setTimeout(r,370));
  assert.equal(fetches.at(-1).q,'plano');
  assert.equal(hits().length,20);
  assert.equal(find('ofss-summary').textContent,'43 cartões encontrados');
  assert.equal(hits().length,20, 'multiple sources do not duplicate cards');
  const firstHit = hits()[0];
  const details = firstHit.children.find(e=>e.tagName==='details');
  assert.ok(details,'expandable sources exist');
  assert.equal(details.children[0].textContent,'Ver todas as correspondências (4)');
  const origins = details.children[1].children;
  assert.equal(origins.length,4);
  assert.ok(origins.some(e=>e.children[0].textContent.includes('teste.md')),'Markdown source shown');
  assert.ok(origins.some(e=>e.children[0].textContent.includes('teste.txt')),'TXT source shown');
  input.value=''; input.listeners.input();
  await new Promise(r=>setTimeout(r,370));
  assert.equal(fetches.at(-1).mode,'summary');
  assert.equal(hits().length,0);
  find('ofss-refine').listeners.click();
  await wait();
  assert.equal(fetches.at(-1).mode,'facets');
  const selects=all.filter(e=>e.tagName==='select');
  assert.deepEqual(selects.map(e=>e.attrs['aria-label']),['Raia','Coluna','Categoria','Etiqueta','Responsável','Prioridade','Complexidade','Status']);
  const status=selects[7];
  assert.equal(status.attrs['aria-label'],'Status');
  assert.deepEqual(status.children.map(e=>e.textContent),['Todos','Abertos (110)','Finalizados (27)']);
  const raia = selects[0], priority = selects[5], complexity = selects[6];
  assert.deepEqual(priority.children.map(e=>e.textContent),['Todos','0 (22)','1 (100)','2 (15)']);
  assert.deepEqual(complexity.children.map(e=>e.textContent),['Todos','0 (Não informada) (70)','3 (12)']);
  raia.value='9'; raia.listeners.change(); await wait();
  priority.value='2'; priority.listeners.change(); await wait();
  complexity.value='3'; complexity.listeners.change(); await wait();
  assert.equal(fetches.at(-1).swimlane,'9');
  assert.equal(fetches.at(-1).priority,'2');
  assert.equal(fetches.at(-1).complexity,'3');
  status.value='0'; status.listeners.change();
  await wait();
  assert.equal(fetches.at(-1).status,'0');
  assert.equal(fetches.at(-1).mode,'results');
  find('ofss-clear').listeners.click();
  await wait();
  assert.equal(hits().length,0);
  assert.equal(JSON.parse(sessionStorage.getItem('ofss:search:v1:project:41')).pinned,true);
  pin.listeners.click();
  assert.equal(sessionStorage.getItem('ofss:search:v1:project:41'),null, 'unpin clears stored data');
  console.log('PASS: pin persistence, clean opening, pagination, combined facets, status, multi-source, reset');
})().catch(e=>{console.error(e);process.exitCode=1});
