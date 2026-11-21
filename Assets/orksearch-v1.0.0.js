(function () {
  'use strict';

  const translations = {
    pt: {
      open: 'Busca Inteligente', pin: 'Fixar busca', unpin: 'Busca fixada', search: 'Encontre um cartão por qualquer informação...',
      filters: 'Refinar resultados', clear: 'Limpar', close: 'Fechar',
      swimlane: 'Raia', column: 'Coluna', category: 'Categoria', tag: 'Etiqueta',
      assignee: 'Responsável', priority: 'Prioridade', complexity: 'Complexidade', status: 'Status', all: 'Todos',
      statusOpen: 'Abertos', statusClosed: 'Finalizados', complexityUnspecified: '0 (Não informada)',
      available: 'cartões disponíveis', showAll: 'Ver todos os cartões',
      found: 'cartões encontrados', showing: 'Exibindo', of: 'de',
      loadMore: 'Carregar mais 20', loading: 'Pesquisando...',
      empty: 'Nenhum cartão encontrado.',
      error: 'A pesquisa falhou. Atualize a página e tente novamente.',
      limited: 'Busca parcial: mais de 2.000 cartões no projeto. Refine a pesquisa.',
      source: 'Encontrado em', sources: 'Ver todas as correspondências', matches: 'correspondências', moreSources: 'Outras ocorrências não exibidas',
      fields: {id:'Identificador',title:'Título',description:'Descrição',category:'Categoria',tag:'Etiqueta',column:'Coluna',swimlane:'Raia',assignee:'Responsável',comment:'Comentário',filename:'Nome do anexo',attachment_content:'Conteúdo do anexo',subtask:'Subtarefa'}
    },
    en: {
      open: 'Smart Search', pin: 'Pin search', unpin: 'Search pinned', search: 'Find a task by any information...',
      filters: 'Refine results', clear: 'Clear', close: 'Close',
      swimlane: 'Swimlane', column: 'Column', category: 'Category', tag: 'Tag',
      assignee: 'Assignee', priority: 'Priority', complexity: 'Complexity', status: 'Status', all: 'All',
      statusOpen: 'Open', statusClosed: 'Closed', complexityUnspecified: '0 (Not specified)',
      available: 'tasks available', showAll: 'View all tasks',
      found: 'tasks found', showing: 'Showing', of: 'of',
      loadMore: 'Load 20 more', loading: 'Searching...',
      empty: 'No tasks found.',
      error: 'Search failed. Refresh the page and try again.',
      limited: 'Partial search: more than 2,000 tasks. Narrow your query.',
      source: 'Found in', sources: 'View all matches', matches: 'matches', moreSources: 'Additional matches not shown',
      fields: {id:'ID',title:'Title',description:'Description',category:'Category',tag:'Tag',column:'Column',swimlane:'Swimlane',assignee:'Assignee',comment:'Comment',filename:'Attachment name',attachment_content:'Attachment content',subtask:'Subtask'}
    }
  };
  const lang = ((document.documentElement.lang || navigator.language || 'pt').toLowerCase().startsWith('pt')) ? 'pt' : 'en';
  const tr = translations[lang];
  const facets = ['swimlane', 'column', 'category', 'tag', 'assignee', 'priority', 'complexity', 'status'];

  function elem(tag, className, text) {
    const el = document.createElement(tag);
    if (className) el.className = className;
    if (text !== undefined) el.textContent = text;
    return el;
  }
  function ready() {
    const anchor = document.querySelector('.ofss-anchor[data-project-id]');
    const native = document.querySelector('.project-header .filter-box .input-addon');
    if (!anchor || !native || anchor.dataset.ready === '1') return;
    anchor.dataset.ready = '1';
    native.appendChild(anchor);
    anchor.hidden = false;
    const launch = anchor.querySelector('.ofss-launch');
    const label = anchor.querySelector('.ofss-launch-label');
    label.textContent = tr.open;
    launch.title = tr.open;
    launch.setAttribute('aria-label', tr.open);

    const popover = elem('section', 'ofss-popover');
    popover.setAttribute('role', 'dialog');
    popover.setAttribute('aria-label', tr.open);
    popover.hidden = true;
    const top = elem('div', 'ofss-head');
    top.appendChild(elem('strong', '', tr.open));
    const headActions = elem('div', 'ofss-head-actions');
    const pin = elem('button', 'ofss-pin');
    pin.type = 'button';
    pin.setAttribute('aria-pressed', 'false');
    pin.setAttribute('aria-label', tr.pin);
    pin.title = tr.pin;
    const pinIcon = elem('i', 'fa fa-thumb-tack');
    pinIcon.setAttribute('aria-hidden', 'true');
    pin.appendChild(pinIcon);
    pin.appendChild(elem('span', 'ofss-pin-label', tr.pin));
    headActions.appendChild(pin);
    const close = elem('button', 'ofss-close', '×');
    close.type = 'button'; close.setAttribute('aria-label', tr.close);
    headActions.appendChild(close);
    top.appendChild(headActions);
    popover.appendChild(top);
    const searchBox = elem('input', 'ofss-input');
    searchBox.type = 'search'; searchBox.placeholder = tr.search;
    searchBox.setAttribute('aria-label', tr.search);
    popover.appendChild(searchBox);
    const actions = elem('div', 'ofss-actions');
    const refine = elem('button', 'ofss-refine', tr.filters + ' ▾');
    refine.type = 'button';
    const clear = elem('button', 'ofss-clear', tr.clear);
    clear.type = 'button';
    actions.appendChild(refine); actions.appendChild(clear);
    popover.appendChild(actions);
    const facetContainer = elem('div', 'ofss-facets');
    facetContainer.hidden = true;
    const selects = {};
    facets.forEach(f => {
      const wrap = elem('label', 'ofss-facet');
      wrap.appendChild(elem('span', '', tr[f]));
      const select = elem('select', 'ofss-select');
      select.setAttribute('aria-label', tr[f]);
      selects[f] = select;
      wrap.appendChild(select);
      facetContainer.appendChild(wrap);
    });
    popover.appendChild(facetContainer);
    const summary = elem('p', 'ofss-summary');
    summary.setAttribute('aria-live', 'polite');
    popover.appendChild(summary);
    const intro = elem('div', 'ofss-intro');
    const viewAll = elem('button', 'ofss-view-all', tr.showAll);
    viewAll.type = 'button';
    intro.appendChild(viewAll);
    popover.appendChild(intro);
    const results = elem('div', 'ofss-results');
    popover.appendChild(results);
    const foot = elem('div', 'ofss-foot');
    popover.appendChild(foot);
    document.body.appendChild(popover);

    let opened = false, debounce = null, activeRequest = null, requestSerial = 0;
    let allRequested = false, displayed = 0, lastTotal = 0;
    let pinned = false;
    const stateKey = 'ofss:search:v1:project:' + String(anchor.dataset.projectId);
    const values = { swimlane:'', column:'', category:'', tag:'', assignee:'', priority:'', complexity:'', status:'' };

    // Per-project, per-tab state. Never store document contents or API results.
    // Storage can be disabled by the browser: failure must not block searching.
    function saveState() {
      try {
        if (!pinned) {
          sessionStorage.removeItem(stateKey);
          return;
        }
        sessionStorage.setItem(stateKey, JSON.stringify({
          version: 1, pinned: true, open: opened,
          q: searchBox.value.slice(0, 300),
          values: Object.fromEntries(facets.map(f => [f, String(values[f]).slice(0, 80)])),
          allRequested: !!allRequested,
          refineVisible: !facetContainer.hidden
        }));
      } catch (_) { /* Private browsing / storage restrictions. */ }
    }
    function restoreState() {
      try {
        const raw = sessionStorage.getItem(stateKey);
        if (!raw || raw.length > 4096) return false;
        const saved = JSON.parse(raw);
        if (!saved || saved.version !== 1 || saved.pinned !== true) return false;
        pinned = true;
        searchBox.value = typeof saved.q === 'string' ? saved.q.slice(0, 300) : '';
        facets.forEach(f => { values[f] = String((saved.values || {})[f] || '').slice(0, 80); });
        allRequested = !!saved.allRequested;
        facetContainer.hidden = !saved.refineVisible;
        refine.textContent = tr.filters + (facetContainer.hidden ? ' ▾' : ' ▴');
        return !!saved.open;
      } catch (_) { return false; }
    }
    function updatePin() {
      pin.classList.toggle('ofss-pinned', pinned);
      pin.setAttribute('aria-pressed', pinned ? 'true' : 'false');
      pin.setAttribute('aria-label', pinned ? tr.unpin : tr.pin);
      pin.title = pinned ? tr.unpin : tr.pin;
      pin.querySelector('.ofss-pin-label').textContent = pinned ? tr.unpin : tr.pin;
    }
    function hasCriteria() {
      return !!searchBox.value.trim() || Object.keys(values).some(k => values[k] !== '');
    }
    function position() {
      if (!opened) return;
      const rect = launch.getBoundingClientRect();
      const width = Math.min(570, Math.max(300, window.innerWidth - 20));
      const x = Math.min(Math.max(10, rect.left), Math.max(10, window.innerWidth - width - 10));
      popover.style.width = width + 'px';
      popover.style.left = x + 'px';
      popover.style.top = Math.max(8, Math.min(rect.bottom + 8, window.innerHeight - 100)) + 'px';
    }
    function toggle(on, focus = true) {
      opened = on;
      popover.hidden = !on;
      launch.setAttribute('aria-expanded', on ? 'true' : 'false');
      saveState();
      if (on) {
        position();
        if (focus) searchBox.focus();
        search();
      } else {
        if (activeRequest) activeRequest.abort();
        requestSerial++;
      }
    }
    pin.addEventListener('click', () => {
      pinned = !pinned;
      updatePin();
      saveState();
    });
    launch.addEventListener('click', e => { e.preventDefault(); toggle(!opened); });
    close.addEventListener('click', () => toggle(false));
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && opened) toggle(false); });
    document.addEventListener('pointerdown', e => {
      // A pinned search remains available while consulting other project controls.
      if (opened && !pinned && !popover.contains(e.target) && !anchor.contains(e.target)) toggle(false);
    });
    window.addEventListener('resize', position);
    window.addEventListener('scroll', position, true);
    refine.addEventListener('click', () => {
      facetContainer.hidden = !facetContainer.hidden;
      refine.textContent = tr.filters + (facetContainer.hidden ? ' ▾' : ' ▴');
      saveState();
      position();
      // Fetch facet options on demand, but don't list cards without a query.
      if (!facetContainer.hidden && !hasCriteria() && !allRequested) search(true);
    });
    clear.addEventListener('click', () => {
      searchBox.value = '';
      Object.keys(values).forEach(f => { values[f] = ''; });
      allRequested = false;
      saveState();
      search();
      searchBox.focus();
    });
    searchBox.addEventListener('input', () => {
      allRequested = false;
      saveState();
      clearTimeout(debounce);
      debounce = setTimeout(() => search(), 300);
    });
    facets.forEach(f => selects[f].addEventListener('change', () => {
      values[f] = selects[f].value;
      allRequested = false;
      saveState();
      search();
    }));
    viewAll.addEventListener('click', () => {
      allRequested = true;
      saveState();
      search();
    });
    const reopenPinned = restoreState();
    updatePin();
    if (reopenPinned) toggle(true, false);

    function updateFacets(data) {
      facets.forEach(f => {
        const select = selects[f];
        const existingValue = values[f];
        select.replaceChildren();
        const all = elem('option', '', tr.all);
        all.value = ''; select.appendChild(all);
        const rawItems = (data.facets || {})[f] || [];
        const items = f === 'status' ? [
          { value:'1', label:tr.statusOpen, count: Number((rawItems.find(item => String(item.value) === '1') || {}).count || 0) },
          { value:'0', label:tr.statusClosed, count: Number((rawItems.find(item => String(item.value) === '0') || {}).count || 0) }
        ] : rawItems.map(item => (f === 'complexity' && String(item.value) === '0')
            ? {...item, label: tr.complexityUnspecified} : item);
        let found = existingValue === '';
        items.forEach(item => {
          const option = elem('option', '', item.label + ' (' + item.count + ')');
          option.value = String(item.value);
          if (String(item.value) === existingValue) found = true;
          select.appendChild(option);
        });
        if (!found) {
          const option = elem('option', '', existingValue + ' (0)');
          option.value = existingValue;
          select.appendChild(option);
        }
        select.value = existingValue;
      });
    }
    function renderIntro(count) {
      lastTotal = Number(count || 0);
      summary.textContent = lastTotal + ' ' + tr.available;
      intro.hidden = false;
      results.replaceChildren();
      foot.replaceChildren();
      displayed = 0;
    }
    function sourceLabel(source) {
      const base = tr.fields[source.field] || source.field || '';
      return source.filename ? base + ' - ' + source.filename : base;
    }
    function appendHits(hits) {
      hits.forEach(hit => {
        // Keep one card in the results, with a separate expandable source list.
        // Avoid nested interactive elements: details are siblings of the link.
        const item = elem('div', 'ofss-hit');
        const a = elem('a', 'ofss-hit-link');
        a.href = hit.url;
        a.appendChild(elem('div', 'ofss-hit-title', '#' + hit.id + ' · ' + hit.title));
        const meta = [hit.swimlane, hit.column, hit.category].filter(Boolean).join(' · ');
        if (meta) a.appendChild(elem('div', 'ofss-hit-meta', meta));
        const sources = Array.isArray(hit.matches) && hit.matches.length
          ? hit.matches : (hit.match_field && hit.snippet
            ? [{field:hit.match_field,snippet:hit.snippet}] : []);
        const first = sources[0];
        if (first) {
          const d = elem('div', 'ofss-hit-snippet');
          d.appendChild(elem('strong', '', tr.source + ' ' + sourceLabel(first) + ': '));
          d.appendChild(document.createTextNode(first.snippet || ''));
          a.appendChild(d);
        }
        item.appendChild(a);
        const total = Number(hit.match_count || sources.length);
        if (total > 1) {
          const details = elem('details', 'ofss-matches');
          details.appendChild(elem('summary', 'ofss-matches-summary', tr.sources + ' (' + total + ')'));
          const list = elem('div', 'ofss-matches-list');
          sources.forEach(source => {
            const entry = elem('div', 'ofss-match-entry');
            entry.appendChild(elem('strong', '', sourceLabel(source) + ': '));
            entry.appendChild(document.createTextNode(source.snippet || ''));
            list.appendChild(entry);
          });
          if (total > sources.length) {
            list.appendChild(elem('div', 'ofss-matches-overflow', tr.moreSources));
          }
          details.appendChild(list);
          item.appendChild(details);
        }
        results.appendChild(item);
      });
    }
    function showResults(data, append) {
      updateFacets(data);
      const count = Number(data.total || 0);
      const hits = data.hits || [];
      intro.hidden = true;
      summary.textContent = count + ' ' + tr.found;
      if (!append) {
        results.replaceChildren();
        displayed = 0;
      }
      foot.replaceChildren();
      appendHits(hits);
      displayed += hits.length;
      if (!count) results.appendChild(elem('p', 'ofss-empty', tr.empty));
      if (count && (data.has_more || displayed < count)) {
        const load = elem('button', 'ofss-load-more', tr.loadMore);
        load.type = 'button';
        load.addEventListener('click', () => search(false, true));
        foot.appendChild(load);
      }
      if (count) foot.appendChild(elem('span', 'ofss-page-status', tr.showing + ' ' + displayed + ' ' + tr.of + ' ' + count));
      if (data.truncated) foot.appendChild(elem('span', 'ofss-truncated', tr.limited));
    }
    async function search(facetsOnly = false, append = false) {
      if (!opened) return;
      clearTimeout(debounce);
      if (activeRequest) activeRequest.abort();
      const serial = ++requestSerial;
      const abortController = new AbortController();
      activeRequest = abortController;
      const initial = !allRequested && !hasCriteria();
      const mode = facetsOnly ? 'facets' : (initial ? (facetContainer.hidden ? 'summary' : 'facets') : 'results');
      const offset = append ? displayed : 0;
      const params = new URLSearchParams({
        controller:'SmartSearchController', action:'search', plugin:'OrkSearch',
        project_id:anchor.dataset.projectId, q:searchBox.value.trim(), ...values,
        mode, offset:String(offset)
      });
      if (mode === 'results' && !append) {
        intro.hidden = true;
        results.replaceChildren();
        foot.replaceChildren();
        displayed = 0;
      }
      if (!append) summary.textContent = tr.loading;
      else foot.textContent = tr.loading;
      try {
        const response = await fetch(window.location.pathname + '?' + params.toString(), {
          credentials:'same-origin', headers:{'Accept':'application/json'}, signal:abortController.signal
        });
        if (!response.ok) throw new Error('HTTP '+response.status);
        const data = await response.json();
        if (serial !== requestSerial || !opened) return;
        if (mode === 'summary') renderIntro(data.total);
        else if (mode === 'facets') {
          updateFacets(data);
          renderIntro(data.total);
        } else showResults(data, append);
      } catch(err) {
        if (err.name === 'AbortError' || serial !== requestSerial) return;
        summary.textContent = tr.error;
        if (!append) results.replaceChildren();
        foot.replaceChildren();
        if (mode !== 'results') intro.hidden = true;
      }
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready);
  else ready();
})();
