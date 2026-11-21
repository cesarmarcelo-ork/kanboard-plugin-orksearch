'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const memory=new Map();
const sessionStorage={getItem:k=>memory.get(k)||null,setItem:(k,v)=>memory.set(k,v),removeItem:k=>memory.delete(k)};
const script=fs.readFileSync('Assets/orksearch-v1.0.0.js','utf8');
class Element {
  constructor(tag){
    this.tagName=tag; this.children=[]; this.dataset={}; this.style={};this.attrs={};
    this.hidden=false;this.value='';this.textContent='';this.listeners={};this.className='';
    this.classList={toggle:()=>{}};
  }
  appendChild(el){this.children.push(el);return el;}
  replaceChildren(...els){this.children=els;}
  setAttribute(k,v){this.attrs[k]=v;}
  addEventListener(k,fn){this.listeners[k]=fn;}
  querySelector(s){const cls=s.slice(1);return this.children.find(x=>x.className===cls)||null;}
  getBoundingClientRect(){return {left:150,bottom:44};}
  focus(){}
  contains(el){return el===this||this.children.includes(el);}
}
function createPage(projectId){
  const all=[];
  const doc={readyState:'complete',documentElement:{lang:'pt-BR'},body:new Element('body'),
    createElement:tag=>{const el=new Element(tag);all.push(el);return el;},
    createTextNode:t=>({textContent:t}),
    addEventListener:()=>{},querySelector:sel=>sel.includes('data-project-id')?anchor:sel.includes('input-addon')?native:null};
  const anchor=new Element('div');anchor.dataset.projectId=String(projectId);
  const launch=new Element('a');const label=new Element('span');
  anchor.querySelector=s=>s==='.ofss-launch'?launch:label;
  const native=new Element('div');
  const fetched=[];
  const scope={document:doc,navigator:{language:'pt-BR'},sessionStorage,
    window:{innerWidth:1200,innerHeight:900,location:{pathname:'/'},addEventListener:()=>{}},
    AbortController,URLSearchParams,console,setTimeout,clearTimeout,
    fetch:async uri=>{
      const query=new URL(uri,'https://local.test').searchParams;
      fetched.push(Object.fromEntries(query));
      return {ok:true,json:async()=>({total:2,hits:[],facets:{swimlane:[{value:'7',label:'Operações',count:2}]},has_more:false})};
    }
  };
  vm.runInNewContext(script,scope);
  const find=cls=>all.find(el=>el.className===cls);
  return {find,launch,fetched,all};
}
const wait=()=>new Promise(r=>setTimeout(r,25));
(async()=>{
  let first=createPage(41);
  first.launch.listeners.click({preventDefault(){}});
  first.find('ofss-input').value='fluoreto';first.find('ofss-input').listeners.input();
  first.find('ofss-refine').listeners.click();await wait();
  const raia=first.all.find(e=>e.tagName==='select'&&e.attrs['aria-label']==='Raia');
  raia.value='7';raia.listeners.change();
  first.find('ofss-pin').listeners.click();
  assert.equal(JSON.parse(memory.get('ofss:search:v1:project:41')).q,'fluoreto');
  let reload=createPage(41);await wait();
  assert.equal(reload.find('ofss-popover').hidden,false,'restores visible search when returning to project');
  assert.equal(reload.find('ofss-input').value,'fluoreto');
  assert.equal(reload.find('ofss-facets').hidden,false);
  assert.equal(reload.fetched.at(-1).q,'fluoreto');
  assert.equal(reload.fetched.at(-1).swimlane,'7');
  reload.find('ofss-close').listeners.click();
  assert.equal(JSON.parse(memory.get('ofss:search:v1:project:41')).open,false);
  reload=createPage(41);
  assert.equal(reload.find('ofss-popover').hidden,true,'manual close remains closed after navigation');
  reload.launch.listeners.click({preventDefault(){}});
  assert.equal(reload.find('ofss-input').value,'fluoreto','saved criteria survive manual close');
  let other=createPage(42);
  assert.equal(other.find('ofss-popover').hidden,true,'other project not auto-opened');
  assert.equal(other.find('ofss-input').value,'','other project query independent');
  assert.equal(other.find('ofss-pin').attrs['aria-pressed'],'false');
  reload.find('ofss-pin').listeners.click();
  assert.equal(memory.has('ofss:search:v1:project:41'),false,'unpin clears saved state');
  reload=createPage(41);
  assert.equal(reload.find('ofss-popover').hidden,true);
  assert.equal(reload.find('ofss-input').value,'');
  console.log('PASS: pin across pages, filters and query restored, manual close, per-project isolation, unpin');
})().catch(e=>{console.error(e);process.exitCode=1});
