(()=>{"use strict";if(typeof window!=="undefined"&&window.localStorage){try{window.localStorage.removeItem("snapshoter:theme");}catch(e){}}const _snapReact=(window.wp&&window.wp.element)?window.wp.element:(window.React||{}),_snapI18n=(window.wp&&window.wp.i18n)?window.wp.i18n:{__:s=>s,_x:s=>s,_n:(s,p,n)=>n===1?s:p,sprintf:(f,...a)=>f};const el=_snapReact;function createJsx(t,p,k){const c=Object.assign({},p);if(k!==undefined)c.key=k;const ch=c.children;delete c.children;if(Array.isArray(ch))return el.createElement.apply(el,[t,c].concat(ch));else if(ch!==undefined)return el.createElement(t,c,ch);return el.createElement(t,c);}const _snapJsx={jsx:createJsx,jsxs:createJsx,Fragment:el.Fragment||(window.React&&window.React.Fragment)||"div"};const e=_snapReact,s=_snapI18n,n=_snapJsx,t="snapshoter:theme",a=(0,_snapReact.createContext)(null);function r(){return"undefined"!=typeof window&&window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light"}function l({children:s}){const u=(0,_snapReact.useMemo)(()=>({preference:"light",resolved:"light",setPreference:()=>{},toggle:()=>{}}),[]);return(0,_snapJsx.jsx)(a.Provider,{value:u,children:s})}function o({variant:e="secondary",size:s="md",loading:t=!1,icon:a,children:r,className:l,disabled:o,type:i="button",...c}){const d=["snap-btn",`snap-btn--${e}`,`snap-btn--${s}`,t?"snap-btn--loading":"",l??""].filter(Boolean).join(" ");let p=null;return t?p=(0,_snapJsx.jsx)("span",{className:"snap-btn__spinner","aria-hidden":"true"}):a&&(p=(0,_snapJsx.jsx)("span",{className:"snap-btn__icon","aria-hidden":"true",children:a})),(0,_snapJsx.jsxs)("button",{type:i,className:d,disabled:o||t,"aria-busy":t||void 0,...c,children:[p,(0,_snapJsx.jsx)("span",{className:"snap-btn__label",children:r})]})}function i({name:e,size:s=16,strokeWidth:t=1.75,className:a,...r}){return(0,_snapJsx.jsx)("svg",{xmlns:"http://www.w3.org/2000/svg",width:s,height:s,viewBox:"0 0 24 24",fill:"none",stroke:"currentColor",strokeWidth:t,strokeLinecap:"round",strokeLinejoin:"round",className:["snap-icon",a].filter(Boolean).join(" "),"aria-hidden":"true",focusable:"false",...r,children:c[e]})}const c={activity:(0,_snapJsx.jsx)("polyline",{points:"22 12 18 12 15 21 9 3 6 12 2 12"}),server:(0,_snapJsx.jsxs)(_snapJsx.Fragment,{children:[(0,_snapJsx.jsx)("rect",{width:"20",height:"8",x:"2",y:"2",rx:"2",ry:"2"}),(0,_snapJsx.jsx)("rect",{width:"20",height:"8",x:"2",y:"14",rx:"2",ry:"2"}),(0,_snapJsx.jsx)("line",{x1:"6",x2:"6.01",y1:"6",y2:"6"}),(0,_snapJsx.jsx)("line",{x1:"6",x2:"6.01",y1:"18",y2:"18"})]}),"hard-drive":(0,_snapJsx.jsxs)(_snapJsx.Fragment,{children:[(0,_snapJsx.jsx)("line",{x1:"22",y1:"12",x2:"2",y2:"12"}),(0,_snapJsx.jsx)("path",{d:"M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"}),(0,_snapJsx.jsx)("line",{x1:"6",x2:"6.01",y1:"16",y2:"16"}),(0,_snapJsx.jsx)("line",{x1:"10",x2:"10.01",y1:"16",y2:"16"})]}),"alert-triangle":(0,_snapJsx.jsxs)(_snapJsx.Fragment,{children:[(0,_snapJsx.jsx)("path",{d:"m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"}),(0,_snapJsx.jsx)("line",{x1:"12",x2:"12",y1:"9",y2:"13"}),(0,_snapJsx.jsx)("line",{x1:"12",x2:"12.01",y1:"17",y2:"17"})]}),"check-circle":(0,_snapJsx.jsxs)(_snapJsx.Fragment,{children:[(0,_snapJsx.jsx)("circle",{cx:"12",cy:"12",r:"10"}),(0,_snapJsx.jsx)("path",{d:"m9 12 2 2 4-4"})]}),"x-circle":(0,_snapJsx.jsxs)(_snapJsx.Fragment,{children:[(0,_snapJsx.jsx)("circle",{cx:"12",cy:"12",r:"10"}),(0,_snapJsx.jsx)("path",{d:"m15 9-6 6"}),(0,_snapJsx.jsx)("path",{d:"m9 9 6 6"})]}),info:(0,_snapJsx.jsxs)(_snapJsx.Fragment,{children:[(0,_snapJsx.jsx)("circle",{cx:"12",cy:"12",r:"10"}),(0,_snapJsx.jsx)("path",{d:"M12 16v-4"}),(0,_snapJsx.jsx)("path",{d:"M12 8h.01"})]}),shield:(0,_snapJsx.jsx)("path",{d:"M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"}),"shield-check":(0,_snapJsx.jsxs)(_snapJsx.Fragment,{children:[(0,_snapJsx.jsx)("path",{d:"M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"}),(0,_snapJsx.jsx)("path",{d:"m9 12 2 2 4-4"})]}),database:(0,_snapJsx.jsxs)(_snapJsx.Fragment,{children:[(0,_snapJsx.jsx)("ellipse",{cx:"12",cy:"5",rx:"9",ry:"3"}),(0,_snapJsx.jsx)("path",{d:"M3 5v14a9 3 0 0 0 18 0V5"}),(0,_snapJsx.jsx)("path",{d:"M3 12a9 3 0 0 0 18 0"})]}),file:(0,_snapJsx.jsxs)(_snapJsx.Fragment,{children:[(0,_snapJsx.jsx)("path",{d:"M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"}),(0,_snapJsx.jsx)("path",{d:"M14 2v4a2 2 0 0 0 2 2h4"})]}),folder:(0,_snapJsx.jsx)("path",{d:"M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"}),archive:(0,_snapJsx.jsxs)(_snapJsx.Fragment,{children:[(0,_snapJsx.jsx)("rect",{width:"20",height:"5",x:"2",y:"3",rx:"1"}),(0,_snapJsx.jsx)("path",{d:"M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"}),(0,_snapJsx.jsx)("path",{d:"M10 12h4"})]}),plug:(0,_snapJsx.jsxs)(_snapJsx.Fragment,{children:[(0,_snapJsx.jsx)("path",{d:"M12 22v-5"}),(0,_snapJsx.jsx)("path",{d:"M9 8V2"}),(0,_snapJsx.jsx)("path",{d:"M15 8V2"}),(0,_snapJsx.jsx)("path",{d:"M18 8v5a4 4 0 0 1-4 4h-4a4 4 0 0 1-4-4V8Z"})]}),clock:(0,_snapJsx.jsxs)(_snapJsx.Fragment,{children:[(0,_snapJsx.jsx)("circle",{cx:"12",cy:"12",r:"10"}),(0,_snapJsx.jsx)("polyline",{points:"12 6 12 12 16 14"})]}),download:(0,_snapJsx.jsxs)(_snapJsx.Fragment,{children:[(0,_snapJsx.jsx)("path",{d:"M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"}),(0,_snapJsx.jsx)("polyline",{points:"7 10 12 15 17 10"}),(0,_snapJsx.jsx)("line",{x1:"12",x2:"12",y1:"15",y2:"3"})]}),upload:(0,_snapJsx.jsxs)(_snapJsx.Fragment,{children:[(0,_snapJsx.jsx)("path",{d:"M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"}),(0,_snapJsx.jsx)("polyline",{points:"17 8 12 3 7 8"}),(0,_snapJsx.jsx)("line",{x1:"12",x2:"12",y1:"3",y2:"15"})]}),refresh:(0,_snapJsx.jsxs)(_snapJsx.Fragment,{children:[(0,_snapJsx.jsx)("path",{d:"M21 12a9 9 0 0 0-15.7-6L3 8"}),(0,_snapJsx.jsx)("path",{d:"M3 4v5h5"}),(0,_snapJsx.jsx)("path",{d:"M3 12a9 9 0 0 0 15.7 6l2.3-2"}),(0,_snapJsx.jsx)("path",{d:"M21 20v-5h-5"})]}),"rotate-ccw":(0,_snapJsx.jsxs)(_snapJsx.Fragment,{children:[(0,_snapJsx.jsx)("path",{d:"M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"}),(0,_snapJsx.jsx)("path",{d:"M3 3v5h5"})]}),sun:(0,_snapJsx.jsxs)(_snapJsx.Fragment,{children:[(0,_snapJsx.jsx)("circle",{cx:"12",cy:"12",r:"4"}),(0,_snapJsx.jsx)("path",{d:"M12 2v2"}),(0,_snapJsx.jsx)("path",{d:"M12 20v2"}),(0,_snapJsx.jsx)("path",{d:"m4.93 4.93 1.41 1.41"}),(0,_snapJsx.jsx)("path",{d:"m17.66 17.66 1.41 1.41"}),(0,_snapJsx.jsx)("path",{d:"M2 12h2"}),(0,_snapJsx.jsx)("path",{d:"M20 12h2"}),(0,_snapJsx.jsx)("path",{d:"m6.34 17.66-1.41 1.41"}),(0,_snapJsx.jsx)("path",{d:"m19.07 4.93-1.41 1.41"})]}),moon:(0,_snapJsx.jsx)("path",{d:"M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"}),"chevron-down":(0,_snapJsx.jsx)("polyline",{points:"6 9 12 15 18 9"}),"chevron-right":(0,_snapJsx.jsx)("polyline",{points:"9 18 15 12 9 6"}),"external-link":(0,_snapJsx.jsxs)(_snapJsx.Fragment,{children:[(0,_snapJsx.jsx)("path",{d:"M15 3h6v6"}),(0,_snapJsx.jsx)("path",{d:"M10 14 21 3"}),(0,_snapJsx.jsx)("path",{d:"M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"})]}),trash:(0,_snapJsx.jsxs)(_snapJsx.Fragment,{children:[(0,_snapJsx.jsx)("path",{d:"M3 6h18"}),(0,_snapJsx.jsx)("path",{d:"M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"}),(0,_snapJsx.jsx)("path",{d:"M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"})]}),x:(0,_snapJsx.jsxs)(_snapJsx.Fragment,{children:[(0,_snapJsx.jsx)("line",{x1:"18",x2:"6",y1:"6",y2:"18"}),(0,_snapJsx.jsx)("line",{x1:"6",x2:"18",y1:"6",y2:"18"})]}),check:(0,_snapJsx.jsx)("polyline",{points:"20 6 9 17 4 12"}),"help-circle":(0,_snapJsx.jsxs)(_snapJsx.Fragment,{children:[(0,_snapJsx.jsx)("circle",{cx:"12",cy:"12",r:"10"}),(0,_snapJsx.jsx)("path",{d:"M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"}),(0,_snapJsx.jsx)("line",{x1:"12",x2:"12.01",y1:"17",y2:"17"})]})},d=[],p=new Set,h="2026-05-28T19-33-52-729Z-qitg0o";function u(){return"undefined"!=typeof window&&Boolean(window.snapshoterDebug)}function _(e,s,n,t){const a={t:Date.now(),level:e,category:s,message:n,payload:t};d.push(a),d.length>500&&d.splice(0,d.length-500),"undefined"!=typeof window&&(window.snapshoterDebugBuffer=d);for(const e of p)e([...d]);if(!u())return;const r=`[Snapshoter:${s}]`;let l;l="error"===e?console.error:"warn"===e?console.warn:console.log,void 0!==t?l(r,n,t):l(r,n)}"undefined"!=typeof window&&(window.snapshoterBuildId=h,"undefined"!=typeof document&&document.documentElement.setAttribute("data-snapshoter-build",h));const m={info(e,s,n){_("info",e,s,n)},warn(e,s,n){_("warn",e,s,n)},error(e,s,n){_("error",e,s,n)},getBuffer:()=>[...d],subscribe:e=>(p.add(e),()=>{p.delete(e)}),clear(){d.length=0;for(const e of p)e([])},isEnabled:u};class f extends Error{constructor(e,s={}){super(e),this.name="ApiError",this.status=s.status??0,this.code=s.code,this.attempts=s.attempts??1}}
const ACTIVE_JOB_KEY="snapshoter:active_job_v1";
function isValidActiveJobId(id){const s=String(id||"").trim();if(!s||s.length>128)return!1;if(/error|HTTP\s+\d{3}|ApiError|TypeError|failed/i.test(s))return!1;return!0}
function saveActiveJob(job){try{if("undefined"==typeof window||!window.localStorage||!job?.jobId||!job?.jobPassphrase)return;if(!isValidActiveJobId(job.jobId))return;window.localStorage.setItem(ACTIVE_JOB_KEY,JSON.stringify({jobId:String(job.jobId),jobPassphrase:String(job.jobPassphrase),kind:job.kind||"import",mode:job.mode||"poll",savedAt:Date.now()}))}catch(e){}}
function loadActiveJob(){try{if("undefined"==typeof window||!window.localStorage)return null;const raw=window.localStorage.getItem(ACTIVE_JOB_KEY);if(!raw)return null;const j=JSON.parse(raw);if(!j?.jobId||!j?.jobPassphrase)return null;if(!isValidActiveJobId(j.jobId)){try{window._snapStepFloor=null}catch(_sf){}clearActiveJob();return null}if(Date.now()-(j.savedAt||0)>216e5){clearActiveJob();return null}return j}catch(e){return null}}
function clearActiveJob(){try{"undefined"!=typeof window&&window.localStorage&&window.localStorage.removeItem(ACTIVE_JOB_KEY)}catch(e){}}
function isCriticalSiteError(e){if(!e)return!1;if(e.code==="site_critical")return!0;const msg=String(e.message||e||"");return/critical error|briefly unavailable|fatal error|allowed memory size|maximum execution time/i.test(msg)}function isAuthLostError(e){if(!e)return!1;if(e.code==="auth_lost")return!0;if(isCriticalSiteError(e))return!1;return /\bHTTP (?:401|403)\b|wp-login\.php/i.test(String(e.message||e||""))}function preferGracefulRestore(){try{const s="undefined"!=typeof window&&window.snapshoter?window.snapshoter:null;return!(s&&s.gracefulRestore===!1)}catch{return!0}}
const x=new Set([502,503,504]);class g{constructor(e={}){const s=e.endpoint&&e.nonce?void 0:function(){try{return function(){if("undefined"==typeof window||!window.snapshoter)throw new f("Snapshoter global is not available. Plugin.php must localize `window.snapshoter` before the bundle runs.");return window.snapshoter}()}catch{return}}();if(this.endpoint=e.endpoint??s?.ajaxUrl??"",this.nonce=e.nonce??s?.nonce??"",this.fetchImpl=e.fetchImpl??("undefined"!=typeof window?window.fetch.bind(window):fetch),this.schedule=e.scheduler??((e,s)=>{setTimeout(e,s)}),this.maxAttempts=e.maxAttempts??5,this.baseDelayMs=e.baseDelayMs??2e3,!this.endpoint)throw new f("SnapshoterApi: missing admin-ajax endpoint. Provide one explicitly or ensure window.snapshoter.ajaxUrl is set.")}async request(e,s={},n){const t=new FormData;const sn=("undefined"!=typeof window&&window.snapshoter&&window.snapshoter.nonce)||this.nonce;t.append("action",e),t.append("_wpnonce",sn),t.append("SNAPSHOTER_nonce",sn);for(const[e,n]of Object.entries(s))null!=n&&(n instanceof Blob?t.append(e,n):t.append(e,String(n)));let a=this.endpoint;if("SNAPSHOTER_list_snapshots"===e){const e=a.includes("?")?"&":"?";a+=`${e}_t=${Date.now()}`}const r=await this.fetchWithRetry(a,t,n);let l;const rawBody=await r.text();try{l=JSON.parse(rawBody)}catch{let errBody=rawBody;const trimmed=(errBody||"").trim();const snippet=errBody?errBody.replace(/<[^>]*>?/gm," ").replace(/\s+/g," ").trim().slice(0,150):"";const isLoginHtml=/wp-login\.php|name=["']log["']|id=["']loginform["']/i.test(errBody||"");const isCritical=/critical error|There has been a critical error|fatal error|allowed memory size|Maximum execution time/i.test(errBody||"");const authLost=401===r.status||403===r.status||"0"===trimmed||"-1"===trimmed||isLoginHtml;const code=isCritical?"site_critical":(authLost?"auth_lost":void 0);throw new f(`Snapshoter ${e} error (HTTP ${r.status})${snippet?": "+snippet:" (non-JSON response)." }`,{status:r.status,code:code})}if(!l.success){const s="string"==typeof l.data?.message&&l.data.message?l.data.message:(l.data?.code?`Snapshoter ${e} failed (${l.data.code}).`:`Snapshoter ${e} failed.`);throw new f(s,{status:r.status,code:l.data?.code,data:l.data})}return l.data}fetchWithRetry(e,s,n){return new Promise((t,a)=>{const r=(l,o)=>{n?.aborted?a(new f("Aborted.",{attempts:l})):this.fetchImpl(e,{method:"POST",credentials:"same-origin",body:s,headers:o,signal:n}).then(e=>{if(!e.ok&&x.has(e.status)&&l<this.maxAttempts){const s=this.baseDelayMs*Math.pow(2,l-1);return (u()&&console.warn(`[Snapshoter] HTTP ${e.status} from admin-ajax. Backing off for ${s}ms (attempt ${l}/${this.maxAttempts}).`)),void this.schedule(()=>{r(l+1,{"X-snapshoter-Retry":"true","X-snapshoter-Throttle":"true"})},s)}t(e)}).catch(e=>{if(e instanceof Error&&"AbortError"===e.name)return void a(new f("Aborted.",{attempts:l}));if(l<this.maxAttempts){const s=this.baseDelayMs;return (u()&&console.warn(`[Snapshoter] Network error contacting admin-ajax. Retrying in ${s}ms (attempt ${l}/${this.maxAttempts}).`),e),void this.schedule(()=>{r(l+1,o)},s)}const s=e instanceof Error?e.message:"Network error";a(new f(s,{attempts:l}))})};r(1,void 0)})}requestWithUploadProgress(e,s={},n,o){return new Promise((a,r)=>{const t=new FormData,i=("undefined"!=typeof window&&window.snapshoter&&window.snapshoter.nonce)||this.nonce;t.append("action",e),t.append("_wpnonce",i),t.append("SNAPSHOTER_nonce",i);for(const[e,n]of Object.entries(s))null!=n&&(n instanceof Blob?t.append(e,n):t.append(e,String(n)));const c=new XMLHttpRequest;c.open("POST",this.endpoint),c.withCredentials=!0;const p=()=>{try{n&&n.removeEventListener("abort",p)}catch{}try{c.abort()}catch{}};n&&(n.aborted?p():n.addEventListener("abort",p));c.upload&&"function"==typeof o&&(c.upload.onprogress=e=>{o(e.loaded|0,e.total|0)});c.onload=()=>{try{n&&n.removeEventListener("abort",p)}catch{}let s;const i=c.responseText||"";try{s=JSON.parse(i)}catch{const n=(i||"").replace(/<[^>]*>?/gm," ").replace(/\s+/g," ").trim().slice(0,150),t=/wp-login\.php|name=["']log["']|id=["']loginform["']/i.test(i||""),l=/critical error|There has been a critical error|fatal error|allowed memory size|Maximum execution time/i.test(i||""),h=401===c.status||403===c.status||"0"===(i||"").trim()||"-1"===(i||"").trim()||t;return void r(new f(`Snapshoter ${e} error (HTTP ${c.status})${n?": "+n:" (non-JSON response)."}`,{status:c.status,code:l?"site_critical":h?"auth_lost":void 0}))}if(!s||!s.success){const n="string"==typeof s?.data?.message&&s.data.message?s.data.message:(s?.data?.code?`Snapshoter ${e} failed (${s.data.code}).`:`Snapshoter ${e} failed.`);return void r(new f(n,{status:c.status,code:s?.data?.code,data:s?.data}))}a(s.data)};c.onerror=()=>{try{n&&n.removeEventListener("abort",p)}catch{}r(new f("Network error",{status:c.status||0}))};c.onabort=()=>{try{n&&n.removeEventListener("abort",p)}catch{}r(new f("Aborted.",{status:0}))};c.send(t)})}}let b=null;function j(){return b||(b=new g),b}function y(e){const s={...e.state??{},...e};return delete s.state,delete s.log,"string"!=typeof s.type&&e.state?.type&&(s.type=e.state.type),"string"!=typeof s.engine&&e.state?.engine&&(s.engine=e.state.engine),"string"!=typeof s.restore_mode&&e.state?.restore_mode&&(s.restore_mode=e.state.restore_mode),"string"!=typeof s.step&&(s.step=e.completed?"completed":"init"),"string"!=typeof s.status&&(e.completed?s.status="completed":e.cancelled?s.status="cancelled":s.status="running"),"number"!=typeof s.percent&&"number"==typeof s.progress&&(s.percent=s.progress),s}function w(e){return{downloadUrl:String(e.download_url??e.downloadUrl??""),filename:String(e.filename??e.name??""),size:"number"==typeof e.size?e.size:void 0}}async function v(e,s){m.info("api","export_database_only →",{});const n=await e.request("SNAPSHOTER_export_database_only",{},s);return m.info("api","export_database_only ←",n),w(n)}async function k(e,s){m.info("api","export_files_only →",{});const n=await e.request("SNAPSHOTER_export_files_only",{},s);return m.info("api","export_files_only ←",n),w(n)}async function S(e,s,n,o){m.info("api",`upload_chunk → ${s.chunkIndex+1}/${s.totalChunks} (${s.chunk.size}B)`);const t=await e.requestWithUploadProgress("SNAPSHOTER_upload_chunk",{job_id:s.jobId,job_passphrase:s.jobPassphrase,chunk_index:s.chunkIndex,total_chunks:s.totalChunks,file_name:s.fileName,chunk:s.chunk,upload_mode:"parts"},n,o),a=y(t);return"number"==typeof t.received_bytes&&(a.received_bytes=t.received_bytes),"number"==typeof t.next_chunk_index&&(a.next_chunk_index=t.next_chunk_index),a}function N(e,s,n){return e.request("SNAPSHOTER_get_job_log",{job_id:s.jobId,job_passphrase:s.jobPassphrase||"",offset:s.offset},n)}function C(e){
  const s = e.filename ?? e.name ?? "";
  const n = s.includes(".") ? s.split(".").pop()?.toLowerCase() : void 0;
  const t = e.createdAt ?? ("number" == typeof e.timestamp ? new Date(1e3 * e.timestamp).toISOString() : e.date ?? "");
  const locLabel = e.location_label || "Local (Server)";
  return {
    id: e.id ?? e.path ?? s,
    name: s || e.name || e.id || "snapshot",
    filename: s,
    extension: n,
    createdAt: t,
    sizeBytes: "number" == typeof e.sizeBytes ? e.sizeBytes : 0,
    sizeFormatted: e.sizeFormatted ?? e.size,
    path: e.path,
    downloadUrl: e.downloadUrl ?? e.download_url,
    location: "local",
    locationLabel: locLabel
  };
}


function P(e){return{payload:JSON.stringify(e??{})}}function E(e){if(!e)return(0,_snapI18n.__)("-","snapshoter");const n=Math.max(0,Math.floor(Date.now()/1e3)-e);if(n<60)return(0,_snapI18n.__)("just now","snapshoter");if(n<3600){const e=Math.floor(n/60);return(0,_snapI18n.sprintf)(/* translators: %d: minutes */
(0,_snapI18n.__)("%dm ago","snapshoter"),e)}if(n<86400){const e=Math.floor(n/3600);return(0,_snapI18n.sprintf)(/* translators: %d: hours */
(0,_snapI18n.__)("%dh ago","snapshoter"),e)}const t=Math.floor(n/86400);return(0,_snapI18n.sprintf)(/* translators: %d: days */
(0,_snapI18n.__)("%dd ago","snapshoter"),t)}function R(e){return"success"===e?(0,_snapI18n.__)("Last full backup ok","snapshoter"):"partial"===e?(0,_snapI18n.__)("Last full backup partial","snapshoter"):"failed"===e?(0,_snapI18n.__)("Last full backup failed","snapshoter"):(0,_snapI18n.__)("Last full backup","snapshoter")}function formatLastBackupDate(e){if(!e)return"";try{const s=ne(),n=new Date(1e3*e),t=n.toLocaleDateString(void 0,Object.assign({},s?{timeZone:s}:{},{month:"short",day:"numeric"})),a=n.toLocaleTimeString(void 0,Object.assign({},s?{timeZone:s}:{},{hour:"numeric",minute:"2-digit"}));return t+", "+a}catch{return E(e)}}function $(){const[s,t]=(0,_snapReact.useState)(null),[,a]=(0,_snapReact.useState)(0);const fetchLatest=(0,_snapReact.useCallback)(async()=>{try{const e=await j().request("SNAPSHOTER_list_snapshots",{},void 0);const list=Array.isArray(e&&e.snapshots)?e.snapshots:[];const isFullSmartin=s=>{const n=String(s&&(s.name||s.filename||s.file||""));return n.endsWith(".smartin");};const s=list.find(isFullSmartin)||null;if(s){const n=s.timestamp?s.timestamp:Math.floor(new Date(s.createdAt||s.date||Date.now()).getTime()/1e3);t({at:n,status:"success",type:"full"});return}t(null)}catch{}},[]);(0,_snapReact.useEffect)(()=>{fetchLatest();if("undefined"!=typeof window){window.addEventListener("snapshoter:backup-created",fetchLatest);window.addEventListener("snapshoter:snapshots-updated",fetchLatest);window.addEventListener("snapshoter:backup-deleted",fetchLatest);window.addEventListener("snapshoter:snapshot-deleted",fetchLatest);return()=>{window.removeEventListener("snapshoter:backup-created",fetchLatest);window.removeEventListener("snapshoter:snapshots-updated",fetchLatest);window.removeEventListener("snapshoter:backup-deleted",fetchLatest);window.removeEventListener("snapshoter:snapshot-deleted",fetchLatest);}}},[fetchLatest]);(0,_snapReact.useEffect)(()=>{const e=setInterval(()=>a(e=>e+1),6e4);return()=>clearInterval(e)},[]);if(!s)return(0,_snapJsx.jsxs)("span",{className:"snap-latest snap-latest--none",title:(0,_snapI18n.__)("No full backup created yet","snapshoter"),children:[(0,_snapJsx.jsx)("span",{className:"snap-latest__dot snap-latest__dot--gray","aria-hidden":"true"}),(0,_snapJsx.jsx)("span",{className:"snap-latest__label",children:(0,_snapI18n.__)("Last full backup:","snapshoter")}),(0,_snapJsx.jsx)("span",{className:"snap-latest__time",children:(0,_snapI18n.__)("None yet","snapshoter")})]});const o=(0,_snapI18n.__)("Local","snapshoter"),d="Last full backup · "+formatLastBackupDate(s.at)+" ("+E(s.at)+") · "+o;return(0,_snapJsx.jsxs)("a",{className:"snap-latest snap-latest--success",href:"#snapshots",title:s.message||d,children:[(0,_snapJsx.jsx)("span",{className:"snap-latest__dot","aria-hidden":"true"}),(0,_snapJsx.jsx)("span",{className:"snap-latest__label",children:(0,_snapI18n.__)("Last full backup:","snapshoter")}),(0,_snapJsx.jsx)("strong",{className:"snap-latest__datetime",children:formatLastBackupDate(s.at)}),(0,_snapJsx.jsxs)("span",{className:"snap-latest__time",children:["(",E(s.at),")"]}),(0,_snapJsx.jsxs)("span",{className:"snap-latest__type-pill snap-latest__type-pill--"+(l?"online":"local"),style:{display:"inline-flex",alignItems:"center",gap:"5px",whiteSpace:"nowrap",flexShrink:0},children:[(0,_snapJsx.jsx)(i,{name:"server",size:12,className:"snap-latest__icon"}),(0,_snapJsx.jsx)("span",{style:{whiteSpace:"nowrap"},children:o})]})]})}
function Ie(e){if(!e)return"-";try{const t=Number(e)>1e12?Number(e):Number(e)*1e3;const d=new Date(t);return Number.isNaN(d.getTime())?"-":d.toLocaleString()}catch{return"-"}}
function DiagnosticsModal({open: s, onClose: a}) {
  const [copied, setCopied] = (0,_snapReact.useState)(false);
  const [downloaded, setDownloaded] = (0,_snapReact.useState)(false);
  const [loading, setLoading] = (0,_snapReact.useState)(true);
  const [lastBackup, setLastBackup] = (0,_snapReact.useState)(null);
  const [viewLogs, setViewLogs] = (0,_snapReact.useState)(true);

  const snap = "undefined" != typeof window ? (window.snapshoter || {}) : {};

  (0,_snapReact.useEffect)(() => {
    if (!s) return;
    let mounted = true;
    setLoading(true);
    setLastBackup(null);
    (async () => {
      const mapSnap = (sn) => {
        if (!sn) return null;
        const name = String(sn.name || sn.filename || sn.file || "");
        const lower = name.toLowerCase();
        let type = sn.type || "full";
        if (lower.endsWith(".sql")) type = "database";
        else if (lower.endsWith(".zip")) type = "files";
        else if (lower.endsWith(".smartin")) type = "full";
        else if (sn.scope) {
          const sc = String(sn.scope).toLowerCase();
          if (sc.indexOf("database") >= 0) type = "database";
          else if (sc.indexOf("file") >= 0) type = "files";
        }
        const at = sn.at || sn.timestamp || sn.created || (sn.createdAt ? Math.floor(new Date(sn.createdAt).getTime() / 1000) : 0);
        const bytes = Number(sn.bytes || sn.sizeBytes || sn.size_bytes || 0) || 0;
        const dur = sn.duration || sn.duration_seconds;
        return {
          at: at || 0,
          status: sn.status || "success",
          type: type,
          scope_label: sn.scope || null,
          bytes: bytes,
          duration: (dur !== undefined && dur !== null && dur !== "" && Number(dur) > 0) ? Number(dur) : null,
          error_message: sn.error_message || sn.error || null,
          name: name
        };
      };
      let resolved = null;
      try {
        const snaps = await j().request("SNAPSHOTER_list_snapshots", {}, void 0);
        const list = Array.isArray(snaps && snaps.snapshots) ? snaps.snapshots.slice() : [];
        list.sort((a, b) => {
          const ta = a && (a.timestamp || a.created || 0);
          const tb = b && (b.timestamp || b.created || 0);
          return (tb || 0) - (ta || 0);
        });
        const isFullSmartin = (x) => {
          const n = String(x && (x.name || x.filename || x.file || ""));
          return n.endsWith(".smartin");
        };
        const pick = list.find(isFullSmartin) || list[0] || null;
        resolved = mapSnap(pick);
      } catch (e) {}
      if (mounted) setLastBackup(resolved);
      if (mounted) setLoading(false);
    })();
    return () => { mounted = false; };
  }, [s]);

  const envInfo = {
    wpVersion: snap.wpVersion || "-",
    phpVersion: snap.phpVersion || "-",
    serverSoftware: snap.serverSoftware || "-",
    memoryLimit: snap.memoryLimit || "-",
    maxExecutionTime: snap.maxExecutionTime !== undefined && snap.maxExecutionTime !== "" && snap.maxExecutionTime !== null ? (String(snap.maxExecutionTime).endsWith("s") ? String(snap.maxExecutionTime) : (snap.maxExecutionTime + "s")) : "-",
    uploadMaxFilesize: snap.uploadMaxFilesize || "-",
    postMaxSize: snap.postMaxSize || "-",
    activeTheme: snap.activeTheme || "-",
    timezone: snap.timezone || "-",
    siteUrl: snap.siteUrl || ("undefined" != typeof window ? window.location.origin : "-")
  };

  const sanitizeText = (txt) => {
    if (!txt) return "";
    return txt
      .replace(/AKIA[0-9A-Z]{16}/g, "AKIA************")
      .replace(/(secret[_-]?key["':=\s]+)["']?[^"',\s}]+["']?/gi, '$1"[REDACTED]"')
      .replace(/(password["':=\s]+)["']?[^"',\s}]+["']?/gi, '$1"[REDACTED]"')
      .replace(/(access[_-]?token["':=\s]+)["']?[^"',\s}]+["']?/gi, '$1"[REDACTED]"')
      .replace(/(nonce["':=\s]+)["']?[a-f0-9]+["']?/gi, '$1"[REDACTED]"')
  };

  const buildReportText = () => {
    const rawEvents = m ? m.getBuffer() : [];
    const eventLines = rawEvents.slice(-50).map(Ve);

    const lines = [
      "=== SNAPSHOTER DIAGNOSTIC REPORT ===",
      "Generated: " + new Date().toUTCString(),
      "Site URL: " + envInfo.siteUrl,
      "",
      "[SERVER & ENVIRONMENT]",
      "WordPress: " + envInfo.wpVersion,
      "PHP: " + envInfo.phpVersion,
      "Web Server: " + envInfo.serverSoftware,
      "Memory Limit: " + envInfo.memoryLimit,
      "Max Execution Time: " + envInfo.maxExecutionTime,
      "Upload Max Filesize: " + envInfo.uploadMaxFilesize,
      "Post Max Size: " + envInfo.postMaxSize,
      "Active Theme: " + envInfo.activeTheme,
      "Timezone: " + envInfo.timezone,
      "",
      "[LAST BACKUP RUN]",
      "Status: " + (lastBackup ? (lastBackup.status || "success").toUpperCase() : "No backup yet"),
      "Date & Time: " + (lastBackup ? Ie(lastBackup.at) : "-"),
      "Scope: " + (lastBackup ? (lastBackup.type || "full").toUpperCase() : "-"),
      "Location: " + (lastBackup ? "Local Server" : "-"),
      "Size: " + (lastBackup && lastBackup.bytes ? U(lastBackup.bytes) : "-"),
      "Error: " + (lastBackup && lastBackup.error_message ? lastBackup.error_message : (lastBackup && "failed" === lastBackup.status ? "Backup failed" : "None")),
      "Skipped Files: " + (lastBackup && lastBackup.skipped_files && lastBackup.skipped_files.count ? lastBackup.skipped_files.count : "0"),
      "",
      "[DIAGNOSTIC EVENTS (LAST 50)]",
      ...(eventLines.length > 0 ? eventLines : ["[No events recorded]"]),
      "===================================="
    ];
    return sanitizeText(lines.join("\n"));
  };

  const handleCopy = async () => {
    const text = buildReportText();
    let success = false;
    try {
      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(text);
        success = true;
      }
    } catch (e) {}

    if (!success) {
      try {
        const textArea = document.createElement("textarea");
        textArea.value = text;
        textArea.style.position = "fixed";
        textArea.style.left = "-999999px";
        textArea.style.top = "-999999px";
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        success = document.execCommand("copy");
        textArea.remove();
      } catch (e) {}
    }

    if (success) {
      setCopied(true);
      setTimeout(() => setCopied(false), 2500);
    }
  };

  const handleDownload = () => {
    try {
      const text = buildReportText();
      const blob = new Blob([text], { type: "text/plain;charset=utf-8" });
      const url = URL.createObjectURL(blob);
      const host = (envInfo.siteUrl || "site").replace(/https?:\/\//, "").replace(/[^a-zA-Z0-9.-]/g, "_");
      const dateStr = new Date().toISOString().slice(0, 10);
      const a = document.createElement("a");
      a.href = url;
      a.download = `snapshoter-diagnostics-${host}-${dateStr}.txt`;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
      setDownloaded(true);
      setTimeout(() => setDownloaded(false), 2500);
    } catch (e) {}
  };

  const handleEmailSupport = () => {
    const host = (envInfo.siteUrl || "WordPress Site").replace(/https?:\/\//, "");
    const subject = encodeURIComponent(`[Snapshoter Support] Diagnostics - ${host}`);
    const summary = encodeURIComponent(
      `Hello,\n\nI am attaching the diagnostics report for ${host}:\n\n` + buildReportText()
    );
    window.open(`mailto:smartin@smartin.in?subject=${subject}&body=${summary}`, "_blank");
  };

  const rawEvents = m ? m.getBuffer() : [];
  const eventLines = rawEvents.slice(-50).map(Ve);

  const isFailed = lastBackup && "failed" === lastBackup.status;

  const modalBody = (0,_snapJsx.jsxs)("div",{style:{display:"flex",flexDirection:"column",gap:"12px",fontSize:"13px",lineHeight:"1.45",color:"#1d2327"},children:[
    (0,_snapJsx.jsxs)("div",{style:{display:"grid",gridTemplateColumns:"1fr 1fr",gap:"10px"},children:[
      (0,_snapJsx.jsxs)("div",{
        style:{
          padding:"12px 14px",
          borderRadius:"2px",
          background:"#fff",
          border:"1px solid #c3c4c7"
        },
        children:[
          (0,_snapJsx.jsx)("div",{
            style:{fontWeight:600,fontSize:"12px",color:"#1d2327",marginBottom:"8px",paddingBottom:"6px",borderBottom:"1px solid #dcdcde"},
            children:(0,_snapI18n.__)("Server & environment","snapshoter")
          }),
          (0,_snapJsx.jsxs)("div",{style:{display:"flex",flexDirection:"column",gap:"5px",fontSize:"12.5px"},children:[
            (0,_snapJsx.jsxs)("div",{style:{display:"flex",justifyContent:"space-between",gap:"10px"},children:[(0,_snapJsx.jsx)("span",{style:{color:"#646970"},children:"PHP Version"}),(0,_snapJsx.jsx)("strong",{style:{color:"#1d2327",fontWeight:600},children:envInfo.phpVersion})]}),
            (0,_snapJsx.jsxs)("div",{style:{display:"flex",justifyContent:"space-between",gap:"10px"},children:[(0,_snapJsx.jsx)("span",{style:{color:"#646970"},children:"Memory Limit"}),(0,_snapJsx.jsx)("strong",{style:{color:"#1d2327",fontWeight:600},children:envInfo.memoryLimit})]}),
            (0,_snapJsx.jsxs)("div",{style:{display:"flex",justifyContent:"space-between",gap:"10px"},children:[(0,_snapJsx.jsx)("span",{style:{color:"#646970"},children:"Max Exec Time"}),(0,_snapJsx.jsx)("strong",{style:{color:"#1d2327",fontWeight:600},children:envInfo.maxExecutionTime})]}),
            (0,_snapJsx.jsxs)("div",{style:{display:"flex",justifyContent:"space-between",gap:"10px"},children:[(0,_snapJsx.jsx)("span",{style:{color:"#646970"},children:"Upload Max"}),(0,_snapJsx.jsx)("strong",{style:{color:"#1d2327",fontWeight:600},children:envInfo.uploadMaxFilesize})]}),
            (0,_snapJsx.jsxs)("div",{style:{display:"flex",justifyContent:"space-between",gap:"10px"},children:[(0,_snapJsx.jsx)("span",{style:{color:"#646970"},children:"Web Server"}),(0,_snapJsx.jsx)("strong",{style:{color:"#1d2327",fontWeight:600},children:envInfo.serverSoftware})]})
          ]})
        ]
      }),

      (0,_snapJsx.jsxs)("div",{
        style:{
          padding:"12px 14px",
          borderRadius:"2px",
          background:"#fff",
          border:"1px solid #c3c4c7"
        },
        children:[
          (0,_snapJsx.jsx)("div",{
            style:{fontWeight:600,fontSize:"12px",color:"#1d2327",marginBottom:"8px",paddingBottom:"6px",borderBottom:"1px solid #dcdcde"},
            children:(0,_snapI18n.__)("Last backup","snapshoter")
          }),
          loading ? (0,_snapJsx.jsx)("div",{style:{color:"#646970",fontSize:"12.5px",padding:"6px 0"},children:(0,_snapI18n.__)("Loading backup data...","snapshoter")}) :
          (0,_snapJsx.jsxs)("div",{style:{display:"flex",flexDirection:"column",gap:"5px",fontSize:"12.5px"},children:[
            (0,_snapJsx.jsxs)("div",{style:{display:"flex",justifyContent:"space-between",gap:"10px",alignItems:"baseline"},children:[
              (0,_snapJsx.jsx)("span",{style:{color:"#646970"},children:"Status"}),
              (0,_snapJsx.jsx)("strong",{
                style:{
                  fontWeight:600,
                  fontSize:"12.5px",
                  color: !lastBackup ? "#646970" : (isFailed ? "#b32d2e" : "#007017")
                },
                children: lastBackup ? (lastBackup.status || "success").toUpperCase() : (0,_snapI18n.__)("No backup yet","snapshoter")
              })
            ]}),
            (0,_snapJsx.jsxs)("div",{style:{display:"flex",justifyContent:"space-between",gap:"10px"},children:[(0,_snapJsx.jsx)("span",{style:{color:"#646970"},children:"Scope"}),(0,_snapJsx.jsx)("strong",{style:{color:"#1d2327",fontWeight:600,textAlign:"right"},children:lastBackup?`${(lastBackup.scope_label||lastBackup.type||"full").toString().toUpperCase()}`:"-"})]}),
            (0,_snapJsx.jsxs)("div",{style:{display:"flex",justifyContent:"space-between",gap:"10px"},children:[(0,_snapJsx.jsx)("span",{style:{color:"#646970"},children:"Archive Size"}),(0,_snapJsx.jsx)("strong",{style:{color:"#1d2327",fontWeight:600},children:lastBackup&&lastBackup.bytes?U(lastBackup.bytes):"-"})]}),
            (0,_snapJsx.jsxs)("div",{style:{display:"flex",justifyContent:"space-between",gap:"10px"},children:[(0,_snapJsx.jsx)("span",{style:{color:"#646970"},children:"Date"}),(0,_snapJsx.jsx)("strong",{style:{color:"#1d2327",fontWeight:600},children:lastBackup&&lastBackup.at?Ie(lastBackup.at):"-"})]})
          ]})
        ]
      })
    ]}),

    (0,_snapJsx.jsxs)("div",{style:{
      border:"1px solid #c3c4c7",
      borderRadius:"2px",
      background:"#fff",
      overflow:"hidden"
    },children:[
      (0,_snapJsx.jsxs)("div",{style:{
        display:"flex",
        justifyContent:"space-between",
        alignItems:"center",
        gap:"10px",
        padding:"8px 12px",
        background:"#f0f0f1",
        borderBottom: viewLogs ? "1px solid #c3c4c7" : "none"
      },children:[
        (0,_snapJsx.jsxs)("div",{style:{display:"flex",alignItems:"baseline",gap:"8px",minWidth:0,flexWrap:"wrap"},children:[
          (0,_snapJsx.jsx)("span",{style:{fontWeight:600,fontSize:"12.5px",color:"#1d2327"},children:(0,_snapI18n.__)("Activity log","snapshoter")}),
          (0,_snapJsx.jsx)("span",{style:{fontSize:"12px",color:"#646970",fontWeight:400},children:(0,_snapJsx.jsxs)(_snapJsx.Fragment,{children:["(",(0,_snapI18n.sprintf)(
            /* translators: %d: event count */
            (0,_snapI18n._n)("%d event","%d events",eventLines.length,"snapshoter"),
            eventLines.length
          ),")"]})})
        ]}),
        (0,_snapJsx.jsx)("button",{
          type:"button",
          onClick:()=>setViewLogs(!viewLogs),
          style:{
            background:"transparent",
            border:"none",
            color:"#2271b1",
            cursor:"pointer",
            fontSize:"12px",
            fontWeight:400,
            padding:0,
            textDecoration:"underline",
            lineHeight:1.2
          },
          children:viewLogs?(0,_snapI18n.__)("Hide","snapshoter"):(0,_snapI18n.__)("Show","snapshoter")
        })
      ]}),
      viewLogs ? (0,_snapJsx.jsx)("div",{
        style:{
          margin:0,
          background:"#1d2327",
          maxHeight:"180px",
          overflow:"auto"
        },
        children: (rawEvents.slice(-50).length>0)
          ? (0,_snapJsx.jsx)("div",{style:{display:"flex",flexDirection:"column",padding:"4px 0"},children:
              rawEvents.slice(-50).map((ev, i) => {
                const level = String((ev && ev.level) || "info").toLowerCase();
                let time = "-";
                try {
                  if (ev && ev.t) {
                    const d = new Date(ev.t);
                    const p = (n,w=2)=>String(n).padStart(w,"0");
                    time = p(d.getHours())+":"+p(d.getMinutes())+":"+p(d.getSeconds());
                  }
                } catch(e) {}
                const cat = sanitizeText(String((ev && ev.category) || "app"));
                const msg = sanitizeText(String((ev && ev.message) || ""));
                return (0,_snapJsx.jsxs)("div",{
                  key: i,
                  style:{
                    display:"grid",
                    gridTemplateColumns:"64px 48px minmax(48px,64px) 1fr",
                    gap:"8px",
                    alignItems:"start",
                    padding:"5px 12px",
                    borderBottom: i === rawEvents.slice(-50).length - 1 ? "none" : "1px solid #2c3338",
                    fontFamily:"Consolas, Monaco, monospace",
                    fontSize:"11.5px",
                    lineHeight:1.4
                  },
                  children:[
                    (0,_snapJsx.jsx)("span",{style:{color:"#a7aaad",fontVariantNumeric:"tabular-nums"},children:time}),
                    (0,_snapJsx.jsx)("span",{style:{
                      color:"#f0f0f1",fontWeight:600,textTransform:"uppercase",fontSize:"11px"
                    },children: level}),
                    (0,_snapJsx.jsx)("span",{style:{color:"#8c8f94",overflow:"hidden",textOverflow:"ellipsis",whiteSpace:"nowrap"},children:cat}),
                    (0,_snapJsx.jsx)("span",{style:{color:"#f0f0f1",wordBreak:"break-word"},children:msg})
                  ]
                });
              })
            })
          : (0,_snapJsx.jsx)("div",{style:{
              padding:"20px 12px",
              color:"#a7aaad",
              fontSize:"12.5px",
              fontFamily:"Consolas, Monaco, monospace"
            },children:(0,_snapI18n.__)("No events yet. Backup and restore activity will show here.","snapshoter")})
      }) : null
    ]})
  ]});

  const modalActions = [
    {
      label: copied ? (0,_snapI18n.__)("Copied!","snapshoter") : (0,_snapI18n.__)("Copy report","snapshoter"),
      variant: copied ? "primary" : "secondary",
      onClick: handleCopy
    },
    {
      label: downloaded ? (0,_snapI18n.__)("Downloaded!","snapshoter") : (0,_snapI18n.__)("Download .txt","snapshoter"),
      variant: "secondary",
      onClick: handleDownload
    },
    {
      label: (0,_snapI18n.__)("Email support","snapshoter"),
      variant: "secondary",
      onClick: handleEmailSupport
    },
    {
      label: (0,_snapI18n.__)("Close","snapshoter"),
      variant: "ghost",
      onClick: a
    }
  ];

  return (0,_snapJsx.jsx)(ue,{
    open: s,
    maxWidth: "580px",
    title: (0,_snapI18n.__)("Snapshoter System Diagnostics","snapshoter"),
    description: (0,_snapI18n.__)("Server limits and recent backup details for support.","snapshoter"),
    onClose: a,
    actions: modalActions,
    children: modalBody
  });
}
function DiagnosticsFooterBtn() {
  const [isOpen, setIsOpen] = (0,_snapReact.useState)(false);
  return (0,_snapJsx.jsxs)(_snapJsx.Fragment, {
    children: [
      (0,_snapJsx.jsxs)("button", {
        type: "button",
        className: "snap-diag-footer-btn",
        onClick: () => setIsOpen(true),
        title: (0,_snapI18n.__)("View system diagnostics", "snapshoter"),
        children: [
          (0,_snapJsx.jsx)("span", { children: (0,_snapI18n.__)("Diagnostics", "snapshoter") })
        ]
      }),
      (0,_snapJsx.jsx)(DiagnosticsModal, { open: isOpen, onClose: () => setIsOpen(false) })
    ]
  });
}

function z({version:t,docsUrl:r,sourceUrl:l,notices:c,children:d}){
  const{resolved:p,toggle:u}=function(){const s=(0,_snapReact.useContext)(a);if(!s)throw new Error("useTheme() must be used inside <ThemeProvider>");return s}(),_="dark"===p?(0,_snapI18n.__)("Switch to light theme","snapshoter"):(0,_snapI18n.__)("Switch to dark theme","snapshoter");return(0,_snapJsx.jsxs)("div",{className:"snapshoter-app","data-theme":p,children:[(0,_snapJsx.jsxs)("header",{className:"snap-shell__header",children:[(0,_snapJsx.jsxs)("div",{className:"snap-shell__brand",children:[(0,_snapJsx.jsx)("img",{src:("undefined"!=typeof window&&window.snapshoter?.pluginUrl?window.snapshoter.pluginUrl+"assets/icons/snapshoter.svg?v="+(window.snapshoter?.version||Date.now()):""),alt:"Snapshoter Logo",className:"snap-shell__logo"}),(0,_snapJsx.jsxs)("div",{className:"snap-shell__title-block",children:[(0,_snapJsx.jsxs)("div",{className:"snap-shell__name-column",children:[(0,_snapJsx.jsx)("h1",{children:(0,_snapI18n.__)("Snapshoter","snapshoter")}),(0,_snapJsx.jsxs)("span",{className:"snap-shell__author",children:[(0,_snapI18n.__)("by ","snapshoter"),(0,_snapJsx.jsx)("a",{href:window.snapshoter?.authorUrl||"https://www.smartin.in",target:"_blank",rel:"noreferrer",className:"snap-shell__author-link",children:"Smartin"})]})]}),t?(0,_snapJsx.jsxs)("span",{className:"snap-shell__version",children:["v",t]}):null]})]}),(0,_snapJsx.jsx)("div",{className:"snap-shell__header-actions",children:(0,_snapJsx.jsx)($,{})})]}),c?(0,_snapJsx.jsx)("div",{className:"snap-shell__notices",role:"region","aria-label":"Notifications",children:c}):null,(0,_snapJsx.jsx)("main",{className:"snap-shell__main",children:d}),(0,_snapJsx.jsxs)("footer",{className:"snap-shell__footer",children:[(0,_snapJsx.jsxs)("span",{className:"snap-shell__footer-left",children:[(0,_snapJsx.jsx)("span",{children:(0,_snapI18n.__)("Snapshoter","snapshoter")}),(0,_snapJsx.jsx)(DiagnosticsFooterBtn,{})]}),(0,_snapJsx.jsxs)("span",{className:"snap-shell__footer-right",children:[r?(0,_snapJsx.jsx)("a",{href:r,rel:"noreferrer",children:(0,_snapI18n.__)("Help","snapshoter")}):null,l?(0,_snapJsx.jsx)("a",{href:l,target:"_blank",rel:"noreferrer",children:(0,_snapI18n.__)("Website","snapshoter")}):null,(0,_snapJsx.jsx)("span",{className:"snap-shell__build-dot",title:(0,_snapI18n.sprintf)(/* translators: %s: 16-character build identifier. */
(0,_snapI18n.__)("Snapshoter build %s","snapshoter"),h.slice(0,16)),"aria-label":(0,_snapI18n.__)("Snapshoter build identifier","snapshoter"),children:"·"})]})]})]})}
function F({tabs:s}){if(!s||0===s.length)return null;return(0,_snapJsx.jsx)("div",{className:"snap-single-panel",children:s[0].content})}function B({title:e,description:s,actions:t,footer:a,tone:r="default",className:l,children:o}){const i=["snap-card",`snap-card--${r}`,l??""].filter(Boolean).join(" ");return(0,_snapJsx.jsxs)("section",{className:i,children:[(e||t)&&(0,_snapJsx.jsxs)("header",{className:"snap-card__header",children:[(0,_snapJsx.jsxs)("div",{className:"snap-card__heading",children:[e?(0,_snapJsx.jsx)("h2",{className:"snap-card__title",children:e}):null,s?(0,_snapJsx.jsx)("p",{className:"snap-card__description",children:s}):null]}),t?(0,_snapJsx.jsx)("div",{className:"snap-card__actions",children:t}):null]}),o?(0,_snapJsx.jsx)("div",{className:"snap-card__body",children:o}):null,a?(0,_snapJsx.jsx)("footer",{className:"snap-card__footer",children:a}):null]})}const L=[{id:"full",label:(0,_snapI18n.__)("Full backup","snapshoter"),description:(0,_snapI18n.__)("Database + uploads + plugins + themes into a .smartin archive.","snapshoter")},{id:"database",label:(0,_snapI18n.__)("Database only","snapshoter"),description:(0,_snapI18n.__)("Generates a standalone .sql database dump.","snapshoter")},{id:"files",label:(0,_snapI18n.__)("Files only","snapshoter"),description:(0,_snapI18n.__)("Generates a standard .zip archive of wp-content (skipping database).","snapshoter")}];function H({disabled:t=!1,onStart:a}){const[r,l]=(0,_snapReact.useState)("full"),[submitting,setSubmitting]=(0,_snapReact.useState)(!1);return(0,_snapJsx.jsxs)(B,{title:(0,_snapI18n.__)("Create a backup","snapshoter"),description:(0,_snapI18n.__)("Generates a .smartin archive containing your entire site state. Stored locally under wp-content/uploads/snapshoter/.","snapshoter"),children:[(0,_snapJsx.jsxs)("fieldset",{className:"snap-export__modes",disabled:t||submitting,children:[(0,_snapJsx.jsx)("legend",{className:"visually-hidden",children:(0,_snapI18n.__)("Backup scope","snapshoter")}),L.map(e=>{const s=`snap-export-mode-${e.id}`;return(0,_snapJsx.jsxs)("label",{htmlFor:s,"aria-label":e.label,className:"snap-export__option"+(r===e.id?" snap-export__option--selected":""),children:[(0,_snapJsx.jsx)("input",{id:s,type:"radio",name:"snap-export-mode",value:e.id,checked:r===e.id,onChange:()=>l(e.id)}),(0,_snapJsx.jsxs)("span",{className:"snap-export__option-body",children:[(0,_snapJsx.jsx)("span",{className:"snap-export__option-label",children:e.label}),(0,_snapJsx.jsx)("span",{className:"snap-export__option-desc",children:e.description})]})]},e.id)})]}),(0,_snapJsx.jsxs)("div",{className:"snap-export__cta",children:[(0,_snapJsx.jsx)(o,{variant:"primary",loading:submitting,disabled:t,onClick:async()=>{setSubmitting(!0);try{await a(r,"local")}catch(err){throw err}finally{setSubmitting(!1)}},children:(0,_snapI18n.__)("Start backup","snapshoter")}),(0,_snapJsx.jsx)("p",{className:"snap-export__note",children:(0,_snapI18n.__)("Snapshoter does not limit backup size - only your website size, and PHP, memory limits, and security settings do.","snapshoter")})]})]})} function O({tone:e="neutral",children:s}){return(0,_snapJsx.jsx)("span",{className:`snap-badge snap-badge--${e}`,children:s})}function U(e){if(e<1024)return`${e} B`;const s=["KB","MB","GB","TB"];let n=e,t=-1;for(;n>=1024&&t<s.length-1;)n/=1024,t+=1;return`${n.toFixed(0===t?0:1)} ${s[t]}`}function q({disabled:t=!1,onSelect:a}){const[r,l]=(0,_snapReact.useState)(!1),[i,c]=(0,_snapReact.useState)(null),d=(0,_snapReact.useCallback)(e=>{/\.(smartin|zip)$/i.test(e.name)||"application/zip"===e.type||"application/octet-stream"===e.type||""===e.type?c(e):c(null)},[]),p=(0,_snapReact.useCallback)(e=>{e.preventDefault(),l(!1);const s=e.dataTransfer.files?.[0];s&&d(s)},[d]);return(0,_snapJsx.jsxs)(B,{title:(0,_snapI18n.__)("Restore from a backup","snapshoter"),description:(0,_snapI18n.__)("Upload a .smartin file backup archive. We verify its checksum before overwriting anything.","snapshoter"),children:[(0,_snapJsx.jsxs)("label",{htmlFor:"snap-import-file","aria-label":(0,_snapI18n.__)("Upload backup archive","snapshoter"),className:`snap-import__dropzone${r?" snap-import__dropzone--dragging":""}${t?" snap-import__dropzone--disabled":""}`,onDragOver:e=>{e.preventDefault(),t||l(!0)},onDragLeave:()=>l(!1),onDrop:t?void 0:p,children:[(0,_snapJsx.jsx)("input",{id:"snap-import-file",type:"file",accept:".smartin,.zip,application/zip,application/octet-stream",className:"visually-hidden",disabled:t,onChange:e=>{const s=e.target.files?.[0];s&&d(s)}}),(0,_snapJsx.jsxs)("div",{className:"snap-import__dropzone-body",children:[(0,_snapJsx.jsx)("strong",{children:i?i.name:(0,_snapI18n.__)("Drop a .smartin backup here","snapshoter")}),(0,_snapJsx.jsx)("span",{className:"snap-import__hint",children:i?(0,_snapJsx.jsxs)(_snapJsx.Fragment,{children:[U(i.size),(0,_snapJsx.jsx)(O,{tone:"info",children:(0,_snapI18n.__)("Ready","snapshoter")})]}):(0,_snapI18n.__)("or click to choose from your computer","snapshoter")})]})]}),(0,_snapJsx.jsx)("div",{className:"snap-import__cta",children:(0,_snapJsx.jsx)(o,{variant:"primary",disabled:t||!i,onClick:()=>i&&a(i),children:(0,_snapI18n.__)("Review & restore","snapshoter")})})]})}function W(e){return e.sizeBytes&&e.sizeBytes>0?function(e){if(!e||e<1024)return`${e??0} B`;const s=["KB","MB","GB","TB"];let n=e,t=-1;for(;n>=1024&&t<s.length-1;)n/=1024,t+=1;return`${n.toFixed(0===t?0:1)} ${s[t]}`}(e.sizeBytes):e.sizeFormatted?e.sizeFormatted:"-"}function V(e){const s=(e.extension??"").toLowerCase();if(s)return"."+s;const n=e.filename??e.name??"";return n.includes(".")?"."+n.split(".").pop():".smartin"}function K(e){try{const s=new Date(e);return Number.isNaN(s.getTime())?e:s.toLocaleString()}catch{return e}}function canRestoreSnapshot(e){const n=String(e?.filename||e?.name||"").toLowerCase();return n.endsWith(".smartin")}
function parseSnapshotInfo(e){
  const s = e || "";
  const ext = s.includes(".") ? s.split(".").pop().toLowerCase() : "";
  let typeTitle = "Full Site Backup (.smartin)";
  if (ext === "sql") typeTitle = "Database Backup (.sql)";
  else if (ext === "zip") typeTitle = "Files Backup (.zip)";
    return { title: typeTitle, full: s };
}
function G({snapshots:e,loading:t=!1,disabledActions:a=!1,onRefresh:r,onDownload:l,onRestore:i,onDelete:c}){return(0,_snapJsx.jsx)(B,{title:(0,_snapI18n.__)("Stored snapshots","snapshoter"),description:(0,_snapI18n.__)("Archives stored on this server.","snapshoter"),actions:r?(0,_snapJsx.jsx)(o,{variant:"ghost",onClick:r,loading:t,children:(0,_snapI18n.__)("Refresh","snapshoter")}):null,children:(0,_snapJsx.jsxs)("div",{style:{display:"flex",flexDirection:"column",gap:"12px"},children:[0===e.length?(0,_snapJsx.jsx)("div",{className:"snap-snapshots__empty",children:t?(0,_snapI18n.__)("Loading snapshots...","snapshoter"):0===e.length?(0,_snapI18n.__)("No snapshots yet. Create a backup above to get started.","snapshoter"):(0,_snapI18n.__)("No snapshots matching this filter.","snapshoter")}):(0,_snapJsx.jsxs)("div",{className:"snap-snapshots__table snap-snapshots__table--local-only",role:"table",children:[(0,_snapJsx.jsxs)("div",{className:"snap-snapshots__row snap-snapshots__row--head",role:"row",children:[(0,_snapJsx.jsx)("span",{role:"columnheader",children:(0,_snapI18n.__)("Name","snapshoter")}),(0,_snapJsx.jsx)("span",{role:"columnheader",children:(0,_snapI18n.__)("Created","snapshoter")}),(0,_snapJsx.jsx)("span",{role:"columnheader",children:(0,_snapI18n.__)("Size","snapshoter")}),(0,_snapJsx.jsx)("span",{role:"columnheader",className:"visually-hidden",children:(0,_snapI18n.__)("Actions","snapshoter")})]}),e.map(s=>{const _s=parseSnapshotInfo(s.name);return(0,_snapJsx.jsxs)("div",{className:"snap-snapshots__row",role:"row",children:[(0,_snapJsx.jsxs)("span",{role:"cell",className:"snap-snapshots__name",children:[(0,_snapJsx.jsx)("strong",{className:"snap-snapshots__site-name",children:_s.title}),(0,_snapJsx.jsx)("span",{className:"snap-snapshots__filename",title:s.name,children:s.name})]}),(0,_snapJsx.jsx)("span",{role:"cell",className:"snap-snapshots__date",children:s.createdFormatted||K(s.createdAt)}),(0,_snapJsx.jsx)("span",{role:"cell",className:"snap-snapshots__size",children:W(s)}),(0,_snapJsx.jsxs)("span",{role:"cell",className:"snap-snapshots__actions",children:[l?(0,_snapJsx.jsx)(o,{size:"sm",variant:"primary",onClick:()=>l(s),disabled:a,icon:(0,_snapJsx.jsxs)("svg",{xmlns:"http://www.w3.org/2000/svg",width:14,height:14,viewBox:"0 0 24 24",fill:"none",stroke:"currentColor",strokeWidth:1.75,strokeLinecap:"round",strokeLinejoin:"round",className:"snap-icon","aria-hidden":"true",children:[(0,_snapJsx.jsx)("path",{d:"M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"}),(0,_snapJsx.jsx)("polyline",{points:"7 10 12 15 17 10"}),(0,_snapJsx.jsx)("line",{x1:"12",x2:"12",y1:"15",y2:"3"})]}),children:(0,_snapI18n.__)("Download","snapshoter")}):(0,_snapJsx.jsx)("span",{className:"snap-snapshots__action-spacer","aria-hidden":"true"}),i&&canRestoreSnapshot(s)?(0,_snapJsx.jsx)(o,{size:"sm",variant:"secondary",onClick:()=>i(s),disabled:a,children:(0,_snapI18n.__)("Restore","snapshoter")}):(0,_snapJsx.jsx)("span",{className:"snap-snapshots__action-spacer","aria-hidden":"true",children:(0,_snapJsx.jsx)("span",{className:"snap-btn snap-btn--sm snap-btn--secondary",children:(0,_snapI18n.__)("Restore","snapshoter")})}),c?(0,_snapJsx.jsx)(o,{size:"sm",variant:"danger",onClick:()=>c(s),disabled:a,"aria-label":(0,_snapI18n.__)("Delete this snapshot permanently","snapshoter"),children:(0,_snapI18n.__)("Delete","snapshoter")}):(0,_snapJsx.jsx)("span",{className:"snap-snapshots__action-spacer","aria-hidden":"true"})]})]},s.id||s.path||s.name)})]})]})})};const Z={info:"i",warning:"!",danger:"×",success:"✓"};function Y({tone:e="info",title:s,icon:t,actions:a,children:r}){return(0,_snapJsx.jsxs)("div",{className:`snap-banner snap-banner--${e}`,role:"danger"===e||"warning"===e?"alert":"status",children:[(0,_snapJsx.jsx)("span",{className:"snap-banner__glyph","aria-hidden":"true",children:t??Z[e]}),(0,_snapJsx.jsxs)("div",{className:"snap-banner__body",children:[s?(0,_snapJsx.jsx)("strong",{className:"snap-banner__title",children:s}):null,r?(0,_snapJsx.jsx)("div",{className:"snap-banner__message",children:r}):null]}),a?(0,_snapJsx.jsx)("div",{className:"snap-banner__actions",children:a}):null]})}const ie=window.ReactDOM,ce={light:{backdrop:"rgba(15, 23, 42, 0.78)",surface:"#ffffff",text:"#0f172a",textMuted:"#475569",border:"#e2e8f0",shadow:"0 24px 60px rgba(15, 23, 42, 0.35)"},dark:{backdrop:"rgba(2, 4, 9, 0.88)",surface:"#161922",text:"#e2e8f0",textMuted:"#94a3b8",border:"#2a2f3e",shadow:"0 24px 60px rgba(0, 0, 0, 0.65)"}},de={default:"transparent",warning:"#f59e0b",danger:"#ef4444"},pe="snapshoter-modal-root",he=2147483647;

function ue({open:s,title:t,description:a,onClose:r,actions:l=[],closeLabel:i="Close dialog",children:c,tone:d="default",maxWidth:mWidth}){
  const p=(0,_snapReact.useRef)(null),
  h=(0,_snapReact.useRef)(null),
  hasFocused=(0,_snapReact.useRef)(!1),
  u=(0,_snapReact.useMemo)(()=>"undefined"==typeof document?null:function(){let e=document.getElementById(pe);return e||(e=document.createElement("div"),e.id=pe,Object.assign(e.style,{position:"static",zIndex:String(he)}),document.body.appendChild(e)),e}(),[]),
  _=function(){if("undefined"==typeof document)return"light";const e=document.querySelector(".snapshoter-app[data-theme]"),s=e?.getAttribute("data-theme");return"dark"===s?"dark":"light"}(),
  f=ce[_],
  x=de[d];

  (0,_snapReact.useEffect)(()=>{
    if(!s){
      hasFocused.current=!1;
      return;
    }
    const e=p.current,n=e?.ownerDocument??document;
    if(!hasFocused.current){
      hasFocused.current=!0;
      h.current=n.activeElement??null;
      const a=e?.querySelector('input:not([disabled]), textarea:not([disabled]), button:not([disabled]), [tabindex]:not([tabindex="-1"])');
      a?.focus();
    }
    const l=n.documentElement,o=n.body,i=l?.style.overflow??"",c=o?.style.overflow??"";
    l&&(l.style.overflow="hidden");
    o&&(o.style.overflow="hidden");
    const u=ev=>{"Escape"===ev.key&&(ev.preventDefault(),r())};
    n.addEventListener("keydown",u,{capture:!0});
    return()=>{
      n.removeEventListener("keydown",u,{capture:!0});
      l&&(l.style.overflow=i);
      o&&(o.style.overflow=c);
      h.current?.focus?.();
    };
  },[s,r]);

  const g=(0,_snapReact.useCallback)(e=>{},[]),
  b=(0,_snapReact.useCallback)(e=>{"Escape"===e.key&&e.target===e.currentTarget&&(e.preventDefault(),r())},[r]);

  if(!s||!u)return null;

  const j={position:"fixed",top:0,right:0,bottom:0,left:0,width:"100vw",height:"100vh",minHeight:"100vh",display:"flex",alignItems:"center",justifyContent:"center",padding:"24px",background:f.backdrop,WebkitBackdropFilter:"blur(6px)",backdropFilter:"blur(6px)",zIndex:he,isolation:"isolate",pointerEvents:"auto"},
  y={position:"relative",background:f.surface,color:f.text,borderRadius:"16px",boxShadow:f.shadow,border:`1px solid ${f.border}`,maxWidth:mWidth||"540px",width:"100%",maxHeight:"calc(100vh - 48px)",overflow:"auto",padding:"28px",display:"flex",flexDirection:"column",gap:"18px",isolation:"isolate",transform:"none",fontFamily:"-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif",fontSize:"14px",lineHeight:1.5,"--snap-dialog-tone-accent":x},
  w={margin:0,fontSize:"17px",fontWeight:600,color:f.text,lineHeight:1.3,letterSpacing:"-0.01em"},
  v={margin:0,color:f.textMuted,fontSize:"13.5px",lineHeight:1.55},
  k={background:"transparent",border:"none",color:f.textMuted,fontSize:"18px",lineHeight:1,cursor:"pointer",padding:"6px",width:"32px",height:"32px",display:"inline-flex",alignItems:"center",justifyContent:"center",borderRadius:"8px",marginLeft:"auto",flexShrink:0},
  S=(0,_snapJsx.jsx)("div",{className:`snap-modal snap-modal--${d}`,"data-snap-modal-overlay":!0,"data-snap-theme":_,role:"presentation",onClick:g,onKeyDown:b,style:j,children:(0,_snapJsx.jsxs)("div",{className:"snap-modal__dialog","data-snap-theme":_,role:"dialog","aria-modal":"true","aria-labelledby":"snap-modal-title","aria-describedby":a?"snap-modal-desc":void 0,ref:p,style:y,onClick:e=>e.stopPropagation(),onKeyDown:e=>e.stopPropagation(),children:[(0,_snapJsx.jsxs)("header",{className:"snap-modal__header",style:{display:"flex",alignItems:"center",justifyContent:"space-between",gap:"12px",marginBottom:"-2px"},children:[(0,_snapJsx.jsx)("h2",{className:"snap-modal__title",id:"snap-modal-title",style:w,children:t}),(0,_snapJsx.jsx)("button",{type:"button",className:"snap-modal__close",onClick:r,"aria-label":i,style:k,children:"×"})]}),a?(0,_snapJsx.jsx)("p",{className:"snap-modal__description",id:"snap-modal-desc",style:v,children:a}):null,c?(0,_snapJsx.jsx)("div",{className:"snap-modal__body",style:{display:"flex",flexDirection:"column",gap:"16px"},children:c}):null,l.length>0?(0,_snapJsx.jsx)("footer",{className:"snap-modal__footer",style:{display:"flex",justifyContent:"flex-end",alignItems:"center",gap:"8px",marginTop:"4px"},children:l.map((e,s)=>(0,_snapJsx.jsx)(o,{variant:e.variant??"secondary",onClick:e.onClick,disabled:e.disabled,loading:e.loading,children:e.label},s))}):null]})});
  return(0,ie.createPortal)(S,u);
}

function Le({value:e,label:s,hint:t,tone:a="accent"}){const r=void 0===e||Number.isNaN(e),l=r?0:Math.min(100,Math.max(0,e)),o=r?null:Math.round(l);return(0,_snapJsx.jsxs)("div",{className:`snap-progress snap-progress--${a}`,children:[(s||null!==o)&&(0,_snapJsx.jsxs)("div",{className:"snap-progress__row",children:[s?(0,_snapJsx.jsx)("span",{className:"snap-progress__label",children:s}):(0,_snapJsx.jsx)("span",{}),null!==o?(0,_snapJsx.jsxs)("span",{className:"snap-progress__value",children:[o,"%"]}):null]}),(0,_snapJsx.jsx)("div",{className:"snap-progress__track"+(r?" snap-progress__track--indeterminate":""),role:"progressbar","aria-valuemin":0,"aria-valuemax":100,"aria-valuenow":r?void 0:l,children:(0,_snapJsx.jsx)("div",{className:"snap-progress__fill",style:{width:r?"40%":`${l}%`}})}),t?(0,_snapJsx.jsx)("div",{className:"snap-progress__hint",children:t}):null]})}function He({state:e,index:s}){return"complete"===e?(0,_snapJsx.jsx)("svg",{viewBox:"0 0 16 16",width:"14",height:"14","aria-hidden":"true",children:(0,_snapJsx.jsx)("path",{d:"M2 8.5 6 12.5 14 4.5",stroke:"currentColor",strokeWidth:"2.2",fill:"none",strokeLinecap:"round",strokeLinejoin:"round"})}):"failed"===e?(0,_snapJsx.jsx)("svg",{viewBox:"0 0 16 16",width:"14",height:"14","aria-hidden":"true",children:(0,_snapJsx.jsx)("path",{d:"M4 4 12 12 M12 4 4 12",stroke:"currentColor",strokeWidth:"2.2",strokeLinecap:"round"})}):(0,_snapJsx.jsx)("span",{className:"snap-stepper__number",children:s+1})}function Oe({steps:e}){return(0,_snapJsx.jsx)("ol",{className:"snap-stepper","aria-label":"Job progress steps",children:e.map((e,s)=>(0,_snapJsx.jsxs)("li",{className:`snap-stepper__item snap-stepper__item--${e.state}`,"aria-current":"active"===e.state?"step":void 0,children:[(0,_snapJsx.jsx)("div",{className:"snap-stepper__marker",children:(0,_snapJsx.jsx)(He,{state:e.state,index:s})}),(0,_snapJsx.jsxs)("div",{className:"snap-stepper__content",children:[(0,_snapJsx.jsx)("div",{className:"snap-stepper__label",children:e.label}),e.description?(0,_snapJsx.jsx)("div",{className:"snap-stepper__description",children:e.description}):null]})]},e.id))})}function Ue({chunks:s,title:t="Activity log",defaultOpen:a=!1,maxLines:r=500}){const[l,i]=(0,_snapReact.useState)(a),[c,d]=(0,_snapReact.useState)(!0),p=(0,_snapReact.useRef)(null),h=(0,_snapReact.useMemo)(()=>{const e=s.join("").split(/\r?\n/).filter(Boolean);return e.length<=r?e:e.slice(e.length-r)},[s,r]);(0,_snapReact.useEffect)(()=>{if(!l||!c)return;const e=p.current;e&&(e.scrollTop=e.scrollHeight)},[h,l,c]);const u=(0,_snapReact.useCallback)(()=>{const e=p.current;if(!e)return;const s=e.scrollHeight-(e.scrollTop+e.clientHeight);d(s<16)},[]);return(0,_snapJsx.jsxs)("div",{className:"snap-log"+(l?" snap-log--open":""),children:[(0,_snapJsx.jsxs)("button",{type:"button",className:"snap-log__toggle",onClick:()=>i(e=>!e),"aria-expanded":l,children:[(0,_snapJsx.jsx)("span",{"aria-hidden":"true",className:"snap-log__chevron",children:"▸"}),(0,_snapJsx.jsx)("span",{className:"snap-log__title",children:t}),(0,_snapJsx.jsxs)("span",{className:"snap-log__count",children:[h.length," lines"]})]}),l?(0,_snapJsx.jsxs)("div",{className:"snap-log__body",ref:p,onScroll:u,children:[0===h.length?(0,_snapJsx.jsx)("div",{className:"snap-log__empty",children:"Waiting for output..."}):h.map((e,s)=>(0,_snapJsx.jsx)("div",{className:"snap-log__line",children:e},s)),c?null:(0,_snapJsx.jsx)("div",{className:"snap-log__jump",children:(0,_snapJsx.jsx)(o,{size:"sm",variant:"secondary",onClick:()=>{d(!0),p.current&&(p.current.scrollTop=p.current.scrollHeight)},children:"Jump to latest"})})]}):null]})}function qe(e,s){return s instanceof Error?{name:s.name,message:s.message,...s}:s}function We({defaultOpen:t=!1}){const[a,r]=(0,_snapReact.useState)(t),l=function(){const[s,n]=(0,_snapReact.useState)(()=>m.getBuffer());return(0,_snapReact.useEffect)(()=>m.subscribe(e=>n(e)),[]),s}(),o=(0,_snapReact.useRef)(null);return(0,_snapReact.useEffect)(()=>{a&&o.current&&(o.current.scrollTop=o.current.scrollHeight)},[l,a]),(0,_snapJsx.jsxs)("details",{className:"snap-diag",open:a,onToggle:e=>r(e.currentTarget.open),children:[(0,_snapJsx.jsxs)("summary",{children:[(0,_snapJsx.jsx)("span",{children:(0,_snapI18n.__)("Diagnostics","snapshoter")}),(0,_snapJsx.jsxs)("span",{className:"snap-diag__meta",children:["build ",(0,_snapJsx.jsx)("code",{children:h})," · ",l.length," ",(0,_snapI18n.__)("events","snapshoter"),m.isEnabled()?"":" · "+(0,_snapI18n.__)("console silenced (define SNAPSHOTER_DEBUG)","snapshoter")]})]}),(0,_snapJsx.jsxs)("div",{className:"snap-diag__toolbar",children:[(0,_snapJsx.jsx)("button",{type:"button",className:"snap-diag__btn",onClick:()=>m.clear(),children:(0,_snapI18n.__)("Clear","snapshoter")}),(0,_snapJsx.jsx)("button",{type:"button",className:"snap-diag__btn",onClick:async()=>{const e=l.map(e=>Ve(e)).join("\n");try{await navigator.clipboard.writeText(e)}catch{}},children:(0,_snapI18n.__)("Copy to clipboard","snapshoter")})]}),(0,_snapJsx.jsx)("pre",{ref:o,className:"snap-diag__log",children:0===l.length?(0,_snapI18n.__)("No events yet.","snapshoter"):l.map(Ve).join("\n")})]})}function Ve(e){const s=`${function(e){const s=new Date(e),n=(e,s=2)=>String(e).padStart(s,"0");return`${n(s.getHours())}:${n(s.getMinutes())}:${n(s.getSeconds())}.${n(s.getMilliseconds(),3)}`}(e.t)} [${e.level.toUpperCase()}] [${e.category}] ${e.message}`,n=function(e){if(void 0===e)return"";try{return JSON.stringify(e,qe,2)}catch{return String(e)}}(e.payload);return n?`${s}\n  ${n.replace(/\n/g,"\n  ")}`:s}const Ke={no_block:"Older archive without an integrity block.",unknown_algorithm:"Manifest used an unrecognised hash algorithm.",missing_hash:"Manifest is missing the database SHA-256 field.",size_mismatch:"Database file size did not match the manifest.",hash_mismatch:"Database SHA-256 hash did not match the manifest."};function Ge({progress:e}){if(!e?.integrity_status)return null;let t,a,r;if("verified"===e.integrity_status){a="success",t=(0,_snapI18n.__)("Integrity verified","snapshoter");const n=e.integrity_algorithm?e.integrity_algorithm.toUpperCase():"SHA-256",l=e.integrity_database_size?function(e){if(e<1024)return`${e} B`;const s=["KB","MB","GB"];let n=e,t=-1;for(;n>=1024&&t<s.length-1;)n/=1024,t+=1;return`${n.toFixed(0===t?0:1)} ${s[t]}`}(e.integrity_database_size):"",o=e.integrity_database_sha256_short?`${e.integrity_database_sha256_short}...`:"";r=(0,_snapI18n.sprintf)(/* translators: 1: hashing algorithm, 2: short hash, 3: database size */
(0,_snapI18n.__)("%1$s matches manifest (%2$s, %3$s).","snapshoter"),n,o,l).trim()}else"failed"===e.integrity_status?(a="danger",t=(0,_snapI18n.__)("Integrity failed","snapshoter"),r=Ke[e.integrity_reason??""]??(0,_snapI18n.__)("Archive contents did not match the manifest.","snapshoter")):(a="warning",t=(0,_snapI18n.__)("Integrity unchecked","snapshoter"),r=Ke[e.integrity_reason??""]??(0,_snapI18n.__)("No integrity block found in the manifest. Verification was skipped.","snapshoter"));return(0,_snapJsx.jsx)("span",{title:r,children:(0,_snapJsx.jsx)(O,{tone:a,children:t})})}const Je={permalink:"refresh",warning:"alert-triangle",info:"info",error:"x-circle",success:"check-circle"},Ze={permalink:"accent",warning:"warning",info:"info",error:"danger",success:"success"};function Ye({notices:e}){return e&&0!==e.length?(0,_snapJsx.jsx)("div",{className:"snap-notices",role:"region","aria-label":"Post-restore notices",children:e.map((e,s)=>(0,_snapJsx.jsx)(Xe,{notice:e},s))}):null}function Xe({notice:e}){
  const s = e.type ?? "info";
  const t = Ze[s] ?? "info";
  const a = Je[s] ?? "info";
  const r = function(e){
    if(!e)return"";
    if("undefined"==typeof document)return e.replace(/<[^>]*>/g,"");
    const s=document.createElement("div");
    s.innerHTML=e;
    const n=e=>{
      const s=Array.from(e.childNodes);
      for(const e of s){
        if(e.nodeType===Node.ELEMENT_NODE){
          const s=e;
          if("A"===s.tagName){
            const e=s.getAttribute("href")??"";
            if(/^\s*(javascript:|data:)/i.test(e)){
              const e=s.textContent??"";
              s.replaceWith(document.createTextNode(e));
              continue;
            }
            const t=Array.from(s.attributes);
            for(const e of t){
              "href"!==e.name&&"target"!==e.name&&"rel"!==e.name&&s.removeAttribute(e.name);
            }
            s.setAttribute("target","_blank");
            s.setAttribute("rel","noopener noreferrer");
            n(s);
          }else{
            const e=s.textContent??"";
            s.replaceWith(document.createTextNode(e));
          }
        }
      }
    };
    return n(s),s.innerHTML;
  }(e.message??"");

  return (0,_snapJsx.jsxs)("div", {
    className: `snap-notice snap-notice--${t}`,
    children: [
      (0,_snapJsx.jsx)("span", {
        className: "snap-notice__icon",
        children: (0,_snapJsx.jsx)(i, { name: a, size: 15 })
      }),
      (0,_snapJsx.jsx)("span", {
        className: "snap-notice__message",
        dangerouslySetInnerHTML: { __html: r }
      })
    ]
  });
}const Qe=[{id:"init",label:"Prepare",matches:["initialize","init"]},{id:"database",label:"Database",matches:["database","dump_database"]},{id:"discover",label:"Scan files",matches:["discover_files","discover"]},{id:"files",label:"Files",matches:["files","collect_files","add_files"]},{id:"finalize",label:"Finalize",matches:["finalize","organize","upload","hooks"]},{id:"completed",label:"Done",matches:["completed"]}];const es=[{id:"upload",label:"Upload",matches:["init","upload","awaiting-upload"]},{id:"validate",label:"Validate",matches:["validating","validate","downloading"]},{id:"extract",label:"Extract",matches:["extract","extracting"]},{id:"disable_site",label:"Prepare",matches:["disable_site","disabling-site"]},{id:"files",label:"Files",matches:["restore_files","restoring_files","restoring-files","mirror_prune","pruning-orphans"]},{id:"database",label:"Database",matches:["import_database","importing_database","importing-database","restoring_db","restore_database"]},{id:"finalize",label:"Finalize",matches:["finalize","finalizing","rewriting-urls"]},{id:"completed",label:"Done",matches:["completed"]}];function ss({jobType:t,progress:a,phase:r,logChunks:l,error:i,upload:c,downloadUrl:d,onCancel:p,onDismiss:h}){const[u,_]=(0,_snapReact.useState)(!1),[m,f]=(0,_snapReact.useState)(!1),[etaTxt,setEtaTxt]=(0,_snapReact.useState)(""),etaRef=(0,_snapReact.useRef)({t:0,bytes:0,ema:0,start:0}),etaBytesRef=(0,_snapReact.useRef)(0);const x="allocated"===r&&null!=c;etaBytesRef.current=x&&c?c.bytesUploaded|0:0;(0,_snapReact.useEffect)(()=>{if(!x||!c){etaRef.current={t:0,bytes:0,ema:0,start:0};setEtaTxt("");return;}const total=c.totalBytes|0;if(total<5242880){etaRef.current={t:0,bytes:0,ema:0,start:0};setEtaTxt("");return;}const t0=Date.now();etaRef.current={t:t0,bytes:etaBytesRef.current,ema:0,start:t0};setEtaTxt("");const tick=()=>{const uploaded=etaBytesRef.current|0,now=Date.now(),prev=etaRef.current;if(!prev.t||!prev.start){etaRef.current={t:now,bytes:uploaded,ema:0,start:now};return;}if(uploaded<prev.bytes){etaRef.current={t:now,bytes:uploaded,ema:0,start:now};setEtaTxt("");return;}const dt=(now-prev.t)/1e3,db=uploaded-prev.bytes,elapsed=(now-prev.start)/1e3;if(dt<0.5)return;let ema=prev.ema;if(db>0){let inst=db/dt;if(ema>=1024)inst=Math.min(inst,ema*2.5);ema=ema>0?ema*.75+inst*.25:inst;}etaRef.current={t:now,bytes:uploaded,ema,start:prev.start};if(uploaded<=0||ema<1024||elapsed<1){setEtaTxt("");return;}const life=uploaded/Math.max(elapsed,1);const speed=Math.max(1024,Math.min(ema,life>1024?ema*.45+life*.55:ema));const rem=Math.max(0,total-uploaded);if(rem<=0){setEtaTxt("");return;}let secs=rem/speed;if(rem>2*1024*1024)secs=Math.max(secs,3);if(rem>16*1024*1024)secs=Math.max(secs,8);setEtaTxt(ts(speed)+"/s · "+(0,_snapI18n.sprintf)((0,_snapI18n.__)("~%s left","snapshoter"),etaFmt(secs)));};const id=window.setInterval(tick,400);tick();return()=>window.clearInterval(id);},[x,c?.totalBytes]);const g=(0,_snapReact.useMemo)(()=>x?{step:"upload",status:"running",percent:c.percent}:a,[x,c?.percent,a]),b=(0,_snapReact.useMemo)(()=>function(e,s){const sc=s?.scope||s?.options?.scope||s?.manifest?.scope||s?.export_mode||window._snapActiveExportScope||"full";let n="import"===e?es:Qe;if("import"!==e){let filtered=[...n];if("database"===sc){filtered=filtered.filter(x=>"files"!==x.id&&"discover"!==x.id)}else if("files"===sc){filtered=filtered.filter(x=>"database"!==x.id)}filtered=filtered.filter(e=>"upload"!==e.id);n=filtered;}const t="failed"===s?.step||"failed"===s?.status,a="completed"===s?.step||"completed"===s?.status;if(!s)return n.map(e=>({id:e.id,label:e.label,state:"pending"}));if(a)return n.map(e=>({id:e.id,label:e.label,state:"complete"}));let r=n.findIndex(e=>e.matches.includes(String(s.step)));if(-1===r)r=n.findIndex(e=>e.matches.includes(String(s.status)));if("undefined"!=typeof window){const jid=String(s.jobId||s.job_id||"");const fl=window._snapStepFloor||(window._snapStepFloor={job:"",i:-1});if(fl.job!==jid){fl.job=jid;fl.i=-1}if(r>fl.i)fl.i=r;else if(r>=0&&r<fl.i)r=fl.i;}return n.map((e,s)=>-1===r?{id:e.id,label:e.label,state:"pending"}:s<r?{id:e.id,label:e.label,state:"complete"}:s===r?{id:e.id,label:e.label,state:t?"failed":"active"}:{id:e.id,label:e.label,state:"pending"})}(t,g),[t,g]),j=function(e){switch(e){case"starting":return{label:"Starting...",tone:"info"};case"allocated":return{label:"Uploading",tone:"info"};case"running":return{label:"Running",tone:"accent"};case"completed":return{label:"Completed",tone:"success"};case"failed":return{label:"Failed",tone:"danger"};case"cancelled":return{label:"Cancelled",tone:"warning"};default:return{label:"Idle",tone:"neutral"}}}(r),y="completed"===r||"failed"===r||"cancelled"===r;let w;w="completed"===r?100:"failed"===r||"cancelled"===r?"number"==typeof g?.percent?Math.max(0,Math.min(100,g.percent)):0:"number"==typeof g?.percent?Math.max(0,Math.min(100,g.percent)):void 0;const v="import"===t?("completed"===r?(0,_snapI18n.__)("Restore completed","snapshoter"):(0,_snapI18n.__)("Restore in progress","snapshoter")):("completed"===r?(0,_snapI18n.__)("Backup completed","snapshoter"):(0,_snapI18n.__)("Backup in progress","snapshoter")),k=x?(0,_snapI18n.__)("Upload","snapshoter"):(function(step,s){if("completed"===r||"completed"===step)return"import"===t?(0,_snapI18n.__)("Restore completed!","snapshoter"):(0,_snapI18n.__)("Backup completed!","snapshoter");switch(String(step)){case"init":case"initialize":return(0,_snapI18n.__)("Preparing backup...","snapshoter");case"database":case"dump_database":return(0,_snapI18n.__)("Backing up database...","snapshoter");case"discover":case"discover_files":return(0,_snapI18n.__)("Scanning site files...","snapshoter");case"files":case"collect_files":case"add_files":return(0,_snapI18n.__)("Archiving wp-content files...","snapshoter");case"restore_files":case"restoring-files":case"restoring_files":case"mirror_prune":case"pruning-orphans":return(0,_snapI18n.__)("Restoring site files...","snapshoter");case"import_database":case"importing_database":case"importing-database":case"restoring_db":case"restore_database":return(0,_snapI18n.__)("Importing database...","snapshoter");case"finalize":case"finalizing":case"rewriting-urls":return"import"===t?(s?.message||(0,_snapI18n.__)("Finishing restore...","snapshoter")):(0,_snapI18n.__)("Packaging archive & checksums...","snapshoter");case"hooks":case"upload":return("import"===t||x)?(s?.message||(0,_snapI18n.__)("Uploading archive to this server...","snapshoter")):(0,_snapI18n.__)("Finalizing snapshot...","snapshoter");case"disable_site":case"disabling-site":return"import"===t?(0,_snapI18n.__)("Preparing site for restore...","snapshoter"):(0,_snapI18n.__)("Preparing...","snapshoter");case"completed":return(0,_snapI18n.__)("Backup completed!","snapshoter");default:return s?.message||(step?(step.charAt(0).toUpperCase()+step.slice(1).replace(/_/g," ")):"" )}})(g?.step,g),S=x?ts(c.bytesUploaded)+" / "+ts(c.totalBytes)+(etaTxt?" · "+etaTxt:""):("number"==typeof g?.upload_bytes&&"number"==typeof g?.upload_total&&g.upload_total>0?ts(g.upload_bytes)+" / "+ts(g.upload_total)+(g.upload_parts?` · part ${g.upload_part||0}/${g.upload_parts}`:""):i?.message??(g?.stats?function(e){const s=Object.entries(e);if(0!==s.length)return s.slice(0,4).map(([e,s])=>`${e}: ${s}`).join("  ·  ")}(g.stats):void 0));let N=null;y?"completed"===r&&d?N=(0,_snapJsx.jsxs)("span",{className:"snap-job__actions",children:[(0,_snapJsx.jsx)(o,{variant:"primary",onClick:()=>{window.location.href=d},children:(0,_snapI18n.__)("Download backup","snapshoter")}),h?(0,_snapJsx.jsx)(o,{variant:"ghost",onClick:h,children:(0,_snapI18n.__)("Dismiss","snapshoter")}):null]}):h&&(N=(0,_snapJsx.jsx)(o,{variant:"ghost",onClick:h,children:(0,_snapI18n.__)("Dismiss","snapshoter")})):N=(0,_snapJsx.jsx)(o,{variant:"ghost",onClick:()=>_(!0),children:(0,_snapI18n.__)("Cancel","snapshoter")});let C="accent";"failed"===r?C="danger":"completed"===r&&(C="success");const staleFilesMsg=g?.message&&/Restoring site files/i.test(String(g.message));const finalizeStep="finalize"===g?.step||"finalizing"===g?.status||"rewriting-urls"===g?.status;const cardDescription="completed"===r?("import"===t?(0,_snapI18n.__)("Restore finished successfully.","snapshoter"):(0,_snapI18n.__)("Backup created and stored locally successfully.","snapshoter")):("import"===t&&finalizeStep?(staleFilesMsg?k:(g?.message||k)):(g?.message??ns(r,t,a)));return(0,_snapJsx.jsxs)(B,{title:(0,_snapJsx.jsxs)("span",{className:"snap-job__title",children:[(0,_snapJsx.jsx)("span",{children:v}),(0,_snapJsx.jsx)(O,{tone:j.tone,children:j.label}),"import"===t?(0,_snapJsx.jsx)(Ge,{progress:a}):null]}),description:cardDescription,actions:N,children:[(0,_snapJsx.jsx)(Le,{label:k,value:w,tone:C,hint:S}),(0,_snapJsx.jsx)(Oe,{steps:b}),"completed"===r&&"import"===t?(0,_snapJsx.jsx)(Ye,{notices:a?.post_finalize_notices??[]}):null,null,(0,_snapJsx.jsx)(ue,{open:u,title:(0,_snapI18n.__)("Cancel this job?","snapshoter"),description:(0,_snapI18n.__)("Temporary backup files on this server will be removed. You can start a fresh backup afterwards.","snapshoter"),tone:"warning",onClose:()=>_(!1),actions:[{label:(0,_snapI18n.__)("Keep running","snapshoter"),variant:"ghost",onClick:()=>_(!1)},{label:(0,_snapI18n.__)("Cancel job","snapshoter"),variant:"danger",loading:m,onClick:async()=>{f(!0);try{await p()}finally{f(!1),_(!1)}}}]})]})}function ns(e,n,a){switch(e){case"starting":return(0,_snapI18n.__)("Allocating job and preparing storage...","snapshoter");case"allocated":return(0,_snapI18n.__)("Uploading the archive to the server in chunks.","snapshoter");case"running":return"import"===n?(0,_snapI18n.__)("Restoring database and files. This can take a while on large sites.","snapshoter"):(0,_snapI18n.__)("Snapshotting the site. Safe to leave this tab open.","snapshoter");case"completed":return(0,_snapI18n.__)("All steps completed successfully.","snapshoter");case"failed":return(0,_snapI18n.__)("The job stopped early.","snapshoter");case"cancelled":return(0,_snapI18n.__)("The job was cancelled before completing.","snapshoter");default:return""}}function etaFmt(e){if(!Number.isFinite(e)||e<0)return"-";if(e<60)return Math.max(1,Math.round(e))+" sec";if(e<3600)return Math.round(e/60)+" min";const h=Math.floor(e/3600),m=Math.round(e%3600/60);return m>0?h+"h "+m+"m":h+"h"}function ts(e){if(e<1024)return`${e} B`;const s=["KB","MB","GB"];let n=e,t=-1;for(;n>=1024&&t<s.length-1;)n/=1024,t+=1;return`${n.toFixed(0===t?0:1)} ${s[t]}`}function as({phase:e,kind:t,result:a,error:r,onCancel:l,onDismiss:c}){const d=function(e){return"database"===e?{title:(0,_snapI18n.__)("Database-only backup","snapshoter"),running:(0,_snapI18n.__)("Dumping every WordPress table into a single .sql file. This is a single-pass operation - progress updates aren't available.","snapshoter"),completed:(0,_snapI18n.__)("Database dump ready.","snapshoter"),downloadCta:(0,_snapI18n.__)("Download .sql","snapshoter")}:{title:(0,_snapI18n.__)("Files-only backup","snapshoter"),running:(0,_snapI18n.__)("Archiving wp-content (uploads, plugins, themes) into a .zip. This is a single-pass operation - progress updates aren't available.","snapshoter"),completed:(0,_snapI18n.__)("File archive ready.","snapshoter"),downloadCta:(0,_snapI18n.__)("Download .zip","snapshoter")}}(t);return(0,_snapJsx.jsxs)(B,{className:"snap-job",children:[(0,_snapJsx.jsxs)("div",{className:"snap-job__title",children:[(0,_snapJsx.jsx)("h3",{children:d.title}),(0,_snapJsx.jsx)("span",{className:`snap-job__phase snap-job__phase--${e}`,children:rs(e)})]}),(0,_snapJsx.jsx)(Le,{value:ls(e),tone:os(e),label:is(e,d,r)}),("completed"===e||d)&&(d||a?.downloadUrl)?(0,_snapJsx.jsxs)("div",{className:"snap-job__actions",children:[(0,_snapJsx.jsxs)(o,{variant:"primary",onClick:()=>{window.location.href=d||a.downloadUrl},children:[(0,_snapJsx.jsx)(i,{name:"download",size:14})," ",d.downloadCta]}),(0,_snapJsx.jsx)(o,{variant:"ghost",onClick:c,children:(0,_snapI18n.__)("Dismiss","snapshoter")})]}):null,"running"===e?(0,_snapJsx.jsx)("div",{className:"snap-job__actions",children:(0,_snapJsx.jsx)(o,{variant:"ghost",onClick:l,children:(0,_snapI18n.__)("Cancel","snapshoter")})}):null,"failed"===e?(0,_snapJsx.jsx)("div",{className:"snap-job__actions",children:(0,_snapJsx.jsx)(o,{variant:"ghost",onClick:c,children:(0,_snapI18n.__)("Dismiss","snapshoter")})}):null]})}function rs(e){switch(e){case"running":return(0,_snapI18n.__)("In progress","snapshoter");case"completed":return(0,_snapI18n.__)("Completed","snapshoter");case"failed":return(0,_snapI18n.__)("Failed","snapshoter");default:return(0,_snapI18n.__)("Idle","snapshoter")}}function ls(e){return"completed"===e?100:"failed"===e?0:void 0}function os(e){return"completed"===e?"success":"failed"===e?"danger":"accent"}function is(e,n,t){return"completed"===e?n.completed:"failed"===e?t?.message??(0,_snapI18n.__)("Export failed.","snapshoter"):n.running}function cs({open:e,filename:t,size:a,helpUrl:r,confirming:l=!1,onConfirm:c,onCancel:d}){const p=[{icon:"database",title:(0,_snapI18n.__)("Database is replaced","snapshoter"),detail:(0,_snapI18n.__)("All tables matching your current prefix are dropped and re-imported.","snapshoter")},{icon:"folder",title:(0,_snapI18n.__)("Files are overwritten","snapshoter"),detail:(0,_snapI18n.__)("wp-content (plugins, themes, uploads) is replaced from the archive.","snapshoter")},{icon:"plug",title:(0,_snapI18n.__)("Plugins and theme reload","snapshoter"),detail:(0,_snapI18n.__)("Active plugins and the theme are deactivated during restore and re-enabled afterwards.","snapshoter")}];return(0,_snapJsx.jsxs)(ue,{open:e,tone:"danger",onClose:d,title:(0,_snapJsx.jsxs)("span",{className:"snap-restore-modal__title",children:[(0,_snapJsx.jsx)("span",{className:"snap-restore-modal__title-badge",children:(0,_snapJsx.jsx)(i,{name:"alert-triangle",size:18})}),(0,_snapJsx.jsx)("span",{children:(0,_snapI18n.__)("Restore from backup?","snapshoter")})]}),description:(0,_snapI18n.__)("This overwrites the live database and files. Take a fresh backup first if you are not certain.","snapshoter"),children:[(0,_snapJsx.jsxs)("dl",{className:"snap-restore-modal__file",children:[(0,_snapJsx.jsx)("dt",{children:(0,_snapI18n.__)("Archive","snapshoter")}),(0,_snapJsx.jsxs)("dd",{children:[(0,_snapJsx.jsx)("span",{className:"snap-restore-modal__file-icon",children:(0,_snapJsx.jsx)(i,{name:"archive",size:14})}),(0,_snapJsx.jsx)("code",{children:t}),a?(0,_snapJsx.jsx)("span",{className:"snap-restore-modal__file-size",children:a}):null]})]}),(0,_snapJsx.jsx)("ul",{className:"snap-restore-modal__risks",children:p.map(e=>(0,_snapJsx.jsxs)("li",{children:[(0,_snapJsx.jsx)("span",{className:"snap-restore-modal__risk-icon",children:(0,_snapJsx.jsx)(i,{name:e.icon,size:18})}),(0,_snapJsx.jsxs)("span",{className:"snap-restore-modal__risk-body",children:[(0,_snapJsx.jsx)("strong",{children:e.title}),(0,_snapJsx.jsx)("span",{className:"snap-restore-modal__risk-detail",children:e.detail})]})]},e.title))}),(0,_snapJsx.jsxs)("div",{className:"snap-restore-modal__footnote",children:[(0,_snapJsx.jsx)("span",{className:"snap-restore-modal__footnote-icon",children:(0,_snapJsx.jsx)(i,{name:"info",size:14})}),(0,_snapJsx.jsxs)("span",{children:[(0,_snapI18n.__)("Keep this tab open until the restore finishes.","snapshoter"),r?(0,_snapJsx.jsxs)(_snapJsx.Fragment,{children:[" ",(0,_snapJsx.jsxs)("a",{className:"snap-restore-modal__footnote-link",href:r,target:"_blank",rel:"noopener noreferrer",children:[(0,_snapI18n.__)("Manual recovery steps","snapshoter"),(0,_snapJsx.jsx)(i,{name:"external-link",size:12})]})]}):null]})]}),(0,_snapJsx.jsxs)("footer",{className:"snap-restore-modal__footer",children:[(0,_snapJsx.jsx)(o,{variant:"ghost",onClick:d,disabled:l,children:(0,_snapI18n.__)("Cancel","snapshoter")}),(0,_snapJsx.jsx)(o,{variant:"danger",onClick:c,loading:l,icon:(0,_snapJsx.jsx)(i,{name:"refresh",size:14}),children:(0,_snapI18n.__)("Continue restore","snapshoter")})]})]})}const ds=new Set(["completed","failed","cancelled"]),ps=new Set(["completed","partial","failed","cancelled"]);;
function DeleteSnapshotModal({ open, snapshot, onConfirm, onCancel, deleting = false }) {
  if (!snapshot) return null;
  const filename = snapshot.filename || snapshot.name || "";
  const locationLabel = snapshot.locationLabel || (0, _snapI18n.__)("Local server", "snapshoter");
  
  return (0, _snapJsx.jsxs)(ue, {
    open: open,
    tone: "danger",
    onClose: onCancel,
    title: (0, _snapJsx.jsxs)("span", {
      className: "snap-restore-modal__title",
      children: [
        (0, _snapJsx.jsx)("span", {
          className: "snap-restore-modal__title-badge",
          style: { background: "rgba(239, 68, 68, 0.12)", color: "#ef4444" },
          children: (0, _snapJsx.jsx)(i, { name: "trash", size: 18 })
        }),
        (0, _snapJsx.jsx)("span", { children: (0, _snapI18n.__)("Delete snapshot?", "snapshoter") })
      ]
    }),
    description: (0, _snapI18n.__)("This cannot be undone. The .smartin archive file will be permanently deleted from your server.", "snapshoter"),
    children: [
      (0, _snapJsx.jsxs)("dl", {
        className: "snap-restore-modal__file",
        children: [
          (0, _snapJsx.jsx)("dt", { children: (0, _snapI18n.__)("Archive file", "snapshoter") }),
          (0, _snapJsx.jsxs)("dd", {
            children: [
              (0, _snapJsx.jsx)("span", {
                className: "snap-restore-modal__file-icon",
                children: (0, _snapJsx.jsx)(i, { name: "archive", size: 14 })
              }),
              (0, _snapJsx.jsx)("code", { style: { wordBreak: "break-all" }, children: filename }),
              (snapshot.sizeFormatted || snapshot.size) ? (0, _snapJsx.jsx)("span", {
                className: "snap-restore-modal__file-size",
                children: snapshot.sizeFormatted || snapshot.size
              }) : null
            ]
          })
        ]
      }),
      (0, _snapJsx.jsxs)("div", {
        className: "snap-restore-modal__footnote",
        children: [
          (0, _snapJsx.jsx)("span", {
            className: "snap-restore-modal__footnote-icon",
            children: (0, _snapJsx.jsx)(i, { name: "server", size: 14 })
          }),
          (0, _snapJsx.jsxs)("span", {
            children: [
              (0, _snapI18n.__)("Storage location: ", "snapshoter"),
              (0, _snapJsx.jsx)("strong", { children: locationLabel })
            ]
          })
        ]
      }),
      (0, _snapJsx.jsxs)("footer", {
        className: "snap-restore-modal__footer",
        style: { marginTop: "8px" },
        children: [
          (0, _snapJsx.jsx)(o, {
            variant: "ghost",
            onClick: onCancel,
            disabled: deleting,
            children: (0, _snapI18n.__)("Cancel", "snapshoter")
          }),
          (0, _snapJsx.jsx)(o, {
            variant: "danger",
            onClick: onConfirm,
            loading: deleting,
            icon: (0, _snapJsx.jsx)(i, { name: "trash", size: 14 }),
            children: (0, _snapI18n.__)("Delete permanently", "snapshoter")
          })
        ]
      })
    ]
  });
}

function hs(){const t=function(s={}){const n=(0,_snapReact.useRef)(s.api??j()),t=s.pollIntervalMs??750,a=Math.max(t,s.maxPollIntervalMs??5e3),r=(0,_snapReact.useMemo)(()=>s.scheduler??((e,s)=>window.setTimeout(e,s)),[s.scheduler]),l=(0,_snapReact.useMemo)(()=>s.cancelScheduler??(e=>window.clearTimeout(e)),[s.cancelScheduler]),[o,i]=(0,_snapReact.useState)("idle"),[c,d]=(0,_snapReact.useState)(null),[p,h]=(0,_snapReact.useState)(null),[u,_]=(0,_snapReact.useState)(null),[x,g]=(0,_snapReact.useState)(null),[b,w]=(0,_snapReact.useState)([]),v=(0,_snapReact.useRef)(null),k=(0,_snapReact.useRef)(null),S=(0,_snapReact.useRef)(null),C=(0,_snapReact.useRef)(null),P=(0,_snapReact.useRef)(!1),M=(0,_snapReact.useRef)(0),T=(0,_snapReact.useRef)(t),E=(0,_snapReact.useRef)({}),resumedRef=(0,_snapReact.useRef)(!1),R=(0,_snapReact.useCallback)(()=>{null!==C.current&&(l(C.current),C.current=null)},[l]),$=(0,_snapReact.useCallback)(()=>{R(),S.current?.abort(),S.current=null,v.current=null,k.current=null,P.current=!1,M.current=0,T.current=t,E.current={},resumedRef.current=!1,clearActiveJob(),i("idle"),d(null),h(null),_(null),g(null),w([])},[R,t]);(0,_snapReact.useEffect)(()=>()=>{R(),S.current?.abort()},[R]);const z=(0,_snapReact.useCallback)(async e=>{try{const s=await N(n.current,{jobId:e,jobPassphrase:k.current||"",offset:M.current});s.chunk&&(M.current=s.next_offset,w(e=>[...e,s.chunk]))}catch{}},[]),I=(0,_snapReact.useCallback)(async(e,mode="drive")=>{if(!P.current){S.current=new AbortController;try{let o=await async function(e,s,n,mode){const action="poll"===mode?"SNAPSHOTER_get_status":"SNAPSHOTER_run_job";const t=y(await e.request(action,{job_id:s.jobId,job_passphrase:s.jobPassphrase},n));return m.info("api",`${action} ← step=${t.step} status=${t.status} pct=${t.percent??"-"}`,t),t}(n.current,{jobId:e,jobPassphrase:k.current??""},S.current.signal,mode);d(o),("import"!==o?.type&&(o?.downloadUrl||o?.download_url||o?.filename)&&"undefined"!=typeof window&&(o.downloadUrl||o.download_url?(window._snapLastExportDownload=o.downloadUrl||o.download_url):null,o.filename&&(window._snapLastExportFilename=o.filename))),await z(e),o?.log&&"string"==typeof o.log&&o.log.trim()&&w(e=>e.join("").includes(o.log)?e:[...e,o.log]);const c=function(e){return"completed"===e.step||"completed"===e.status||"partial"===e.status||"success"===e.status?"completed":"failed"===e.step||"failed"===e.status?"failed":"cancelled"===e.step||"cancelled"===e.status?"cancelled":ds.has(String(e.step))||ps.has(String(e.status))?"completed":null}(o);if(c){m.info("job",`tick → terminal=${c} step=${o.step} status=${o.status}`);if("completed"===c&&"import"!==o?.type){let dl=o.downloadUrl||o.download_url||("undefined"!=typeof window?window._snapLastExportDownload:void 0);let fn=o.filename||("undefined"!=typeof window?window._snapLastExportFilename:void 0);if(!fn){const p=o.snapshot_path||o.archive_path||"";if(p)fn=String(p).split(/[/\\]/).pop()}if(!dl&&fn&&/\.(smartin|sql|zip)$/i.test(fn)&&"undefined"!=typeof window&&window.snapshoter&&window.snapshoter.ajaxUrl){dl=`${window.snapshoter.ajaxUrl}?${new URLSearchParams({action:"SNAPSHOTER_download_file",file:fn,nonce:window.snapshoter.nonce||""}).toString()}`}if(dl||fn){o={...o,downloadUrl:dl||o.downloadUrl,download_url:dl||o.download_url,filename:fn||o.filename};d(o);if("undefined"!=typeof window){if(dl)window._snapLastExportDownload=dl;if(fn)window._snapLastExportFilename=fn}}}clearActiveJob();if("completed"===c&&"undefined"!=typeof window&&"function"==typeof window.dispatchEvent){window.dispatchEvent(new CustomEvent("snapshoter:backup-created"));window.dispatchEvent(new CustomEvent("snapshoter:snapshots-updated"))}return void i(c);}const p=function(e){if(!e)return{};const s=e,n="number"==typeof s.processed?s.processed:"number"==typeof s.bytes_written?s.bytes_written:void 0;return{step:e.step,status:e.status,percent:"number"==typeof e.percent?e.percent:void 0,processed:n}}(o);s=p,l=E.current,s.step===l.step&&s.status===l.status&&s.percent===l.percent&&s.processed===l.processed?T.current=Math.min(a,Math.ceil(1.5*T.current)):T.current=t,E.current=p,C.current=r(()=>{I(e,mode)},T.current)}catch(err){if(P.current)return m.warn("job","tick aborted (cancelled)",err),void i("cancelled");{
const jobIdForResume=v.current||e;
const driveFail="drive"===mode;
const softFail=driveFail||"poll"===mode;
if(softFail){m.warn("job",driveFail?"drive tick error - falling back to status poll (server keeps restoring)":"status poll transient error - retrying",err);const resumeMode=isAuthLostError(err)?mode:"poll";if(isCriticalSiteError(err)){saveActiveJob({jobId:jobIdForResume,jobPassphrase:k.current||"",kind:"import",mode:resumeMode});d(prev=>({...(prev&&"object"==typeof prev?prev:{}),status:"running",message:(0,_snapI18n.__)("Snapshoter lost contact with WordPress for a moment (common on large restores). Wait 30-60 seconds, then refresh this page - progress is saved and the restore usually continues. Stay on this screen; do not open the site homepage until restore finishes.","snapshoter")}));}else if(isAuthLostError(err)){saveActiveJob({jobId:jobIdForResume,jobPassphrase:k.current||"",kind:"import",mode:resumeMode});d(prev=>({...(prev&&"object"==typeof prev?prev:{}),status:"running",message:(0,_snapI18n.__)("Please log in in this window - restore keeps running. After login, this page shows Restore completed.","snapshoter")}));try{if("function"==typeof window.snapshoterShowLogin)window.snapshoterShowLogin()}catch(showErr){}if("undefined"!=typeof window&&!window._snapAuthRetryBound){window._snapAuthRetryBound=!0;const kick=()=>{try{if(!P.current&&v.current){I(v.current,"poll")}}catch(kickErr){}};window.addEventListener("focus",kick);document.addEventListener("visibilitychange",()=>{if(!document.hidden)kick()});}}else if(driveFail){saveActiveJob({jobId:jobIdForResume,jobPassphrase:k.current||"",kind:"import",mode:resumeMode});d(prev=>({...(prev&&"object"==typeof prev?prev:{}),status:"running",message:(0,_snapI18n.__)("Browser tick hiccup - restore continues on the server. Status will update...","snapshoter")}));}return void(C.current=r(()=>{I(jobIdForResume,resumeMode)},Math.min(a,Math.max(t,2500))))}const s=err instanceof f?err:new f(err instanceof Error?err.message:"Job failed.");m.error("job","tick failed",s),h(s),i("failed")}}var s,l}},[t,a,z,r]),A=(0,_snapReact.useCallback)(()=>{const e=v.current;e&&k.current&&!P.current?(m.info("job",`run() starting drive loop for ${e}`),i("running"),I(e,"drive")):m.warn("job","run() called with no allocated job",{jobId:e,hasPassphrase:!!k.current,cancelled:P.current})},[I]),W=(0,_snapReact.useCallback)(()=>{const e=v.current;e&&k.current&&!P.current?(m.info("job",`watch() status poll for ${e}`),saveActiveJob({jobId:v.current,jobPassphrase:k.current,kind:"import",mode:"poll"}),i("running"),I(e,"poll")):m.warn("job","watch() called with no allocated job",{jobId:e,hasPassphrase:!!k.current,cancelled:P.current})},[I]);(0,_snapReact.useEffect)(()=>{const resumeFromStorage=(force)=>{const saved=loadActiveJob();if(!saved||P.current)return!1;if(!force&&v.current)return!1;const kind=String(saved.kind||"import");if("export"===kind){m.info("job",`skip auto-resume of export jobId=${saved.jobId} (backups never auto-start on page load)`);clearActiveJob();return!1}m.info("job",`resume after login/reload jobId=${saved.jobId} mode=${saved.mode||"poll"} kind=${kind}`);v.current=saved.jobId,k.current=saved.jobPassphrase,_(saved.jobId),g(saved.jobPassphrase),d({jobId:saved.jobId,type:"import",step:"import_database",status:"running",percent:void 0,message:(0,_snapI18n.__)("Login OK - resuming restore status...","snapshoter")}),i("running"),I(saved.jobId,saved.mode==="drive"?"drive":"poll");return!0};if(!resumedRef.current){resumedRef.current=!0;resumeFromStorage(!1)}const onRestored=()=>{m.info("job","session-restored - forcing poll resume");resumeFromStorage(!0)};window.addEventListener("snapshoter:session-restored",onRestored);return()=>window.removeEventListener("snapshoter:session-restored",onRestored)},[I,_,g]);return{phase:o,progress:c,logChunks:b,error:p,jobId:u,jobPassphrase:x,start:(0,_snapReact.useCallback)(async(e,s={})=>{if(("import"===e.jobType||"restore"===e.jobType)&&preferGracefulRestore()&&void 0===s.autoPoll)s={...s,autoPoll:!1};$(),i("starting"),m.info("job",`start jobType=${e.jobType} autoPoll=${!1!==s.autoPoll} graceful=${preferGracefulRestore()}`,e.options);try{const t=await async function(e,s){const n={job_type:s.jobType,...s.options??{}};m.info("api","start_job →",n);const t=await e.request("SNAPSHOTER_start_job",n,void 0);m.info("api","start_job ←",t);const a=y(t);return a.jobId=String(t.jobId??""),a.jobPassphrase=String(t.jobPassphrase??""),a}(n.current,e);return m.info("job",`allocated jobId=${t.jobId} step=${t.step} status=${t.status}`),v.current=t.jobId,k.current=t.jobPassphrase,_(t.jobId),g(t.jobPassphrase),d(t),saveActiveJob({jobId:t.jobId,jobPassphrase:t.jobPassphrase,kind:e.jobType||"import",mode:!1===s.autoPoll?"poll":"drive"}),!1===s.autoPoll?i("allocated"):(i("running"),I(t.jobId,"drive")),t}catch(e){const s=e instanceof f?e:new f(e instanceof Error?e.message:"Failed to start job.");throw m.error("job","start_job failed",s),h(s),i("failed"),s}},[$,I]),startSnapshotRestore:(0,_snapReact.useCallback)(async(e,s={})=>{const usePoll=preferGracefulRestore()||!1===s.autoPoll;s={...s,autoPoll:!usePoll};$(),i("starting"),m.info("job",`startSnapshotRestore snapshot=${e.snapshot} autoPoll=${!1!==s.autoPoll} graceful=${preferGracefulRestore()}`);try{const t=await async function(e,s){const n={snapshot:s.snapshot};m.info("api","restore_snapshot →",n);const t=await e.request("SNAPSHOTER_restore_snapshot",n,void 0);m.info("api","restore_snapshot ←",t);const a=y(t);return a.jobId=String(t.jobId??""),a.jobPassphrase=String(t.jobPassphrase??""),a}(n.current,e);return m.info("job",`snapshot restore allocated jobId=${t.jobId}`),v.current=t.jobId,k.current=t.jobPassphrase,_(t.jobId),g(t.jobPassphrase),d({...t,type:"import"}),saveActiveJob({jobId:t.jobId,jobPassphrase:t.jobPassphrase,kind:"import",mode:usePoll?"poll":"drive"}),usePoll?(i("running"),I(t.jobId,"poll")):!1===s.autoPoll?i("allocated"):(i("running"),I(t.jobId,"drive")),t}catch(e){const s=e instanceof f?e:new f(e instanceof Error?e.message:"Failed to start snapshot restore.");throw m.error("job","restore_snapshot failed",s),h(s),i("failed"),s}},[$,I]),run:A,watch:W,cancel:(0,_snapReact.useCallback)(async()=>{const e=v.current,s=k.current;if(e&&s){P.current=!0,R(),S.current?.abort();try{await async function(e,s){m.info("api","cancel_job →",s);const n=y(await e.request("SNAPSHOTER_cancel_job",{job_id:s.jobId,job_passphrase:s.jobPassphrase},void 0));return m.info("api","cancel_job ←",n),n}(n.current,{jobId:e,jobPassphrase:s})}catch{}i("cancelled")}},[R]),reset:$}}(),a=function(s={}){const n=(0,_snapReact.useRef)(s.api??j()),[t,a]=(0,_snapReact.useState)("idle"),[r,l]=(0,_snapReact.useState)(0),[o,i]=(0,_snapReact.useState)(0),[c,d]=(0,_snapReact.useState)(null),[p,h]=(0,_snapReact.useState)(null),u=(0,_snapReact.useRef)(null),_=(0,_snapReact.useRef)(!1),x=(0,_snapReact.useCallback)(()=>{u.current?.abort(),u.current=null,_.current=!1,a("idle"),l(0),i(0),d(null),h(null)},[]);(0,_snapReact.useEffect)(()=>()=>{u.current?.abort()},[]);const g=(0,_snapReact.useCallback)(()=>{_.current=!0,u.current?.abort(),a("cancelled")},[]);return{phase:t,bytesUploaded:r,totalBytes:o,percent:o>0?r/o*100:0,error:c,lastResponse:p,upload:(0,_snapReact.useCallback)(async e=>{x();const{jobId:t,jobPassphrase:r,file:o}=e,c=o.size;i(c),a("uploading");const p="undefined"!=typeof window?window.snapshoter?.chunkBytes:void 0,g=Math.max(1,s.chunkBytes??p??1048576),cfgConc="undefined"!=typeof window?window.snapshoter?.uploadConcurrency:void 0,b=Math.max(1,Math.ceil(c/g));let conc=Math.max(1,Math.min(3,Number(s.uploadConcurrency??cfgConc??2)||2));m.info("upload",`begin file=${o.name} size=${c} chunks=${b} chunkBytes=${g} concurrency=${conc}`);const done=new Array(b).fill(!1),inflightLoaded=new Array(b).fill(0);let next=0,inflight=0,uploaded=0,last=null,fatal=null;const controllers=[];u.current={abort:()=>{controllers.forEach(e=>{try{e.abort()}catch{}})}};const isStress=e=>{const s=(e&&e.message?String(e.message):String(e||"")).toLowerCase(),n=e&&"number"==typeof e.status?e.status:0;return 429===n||502===n||503===n||504===n||/timeout|temporar|too many|rate limit|cloudflare|502|503|504/.test(s)};const chunkSize=e=>e===b-1?c-(b-1)*g:Math.min(g,c-e*g);const bump=()=>{let e=0;for(let s=0;s<b;s++)e+=done[s]?chunkSize(s):Math.min(inflightLoaded[s]|0,chunkSize(s));uploaded=Math.min(c,e),l(uploaded)};await new Promise((w,v)=>{const pump=()=>{if(fatal||_.current)return;for(;inflight<conc&&next<b;){const idx=next++;inflight+=1;const start=idx*g,end=Math.min(start+g,c),blob=o.slice(start,end),ctl=new AbortController;controllers.push(ctl);(async()=>{try{const res=await S(n.current,{jobId:t,jobPassphrase:r,chunkIndex:idx,totalChunks:b,chunk:blob,fileName:o.name},ctl.signal,(loaded)=>{inflightLoaded[idx]=Math.min(blob.size,loaded|0),bump()});if(_.current)return;last=res,h(res),inflightLoaded[idx]=0,done[idx]=!0,bump(),m.info("upload",`chunk ${idx+1}/${b} ok (${uploaded}/${c} bytes) conc=${conc}`)}catch(err){if(_.current)return;if(conc>1&&isStress(err)){conc=1,m.warn("upload",`host stress on chunk ${idx+1} - falling back to concurrency=1`);try{const ctl2=new AbortController;controllers.push(ctl2);const res2=await S(n.current,{jobId:t,jobPassphrase:r,chunkIndex:idx,totalChunks:b,chunk:blob,fileName:o.name},ctl2.signal,(loaded)=>{inflightLoaded[idx]=Math.min(blob.size,loaded|0),bump()});if(_.current)return;last=res2,h(res2),inflightLoaded[idx]=0,done[idx]=!0,bump(),m.info("upload",`chunk ${idx+1}/${b} ok after backoff (${uploaded}/${c} bytes)`);const j=controllers.indexOf(ctl2);j>=0&&controllers.splice(j,1)}catch(err2){if(_.current)return;fatal=err2 instanceof f?err2:new f(err2 instanceof Error?err2.message:"Chunk upload failed."),m.error("upload",`chunk ${idx+1}/${b} failed`,fatal)}}else{fatal=err instanceof f?err:new f(err instanceof Error?err.message:"Chunk upload failed."),m.error("upload",`chunk ${idx+1}/${b} failed`,fatal)}}finally{inflight-=1;const j=controllers.indexOf(ctl);j>=0&&controllers.splice(j,1);if(fatal){d(fatal),a("failed"),w()}else if(_.current){a("cancelled"),w()}else if(done.every(Boolean)){m.info("upload","complete - all chunks uploaded"),a("completed"),w(last)}else pump()}})()}};pump()})},[s.chunkBytes,s.uploadConcurrency,x]),cancel:g,reset:x}}(),r=function(s={}){const n=(0,_snapReact.useRef)(s.api??j()),t=(0,_snapReact.useRef)(null),[a,r]=(0,_snapReact.useState)({phase:"idle",kind:null,result:null,error:null}),l=(0,_snapReact.useCallback)(()=>{t.current?.abort(),t.current=null,r({phase:"idle",kind:null,result:null,error:null})},[]),o=(0,_snapReact.useCallback)(()=>{t.current?.abort(),r(e=>({...e,phase:"failed",error:new f("Cancelled by user.")}))},[]);return{...a,start:(0,_snapReact.useCallback)(async(e)=>{t.current?.abort();const s=new AbortController;t.current=s,m.info("partial-export",`start kind=${e}`),r({phase:"running",kind:e,result:null,error:null});try{const t="database"===e?v:k,a=await t(n.current,s.signal);m.info("partial-export","completed",a);if("undefined"!=typeof window&&"function"==typeof window.dispatchEvent){window.dispatchEvent(new CustomEvent("snapshoter:backup-created"));window.dispatchEvent(new CustomEvent("snapshoter:snapshots-updated"));}return r({phase:"completed",kind:e,result:a,error:null}),a}catch(n){if(s.signal.aborted)return null;const t=n instanceof f?n:new f(n instanceof Error?n.message:`${e} export failed.`);return m.error("partial-export",`${e} export failed`,t),r({phase:"failed",kind:e,result:null,error:t}),null}},[]),cancel:o,reset:l}}(),[l,o]=(0,_snapReact.useState)([]),[i,c]=(0,_snapReact.useState)(!1),[d,p]=(0,_snapReact.useState)(null),[h,u]=(0,_snapReact.useState)(null),[pendingDelete,setPendingDelete]=(0,_snapReact.useState)(null),[deletingSnapshot,setDeletingSnapshot]=(0,_snapReact.useState)(!1),_=(0,_snapReact.useCallback)(async(opts)=>{c(!0);try{const localOnly=!!(opts&&opts.localOnly);const s=await j().request("SNAPSHOTER_list_snapshots",localOnly?{local_only:1}:{},void 0);o((Array.isArray(s?.snapshots)?s.snapshots.map(C):[])??[])}catch(err){m.warn("app","SNAPSHOTER_list_snapshots failed",err)}finally{c(!1)}},[]);(0,_snapReact.useEffect)(()=>{_()},[_]),(0,_snapReact.useEffect)(()=>{if("completed"===t.phase){_();const t1=setTimeout(()=>_({localOnly:!0}),500);const t2=setTimeout(()=>_(),1500);return()=>{clearTimeout(t1);clearTimeout(t2)};}},[t.phase,_]);(0,_snapReact.useEffect)(()=>{if("completed"===r.phase){_();const t1=setTimeout(()=>_(),500);const t2=setTimeout(()=>_(),1500);return()=>{clearTimeout(t1);clearTimeout(t2)};}},[r.phase,_]);(0,_snapReact.useEffect)(()=>{if("undefined"==typeof window)return;const e=()=>{_()};return window.addEventListener("snapshoter:snapshots-updated",e),window.addEventListener("snapshoter:backup-created",e),()=>{window.removeEventListener("snapshoter:snapshots-updated",e),window.removeEventListener("snapshoter:backup-created",e)}},[_]);const x=(0,_snapReact.useCallback)(async(e)=>{window._snapActiveExportScope=e;if("undefined"!=typeof window)window._snapLastJobKind="export";m.info("app",`onStartExport → scope=${e}`);try{await t.start({jobType:"export",options:{is_snapshot:!0,scope:e}})}catch(err){m.error("app","onStartExport failed",err);}},[t,l]),g=(0,_snapReact.useCallback)(e=>{m.info("app","onSelectImport → opening restore modal",{filename:e.name,size:e.size,type:e.type}),p({file:e})},[]),b=(0,_snapReact.useCallback)(async()=>{if(!d)return void m.warn("app","onConfirmImport fired with no pending import");const e=d.file;m.info("app","onConfirmImport → starting upload flow",{filename:e.name,size:e.size}),p(null);try{const s=await t.start({jobType:"import"},{autoPoll:!1});await a.upload({jobId:s.jobId,jobPassphrase:s.jobPassphrase,file:e}),t.watch()}catch{}},[t,d,a]),w=(0,_snapReact.useCallback)(e=>{if(!canRestoreSnapshot(e)){if("undefined"!=typeof window)window.alert((0,_snapI18n.__)("Only full-site .smartin archives can be restored. Download .sql / .zip instead.","snapshoter"));return}m.info("app","onRestoreSnapshot → opening restore modal",{id:e.id,name:e.name,filename:e.filename,size:e.sizeFormatted||e.size});u(e)},[]),P=(0,_snapReact.useCallback)(async()=>{if(!h)return void m.warn("app","onConfirmRestore fired with no selection");const e=h;let s=e.filename??e.name??"";if(!canRestoreSnapshot(e)){u(null);if("undefined"!=typeof window)window.alert((0,_snapI18n.__)("Only full-site .smartin archives can be restored. Download .sql / .zip instead.","snapshoter"));return}u(null);m.info("app","onConfirmRestore → restore_snapshot",{filename:s});try{if("undefined"!=typeof window)window._snapLastJobKind="restore";await t.startSnapshotRestore({snapshot:s},{autoPoll:!preferGracefulRestore()})}catch{}},[h,t,_]),M=(0,_snapReact.useCallback)(e=>{setPendingDelete(e)},[]);
const handleConfirmDeleteSnapshot=(0,_snapReact.useCallback)(async()=>{
  if(!pendingDelete) return;
  const e = pendingDelete;
  const n = e.filename ?? e.name ?? "";
  setDeletingSnapshot(!0);
  o(s=>s.filter(s=>s.id!==e.id));
  setPendingDelete(null);
  setDeletingSnapshot(!1);
  try {
    if(n) {
      m.info("app","onDeleteSnapshot → delete_snapshot",{filename:n});
      await j().request("SNAPSHOTER_delete_snapshot",{snapshot:n});
    }
  } catch(err) {
    m.warn("app","delete snapshot failed",err);
  } finally {
    _();
  }
},[pendingDelete,_]),T=(0,_snapReact.useCallback)(async e=>{if("undefined"==typeof window||!window.snapshoter)return;if(e.downloadUrl)return void(window.location.href=e.downloadUrl);const s=new URLSearchParams({action:"SNAPSHOTER_download_file",file:e.filename??e.name??"",nonce:window.snapshoter.nonce});window.location.href=`${window.snapshoter.ajaxUrl}?${s.toString()}`},[_]);const _resolveExportDownloadUrl=(progress,snapshots)=>{
  if(!progress||"import"===progress.type)return;
  const direct=progress.downloadUrl||progress.download_url;
  if(direct){if("undefined"!=typeof window){window._snapLastExportDownload=direct;if(progress.filename)window._snapLastExportFilename=progress.filename;}return direct;}
  let filename=progress.filename||"";
  if(!filename){const p=progress.snapshot_path||progress.archive_path||"";if(p)filename=String(p).split(/[/\\]/).pop();}
  if((!filename||!/\.(smartin|sql|zip)$/i.test(filename))&&"undefined"!=typeof window&&window._snapLastExportFilename)filename=window._snapLastExportFilename;
  if(filename&&/\.(smartin|sql|zip)$/i.test(filename)&&"undefined"!=typeof window&&window.snapshoter&&window.snapshoter.ajaxUrl){
    const q=new URLSearchParams({action:"SNAPSHOTER_download_file",file:filename,nonce:window.snapshoter.nonce||""});
    const url=`${window.snapshoter.ajaxUrl}?${q.toString()}`;
    window._snapLastExportDownload=url;window._snapLastExportFilename=filename;return url;
  }
  if("undefined"!=typeof window&&window._snapLastExportDownload)return window._snapLastExportDownload;
  const list=Array.isArray(snapshots)?snapshots:[];
  const local=list.find(s=>s&&s.downloadUrl);if(local)return local.downloadUrl;
  const any=list.find(s=>s&&s.downloadUrl);return any?any.downloadUrl:void 0;
},
_activeDownloadUrl=("import"!==t.progress?.type&&("completed"===t.phase||!!(t.progress&&(t.progress.downloadUrl||t.progress.download_url||t.progress.filename))))?_resolveExportDownloadUrl(t.progress,l):void 0,
_isBusyJob="starting"===t.phase||"allocated"===t.phase||"running"===t.phase,
_isBusyUpload="uploading"===a.phase,
_isBusyPartial="running"===r.phase,
_isBusy=_isBusyJob||_isBusyUpload||_isBusyPartial,
_noticesContent=(0,_snapJsx.jsxs)(_snapJsx.Fragment,{children:["idle"!==r.phase&&r.kind?(0,_snapJsx.jsx)(as,{phase:r.phase,kind:r.kind,result:r.result,error:r.error,onCancel:r.cancel,onDismiss:r.reset}):null,"idle"!==t.phase?(0,_snapJsx.jsx)(ss,{jobType:("export"===t.progress?.type?"export":("import"===t.progress?.type||"uploading"===a.phase||"allocated"===t.phase||"failed"===a.phase?"import":"export")),progress:t.progress,phase:t.phase,logChunks:t.logChunks,error:t.error??a.error,upload:"uploading"===a.phase?{percent:a.percent,bytesUploaded:a.bytesUploaded,totalBytes:a.totalBytes}:null,downloadUrl:_activeDownloadUrl,onCancel:async()=>{a.cancel(),await t.cancel()},onDismiss:"completed"===t.phase||"failed"===t.phase||"cancelled"===t.phase?()=>{a.reset(),t.reset()}:void 0}):null]}),
_backupContent=(0,_snapJsx.jsxs)(_snapJsx.Fragment,{children:[(0,_snapJsx.jsx)(H,{disabled:_isBusy,onStart:x}),(0,_snapJsx.jsx)(q,{disabled:_isBusy,onSelect:g}),(0,_snapJsx.jsx)(G,{snapshots:l,loading:i,disabledActions:_isBusy,onRefresh:_,onDownload:T,onRestore:w,onDelete:M})]}),
_tabList=(function(){const tabs=[{id:"backup",hash:"backup",label:(0,_snapI18n.__)("Backup & Restore","snapshoter"),content:_backupContent}];return tabs;})();
return(0,_snapJsx.jsxs)(z,{version:window.snapshoter?.version,docsUrl:window.snapshoter?.adminUrl?`${window.snapshoter.adminUrl}admin.php?page=snapshoter-help`:void 0,sourceUrl:(window.snapshoter?.learnMoreUrl||window.snapshoter?.authorUrl||"https://www.smartin.in/snapshoter-wordpress-free-backup-migration-plugin/"),notices:_noticesContent,children:[(0,_snapJsx.jsx)(F,{tabs:_tabList}),(0,_snapJsx.jsx)(cs,{open:null!==d,filename:d?.file.name??"",size:d?us(d.file.size):void 0,helpUrl:window.snapshoter?.adminUrl?`${window.snapshoter.adminUrl}admin.php?page=snapshoter-help`:void 0,onCancel:()=>p(null),onConfirm:()=>{b()}}),(0,_snapJsx.jsx)(cs,{open:null!==h,filename:h?.name??"",size:h?.sizeFormatted,helpUrl:window.snapshoter?.adminUrl?`${window.snapshoter.adminUrl}admin.php?page=snapshoter-help`:void 0,onCancel:()=>u(null),onConfirm:()=>{P()}}),(0,_snapJsx.jsx)(DeleteSnapshotModal,{open:null!==pendingDelete,snapshot:pendingDelete,deleting:deletingSnapshot,onCancel:()=>setPendingDelete(null),onConfirm:handleConfirmDeleteSnapshot})]})}function us(e){if(e<1024)return`${e} B`;const s=["KB","MB","GB"];let n=e,t=-1;for(;n>=1024&&t<s.length-1;)n/=1024,t+=1;return`${n.toFixed(0===t?0:1)} ${s[t]}`}class ErrorBoundary extends _snapReact.Component {
  constructor(props) {
    super(props);
    this.state = { hasError: false, error: null };
  }
  static getDerivedStateFromError(error) {
    return { hasError: true, error };
  }
  componentDidCatch(error, errorInfo) {
    if(typeof window!=="undefined"&&window.snapshoterDebug){console.error("[Snapshoter] UI render error caught by boundary:", error, errorInfo);}
    if (typeof window !== "undefined" && window.snapshoterDebugBuffer) {
      window.snapshoterDebugBuffer.push({
        t: Date.now(),
        level: "error",
        category: "react_error_boundary",
        message: error instanceof Error ? error.message : String(error),
        payload: errorInfo
      });
    }
  }
  render() {
    if (this.state.hasError) {
      return (0,_snapJsx.jsxs)("div", {
        style: {
          padding: "24px",
          background: "#ffffff",
          border: "1px solid #e2e8f0",
          borderRadius: "12px",
          maxWidth: "760px",
          margin: "24px auto",
          boxShadow: "0 4px 12px rgba(0,0,0,0.05)",
          fontFamily: "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif"
        },
        children: [
          (0,_snapJsx.jsx)("h2", { style: { color: "#dc2626", margin: "0 0 10px", fontSize: "18px" }, children: (0,_snapI18n.__)("Snapshoter encountered an interface error", "snapshoter") }),
          (0,_snapJsx.jsx)("p", { style: { color: "#475569", margin: "0 0 16px", fontSize: "13.5px" }, children: this.state.error?.message || String(this.state.error) }),
          (0,_snapJsx.jsxs)("div", { style: { display: "flex", gap: "10px" }, children: [
            (0,_snapJsx.jsx)(o, { variant: "primary", onClick: () => window.location.reload(), children: (0,_snapI18n.__)("Reload Page", "snapshoter") }),
            (0,_snapJsx.jsx)(o, { variant: "secondary", onClick: () => { this.setState({ hasError: false, error: null }); }, children: (0,_snapI18n.__)("Try Again", "snapshoter") })
          ]})
        ]
      });
    }
    return this.props.children;
  }
}
function _s(){return(0,_snapJsx.jsx)(ErrorBoundary,{children:(0,_snapJsx.jsx)(l,{children:(0,_snapJsx.jsx)(hs,{})})})}function ms(e){if("flywheel"===(e.host?.id??""))return!0;const s=(e.host?.label??"").toLowerCase();return s.includes("local")||s.includes("flywheel")||s.includes("ddev")}
const js={ok:{tone:"success",label:"Good",icon:"check-circle"},warning:{tone:"warning",label:"Low",icon:"alert-triangle"},critical:{tone:"danger",label:"Critical",icon:"x-circle"}};function ys(){const e=window.snapshoterHelp,t=e?.version??window.snapshoter?.version??"",a=window.snapshoter?.adminUrl??"",r=a?`${a}admin.php?page=snapshoter`:"#",l=e?.limits??[],o=e?.warnings??[];return(0,_snapJsx.jsxs)(z,{version:t,docsUrl:a?`${a}admin.php?page=snapshoter-help`:void 0,sourceUrl:(window.snapshoter?.learnMoreUrl||window.snapshoter?.authorUrl||"https://www.smartin.in/snapshoter-wordpress-free-backup-migration-plugin/"),children:[(0,_snapJsx.jsxs)("section",{className:"snap-help-hero",children:[(0,_snapJsx.jsx)("div",{className:"snap-help-hero__icon",children:(0,_snapJsx.jsx)(i,{name:"help-circle",size:22})}),(0,_snapJsx.jsxs)("div",{className:"snap-help-hero__body",children:[(0,_snapJsx.jsx)("h2",{className:"snap-help-hero__title",children:(0,_snapI18n.__)("Help & diagnostics","snapshoter")}),(0,_snapJsx.jsx)("p",{className:"snap-help-hero__desc",children:(0,_snapI18n.__)("Server limits, restore help, and support contacts.","snapshoter")})]}),(0,_snapJsx.jsxs)("a",{className:"snap-help-hero__link",href:r,"aria-label":(0,_snapI18n.__)("Return to Snapshoter dashboard","snapshoter"),children:[(0,_snapJsx.jsx)(i,{name:"chevron-right",size:16}),(0,_snapJsx.jsx)("span",{children:(0,_snapI18n.__)("Back to dashboard","snapshoter")})]})]}),(0,_snapJsx.jsxs)(B,{title:(0,_snapJsx.jsxs)("span",{className:"snap-help-card-title",children:[(0,_snapJsx.jsx)("span",{className:"snap-help-card-title__badge",children:(0,_snapJsx.jsx)(i,{name:"shield",size:16})}),(0,_snapI18n.__)("Server health","snapshoter")]}),description:(0,_snapI18n.__)("Live PHP limits on this site. Meet or exceed Recommended for large backups.","snapshoter"),children:[(0,_snapJsx.jsx)(ws,{limits:l}),o.length>0?(0,_snapJsx.jsxs)("aside",{className:"snap-help-warnings",children:[(0,_snapJsx.jsxs)("div",{className:"snap-help-warnings__head",children:[(0,_snapJsx.jsx)(i,{name:"alert-triangle",size:16}),(0,_snapJsx.jsx)("strong",{children:(0,_snapI18n.__)("Recommended adjustments","snapshoter")})]}),(0,_snapJsx.jsx)("ul",{children:o.map(e=>(0,_snapJsx.jsx)("li",{children:e},e))})]}):null,(0,_snapJsx.jsxs)("details",{className:"snap-help-disclosure",children:[(0,_snapJsx.jsxs)("summary",{children:[(0,_snapJsx.jsx)(i,{name:"chevron-right",size:14}),(0,_snapJsx.jsx)("span",{children:(0,_snapI18n.__)("How to raise these limits","snapshoter")})]}),(0,_snapJsx.jsxs)("ol",{className:"snap-help-list",children:[(0,_snapJsx.jsx)("li",{children:(0,_snapI18n.__)("Ask your host to raise memory_limit, upload_max_filesize, and post_max_size.","snapshoter")}),(0,_snapJsx.jsx)("li",{children:(0,_snapI18n.__)("Or set php.ini / .htaccess: memory_limit 256M, max_execution_time 300, upload/post 64M+.","snapshoter")}),(0,_snapJsx.jsx)("li",{children:(0,_snapI18n.__)("Optionally add define('WP_MEMORY_LIMIT', '256M'); in wp-config.php.","snapshoter")})]})]})]}),(0,_snapJsx.jsx)(B,{title:(0,_snapJsx.jsxs)("span",{className:"snap-help-card-title",children:[(0,_snapJsx.jsx)("span",{className:"snap-help-card-title__badge snap-help-card-title__badge--accent",children:(0,_snapJsx.jsx)(i,{name:"shield",size:16})}),(0,_snapI18n.__)("Built-in protections","snapshoter")]}),description:(0,_snapI18n.__)("Always on for backup and restore - no setup needed.","snapshoter"),children:(0,_snapJsx.jsx)("ul",{className:"snap-help-features",children:[{icon:"clock",title:(0,_snapI18n.__)("Chunked, resumable jobs","snapshoter"),body:(0,_snapI18n.__)("Backups run in small chunks to avoid PHP timeouts and can resume after hiccups.","snapshoter")},{icon:"shield-check",title:(0,_snapI18n.__)("Safe-mode restores","snapshoter"),body:(0,_snapI18n.__)("Risky plugins pause during restore; URLs update and permalinks flush when done.","snapshoter")},{icon:"archive",title:(0,_snapI18n.__)("Local snapshot history","snapshoter"),body:(0,_snapI18n.__)("Keep local snapshots so you can roll back without a new export.","snapshoter")},{icon:"shield",title:(0,_snapI18n.__)("Integrity verification","snapshoter"),body:(0,_snapI18n.__)("Archives include SHA-256 hashes checked before restore changes data.","snapshoter")}].map(e=>(0,_snapJsx.jsxs)("li",{className:"snap-help-features__item",children:[(0,_snapJsx.jsx)("span",{className:"snap-help-features__icon",children:(0,_snapJsx.jsx)(i,{name:e.icon,size:16})}),(0,_snapJsx.jsxs)("div",{children:[(0,_snapJsx.jsx)("strong",{children:e.title}),(0,_snapJsx.jsx)("p",{children:e.body})]})]},e.title))})}),(0,_snapJsx.jsx)(B,{title:(0,_snapJsx.jsxs)("span",{className:"snap-help-card-title",children:[(0,_snapJsx.jsx)("span",{className:"snap-help-card-title__badge snap-help-card-title__badge--warning",children:(0,_snapJsx.jsx)(i,{name:"info",size:16})}),(0,_snapI18n.__)("Manual restore (fallback)","snapshoter")]}),description:(0,_snapI18n.__)("Use only if in-app restore cannot finish (for example, tight PHP limits).","snapshoter"),children:(0,_snapJsx.jsx)("ol",{className:"snap-help-steps",children:[{icon:"archive",title:(0,_snapI18n.__)("Prepare the archive","snapshoter"),body:(0,_snapI18n.__)("Download the .smartin, rename to .zip, extract files/, database/database.sql, and manifest.json. Back up live wp-content first.","snapshoter")},{icon:"folder",title:(0,_snapI18n.__)("Restore the files","snapshoter"),body:(0,_snapI18n.__)("Upload files/wp-content/ via SFTP or file manager. Replace wp-content or only uploads/themes/plugins as needed.","snapshoter")},{icon:"database",title:(0,_snapI18n.__)("Import the database","snapshoter"),body:(0,_snapI18n.__)("Import database.sql in phpMyAdmin or MySQL. DB user needs DROP/CREATE.","snapshoter")},{icon:"file",title:(0,_snapI18n.__)("Update wp-config and URLs","snapshoter"),body:(0,_snapI18n.__)("Match DB credentials and table prefix. Search/replace the domain if migrating.","snapshoter")},{icon:"plug",title:(0,_snapI18n.__)("Finalize","snapshoter"),body:(0,_snapI18n.__)("Reactivate plugins, flush caches/CDN, re-save permalinks. Regenerate CSS/thumbnails if needed.","snapshoter")}].map((e,s)=>(0,_snapJsx.jsxs)("li",{children:[(0,_snapJsx.jsx)("span",{className:"snap-help-steps__index",children:String(s+1).padStart(2,"0")}),(0,_snapJsx.jsx)("span",{className:"snap-help-steps__icon",children:(0,_snapJsx.jsx)(i,{name:e.icon,size:16})}),(0,_snapJsx.jsxs)("div",{className:"snap-help-steps__body",children:[(0,_snapJsx.jsx)("strong",{children:e.title}),(0,_snapJsx.jsx)("p",{children:e.body})]})]},e.title))})}),(0,_snapJsx.jsx)(B,{title:(0,_snapJsx.jsxs)("span",{className:"snap-help-card-title",children:[(0,_snapJsx.jsx)("span",{className:"snap-help-card-title__badge snap-help-card-title__badge--danger",children:(0,_snapJsx.jsx)(i,{name:"alert-triangle",size:16})}),(0,_snapI18n.__)("Troubleshooting","snapshoter")]}),description:(0,_snapI18n.__)("Common issues and what to check first.","snapshoter"),children:(0,_snapJsx.jsx)("dl",{className:"snap-help-troubleshoot",children:[{q:(0,_snapI18n.__)("Stuck on "Validating archive"","snapshoter"),a:(0,_snapI18n.__)("Raise memory_limit / max_execution_time and confirm the Snapshoter storage folder is writable.","snapshoter")},{q:(0,_snapI18n.__)("Site redirects to old domain","snapshoter"),a:(0,_snapI18n.__)("Fix Site URL under Settings → General, then search/replace the old domain in the database.","snapshoter")},{q:(0,_snapI18n.__)("Broken styles after restore","snapshoter"),a:(0,_snapI18n.__)("Regenerate theme CSS and purge object cache / CDN.","snapshoter")},{q:(0,_snapI18n.__)("Database error: "packet too large"","snapshoter"),a:(0,_snapI18n.__)("Raise MySQL max_allowed_packet, or import the SQL in smaller chunks.","snapshoter")}].map(e=>(0,_snapJsx.jsxs)("div",{className:"snap-help-troubleshoot__row",children:[(0,_snapJsx.jsx)("dt",{children:e.q}),(0,_snapJsx.jsx)("dd",{children:e.a})]},e.q))})}),(0,_snapJsx.jsxs)(B,{title:(0,_snapJsx.jsxs)("span",{className:"snap-help-card-title",children:[(0,_snapJsx.jsx)("span",{className:"snap-help-card-title__badge snap-help-card-title__badge--info",children:(0,_snapJsx.jsx)(i,{name:"info",size:16})}),(0,_snapI18n.__)("Support","snapshoter")]}),description:(0,_snapI18n.__)("Contact us for help with Snapshoter.","snapshoter"),children:[(0,_snapJsx.jsxs)("div",{className:"snap-help-support",children:[(0,_snapJsx.jsxs)("a",{className:"snap-help-support__card",href:"mailto:smartin@smartin.in",children:[(0,_snapJsx.jsx)("span",{className:"snap-help-support__card-icon",children:(0,_snapJsx.jsx)(i,{name:"file",size:16})}),(0,_snapJsx.jsxs)("div",{children:[(0,_snapJsx.jsx)("strong",{children:(0,_snapI18n.__)("Email support","snapshoter")}),(0,_snapJsx.jsx)("p",{children:"smartin@smartin.in"})]}),(0,_snapJsx.jsx)(i,{name:"external-link",size:14,className:"snap-help-support__card-chevron"})]}),(0,_snapJsx.jsx)("p",{className:"snap-help-disclaimer",children:(0,_snapI18n.__)("WordPress.org forum support will be available after listing approval.","snapshoter")})]})]})]})}function ws({limits:e}){return 0===e.length?(0,_snapJsx.jsx)("p",{className:"snap-help-empty",children:(0,_snapI18n.__)("Server limits are unavailable. Reload the page or check the diagnostics drawer on the dashboard.","snapshoter")}):(0,_snapJsx.jsxs)("div",{className:"snap-help-table",role:"table",children:[(0,_snapJsx.jsxs)("div",{className:"snap-help-table__head",role:"row",children:[(0,_snapJsx.jsx)("span",{role:"columnheader",children:(0,_snapI18n.__)("Setting","snapshoter")}),(0,_snapJsx.jsx)("span",{role:"columnheader",children:(0,_snapI18n.__)("Current","snapshoter")}),(0,_snapJsx.jsx)("span",{role:"columnheader",children:(0,_snapI18n.__)("Recommended","snapshoter")}),(0,_snapJsx.jsx)("span",{role:"columnheader",children:(0,_snapI18n.__)("Status","snapshoter")})]}),e.map(e=>{const s=js[e.status];return(0,_snapJsx.jsxs)("div",{className:"snap-help-table__row",role:"row",children:[(0,_snapJsx.jsx)("span",{className:"snap-help-table__cell snap-help-table__cell--label",role:"cell",children:e.label}),(0,_snapJsx.jsx)("span",{className:"snap-help-table__cell snap-help-table__cell--value",role:"cell",children:(0,_snapJsx.jsx)("code",{children:e.currentFormatted})}),(0,_snapJsx.jsx)("span",{className:"snap-help-table__cell snap-help-table__cell--value",role:"cell",children:(0,_snapJsx.jsx)("code",{children:e.recommended})}),(0,_snapJsx.jsx)("span",{className:"snap-help-table__cell snap-help-table__cell--status",role:"cell",children:(0,_snapJsx.jsxs)(O,{tone:s.tone,children:[(0,_snapJsx.jsx)(i,{name:s.icon,size:12}),(0,_snapJsx.jsx)("span",{children:s.label})]})})]},e.key)})]})}function vs(){return(0,_snapJsx.jsx)(l,{children:(0,_snapJsx.jsx)(ys,{})})}function ks(){const s=document.getElementById("snapshoter-root");if(s){try{const v=(0,_snapJsx.jsx)(_s,{});if(_snapReact&&typeof _snapReact.createRoot==="function"){(0,_snapReact.createRoot)(s).render(v);}else if(window.ReactDOM&&typeof window.ReactDOM.createRoot==="function"){window.ReactDOM.createRoot(s).render(v);}else if(_snapReact&&typeof _snapReact.render==="function"){_snapReact.render(v,s);}else if(window.ReactDOM&&typeof window.ReactDOM.render==="function"){window.ReactDOM.render(v,s);}}catch(e){if(typeof window!=="undefined"&&window.snapshoterDebug){console.error("[Snapshoter] Mount error:",e);}s.innerHTML="<div style=\"padding:24px;background:#fee2e2;border:1px solid #ef4444;border-radius:12px;color:#991b1b;font-family:sans-serif;margin:20px;\"><strong>Snapshoter Initialization Error:</strong><p style=\"margin:8px 0 0\">"+(e&&e.message?e.message:String(e))+"</p></div>";}return;}const t=document.getElementById("snapshoter-help-root");if(t){try{m.info("boot","mounting HelpApp");const v=(0,_snapJsx.jsx)(vs,{});if(_snapReact&&typeof _snapReact.createRoot==="function"){(0,_snapReact.createRoot)(t).render(v);}else if(window.ReactDOM&&typeof window.ReactDOM.createRoot==="function"){window.ReactDOM.createRoot(t).render(v);}else if(_snapReact&&typeof _snapReact.render==="function"){_snapReact.render(v,t);}}catch(e){if(typeof window!=="undefined"&&window.snapshoterDebug){console.error("[Snapshoter] HelpApp mount error:",e);}}}}m.info("boot",`bundle loaded build=${h}`),window.snapshoterInspect=()=>{const e=Array.from(document.querySelectorAll(".snap-modal")),s=document.querySelector(".snapshoter-app"),n=s?window.getComputedStyle(s):null;return{buildId:h,modals:e.map(e=>{const s=window.getComputedStyle(e),n=e.querySelector(".snap-modal__dialog"),t=n?window.getComputedStyle(n):null;return{className:e.className,overlay:{position:s.position,zIndex:s.zIndex,backgroundColor:s.backgroundColor,backdropFilter:s.backdropFilter||s.webkitBackdropFilter},dialog:t&&{backgroundColor:t.backgroundColor,maxWidth:t.maxWidth,boxShadow:t.boxShadow}}}),tokens:n&&{overlay:n.getPropertyValue("--snap-color-overlay").trim(),zModal:n.getPropertyValue("--snap-z-modal").trim(),surface:n.getPropertyValue("--snap-color-surface").trim(),theme:s?.dataset.theme??"unknown"}}},"loading"===document.readyState?document.addEventListener("DOMContentLoaded",ks):ks()})();