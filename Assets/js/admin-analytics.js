(function () {
  'use strict';
  const root = document.getElementById('analyticsPage');
  if (!root || typeof Chart === 'undefined') return;
  const API = root.dataset.api;
  const legacyViewByTarget = {
    anKpis: 'overview',
    'an-sales': 'collections',
    'an-late': 'late',
    'an-reservations': 'reservations',
    'an-feedback': 'feedback',
    'an-forecast': 'forecast'
  };
  const hasViewQuery = new URLSearchParams(location.search).has('view');
  const legacyView = legacyViewByTarget[location.hash.slice(1)];
  const VIEW = (!hasViewQuery && legacyView) || root.dataset.view || 'overview';
  const VIEW_SECTION = {
    collections: 'an-sales',
    late: 'an-late',
    reservations: 'an-reservations',
    feedback: 'an-feedback',
    forecast: 'an-forecast'
  };
  const $ = (id) => document.getElementById(id);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  // Exact money: centavos appear only when the amount really has them.
  const peso = (n) => {
    const v = Number(n || 0), digits = Number.isInteger(v) ? 0 : 2;
    return '₱' + v.toLocaleString('en-PH', { minimumFractionDigits: digits, maximumFractionDigits: digits });
  };
  // Estimates (forecast averages) should not pretend to centavo precision.
  const pesoWhole = (n) => '₱' + Math.round(Number(n || 0)).toLocaleString('en-PH');
  // Tight spots only: axis ticks and labels on the bars. Tooltips keep the exact amount.
  const pesoShort = (n) => {
    const v = Number(n || 0), a = Math.abs(v);
    if (a >= 1e6) return '₱' + +(v / 1e6).toFixed(2) + 'M';
    if (a >= 1e3) return '₱' + +(v / 1e3).toFixed(1) + 'K';
    return pesoWhole(v);
  };
  const nf = new Intl.NumberFormat('en-PH');
  const C = { g: '#0F6B5A', gSoft: '#8DBDB2', g2: '#15803D', o: '#D97706', b: '#1D4ED8', r: '#C81E3A', s: '#4A5C57', p: '#0B4F42' };
  const CHART_FONT = 'Inter, system-ui, sans-serif';
  const GRID = 'rgba(213,224,221,0.7)', TICK = '#4A5C57';
  const TIP = { backgroundColor: '#0F4C3F', titleColor: '#FFFFFF', bodyColor: '#FFFFFF', padding: 11, cornerRadius: 8, borderColor: 'rgba(255,255,255,0.12)', borderWidth: 1 };
  // Colour follows the entity, so a filter that re-sorts the slices never swaps their colours.
  const METHOD_COLORS = { GCash: C.g, Maya: C.b, 'Manual / Cash': C.s };
  const FACILITY_COLORS = { Court: C.g, 'Tennis Court': C.o, Clubhouse: C.b };
  const OTHER_COLORS = [C.o, C.s, C.p, C.r];
  const colorOf = (label, map) => {
    if (map[label]) return map[label];
    let h = 0;
    for (const ch of String(label)) h = (h + ch.charCodeAt(0)) % OTHER_COLORS.length;
    return OTHER_COLORS[h];
  };
  const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  const parseDay = (s) => { const p = String(s).split('-').map(Number); return new Date(p[0], p[1] - 1, p[2]); };
  const fmtDay = (s) => { const d = parseDay(s); return isNaN(d) ? String(s) : `${MONTHS[d.getMonth()]} ${d.getDate()}, ${d.getFullYear()}`; };
  const state = { data: null, grain: 'month', grainPicked: false, charts: {}, cfgs: {}, loading: false, filters: null };

  // Print settings: width matches a chart inside a 2-column A4 grid
  const PRINT_CHART_W = 310;
  let printTitleBackup = null;
  let printTimer = null;

  Chart.defaults.font.family = CHART_FONT;
  Chart.defaults.color = TICK;
  Chart.defaults.plugins.legend.labels.usePointStyle = true;
  Chart.defaults.plugins.legend.labels.boxWidth = 8;
  Chart.defaults.plugins.legend.labels.boxHeight = 8;
  // Draws a dataset's valueLabels (one string per bar, '' for none) at the tip of each bar.
  Chart.register({
    id: 'valueLabels',
    afterDatasetsDraw(ch) {
      const horizontal = ch.options.indexAxis === 'y', ctx = ch.ctx;
      ch.data.datasets.forEach((ds, di) => {
        if (!ds.valueLabels || !ch.isDatasetVisible(di)) return;
        ctx.save();
        ctx.font = `600 ${ds.valueFontSize || (small() ? 11 : 12)}px ${CHART_FONT}`;
        ctx.fillStyle = '#0F1F1B';
        ch.getDatasetMeta(di).data.forEach((bar, i) => {
          const text = ds.valueLabels[i];
          if (!text) return;
          const w = ctx.measureText(text).width;
          if (horizontal) {
            ctx.textAlign = 'left';
            ctx.textBaseline = 'middle';
            ctx.fillText(text, Math.min(bar.x + 6, ch.width - w - 2), bar.y);
          } else {
            ctx.textAlign = 'center';
            ctx.textBaseline = 'bottom';
            ctx.fillText(text, Math.max(w / 2 + 1, Math.min(bar.x, ch.width - w / 2 - 1)), Math.max(14, bar.y - 4));
          }
        });
        ctx.restore();
      });
    }
  });
  Chart.register({
    id: 'center',
    afterDraw(ch) {
      const o = ch.options.plugins.center, meta = ch.getDatasetMeta(0), m = meta && meta.data[0];
      if (ch.config.type !== 'doughnut' || !o || o.t1 === undefined || !m || !o.t2) return;
      const ctx = ch.ctx;
      // Scale the centre label to the hole so a small phone doughnut does not
      // print the number on top of the caption.
      const inner = m.innerRadius || Math.min(ch.width, ch.height) / 3;
      let t1 = Math.round(Math.max(12, Math.min(20, inner * 0.42)));
      const t2 = Math.round(Math.max(10, Math.min(12, inner * 0.24)));
      const showCaption = inner >= t1 + t2 + 6;
      ctx.save();
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      // Large totals shrink to stay inside the hole.
      ctx.font = `700 ${t1}px ${CHART_FONT}`;
      while (t1 > 11 && ctx.measureText(o.t1).width > inner * 1.7) {
        t1 -= 1;
        ctx.font = `700 ${t1}px ${CHART_FONT}`;
      }
      // c1 / c2 let the print renderer use dark text on white paper.
      ctx.fillStyle = o.c1 || '#0F1F1B';
      ctx.fillText(o.t1, m.x, showCaption ? m.y - t2 / 2 : m.y);
      if (showCaption) {
        ctx.fillStyle = o.c2 || '#4A5C57';
        ctx.font = `500 ${t2}px ${CHART_FONT}`;
        ctx.fillText(o.t2, m.x, m.y + t1 / 2 + 2);
      }
      ctx.restore();
    }
  });

  const grad = (a, b) => (c) => {
    const ca = c.chart.chartArea; if (!ca) return a;
    const g = c.chart.ctx.createLinearGradient(0, ca.top, 0, ca.bottom); g.addColorStop(0, a); g.addColorStop(1, b); return g;
  };
  function mk(id, cfg) {
    if (state.charts[id]) state.charts[id].destroy();
    const el = $(id); if (!el) return;
    cfg.options = Object.assign({ responsive: true, maintainAspectRatio: false }, cfg.options || {});
    state.cfgs[id] = cfg; // kept so the print renderer can redraw it with a print palette
    state.charts[id] = new Chart(el, cfg);
  }
  const hasData = (arr) => (arr || []).some((v) => Number(v) > 0);
  const empty = (id, ok, msg) => {
    const box = $(id) && $(id).parentElement; if (!box) return ok;
    let e = box.querySelector('.an-empty');
    if (!ok && !e) { e = document.createElement('div'); e.className = 'an-empty'; e.textContent = msg || 'No data for these filters.'; box.appendChild(e); }
    if (e) { e.hidden = ok; if (!ok && msg) e.textContent = msg; }
    $(id).style.display = ok ? '' : 'none';
    return ok;
  };
  const delta = (cur, prev, prevLabel) => {
    if (!prev) return `<span class="an-delta flat">No payments in the previous period</span><small>${esc(prevLabel)}</small>`;
    const p = Math.round(((cur - prev) / prev) * 100);
    return `<span class="an-delta ${p > 0 ? 'up' : p < 0 ? 'down' : 'flat'}">${p > 0 ? '▲' : p < 0 ? '▼' : '•'} ${Math.abs(p)}% vs previous period</span><small>${peso(prev)} · ${esc(prevLabel)}</small>`;
  };
  const card = (cls, title, sub, body, extra) => `<article class="an-card ${cls}"><div class="an-head"><div><h3>${title}</h3><p>${sub}</p></div>${extra || ''}</div>${body}</article>`;
  const chartBox = (id, cls, label) => `<div class="an-chart ${cls || ''}"><canvas id="${id}"${label ? ` role="img" aria-label="${esc(label)}"` : ''}></canvas></div>`;
  const plural = (n, word) => `${nf.format(n)} ${word}${n === 1 ? '' : 's'}`;

  /* ======================================================================
     RESPONSIVE CHART DEFAULTS
     Charts keep one config for every screen, so these helpers decide how much
     ink a phone can carry: fewer ticks, thinner lines, shorter category names.
     ====================================================================== */
  const SMALL_MAX = 640;
  const small = () => window.innerWidth <= SMALL_MAX;
  const trimLabel = (s, n) => {
    s = String(s ?? '');
    return s.length > n ? s.slice(0, n - 1).trimEnd() + '…' : s;
  };
  const tickFont = (size) => ({ size: small() ? Math.max(11, size - 1) : size });
  const barRow = { color: TICK, autoSkip: false, font: () => ({ size: small() ? 11 : 12, weight: '500' }) };
  const moneyAxis = (compact, extra) => Object.assign({
    beginAtZero: true, border: { display: false }, grid: { color: GRID, drawTicks: false },
    // One style per axis: full pesos under 10,000, compact above it.
    ticks: { color: TICK, padding: 6, precision: 0, maxTicksLimit: compact ? 4 : 6, font: tickFont(12), callback(v) { return this.max >= 10000 ? pesoShort(v) : pesoWhole(v); } }
  }, extra || {});
  const countAxis = (compact, extra) => Object.assign({
    beginAtZero: true, border: { display: false }, grid: { color: GRID, drawTicks: false },
    ticks: { color: TICK, padding: 6, precision: 0, maxTicksLimit: compact ? 4 : 6, font: tickFont(12) }
  }, extra || {});
  const categoryAxis = (compact, wide, narrow, extra) => Object.assign({
    border: { display: false }, grid: { display: false },
    ticks: { color: TICK, autoSkip: true, maxTicksLimit: compact ? narrow : wide, autoSkipPadding: compact ? 18 : 10, maxRotation: 0, minRotation: 0, font: tickFont(12) }
  }, extra || {});
  // Used when each bar already prints its value, so the axis would only repeat it.
  const hiddenValueAxis = (extra) => Object.assign({ beginAtZero: true, display: false, grid: { display: false } }, extra || {});
  const share = (v, total) => (total > 0 ? Math.round(v / total * 100) : 0);

  // The doughnut's own legend carries each slice's exact value and share, so no number
  // is reachable only through a tooltip (phones have no hover).
  function donut(id, labels, values, colors, center, valueText, tipText) {
    const legend = $(id + 'Legend');
    const total = values.reduce((a, b) => a + b, 0);
    if (legend) {
      legend.innerHTML = labels.map((l, i) => `<li><i style="background:${colors[i]}"></i><span>${esc(l)}</span><b>${esc(valueText(i))}</b><small>${share(values[i], total)}%</small></li>`).join('');
    }
    mk(id, {
      type: 'doughnut',
      data: { labels, datasets: [{ data: values, backgroundColor: colors, borderWidth: 2, borderColor: '#fff', hoverOffset: 4 }] },
      options: {
        cutout: '68%', layout: { padding: 4 },
        plugins: {
          legend: { display: false }, center,
          tooltip: Object.assign({}, TIP, { callbacks: { label: (c) => ` ${c.label}: ${tipText(c.dataIndex)} (${share(values[c.dataIndex], total)}%)` } })
        }
      }
    });
  }
  const clearLegend = (id) => { const l = $(id + 'Legend'); if (l) l.innerHTML = ''; };

  // First/last week or month that the date range covers only partly. These bars are drawn
  // lighter so a short period is not mistaken for a real drop in collections.
  function partialBuckets(grain, n, range, cutOff) {
    const set = new Set();
    if (grain === 'day' || !n) return set;
    const f = parseDay(range.from), t = parseDay(range.to);
    if (grain === 'month') {
      if (new Date(t.getFullYear(), t.getMonth() + 1, 0).getDate() !== t.getDate()) set.add(n - 1);
      if (f.getDate() !== 1) set.add(0);
    } else {
      if (t.getDay() !== 0) set.add(n - 1);
      if (f.getDay() !== 1 && !cutOff) set.add(0);
    }
    return set;
  }

  // Fixed bands, so a shade means the same thing on every street and in every year.
  const HEAT_BANDS = [
    { name: 'Quiet', range: '1', lv: 1, min: 1 },
    { name: 'Moderate', range: '2–3', lv: 3, min: 2 },
    { name: 'Busy', range: '4–5', lv: 4, min: 4 },
    { name: 'Very busy', range: '6+', lv: 5, min: 6 },
  ];
  const heatBand = (n) => HEAT_BANDS.slice().reverse().find((b) => n >= b.min) || null;

  const heatBody = `
    <div class="an-heat-wrap" id="anHeatWrap">
      <p class="an-heat-lead" id="anHeatLead"></p>
      <div class="an-heat-summary" id="anHeatSummary"></div>
      <div class="an-heat" id="anHeat"></div>
      <div class="an-heat-legend">
        <span class="an-heat-legend-group an-heat-scale"><span>Bookings per week:</span>${HEAT_BANDS.map((b) => `<span class="an-heat-key"><i class="an-heat-swatch lv${b.lv}"></i>${b.name} (${b.range})</span>`).join('')}</span>
        <span class="an-heat-legend-group"><i class="an-heat-swatch zero"></i> No bookings</span>
        <span class="an-heat-legend-group"><i class="an-heat-swatch future"></i> Upcoming week</span>
        <span class="an-heat-legend-group"><i class="an-heat-dot"></i> Busiest week</span>
        <span class="an-heat-legend-group"><i class="an-heat-swatch now"></i> This week</span>
      </div>
      <div class="an-heat-tip" id="anHeatTip" role="status" aria-live="polite"></div>
    </div>`;

  function layout() {
    $('anApp').className = '';
    $('anApp').innerHTML = `
      <section class="an-kpis" id="anKpis"></section>
      <section class="an-insights" id="anInsights"></section>

      <section class="an-section" id="an-sales"><header><h2>Collections and Payments</h2><p>Cash received, by day, week or month. The best collection days of the week are highlighted.</p></header>
        <div class="an-grid">
          ${card('w8', 'Collections over time', '<span id="anSalesSub">Payments received in the selected date range</span>', chartBox('cSales', 'tall', 'Bar chart of payments received over time. Exact figures are in the CSV export.'),
            '<div class="an-toggle" id="anGrain"><button type="button" data-g="day">Days</button><button type="button" data-g="week">Weeks</button><button type="button" data-g="month">Months</button></div>')}
          ${card('', 'Payment methods', 'Share of money received', chartBox('cMethods', 'donut', 'Doughnut chart of money received by payment method. Values are listed below it.') + '<ul class="an-legend" id="cMethodsLegend"></ul>')}
          ${card('w6', 'Collections by weekday', 'Total received on each weekday. Orange = highest.', chartBox('cDow', '', 'Bar chart of total payments received on each weekday.'))}
          ${card('w6', 'Latest activity', 'Newest system-wide payments and approved bookings', '<ul class="an-timeline" id="anTimeline"></ul>')}
        </div></section>

      <section class="an-section" id="an-late">
        <header class="an-section-header">
          <div class="an-section-title-wrap">
            <span class="an-section-badge">02</span>
            <div>
              <h2>Who Pays Late</h2>
              <p>A payment is late when it arrives after day <b id="anDue">10</b> of its billing month. Click a name to open the homeowner.</p>
            </div>
          </div>
        </header>
        <div class="an-grid">
          ${card('w6', 'Homeowners with most late payments', 'Ranked by late payments, then average days late', '<ol class="an-leader" id="anLeader"></ol>')}
          ${card('w6', 'How late are payments?', 'Paid billing months, by days after the due day', chartBox('cLateDist', '', 'Bar chart of paid billing months by how many days late they were.'))}
          ${card('w6', 'Late rate per street', 'Highest first. Green under 30%, orange 30–49%, red 50% or more. Late / paid billing months in brackets.', chartBox('cLateStreet', '', 'Bar chart of the late payment rate of each street.'))}
          ${card('w6', 'Unpaid accounts', '<span id="anAgingSub">Months unpaid in a row</span>', chartBox('cAging', '', 'Bar chart of accounts by number of months unpaid in a row.'))}
        </div>
      </section>

      <section class="an-section" id="an-reservations"><header><h2>Reservations</h2><p>Approved bookings per week, with the busiest weeks of the whole year below.</p></header>
        <div class="an-grid">
          ${card('w8', 'Weekly reservations', 'Approved vs pending/rejected requests. The busiest week shows its total.', chartBox('cResWeek', 'tall', 'Stacked bar chart of approved and other reservation requests per week.'))}
          ${card('', 'Facilities', 'Share of approved bookings', chartBox('cResFac', 'donut', 'Doughnut chart of approved bookings by facility. Values are listed below it.') + '<ul class="an-legend" id="cResFacLegend"></ul>')}
          ${card('w12', 'Busy-week heatmap', '<span id="anHeatYear"></span> — each square is one week, grouped by month. The number is the approved bookings that week. Darker green means busier.', heatBody)}
        </div></section>

      <section class="an-section" id="an-feedback"><header><h2>Resident Feedback</h2><p>Compare suggestions, concerns, and incident reports over time, then see how submitted feedback is being handled. Select a bar to see that month's status.</p></header>
        <div class="an-grid">
          ${card('w8', 'Feedback by month', 'Suggestions, concerns, and incident reports submitted in the selected date range', chartBox('cFeedbackTrend', 'tall', 'Monthly suggestions, concerns, and incident reports. Select a bar to see the status for that month.'))}
          ${card('', 'Feedback status', 'Status of feedback submitted in this date range', chartBox('cFeedbackStatus', 'donut', 'Resident feedback counts by new, in review, and resolved status. Values are listed below it.') + '<ul class="an-legend" id="cFeedbackStatusLegend"></ul>')}
        </div></section>

      <section class="an-section" id="an-forecast"><header><h2 id="anFcTitle">Forecast (3-month moving average)</h2><p id="anFcNote"></p></header>
        <div class="an-fc-stats" id="anFcStats"></div>
        <div class="an-grid">
          ${card('w8', 'Money collected and expected', 'Solid green = money already collected. Light orange = our estimate.', chartBox('cFc', 'tall fc', 'Bar chart of money collected in the last 6 full months and the amount expected in the next 3 months.'))}
          ${card('', 'How we got this number', 'We add the last 3 full months, then divide by 3.', '<div id="anFcHow"></div>')}
          ${card('w6', 'How reliable is this estimate?', 'We tested the same method on past months.', '<div id="anFcAcc"></div>')}
          ${card('w6', 'Past estimates vs real collections', 'Each estimate used only the 3 months before it. "Less" means we collected less than expected.', '<div class="table-responsive"><table class="an-table" id="anFcBt"></table></div>')}
        </div></section>`;
    // On phones the tab row scrolls sideways: keep the current section in view.
    const tabs = $('anTabs'), activeTab = tabs && tabs.querySelector('.an-tab.active');
    if (activeTab) tabs.scrollLeft = activeTab.offsetLeft - tabs.offsetLeft - (tabs.clientWidth - activeTab.offsetWidth) / 2;
    $('anKpis').hidden = VIEW !== 'overview';
    $('anInsights').hidden = VIEW !== 'overview';
    document.querySelectorAll('#anApp .an-section').forEach((section) => {
      section.hidden = VIEW_SECTION[VIEW] !== section.id;
    });
    document.querySelectorAll('.nav-sublink[data-an-view]').forEach((link) => {
      const active = link.dataset.anView === VIEW;
      link.classList.toggle('active', active);
      if (active) link.setAttribute('aria-current', 'page');
      else link.removeAttribute('aria-current');
    });
    $('anGrain').addEventListener('click', (e) => {
      const b = e.target.closest('button[data-g]'); if (!b) return;
      state.grain = b.dataset.g; state.grainPicked = true; drawSales(); schedulePrintImages();
    });
    bindHeatTip();
  }

  function kpis(d) {
    const k = d.kpis, b = d.billing, F = d.forecast;
    // The forecast's first point is the current, unfinished month (history stops at the last full month).
    const fcItem = F.ok
      ? [`Forecast for this month · ${esc(F.points[0].label)}`, pesoWhole(F.next1), `<small>Likely ${pesoWhole(F.points[0].low)} – ${pesoWhole(F.points[0].high)}</small>`, C.g]
      : ['Forecast for this month', '—', '<small>Not enough payment history yet</small>', C.g];
    const meter = b.rate === null ? '' : `<div class="an-meter" role="presentation"><i style="width:${Math.min(100, Math.max(0, b.rate))}%"></i></div>`;
    const dueNote = b.due_date_label ? `<small>Includes this month's dues, due on ${esc(b.due_date_label)}</small>` : '';
    // Each card opens the page that shows the details behind its number.
    // Analytics links keep the date range and street the admin picked.
    const f = state.filters || {};
    const viewHref = (view) => {
      const q = new URLSearchParams({ view });
      if (f.range && f.range !== 'year') q.set('range', f.range);
      if (f.range === 'custom') { q.set('from', f.from); q.set('to', f.to); }
      if (f.block) q.set('block', f.block);
      return 'analytics.php?' + q;
    };
    const items = [
      ['Cash collected', peso(k.collected), delta(k.collected, k.previous, d.range.previous_label), C.g, viewHref('collections')],
      [`Collection rate · ${esc(b.as_of)} billing`, b.rate === null ? '—' : b.rate + '%', `${meter}<small>${peso(b.collected)} of ${peso(b.expected)} billed</small>`, C.g, 'payments.php'],
      ['Outstanding dues', peso(b.outstanding), `<small>${plural(b.behind, 'account')} with a balance · ${nf.format(b.delinquent)} unpaid 3+ months in a row</small>${dueNote}`, b.outstanding > 0 ? C.r : C.g, 'homeowners.php?unpaid=1'],
      ['Late payment rate', k.late_rate === null ? '—' : k.late_rate + '%', `<small>Paid billing months settled after day ${d.range.due_day}</small>`, k.late_rate >= 30 ? C.r : C.g, viewHref('late')],
      ['Approved reservations', nf.format(k.reservations), `<small>${peso(k.reservation_revenue)} paid · ${plural(k.reservation_requests, 'request')}</small>`, C.g, 'reservations.php'],
      fcItem.concat(viewHref('forecast'))
    ];
    $('anKpis').innerHTML = items.map((i) => `
      <article class="an-kpi" style="border: 1px solid ${i[3]}; border-left: 5px solid ${i[3]}">
        <div class="title-card"><span class="l" style="color:${i[3]}"><a class="an-kpi-link" href="${esc(i[4])}">${i[0]}</a></span><i class="bi bi-arrow-up-right an-kpi-go" aria-hidden="true"></i></div>
        <div class="card-content">
          <strong>${i[1]}</strong>
          <div class="an-kpi-sub">${i[2]}</div>
        </div>
      </article>`).join('');
  }

  function insights(d) {
    const list = [];
    const dow = d.dow, tot = dow.amounts.reduce((a, b) => a + b, 0), top = dow.amounts.indexOf(Math.max(...dow.amounts));
    if (tot > 0) list.push(['bi-calendar-check', `<b>${dow.labels[top]}</b> brings in the most cash: ${peso(dow.amounts[top])} (${Math.round(dow.amounts[top] / tot * 100)}% of the total).`]);
    const L = d.late.leaders[0];
    if (L) list.push(['bi-person-exclamation', `<b>${esc(L.name)}</b> paid late ${L.late} time${L.late === 1 ? '' : 's'}, about ${L.avg_late} days after the due day.`]);
    const a = d.reservations.approved, pk = a.indexOf(Math.max(...a, 0));
    if (a.length && a[pk] > 0) list.push(['bi-calendar-week', `The busiest reservation week starts <b>${esc(d.reservations.week_labels[pk])}</b> with ${a[pk]} approved booking${a[pk] === 1 ? '' : 's'}.`]);
    const y = d.yoy;
    if (y.ytd_previous > 0) { const p = Math.round((y.ytd - y.ytd_previous) / y.ytd_previous * 100); list.push(['bi-graph-up-arrow', `Jan–${MONTHS[y.ytd_months - 1]} ${y.year} is <b>${p >= 0 ? '+' : ''}${p}%</b> vs the same months of ${y.year - 1}.`]); }
    const F = d.forecast;
    if (F.ok) list.push(['bi-bullseye', `<b>${esc(F.points[0].label)}</b> is forecast at <b>${pesoWhole(F.next1)}</b> (${F.window}-month moving average, about ${F.mape === null ? '—' : F.mape + '%'} average error).`]);
    const feedback = d.feedback;
    if (feedback && feedback.total > 0) {
      list.push(['bi-chat-square-text', `<b>${nf.format(feedback.total)}</b> resident feedback item${feedback.total === 1 ? '' : 's'} in this range · ${plural(feedback.incident_total, 'incident report')} · ${nf.format(feedback.open)} new or in review · ${feedback.resolution_rate}% resolved.`]);
    }
    if (!list.length) list.push(['bi-info-circle', 'No activity in this range yet. Try a wider date range.']);
    $('anInsights').innerHTML = list.map((i) => `<div class="an-insight"><i class="bi ${i[0]}"></i><span>${i[1]}</span></div>`).join('');
  }

  // Builds the sales bar-chart config for a grain ('day' | 'week' | 'month').
  // Each bar is one real total: a curved line would invent values between payment days.
  // Used by the screen chart and by the print renderer.
  function salesCfg(grain, compact) {
    const d = state.data, key = { day: 'days', week: 'weeks', month: 'months' }[grain], s = d.sales[key];
    const labels = grain === 'week' ? s.labels.map((l) => l.replace(/^Wk /, '')) : s.labels;
    const peak = s.values.indexOf(Math.max(...s.values));
    const partial = partialBuckets(grain, s.values.length, d.range, s.cut_off);
    const dense = labels.length > 31;
    return {
      type: 'bar',
      data: { labels, datasets: [{ label: 'Collected', data: s.values,
        backgroundColor: s.values.map((v, i) => i === peak ? C.o : partial.has(i) ? C.gSoft : C.g),
        borderRadius: dense ? 2 : 4,
        categoryPercentage: dense ? 0.92 : 0.8,
        barPercentage: dense ? 0.92 : 0.85,
        maxBarThickness: 28,
        valueLabels: s.values.map((v, i) => i === peak && v > 0 ? pesoShort(v) : '') }] },
      options: { animation: { duration: 350 },
        interaction: { mode: 'index', intersect: false },
        layout: { padding: { top: 6, right: 4 } },
        plugins: { legend: { display: false },
          tooltip: Object.assign({}, TIP, { displayColors: false,
            callbacks: {
              title: (items) => (grain === 'week' ? 'Week of ' : '') + (items[0]?.label || ''),
              label: (c) => '  ' + peso(c.parsed.y),
              afterLabel: (c) => partial.has(c.dataIndex) ? `  Partial: only ${d.range.label} is counted` : ''
            } }) },
        scales: {
          y: moneyAxis(compact, { grace: '12%' }),
          x: categoryAxis(compact, grain === 'day' ? 10 : 12, 5)
        } }
    };
  }

  function drawSales() {
    const d = state.data, key = { day: 'days', week: 'weeks', month: 'months' }[state.grain], s = d.sales[key];
    document.querySelectorAll('#anGrain button').forEach((b) => b.classList.toggle('on', b.dataset.g === state.grain));
    const partial = partialBuckets(state.grain, s.values.length, d.range, s.cut_off).size > 0;
    const shown = s.cut_off
      ? `Only the latest ${s.labels.length} ${state.grain}s of ${d.range.label}. Use ${state.grain === 'day' ? 'Weeks or Months' : 'Months'} to see the whole range.`
      : `Payments received, ${d.range.label}.`;
    $('anSalesSub').textContent = `${shown} Orange = highest.${partial ? ' Lighter = period only partly in range.' : ''}`;
    if (!empty('cSales', hasData(s.values), 'No payments in this range.')) return;
    mk('cSales', salesCfg(state.grain, small()));
  }

  /* ======================================================================
     BUSY-WEEK HEATMAP
     One square per ISO week, grouped by month, showing its booking count.
     Details come from a tooltip on hover / focus / tap.
     ====================================================================== */
  function isoNow() {
    const d = new Date();
    const t = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()));
    const dn = t.getUTCDay() || 7;
    t.setUTCDate(t.getUTCDate() + 4 - dn);
    const ys = new Date(Date.UTC(t.getUTCFullYear(), 0, 1));
    return { y: t.getUTCFullYear(), w: Math.ceil(((t - ys) / 864e5 + 1) / 7) };
  }

  // Month group + readable date range for a week, from the API's "Oct 5" style start date.
  function heatWeekInfo(h, Y) {
    const parts = String(h.start).split(' ');
    const mi = MONTHS.indexOf(parts[0]), day = Number(parts[1]);
    const wrapsBack = h.w === 1 && mi === 11; // ISO week 1 can start in late December
    const yr = wrapsBack ? Y - 1 : Y;
    const s = new Date(yr, mi, day), e = new Date(yr, mi, day + 6);
    const range = s.getMonth() === e.getMonth()
      ? `${MONTHS[s.getMonth()]} ${s.getDate()}–${e.getDate()}`
      : `${MONTHS[s.getMonth()]} ${s.getDate()} – ${MONTHS[e.getMonth()]} ${e.getDate()}`;
    return { group: wrapsBack ? 0 : Math.max(0, mi), range };
  }

  function renderHeat(R) {
    const Y = Number(R.heat_year), now = isoNow();
    const status = (w) => Y > now.y ? 'future' : Y < now.y ? 'past' : w > now.w ? 'future' : w === now.w ? 'now' : 'past';
    const months = MONTHS.map((name) => ({ name, weeks: [], total: 0 }));
    let total = 0, max = 0;
    R.heat.forEach((h) => { const n = Number(h.n) || 0; total += n; if (n > max) max = n; });

    R.heat.forEach((h) => {
      const n = Number(h.n) || 0, info = heatWeekInfo(h, Y), st = status(h.w);
      const lv = n > 0 ? heatBand(n).lv : 0;
      const wk = { w: h.w, n, st, lv, range: info.range };
      months[info.group].weeks.push(wk);
      months[info.group].total += n;
    });

    const allWeeks = months.flatMap((m) => m.weeks);
    const elapsed = allWeeks.filter((w) => w.st !== 'future');
    const activeElapsed = elapsed.filter((w) => w.n > 0).length;

    // ---- Summary strip ----
    // One-sentence takeaway above the squares.
    const booked = allWeeks.filter((w) => w.n > 0);
    let lead = '';
    if (booked.length) {
      const top = HEAT_BANDS.map((b) => ({ b, c: booked.filter((w) => heatBand(w.n) === b).length })).reduce((x, y) => (y.c > x.c ? y : x));
      if (top.c * 2 > booked.length) lead += `Most weeks with bookings were <b>${esc(top.b.name.toLowerCase())}</b> (${top.c} of ${booked.length}). `;
      const peaks = booked.filter((w) => w.n === max);
      lead += peaks.length > 1
        ? `The busiest weeks had <b>${esc(plural(max, 'booking'))}</b> each.`
        : `The busiest was <b>${esc(peaks[0].range)}</b> with ${esc(plural(max, 'booking'))}.`;
    }
    $('anHeatLead').innerHTML = lead;
    $('anHeatLead').hidden = !lead;
    const stats = [['Approved bookings', nf.format(total), `in ${Y}${total && allWeeks.some((w) => w.st === 'future' && w.n > 0) ? ' (incl. upcoming)' : ''}`]];
    if (max > 0) {
      const peakWeeks = allWeeks.filter((w) => w.n === max);
      stats.push(['Busiest week', peakWeeks.length > 1 ? `${peakWeeks.length} weeks tied` : peakWeeks[0].range,
        peakWeeks.length > 1 ? `${plural(max, 'booking')} each · first: ${peakWeeks[0].range}` : plural(max, 'booking')]);
      const topMonth = months.reduce((a, b) => (b.total > a.total ? b : a), months[0]);
      stats.push(['Busiest month', topMonth.name, plural(topMonth.total, 'booking')]);
      const done = months.filter((m) => m.weeks.length && m.weeks.every((w) => w.st === 'past'));
      if (done.length >= 2) {
        const low = done.reduce((a, b) => (b.total < a.total ? b : a), done[0]);
        const high = Math.max(...done.map((m) => m.total));
        if (low.total < high) stats.push(['Quietest full month', low.name, plural(low.total, 'booking')]);
      }
    }
    stats.push(['Weeks with bookings', `${activeElapsed} of ${elapsed.length}`, Y === now.y ? 'weeks so far this year' : 'weeks in the year']);
    $('anHeatSummary').innerHTML = stats.map((s) => `<div class="an-heat-stat"><span>${esc(s[0])}</span><b>${esc(s[1])}</b><small>${esc(s[2])}</small></div>`).join('');

    // ---- Month groups ----
    $('anHeat').innerHTML = months.map((m) => {
      const isCur = m.weeks.some((w) => w.st === 'now');
      const allFuture = m.weeks.every((w) => w.st === 'future');
      const cells = m.weeks.map((w) => {
        const label = `Week ${w.w} · ${w.range} · ${w.n ? `${plural(w.n, 'approved booking')} (${heatBand(w.n).name})` : 'No bookings'}${w.st === 'now' ? ' · This week' : w.st === 'future' ? ' · Upcoming' : ''}`;
        const cls = ['an-heat-cell', w.lv ? 'lv' + w.lv : 'zero', w.st === 'future' ? 'future' : '', w.st === 'now' ? 'now' : '', w.n > 0 && w.n === max ? 'peak' : ''].filter(Boolean).join(' ');
        return `<button type="button" class="${cls}" data-tip="${esc(label)}" aria-label="${esc(label)}">${w.n ? nf.format(w.n) : ''}</button>`;
      }).join('');
      const totalHtml = allFuture && m.total === 0 ? '<b>—</b>' : `<b>${nf.format(m.total)}</b> booking${m.total === 1 ? '' : 's'}`;
      return `<div class="an-heat-month${isCur ? ' cur' : ''}"><div class="an-heat-mlabel">${m.name}${isCur ? '<em>now</em>' : ''}</div><div class="an-heat-weeks">${cells}</div><div class="an-heat-mtotal">${totalHtml}</div></div>`;
    }).join('');
    hideHeatTip();
  }

  function showHeatTip(cell) {
    const tip = $('anHeatTip'); if (!tip || !cell) return;
    tip.textContent = cell.dataset.tip || '';
    const wr = tip.parentElement.getBoundingClientRect(), cr = cell.getBoundingClientRect();
    const w = tip.offsetWidth, h = tip.offsetHeight;
    let x = cr.left - wr.left + cr.width / 2 - w / 2;
    x = Math.max(0, Math.min(x, wr.width - w));
    let y = cr.top - wr.top - h - 10;
    if (y < 0) y = cr.bottom - wr.top + 10; // flip below if there is no room above
    tip.style.left = x + 'px';
    tip.style.top = y + 'px';
    tip.classList.add('show');
  }
  function hideHeatTip() {
    const tip = $('anHeatTip'); if (tip) tip.classList.remove('show');
  }
  function bindHeatTip() {
    const heat = $('anHeat'); if (!heat) return;
    const cellOf = (e) => e.target.closest && e.target.closest('.an-heat-cell');
    heat.addEventListener('mouseover', (e) => { const c = cellOf(e); if (c) showHeatTip(c); });
    heat.addEventListener('mouseout', (e) => { if (cellOf(e)) hideHeatTip(); });
    heat.addEventListener('focusin', (e) => { const c = cellOf(e); if (c) showHeatTip(c); });
    heat.addEventListener('focusout', hideHeatTip);
    heat.addEventListener('click', (e) => { const c = cellOf(e); if (c) showHeatTip(c); }); // tap on phones
    document.addEventListener('click', (e) => { if (!e.target.closest || !e.target.closest('#anHeat')) hideHeatTip(); });
    window.addEventListener('scroll', hideHeatTip, { passive: true });
  }

  /* ======================================================================
     FORECAST (moving average)
     ====================================================================== */
  const grade = (mape) => mape === null ? ['', 'n/a'] : mape < 10 ? ['good', 'Good'] : mape < 20 ? ['fair', 'Fair'] : ['weak', 'Weak'];

  // Last 6 full months next to the next 3 estimates. Every bar prints its own amount,
  // so nobody has to read it off an axis. Phones and paper get horizontal rows.
  function fcCfg(F, horizontal, print) {
    const pts = F.points.slice(0, 3);
    const off = Math.max(0, F.labels.length - 6);
    const months = F.labels.slice(off), act = F.actual.slice(off), n = months.length;
    const blanks = (len) => Array(len).fill(null);
    const fontSize = print ? 10 : 14;
    const valueAxis = hiddenValueAxis({ stacked: true, grace: horizontal ? '35%' : '15%' });
    const monthAxis = { stacked: true, border: { display: false }, grid: { display: false },
      ticks: { color: TICK, autoSkip: false, maxRotation: 0, minRotation: 0, font: { size: fontSize, weight: '500' } } };
    return { type: 'bar',
      data: { labels: months.concat(pts.map((p) => p.label)), datasets: [
        { label: 'Collected', data: act.concat(blanks(pts.length)), backgroundColor: C.g, borderColor: C.g, borderWidth: 2, maxBarThickness: 44, barPercentage: 0.8, categoryPercentage: 0.9,
          valueFontSize: fontSize, valueLabels: act.map((v) => pesoWhole(v)).concat(pts.map(() => '')) },
        { label: 'Expected (estimate)', data: blanks(n).concat(pts.map((p) => p.value)), backgroundColor: 'rgba(217,119,6,.28)', borderColor: C.o, borderWidth: 2, maxBarThickness: 44, barPercentage: 0.8, categoryPercentage: 0.9,
          valueFontSize: fontSize, valueLabels: blanks(n).map(() => '').concat(pts.map((p) => pesoWhole(p.value))) }] },
      options: { indexAxis: horizontal ? 'y' : 'x', animation: { duration: 450 }, interaction: { mode: 'index', intersect: false },
        layout: { padding: { top: 4, right: 4 } },
        plugins: {
          legend: { position: 'top', align: 'start', labels: { padding: 14, font: { size: fontSize } } },
          tooltip: Object.assign({}, TIP, { displayColors: false, titleFont: { size: 15 }, bodyFont: { size: 14 },
            filter: (it) => it.raw !== null,
            callbacks: { label: (c) => {
              if (c.datasetIndex === 0) return `Collected: ${pesoWhole(c.raw)}`;
              const p = pts[c.dataIndex - n];
              return [`Expected: about ${pesoWhole(p.value)}`, `Likely ${pesoWhole(p.low)} to ${pesoWhole(p.high)}`];
            } } }) },
        scales: horizontal ? { x: valueAxis, y: monthAxis } : { x: monthAxis, y: valueAxis } } };
  }

  function renderForecast(d) {
    const peso = pesoWhole; // forecasts and their errors are estimates: whole pesos only
    const F = d.forecast;
    const stats = $('anFcStats'), how = $('anFcHow'), acc = $('anFcAcc'), bt = $('anFcBt');

    if (!F.ok) {
      $('anFcTitle').textContent = 'Forecast (3-month moving average)';
      $('anFcNote').textContent = 'An estimate of how much money will be collected. It follows the Street filter but not the date range.';
      stats.innerHTML = '';
      if (state.charts.cFc) { state.charts.cFc.destroy(); delete state.charts.cFc; delete state.cfgs.cFc; }
      empty('cFc', false, F.reason);
      how.innerHTML = '';
      acc.innerHTML = `<p class="an-fc-note">${esc(F.reason)}</p>`;
      bt.innerHTML = '';
      return;
    }

    const pts = F.points;
    $('anFcTitle').textContent = 'Forecast (3-month moving average)';
    $('anFcNote').textContent = 'An estimate of how much money will be collected, based on the last 3 full months. The current month is not counted because it is not finished yet. This follows the Street filter, not the date range.';

    // ---- The answer first, in one sentence ----
    const gap = pts[0].value - F.last_actual;
    const versus = Math.round(gap) === 0 ? `About the same as ${F.last_month}.`
      : `About ${peso(Math.abs(gap))} ${gap > 0 ? 'more' : 'less'} than ${F.last_month}.`;
    stats.innerHTML = [
      ['hl', `Expected this month · ${pts[0].label}`, peso(pts[0].value), `Likely between ${peso(pts[0].low)} and ${peso(pts[0].high)}. ${versus}`],
      ['', 'Expected in the next 3 months', peso(F.next3), `${pts[0].label} to ${pts[2].label}`],
      ['', `Collected last month · ${F.last_month}`, peso(F.last_actual), 'Actual money received']
    ].map((s) => `<div class="an-fc-stat ${s[0]}"><span>${esc(s[1])}</span><b>${esc(s[2])}</b><small>${esc(s[3])}</small></div>`).join('');

    mk('cFc', fcCfg(F, small()));
    empty('cFc', true);

    // ---- The computation, step by step ----
    const lastMonths = F.labels.slice(-3), lastValues = F.actual.slice(-3);
    how.innerHTML = lastMonths.map((m, i) => `<div class="an-fc-row"><span>${esc(m)}</span><b>${peso(lastValues[i])}</b></div>`).join('') + `
      <div class="an-fc-row total"><span>Total of 3 months</span><b>${peso(lastValues.reduce((a, b) => a + b, 0))}</b></div>
      <div class="an-fc-row"><span>Divide by</span><b>3</b></div>
      <div class="an-fc-row result"><span>Expected for ${esc(pts[0].label)}</span><b>${peso(pts[0].value)}</b></div>
      <p class="an-fc-note">Later months use the same steps, so the further ahead the month, the less sure the estimate.</p>`;

    // ---- How reliable, in plain words ----
    const g = grade(F.mape);
    acc.innerHTML = `
      <div class="an-fc-grade ${g[0]}"><b>${F.mape === null ? 'Not enough data' : g[1]}</b><span>${F.mape === null ? 'There are no past collections to compare yet.' : `Past estimates were off by about ${F.mape}% (${peso(F.mae)}) per month.`}</span></div>
      <div class="an-fc-row"><span>Months checked</span><b>${nf.format(F.backtest.length)}</b></div>
      <p class="an-fc-note">Good: off by less than 10%. Fair: off by 10% to 20%. Weak: off by 20% or more.</p>
      <p class="an-fc-note">This is only an estimate, not a promise. Real collections can change when residents pay late or pay ahead.</p>`;

    // ---- Past estimates vs what really came in ----
    const diffText = (r) => {
      const amt = Math.round(Math.abs(r.error));
      if (amt === 0) return 'Exact';
      return `${peso(amt)} ${r.error > 0 ? 'less' : 'more'}`;
    };
    bt.innerHTML = '<thead><tr><th>Month</th><th>Expected</th><th>Collected</th><th>Difference</th></tr></thead><tbody>' +
      F.backtest.map((r) => `<tr><td>${esc(r.label)}</td><td>${peso(r.forecast)}</td><td>${peso(r.actual)}</td><td>${diffText(r)}</td></tr>`).join('') +
      `<tr class="an-fc-total"><td colspan="3">Average difference per month</td><td>${peso(F.mae)}</td></tr></tbody>`;
  }

  function draw(d) {
    const compact = small();
    clearPrintImages();
    if (VIEW === 'overview') { kpis(d); insights(d); }

    if (VIEW === 'collections') {
    // Long ranges start on a grain that can show all of them, until the admin picks one.
    if (!state.grainPicked) state.grain = d.range.days <= 62 ? 'day' : d.range.days <= 184 ? 'week' : 'month';
    drawSales();
    const m = d.methods;
    if (empty('cMethods', hasData(m.amounts))) {
      donut('cMethods', m.labels, m.amounts, m.labels.map((l) => colorOf(l, METHOD_COLORS)),
        { t1: peso(m.amounts.reduce((a, b) => a + b, 0)), t2: 'received' },
        (i) => peso(m.amounts[i]),
        (i) => `${peso(m.amounts[i])}, ${plural(m.counts[i], 'payment')}`);
    } else clearLegend('cMethods');

    const w = d.dow, wp = w.amounts.indexOf(Math.max(...w.amounts));
    if (empty('cDow', hasData(w.amounts))) mk('cDow', {
      type: 'bar',
      data: {
        labels: w.labels,
        datasets: [{
          data: w.amounts,
          borderRadius: 4,
          maxBarThickness: compact ? 28 : 40,
          backgroundColor: w.amounts.map((v, i) => i === wp ? C.o : C.g),
          valueLabels: w.amounts.map((v) => v > 0 ? pesoShort(v) : '')
        }]
      },
      options: {
        layout: { padding: { top: 8, right: 4 } },
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { display: false },
          tooltip: Object.assign({}, TIP, {
            displayColors: false,
            callbacks: { label: (c) => ` ${peso(c.parsed.y)} · ${plural(w.counts[c.dataIndex], 'payment')}` }
          })
        },
        scales: {
          y: hiddenValueAxis({ grace: '14%' }),
          x: { grid: { display: false }, border: { display: false }, ticks: { autoSkip: false, maxRotation: 0, font: tickFont(12), color: TICK } }
        }
      }
    });

    $('anTimeline').innerHTML = d.timeline.length ? d.timeline.map((t) => `<li style="--d:${t.type === 'payment' ? C.g : C.b}"><b>${esc(t.who)}</b> ${t.type === 'payment' ? 'paid ' + peso(t.amount) : 'booked ' + esc(t.note)}<small>${esc(fmtDay(t.date))}${t.type === 'payment' ? ' · ' + esc(t.note) : ''}</small></li>`).join('') : '<li>No activity yet.</li>';
    }

    if (VIEW === 'late') {
    $('anDue').textContent = d.range.due_day;
    const L = d.late, max = Math.max(1, ...L.leaders.map((x) => x.late)), streaks = d.billing.streaks || {};
    $('anLeader').innerHTML = L.leaders.length ? L.leaders.map((x, i) => `<li><span class="an-rank">${i + 1}</span><span class="an-av">${esc(x.name.charAt(0))}</span>
      <a href="homeowner_view.php?id=${x.id}"><span><b>${esc(x.name)}</b><small>${esc(x.street)} · Lot ${esc(x.lot)} · avg ${x.avg_late}d late, worst ${x.max_late}d</small><div class="an-bar"><i style="width:${x.late / max * 100}%"></i></div></span></a>
      <span class="an-leader-badges"><span class="an-pill">${x.late}/${x.periods} late</span>${streaks[x.id] ? `<span class="an-pill warn">${streaks[x.id]} unpaid now</span>` : ''}</span></li>`).join('')
      : '<li style="display:block;padding:24px;text-align:center;color:#64748b">No late payments in this range.</li>';

    const bucketTotal = L.bucket_values.reduce((a, b) => a + b, 0);
    if (empty('cLateDist', hasData(L.bucket_values))) mk('cLateDist', {
      type: 'bar',
      data: {
        // On phones drop the repeated word "late" ("11–20 days late" -> "11–20 days") so labels stay short.
        labels: compact ? L.bucket_labels.map((l) => l.replace(/ late$/i, '')) : L.bucket_labels,
        datasets: [{
          data: L.bucket_values,
          borderRadius: 4,
          // On time, then one warm ramp that darkens as payments get later.
          backgroundColor: [C.g, '#E9B44C', C.o, '#C2410C', '#9F1239'],
          maxBarThickness: 24,
          valueLabels: L.bucket_values.map((v) => v > 0 ? `${nf.format(v)} · ${share(v, bucketTotal)}%` : '0')
        }]
      },
      options: {
        indexAxis: 'y',
        // Room for the label beside the longest bar.
        layout: { padding: { right: compact ? 84 : 92 } },
        plugins: {
          legend: { display: false },
          tooltip: Object.assign({}, TIP, {
            displayColors: false,
            callbacks: {
              title: (items) => L.bucket_labels[items[0].dataIndex],
              label: (c) => ` ${plural(c.parsed.x, 'paid billing month')} (${share(c.parsed.x, bucketTotal)}%)`
            }
          })
        },
        scales: {
          x: hiddenValueAxis(),
          y: { grid: { display: false }, border: { display: false }, ticks: barRow }
        }
      }
    });

    // Sorted by rate so the street that needs attention is first.
    const streetOrder = L.street_labels.map((l, i) => i).sort((a, b) => L.street_rates[b] - L.street_rates[a] || L.street_late[b] - L.street_late[a]);
    const streetRates = streetOrder.map((i) => L.street_rates[i]);
    if (empty('cLateStreet', L.street_labels.length > 0)) mk('cLateStreet', {
      type: 'bar',
      data: {
        labels: streetOrder.map((i) => trimLabel(L.street_labels[i], compact ? 14 : 24)),
        datasets: [{
          data: streetRates,
          borderRadius: 4,
          backgroundColor: streetRates.map((v) => v >= 50 ? C.r : v >= 30 ? C.o : C.g),
          maxBarThickness: 22,
          valueLabels: streetOrder.map((i) => `${L.street_rates[i]}% (${nf.format(L.street_late[i])}/${nf.format(L.street_paid[i])})`)
        }]
      },
      options: {
        indexAxis: 'y',
        // Room for the label beside a bar that reaches 100%.
        layout: { padding: { right: compact ? 84 : 92 } },
        plugins: {
          legend: { display: false },
          tooltip: Object.assign({}, TIP, {
            displayColors: false,
            callbacks: {
              title: (items) => L.street_labels[streetOrder[items[0].dataIndex]],
              label: (c) => { const i = streetOrder[c.dataIndex]; return ` ${L.street_rates[i]}% late: ${nf.format(L.street_late[i])} of ${plural(L.street_paid[i], 'paid billing month')}`; }
            }
          })
        },
        scales: {
          x: hiddenValueAxis({ max: 100 }),
          y: { grid: { display: false }, border: { display: false }, ticks: barRow }
        }
      }
    });
    // One row per street, so a busy community needs a taller box than 260px.
    // The cap keeps a 40-street chart from turning into a wall of bars.
    const streetBox = $('cLateStreet') && $('cLateStreet').parentElement;
    if (streetBox && L.street_labels.length > 0) {
      const rowPx = compact ? 30 : 34;
      streetBox.style.height = streetBox.style.minHeight = Math.round(Math.min(compact ? 620 : 560, Math.max(110, 44 + L.street_labels.length * rowPx))) + 'px';
    }

    const ag = d.billing.aging;
    $('anAgingSub').textContent = `Accounts by months unpaid in a row, up to ${d.billing.as_of}.`
      + (d.billing.due_date_label ? ` This month's dues are counted, but are only due on ${d.billing.due_date_label}.` : '');
    if (empty('cAging', hasData(ag.counts), 'Everyone is up to date.')) mk('cAging', {
      type: 'bar',
      data: {
        labels: ag.labels,
        datasets: [{
          data: ag.counts,
          borderRadius: 4,
          backgroundColor: ['#E9B44C', C.o, '#C2410C', '#9F1239'],
          maxBarThickness: 40,
          valueLabels: ag.counts.map((v) => nf.format(v))
        }]
      },
      options: {
        layout: { padding: { top: 6 } },
        plugins: {
          legend: { display: false },
          tooltip: Object.assign({}, TIP, {
            displayColors: false,
            callbacks: { title: (items) => `${ag.labels[items[0].dataIndex]} unpaid`, label: (c) => ` ${plural(c.parsed.y, 'account')}` }
          })
        },
        scales: {
          y: hiddenValueAxis({ grace: '14%' }),
          x: { grid: { display: false }, border: { display: false }, ticks: { color: TICK, autoSkip: false, maxRotation: 0, minRotation: 0, font: tickFont(12) } }
        }
      }
    });
    }

    if (VIEW === 'reservations') {
    const R = d.reservations, rp = R.approved.indexOf(Math.max(...R.approved, 0));
    const resTotals = R.approved.map((v, i) => v + R.other[i]), resPeak = R.approved[rp] > 0 ? rp : -1;
    if (empty('cResWeek', hasData(R.approved) || hasData(R.other), 'No reservations in this range.')) mk('cResWeek', { type: 'bar',
      data: { labels: R.week_labels, datasets: [
        { label: 'Approved', data: R.approved, backgroundColor: C.g, borderColor: '#fff', borderWidth: { top: 1 }, maxBarThickness: 28, categoryPercentage: 0.85, barPercentage: 0.9 },
        // The label rides the top dataset so it sits above the whole stack.
        { label: 'Pending / rejected', data: R.other, backgroundColor: '#B8C7C2', borderColor: '#fff', borderWidth: { top: 1 }, maxBarThickness: 28, categoryPercentage: 0.85, barPercentage: 0.9,
          valueLabels: R.other.map((v, i) => i === resPeak ? nf.format(resTotals[i]) : '') }] },
      options: { interaction: { mode: 'index', intersect: false },
        layout: { padding: { top: 6 } },
        plugins: {
          legend: { position: 'bottom', labels: { padding: compact ? 10 : 14, font: tickFont(12) } },
          tooltip: Object.assign({}, TIP, { callbacks: {
            title: (items) => `Week of ${items[0].label}`,
            label: (c) => ` ${c.dataset.label}: ${nf.format(c.parsed.y)}` } })
        },
        scales: {
          x: categoryAxis(compact, 14, 5, { stacked: true }),
          y: countAxis(compact, { stacked: true, grace: '10%' })
        } } });
    if (empty('cResFac', hasData(R.facility_values))) {
      donut('cResFac', R.facility_labels, R.facility_values, R.facility_labels.map((l) => colorOf(l, FACILITY_COLORS)),
        { t1: nf.format(R.facility_values.reduce((a, b) => a + b, 0)), t2: 'bookings' },
        (i) => nf.format(R.facility_values[i]),
        (i) => plural(R.facility_values[i], 'booking'));
    } else clearLegend('cResFac');
    $('anHeatYear').textContent = R.heat_year;
    renderHeat(R);
    }

    if (VIEW === 'feedback') {
    const Fd = d.feedback;
    if (empty('cFeedbackTrend', hasData(Fd.suggestions) || hasData(Fd.concerns) || hasData(Fd.incidents), 'No resident feedback in this range.')) mk('cFeedbackTrend', {
      type: 'bar',
      data: { labels: Fd.labels, datasets: [
        { label: 'Suggestions', data: Fd.suggestions, backgroundColor: C.g, borderColor: '#fff', borderWidth: { top: 1 }, maxBarThickness: 32 },
        { label: 'Concerns', data: Fd.concerns, backgroundColor: C.o, borderColor: '#fff', borderWidth: { top: 1 }, maxBarThickness: 32 },
        { label: 'Incident reports', data: Fd.incidents, backgroundColor: C.r, borderColor: '#fff', borderWidth: { top: 1 }, maxBarThickness: 32 }
      ] },
      options: {
        // Nearest month, not the exact pixel, so a small bar is still easy to tap.
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { position: 'bottom', labels: { padding: compact ? 10 : 14, font: tickFont(12) } },
          tooltip: Object.assign({}, TIP, { callbacks: {
            label: (c) => ` ${c.dataset.label}: ${plural(c.parsed.y, 'item')}`,
            footer: (items) => {
              const s = items.length ? Fd.month_status[items[0].dataIndex] : null;
              return s ? Fd.status_labels.map((l, i) => `${l}: ${nf.format(s[i])}`) : [];
            }
          } })
        },
        scales: {
          x: categoryAxis(compact, 12, 4, { stacked: true }),
          y: countAxis(compact, { stacked: true })
        }
      }
    });
    if (empty('cFeedbackStatus', Fd.status_values.some((v) => v > 0), 'No resident feedback in this range.')) {
      donut('cFeedbackStatus', Fd.status_labels, Fd.status_values, [C.b, C.o, C.g],
        { t1: nf.format(Fd.total), t2: 'items' },
        (i) => nf.format(Fd.status_values[i]),
        (i) => plural(Fd.status_values[i], 'item'));
    } else clearLegend('cFeedbackStatus');
    }

    if (VIEW === 'forecast') renderForecast(d);

    $('anUpdatedText').textContent = 'Updated ' + new Date(d.updated).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' });

    schedulePrintImages();
  }

  /* ======================================================================
     PRINT / SAVE PDF
     Charts are canvas elements and print blurry or cut off when resized by
     the browser. Before printing, each chart is redrawn OFF-SCREEN with a
     print palette (dark lines/text on white, visible gridlines) at print
     size and 3x resolution into an <img>; analytics-print.css shows the
     image instead of the canvas. The on-screen dark charts are never
     modified, so nothing flickers.
     ====================================================================== */
  // Dark-theme chart colours → print-safe equivalents (single pass, no chaining).
  const PRINT_COLORS = {
    '#a5b4fc': '#4338ca', '#818cf8': '#4f46e5',
    '#10b981': '#047857', '#34d399': '#059669',
    '#f59e0b': '#d97706', '#d97706': '#b45309',
    '#facc15': '#ca8a04', '#f97316': '#c2410c',
    '#ef4444': '#b91c1c', '#94a3b8': '#475569', '#cbd5e1': '#94a3b8',
    'rgba(255,255,255,0.06)': '#e2e8f0',
    'rgba(148,163,184,.85)': '#64748b',
    'rgba(165,180,252,.28)': 'rgba(67,56,202,.10)',
    'rgba(165,180,252,.30)': 'rgba(67,56,202,.10)'
  };
  const printColor = (c) => (typeof c === 'string' ? (PRINT_COLORS[c.replace(/\s+/g, '').toLowerCase()] || c) : c);

  // Deep-copies a chart config, swapping colours. Callbacks are kept as they are;
  // the gradient functions used for area fills become a flat translucent fill.
  function themeClone(v, key) {
    if (typeof v === 'function') return key === 'backgroundColor' ? 'rgba(15,107,90,.10)' : v;
    if (Array.isArray(v)) return v.map((x) => themeClone(x, key));
    if (v && typeof v === 'object') {
      const o = {};
      Object.keys(v).forEach((k) => { o[k] = themeClone(v[k], k); });
      return o;
    }
    return printColor(v);
  }

  function chartToImage(cfg, w, h) {
    const holder = document.createElement('div');
    holder.style.cssText = `position:fixed;left:-10000px;top:0;width:${w}px;height:${h}px;pointer-events:none;`;
    const canvas = document.createElement('canvas');
    holder.appendChild(canvas);
    document.body.appendChild(holder);
    let chart = null, url = '';
    try {
      const opts = themeClone(cfg.options || {});
      opts.responsive = true;
      opts.maintainAspectRatio = false;
      opts.animation = false;
      opts.devicePixelRatio = 3;
      opts.color = '#334155';
      opts.plugins = opts.plugins || {};
      opts.plugins.tooltip = Object.assign({}, opts.plugins.tooltip, { enabled: false });
      if (cfg.type === 'doughnut') {
        opts.plugins.center = Object.assign({}, opts.plugins.center, { c1: '#0f172a', c2: '#475569' });
      }
      Object.values(opts.scales || {}).forEach((s) => { s.ticks = Object.assign({ color: '#475569' }, s.ticks); });
      chart = new Chart(canvas, { type: cfg.type, data: themeClone(cfg.data), options: opts });
      chart.resize(w, h);
      url = chart.toBase64Image('image/png', 1);
    } catch (e) {
      url = '';
    } finally {
      if (chart) { try { chart.destroy(); } catch (e) { /* ignore */ } }
      holder.remove();
    }
    return url;
  }

  const longRange = (r) => !!r && (new Date(r.to) - new Date(r.from)) / 864e5 > 62;

  function clearPrintImages() {
    document.querySelectorAll('.an-print-img').forEach((img) => {
      const box = img.parentElement;
      img.remove();
      if (box) box.classList.remove('an-has-img');
    });
  }

  function preparePrintImages() {
    if (!state.data) return;
    clearPrintImages();
    Object.keys(state.charts).forEach((id) => {
      const chart = state.charts[id];
      let cfg = state.cfgs[id];
      const canvas = chart && chart.canvas;
      if (!cfg || !canvas || !canvas.isConnected || canvas.style.display === 'none') return;
      const box = canvas.parentElement;
      if (!box) return;
      // A year of daily points only shows the last ~60 days. On paper, long
      // ranges read better as monthly totals, so print those instead.
      if (id === 'cSales' && state.grain === 'day' && longRange(state.data.range) && hasData(state.data.sales.months.values)) {
        cfg = salesCfg('month', false);
      }
      // Full peso labels need rows on paper; a narrow print column would overlap them.
      if (id === 'cFc' && state.data.forecast.ok) cfg = fcCfg(state.data.forecast, true, true);
      const h = box.classList.contains('short') ? 190 : box.classList.contains('tall') ? 230 : 210;
      const url = chartToImage(cfg, PRINT_CHART_W, h);
      if (!url) return;
      const img = new Image();
      img.className = 'an-print-img';
      img.alt = '';
      img.style.display = 'none'; // shown only by analytics-print.css
      img.src = url;
      box.appendChild(img);
      box.classList.add('an-has-img');
    });
  }

  function decodePrintImages() {
    const imgs = Array.from(document.querySelectorAll('.an-print-img'));
    return Promise.all(imgs.map((img) => (img.decode ? img.decode().catch(() => {}) : Promise.resolve())));
  }

  // Pre-render shortly after charts finish animating so Ctrl+P also works.
  function schedulePrintImages() {
    clearTimeout(printTimer);
    printTimer = setTimeout(() => { preparePrintImages(); decodePrintImages(); }, 1300);
  }

  function applyPrintTitle() {
    if (!state.data || printTitleBackup !== null) return;
    printTitleBackup = document.title;
    document.title = 'Analytics Report ' + state.data.range.from + ' to ' + state.data.range.to;
  }

  function restoreTitle() {
    if (printTitleBackup === null) return;
    document.title = printTitleBackup;
    printTitleBackup = null;
  }

  async function printReport() {
    if (!state.data || $('anPrint').disabled) return;
    clearTimeout(printTimer);
    preparePrintImages();
    await decodePrintImages();
    applyPrintTitle();
    window.print();
  }

  window.addEventListener('beforeprint', () => {
    if (!state.data) return;
    if (!document.querySelector('.an-print-img')) preparePrintImages();
    applyPrintTitle();
  });
  window.addEventListener('afterprint', restoreTitle);

  /* ======================================================================
     LOAD DATA
     ====================================================================== */
  async function load() {
    const q = new URLSearchParams({ range: $('anRange').value });
    if ($('anRange').value === 'custom') { q.set('from', $('anFrom').value); q.set('to', $('anTo').value); }
    if ($('anBlock').value) q.set('block', $('anBlock').value);
    state.loading = true;
    syncActionButtons();
    $('anError').hidden = true; $('anUpdatedText').textContent = 'Loading…';
    try {
      const res = await fetch(API + '?' + q, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      const json = await res.json();
      if (!res.ok || json.error) throw new Error(json.error || 'Request failed');
      state.data = json;
      state.filters = {
        range: $('anRange').value,
        from: $('anFrom').value,
        to: $('anTo').value,
        block: $('anBlock').value
      };
      $('anPrintMeta').textContent = 'Report period: ' + json.range.from + ' to ' + json.range.to
        + ' · Street: ' + (state.filters.block || 'All streets')
        + ' · Generated: ' + new Date().toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' });
      const firstRender = !$('anKpis');
      if (firstRender) layout();
      // Chart.js measures axis labels once; a late-loading font would make them overflow and get cut off.
      if (document.fonts && document.fonts.load) {
        await Promise.race([
          Promise.all(['500 12px Inter', '600 12px Inter', '700 20px Inter'].map((f) => document.fonts.load(f))).catch(() => {}),
          new Promise((resolve) => setTimeout(resolve, 1500))
        ]);
      }
      draw(json);
      // Opened from the sidebar submenu (analytics.php#an-late): jump to that section once it exists.
      if (firstRender && location.hash) {
        const target = document.getElementById(decodeURIComponent(location.hash.slice(1)));
        if (target) setTimeout(() => target.scrollIntoView({ behavior: 'smooth', block: 'start' }), 150);
      }
      state.loading = false;
      syncActionButtons();
    } catch (e) {
      state.data = null;
      state.filters = null;
      state.loading = false;
      syncActionButtons();
      $('anError').hidden = false; $('anUpdatedText').textContent = '';
      $('anErrorText').textContent = e.message || 'We couldn’t load analytics right now.';
    }
  }

  function syncActionButtons() {
    const f = state.filters;
    const filtersMatch = !!f && f.range === $('anRange').value && f.block === $('anBlock').value
      && (f.range !== 'custom' || (f.from === $('anFrom').value && f.to === $('anTo').value));
    const ready = !!state.data && !state.loading && filtersMatch;
    $('anCsv').disabled = !ready;
    $('anPrint').disabled = !ready;
    const reason = state.loading ? 'Wait for analytics to finish loading.'
      : !filtersMatch ? 'Apply the changed filters before using this action.'
      : !state.data ? 'Analytics are unavailable until the report loads.' : '';
    $('anCsv').title = ready ? 'Export the current analytics as a CSV file' : reason;
    $('anPrint').title = ready ? 'Print this analytics report or save it as a PDF' : reason;
  }

  /* ======================================================================
     EXPORT CSV
     ====================================================================== */
  function exportCsv() {
    const d = state.data, f = state.filters;
    if (!d || !f) return;
    const rows = [['Section', 'Label', 'Value']];
    const add = (section, labels, values) => (labels || []).forEach((label, i) => rows.push([section, label, values && values[i] !== undefined ? values[i] : '']));

    rows.push(['Report filters', 'Date range', d.range.from + ' to ' + d.range.to]);
    rows.push(['Report filters', 'Street', f.block || 'All streets']);
    rows.push(['Report filters', 'Generated', new Date().toLocaleString('en-PH')]);

    rows.push(['Summary', 'Cash collected', d.kpis.collected]);
    rows.push(['Summary', 'Cash collected in previous period', d.kpis.previous]);
    rows.push(['Summary', 'Payment transactions', d.kpis.payments]);
    rows.push(['Summary', 'Collection rate', d.billing.rate === null ? '' : d.billing.rate + '%']);
    rows.push(['Summary', 'Billed for ' + d.billing.as_of, d.billing.expected]);
    rows.push(['Summary', 'Collected for ' + d.billing.as_of, d.billing.collected]);
    rows.push(['Summary', 'Outstanding dues', d.billing.outstanding]);
    rows.push(['Summary', 'Accounts behind', d.billing.behind]);
    rows.push(['Summary', 'Accounts 3+ months behind', d.billing.delinquent]);
    rows.push(['Summary', 'Late payment rate', d.kpis.late_rate === null ? '' : d.kpis.late_rate + '%']);
    rows.push(['Summary', 'Approved reservations', d.kpis.reservations]);
    rows.push(['Summary', 'Approved reservation payments', d.kpis.reservation_revenue]);
    rows.push(['Summary', 'Reservation requests', d.kpis.reservation_requests]);

    [['day', 'days', 'Cash collected by day'], ['week', 'weeks', 'Cash collected by week'], ['month', 'months', 'Cash collected by month']].forEach((g) => {
      const s = d.sales && d.sales[g[1]];
      if (s) add(g[2] + (s.cut_off ? ' (latest ' + s.labels.length + ' ' + g[0] + 's only)' : ''), s.labels, s.values);
    });
    add('Collected by weekday', d.dow.labels, d.dow.amounts);
    add('Payment methods · transaction count', d.methods.labels, d.methods.counts);
    add('Payment methods · amount received', d.methods.labels, d.methods.amounts);

    d.late.leaders.forEach((x) => rows.push(['Late payers', x.name + ' (' + x.street + ' Lot ' + x.lot + ')', x.late + ' late of ' + x.periods + ', average ' + x.avg_late + ' days, worst ' + x.max_late + ' days']));
    add('Late payment distribution', d.late.bucket_labels, d.late.bucket_values);
    add('Late rate by street', d.late.street_labels, d.late.street_rates.map((v, i) => v + '% (' + d.late.street_late[i] + ' of ' + d.late.street_paid[i] + ' paid billing months)'));
    add('Current unpaid accounts', d.billing.aging.labels, d.billing.aging.counts);

    d.reservations.week_labels.forEach((l, i) => {
      rows.push(['Reservations per week · approved', l, d.reservations.approved[i]]);
      rows.push(['Reservations per week · pending or rejected', l, d.reservations.other[i]]);
    });
    add('Approved reservations by facility', d.reservations.facility_labels, d.reservations.facility_values);
    add('Reservation activity · ISO week ' + d.reservations.heat_year, d.reservations.heat.map((x) => 'Week ' + x.w + ' (' + x.start + ')'), d.reservations.heat.map((x) => x.n));

    rows.push(['Summary', 'Resident feedback submitted', d.feedback.total]);
    rows.push(['Summary', 'Incident reports submitted', d.feedback.incident_total]);
    rows.push(['Summary', 'Feedback new or in review', d.feedback.open]);
    rows.push(['Summary', 'Feedback resolution rate', d.feedback.resolution_rate === null ? '' : d.feedback.resolution_rate + '%']);
    add('Resident feedback · suggestions', d.feedback.labels, d.feedback.suggestions);
    add('Resident feedback · concerns', d.feedback.labels, d.feedback.concerns);
    add('Resident feedback · incident reports', d.feedback.labels, d.feedback.incidents);
    add('Resident feedback · status', d.feedback.status_labels, d.feedback.status_values);

    (d.timeline || []).forEach((x) => rows.push(['Latest system activity (not filtered) · ' + x.type, x.date + ' · ' + x.who, x.type === 'payment' ? x.amount + ' · ' + x.note : x.note + ' · ' + x.amount]));

    rows.push(['Year comparison', d.yoy.year + ' finished months (' + d.yoy.ytd_months + ')', d.yoy.ytd]);
    rows.push(['Year comparison', (d.yoy.year - 1) + ' same months', d.yoy.ytd_previous]);

    const F = d.forecast;
    if (!F.ok) {
      rows.push(['Forecast', 'Status', F.reason]);
    } else {
      rows.push(['Forecast model', 'Method', F.window + '-month moving average']);
      rows.push(['Forecast model', 'Completed months used', F.history_months + ' (' + F.first_month + ' to ' + F.last_month + ')']);
      rows.push(['Forecast model', 'Average error (MAPE)', F.mape === null ? '' : F.mape + '%']);
      rows.push(['Forecast model', 'Average error (MAE, pesos)', F.mae]);
      F.points.forEach((p) => {
        rows.push(['Forecast · value', p.label, p.value]);
        rows.push(['Forecast · low', p.label, p.low]);
        rows.push(['Forecast · high', p.label, p.high]);
      });
      rows.push(['Forecast totals', 'Next 3 months', F.next3]);
      rows.push(['Forecast totals', 'Next 6 months', F.next6]);
      F.backtest.forEach((r) => rows.push(['Forecast backtest · ' + r.label, 'forecast vs actual', 'forecast ' + r.forecast + ', actual ' + r.actual + ', error ' + r.error + (r.pct === null ? '' : ' (' + r.pct + '%)')]));
      add('Monthly collections · actual', F.labels, F.actual);
    }

    // Quote every cell; neutralise spreadsheet formulas in text (not real numbers).
    const safe = (v) => {
      v = String(v ?? '');
      if (/^[=+\-@\t\r]/.test(v) && isNaN(Number(v))) v = "'" + v;
      return '"' + v.replace(/"/g, '""') + '"';
    };
    const csv = '\ufeff' + rows.map((r) => r.map(safe).join(',')).join('\r\n');
    const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
    const a = document.createElement('a');
    a.href = url;
    a.download = 'analytics-' + d.range.from + '_to_' + d.range.to + (f.block ? '-' + f.block.toLowerCase().replace(/[^a-z0-9]+/g, '-') : '') + '.csv';
    a.style.display = 'none';
    document.body.appendChild(a); // needed for Firefox
    a.click();
    setTimeout(() => { a.remove(); URL.revokeObjectURL(url); }, 1000);
  }

  /* ======================================================================
     EVENTS
     ====================================================================== */
  $('anRange').addEventListener('change', () => {
    document.querySelectorAll('.an-custom').forEach((el) => { el.hidden = $('anRange').value !== 'custom'; });
    loadWhenFiltersAreValid();
  });
  ['anFrom', 'anTo', 'anBlock'].forEach((id) => $(id).addEventListener('change', loadWhenFiltersAreValid));
  $('anFilters').addEventListener('submit', (e) => { e.preventDefault(); load(); });
  $('anReset').addEventListener('click', () => {
    $('anRange').value = 'year';
    $('anFrom').value = '';
    $('anTo').value = '';
    $('anBlock').value = '';
    state.grainPicked = false;
    document.querySelectorAll('.an-custom').forEach((el) => { el.hidden = true; });
    load();
  });
  $('anRetry').addEventListener('click', load);
  $('anPrint').addEventListener('click', printReport);
  $('anCsv').addEventListener('click', exportCsv);

  // Rotating a phone or resizing the window changes how many tick labels fit;
  // redraw once the new width settles (but only when the breakpoint flips).
  let wasCompact = small(), resizeTimer = null;
  window.addEventListener('resize', () => {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(() => {
      if (!state.data || small() === wasCompact) return;
      wasCompact = small();
      draw(state.data);
    }, 220);
  });

  load();

  function loadWhenFiltersAreValid() {
    if ($('anRange').value === 'custom') {
      const from = $('anFrom').value, to = $('anTo').value;
      if (!from || !to || from > to) {
        syncActionButtons();
        return;
      }
    }
    load();
  }
})();
