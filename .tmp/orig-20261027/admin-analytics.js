(function () {
  'use strict';
  const root = document.getElementById('analyticsPage');
  if (!root || typeof Chart === 'undefined') return;
  const API = root.dataset.api;
  const $ = (id) => document.getElementById(id);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const peso = (n) => '₱' + Number(n || 0).toLocaleString('en-PH', { maximumFractionDigits: 0 });
  const nf = new Intl.NumberFormat('en-PH');
  const C = { g: '#10b981', g2: '#34d399', o: '#f59e0b', b: '#818cf8', r: '#ef4444', s: '#94a3b8', p: '#a5b4fc' };
  const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  const state = { data: null, grain: 'day', charts: {}, loading: false, filters: null };

  // Print settings: width matches a chart inside a 2-column A4 grid
  const PRINT_CHART_W = 310;
  let printTitleBackup = null;
  let printTimer = null;

  Chart.defaults.font.family = 'Poppins, system-ui, sans-serif';
  Chart.defaults.color = '#94a3b8';
  Chart.defaults.plugins.legend.labels.usePointStyle = true;
  Chart.defaults.plugins.legend.labels.boxWidth = 8;
  Chart.register({
    id: 'center',
    afterDraw(ch) {
      const o = ch.options.plugins.center, m = ch.getDatasetMeta(0).data[0];
      if (ch.config.type !== 'doughnut' || !o || o.t1 === undefined || !m) return;
      const ctx = ch.ctx; ctx.save(); ctx.textAlign = 'center';
      ctx.fillStyle = '#ffffff'; ctx.font = '700 18px Poppins, sans-serif'; ctx.fillText(o.t1, m.x, m.y);
      ctx.fillStyle = '#94a3b8'; ctx.font = '500 11px Poppins, sans-serif'; ctx.fillText(o.t2, m.x, m.y + 16);
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
  const delta = (cur, prev) => {
    if (!prev) return '<span class="an-delta flat">No baseline</span>';
    const p = Math.round(((cur - prev) / prev) * 100);
    return `<span class="an-delta ${p > 0 ? 'up' : p < 0 ? 'down' : 'flat'}">${p > 0 ? '▲' : p < 0 ? '▼' : '•'} ${Math.abs(p)}% vs previous period</span>`;
  };
  const card = (cls, title, sub, body, extra) => `<article class="an-card ${cls}"><div class="an-head"><div><h3>${title}</h3><p>${sub}</p></div>${extra || ''}</div>${body}</article>`;
  const chartBox = (id, cls) => `<div class="an-chart ${cls || ''}"><canvas id="${id}"></canvas></div>`;
  const plural = (n, word) => `${nf.format(n)} ${word}${n === 1 ? '' : 's'}`;

  const heatBody = `
    <div class="an-heat-wrap" id="anHeatWrap">
      <div class="an-heat-summary" id="anHeatSummary"></div>
      <div class="an-heat" id="anHeat"></div>
      <div class="an-heat-legend">
        <span class="an-heat-legend-group">Less
          <i class="an-heat-swatch lv1"></i><i class="an-heat-swatch lv2"></i><i class="an-heat-swatch lv3"></i><i class="an-heat-swatch lv4"></i><i class="an-heat-swatch lv5"></i>
          More</span>
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

      <section class="an-section"><header><h2>1 · Sales &amp; payments</h2><p>Cash received, by day, week or month. The best collection days of the week are highlighted.</p></header>
        <div class="an-grid">
          ${card('w8', 'Collections over time', 'Payments received in the selected date range', chartBox('cSales', 'tall'),
            '<div class="an-toggle" id="anGrain"><button data-g="day" class="on">Days</button><button data-g="week">Weeks</button><button data-g="month">Months</button></div>')}
          ${card('', 'Payment methods', 'Share of money received', chartBox('cMethods', 'tall'))}
          ${card('w6', 'Collections by weekday', 'Total payments received on each weekday', chartBox('cDow'))}
          ${card('w6', 'Latest activity', 'Newest system-wide payments and approved bookings', '<ul class="an-timeline" id="anTimeline"></ul>')}
        </div></section>

      <section class="an-section">
        <header class="an-section-header">
          <div class="an-section-title-wrap">
            <span class="an-section-badge">02</span>
            <div>
              <h2>Who pays late</h2>
              <p>A payment is late when it arrives after day <b id="anDue">10</b> of its billing month. Click a name to open the homeowner.</p>
            </div>
          </div>
        </header>
        <div class="an-grid">
          ${card('w6', 'Homeowners with most late payments', 'Ranked by late payments, then average days late', '<ol class="an-leader" id="anLeader"></ol>')}
          ${card('w6', 'How late are payments?', 'Days after the due day', chartBox('cLateDist'))}
          ${card('w4', 'Late rate per street', '% of payments made late', chartBox('cLateStreet'))}
          ${card('w4', 'Unpaid accounts', 'Still behind at the end of this range', chartBox('cAging', 'short'))}
        </div>
      </section>

      <section class="an-section"><header><h2>3 · Reservations by week</h2><p>Approved bookings per week, with the busiest weeks of the whole year below.</p></header>
        <div class="an-grid">
          ${card('w8', 'Weekly reservations', 'Approved vs pending/rejected requests', chartBox('cResWeek', 'tall'))}
          ${card('', 'Facilities', 'Share of approved bookings', chartBox('cResFac', 'tall'))}
          ${card('w12', 'Busy-week heatmap', '<span id="anHeatYear"></span> — each square is one week, grouped by month. Darker green = more approved bookings. Hover or tap a square for details.', heatBody)}
        </div></section>

      <section class="an-section"><header><h2 id="anFcTitle">4 · Forecast (moving average)</h2><p id="anFcNote"></p></header>
        <div class="an-fc-stats" id="anFcStats"></div>
        <div class="an-grid">
          ${card('w8', 'Monthly collections: actual vs forecast', 'Solid = real monthly totals · thin line = what the model would have predicted · dashed = forecast with its likely range', chartBox('cFc', 'tall'))}
          ${card('', 'Model accuracy', 'How well the model predicted months it had not seen', '<div id="anFcAcc"></div>')}
          ${card('w6', 'Backtest: forecast vs what really happened', 'Each month is predicted using only the months before it', '<div class="table-responsive"><table class="an-table" id="anFcBt"></table></div>')}
          ${card('w6', 'Window comparison &amp; method', 'The window with the lower average % error is used', '<div id="anFcWin"></div>')}
        </div></section>`;
    $('anGrain').addEventListener('click', (e) => {
      const b = e.target.closest('button[data-g]'); if (!b) return;
      state.grain = b.dataset.g; drawSales(); schedulePrintImages();
    });
    bindHeatTip();
  }

  function kpis(d) {
    const k = d.kpis, b = d.billing, F = d.forecast;
    const fcItem = F.ok
      ? [`Next month forecast · ${esc(F.points[0].label)}`, peso(F.next1), `<small>Likely ${peso(F.points[0].low)} – ${peso(F.points[0].high)}</small>`]
      : ['Next month forecast', '—', '<small>Not enough payment history yet</small>'];
    const items = [
      ['Cash collected', peso(k.collected), delta(k.collected, k.previous)],
      [`Collection rate · ${esc(b.as_of)}`, b.rate === null ? '—' : b.rate + '%', `<small>${peso(b.collected)} of ${peso(b.expected)} billed</small>`],
      ['Outstanding dues', peso(b.outstanding), `<small>${nf.format(b.behind)} accounts behind · ${nf.format(b.delinquent)} are 3+ months</small>`],
      ['Late payment rate', k.late_rate === null ? '—' : k.late_rate + '%', `<small>Paid after day ${d.range.due_day}</small>`],
      ['Approved reservations', nf.format(k.reservations), `<small>${peso(k.reservation_revenue)} paid · ${nf.format(k.reservation_requests)} requests</small>`],
      fcItem
    ];
    $('anKpis').innerHTML = items.map((i) => `
      <article class="an-kpi">
        <div class="title-card"><span class="l">${i[0]}</span></div>
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
    if (y.ytd_previous > 0) { const p = Math.round((y.ytd - y.ytd_previous) / y.ytd_previous * 100); list.push(['bi-graph-up-arrow', `${y.year} is <b>${p >= 0 ? '+' : ''}${p}%</b> vs the same months of ${y.year - 1}.`]); }
    const F = d.forecast;
    if (F.ok) list.push(['bi-bullseye', `<b>${esc(F.points[0].label)}</b> is forecast at <b>${peso(F.next1)}</b> (${F.window}-month moving average, about ${F.mape === null ? '—' : F.mape + '%'} average error).`]);
    if (!list.length) list.push(['bi-info-circle', 'No activity in this range yet. Try a wider date range.']);
    $('anInsights').innerHTML = list.map((i) => `<div class="an-insight"><i class="bi ${i[0]}"></i><span>${i[1]}</span></div>`).join('');
  }

  function drawSales() {
    const d = state.data, key = { day: 'days', week: 'weeks', month: 'months' }[state.grain], s = d.sales[key];
    const isDay = state.grain === 'day';
    document.querySelectorAll('#anGrain button').forEach((b) => b.classList.toggle('on', b.dataset.g === state.grain));
    if (!empty('cSales', hasData(s.values), 'No payments in this range.')) return;
    const peak = s.values.indexOf(Math.max(...s.values));
    mk('cSales', {
      type: 'line',
      data: { labels: s.labels, datasets: [{ label: 'Collected', data: s.values,
        borderColor: '#a5b4fc', borderWidth: 3,
        tension: .4, fill: true,
        backgroundColor: grad('rgba(165,180,252,.30)', 'rgba(165,180,252,0)'),
        pointRadius: s.values.map((v, i) => i === peak && v > 0 ? 6 : (isDay ? 3 : 5)),
        pointHoverRadius: 7,
        pointBackgroundColor: s.values.map((v, i) => i === peak && v > 0 ? '#f59e0b' : '#a5b4fc'),
        pointBorderColor: s.values.map((v, i) => i === peak && v > 0 ? '#d97706' : '#818cf8'),
        pointBorderWidth: 2 }] },
      options: { animation: { duration: 450 },
        interaction: { mode: 'index', intersect: false },
        plugins: { legend: { display: false },
          tooltip: { displayColors: false, backgroundColor: '#070c18', padding: 11, cornerRadius: 8, borderColor: 'rgba(255,255,255,0.12)', borderWidth: 1,
            callbacks: { title: (items) => items[0]?.label || '', label: (c) => '  ' + peso(c.parsed.y) } } },
        scales: {
          y: { beginAtZero: true, border: { display: false }, grid: { color: 'rgba(255,255,255,0.06)', drawTicks: false },
            ticks: { color: '#94a3b8', padding: 8, maxTicksLimit: 6, callback: (v) => peso(v) } },
          x: { border: { display: false }, grid: { display: false },
            ticks: isDay
              ? { color: '#94a3b8', autoSkip: true, maxTicksLimit: 6, maxRotation: 0, minRotation: 0 }
              : { color: '#94a3b8', autoSkip: true, maxTicksLimit: 12, maxRotation: 0, minRotation: 0 } }
        } }
    });
  }

  /* ======================================================================
     BUSY-WEEK HEATMAP
     One square per ISO week, grouped by month, colour only (no numbers).
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
      const lv = n > 0 && max > 0 ? Math.min(5, Math.ceil(n / max * 5)) : 0;
      const wk = { w: h.w, n, st, lv, range: info.range };
      months[info.group].weeks.push(wk);
      months[info.group].total += n;
    });

    const allWeeks = months.flatMap((m) => m.weeks);
    const elapsed = allWeeks.filter((w) => w.st !== 'future');
    const activeElapsed = elapsed.filter((w) => w.n > 0).length;

    // ---- Summary strip ----
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
        const label = `Week ${w.w} · ${w.range} · ${w.n ? plural(w.n, 'approved booking') : 'No bookings'}${w.st === 'now' ? ' · This week' : w.st === 'future' ? ' · Upcoming' : ''}`;
        const cls = ['an-heat-cell', w.lv ? 'lv' + w.lv : 'zero', w.st === 'future' ? 'future' : '', w.st === 'now' ? 'now' : '', w.n > 0 && w.n === max ? 'peak' : ''].filter(Boolean).join(' ');
        return `<button type="button" class="${cls}" data-tip="${esc(label)}" aria-label="${esc(label)}"></button>`;
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

  function renderForecast(d) {
    const F = d.forecast;
    const stats = $('anFcStats'), acc = $('anFcAcc'), bt = $('anFcBt'), win = $('anFcWin');

    if (!F.ok) {
      $('anFcTitle').textContent = '4 · Forecast (moving average)';
      $('anFcNote').textContent = 'Forecast of monthly cash collected. It follows the Street filter but not the date range.';
      stats.innerHTML = '';
      if (state.charts.cFc) { state.charts.cFc.destroy(); delete state.charts.cFc; }
      empty('cFc', false, F.reason);
      acc.innerHTML = `<p class="an-fc-note">${esc(F.reason)}</p>`;
      bt.innerHTML = ''; win.innerHTML = '';
      return;
    }

    const N = F.window, g = grade(F.mape), pts = F.points;
    $('anFcTitle').textContent = `4 · Forecast (${N}-month moving average)`;
    $('anFcNote').textContent = `Based on ${F.history_months} completed months (${F.first_month} – ${F.last_month}). The current month is left out because it is not finished. Follows the Street filter, not the date range.`;

    stats.innerHTML = [
      ['hl', `Next month · ${pts[0].label}`, peso(pts[0].value), `Likely ${peso(pts[0].low)} – ${peso(pts[0].high)}`],
      ['', `Next 3 months`, peso(F.next3), `${pts[0].label} to ${pts[2].label}`],
      ['', `Next 6 months`, peso(F.next6), `${pts[0].label} to ${pts[5].label}`],
      ['', `Last full month · ${F.last_month}`, peso(F.last_actual), 'Actual cash collected']
    ].map((s) => `<div class="an-fc-stat ${s[0]}"><span>${esc(s[1])}</span><b>${esc(s[2])}</b><small>${esc(s[3])}</small></div>`).join('');

    // ---- Chart (last 24 months of history + 6 forecast months) ----
    const off = Math.max(0, F.labels.length - 24);
    const hl = F.labels.slice(off), n = hl.length;
    const act = F.actual.slice(off), fit = F.fit.slice(off);
    const nulls = Array(n).fill(null), tail = Array(pts.length).fill(null);
    const fcLine = nulls.slice(), low = nulls.slice(), high = nulls.slice();
    fcLine[n - 1] = low[n - 1] = high[n - 1] = act[n - 1];
    pts.forEach((p) => { fcLine.push(p.value); low.push(p.low); high.push(p.high); });
    const hidden = ['Low', 'High'];
    mk('cFc', { type: 'line',
      data: { labels: hl.concat(pts.map((p) => p.label)), datasets: [
        { label: 'Actual', data: act.concat(tail), borderColor: '#a5b4fc', backgroundColor: grad('rgba(165,180,252,.28)', 'rgba(165,180,252,0)'), fill: true, borderWidth: 3, tension: .25, pointRadius: 3, pointBackgroundColor: '#a5b4fc', pointBorderColor: '#818cf8' },
        { label: `${N}-month moving average (fit)`, data: fit.slice(off ? 0 : 0).concat(tail), borderColor: 'rgba(148,163,184,.85)', borderWidth: 1.5, tension: .25, pointRadius: 0, fill: false },
        { label: 'Forecast', data: fcLine, borderColor: C.o, borderDash: [7, 5], borderWidth: 3, tension: 0, pointRadius: 4, pointBackgroundColor: C.o, pointBorderColor: '#d97706', fill: false },
        { label: 'Low', data: low, borderColor: 'rgba(245,158,11,0)', pointRadius: 0, fill: false },
        { label: 'High', data: high, borderColor: 'rgba(245,158,11,0)', pointRadius: 0, fill: '-1', backgroundColor: 'rgba(245,158,11,.16)' }] },
      options: { animation: { duration: 450 }, interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { position: 'top', align: 'end', labels: { filter: (it) => !hidden.includes(it.text) } },
          tooltip: { displayColors: false, backgroundColor: '#070c18', padding: 11, cornerRadius: 8, borderColor: 'rgba(255,255,255,0.12)', borderWidth: 1,
            filter: (it) => !hidden.includes(it.dataset.label) && it.parsed.y !== null,
            callbacks: { label: (c) => {
              if (c.dataset.label === 'Forecast' && c.dataIndex >= n) { const p = pts[c.dataIndex - n]; return `  Forecast: ${peso(p.value)} (${peso(p.low)} – ${peso(p.high)})`; }
              if (c.dataset.label === 'Forecast') return null;
              return `  ${c.dataset.label}: ${peso(c.parsed.y)}`; } } } },
        scales: {
          y: { beginAtZero: true, border: { display: false }, grid: { color: 'rgba(255,255,255,0.06)', drawTicks: false }, ticks: { color: '#94a3b8', padding: 8, maxTicksLimit: 6, callback: (v) => peso(v) } },
          x: { border: { display: false }, grid: { display: false }, ticks: { color: '#94a3b8', autoSkip: true, maxTicksLimit: 12, maxRotation: 0 } } } } });
    empty('cFc', true);

    // ---- Accuracy panel ----
    acc.innerHTML = `
      <div class="an-fc-grade ${g[0]}"><b>${F.mape === null ? '—' : F.mape + '%'}</b><span>average error (MAPE) · ${g[1]}</span></div>
      <div class="an-fc-row"><span>Method</span><b>${N}-month moving average</b></div>
      <div class="an-fc-row"><span>Average error (MAE)</span><b>${peso(F.mae)} / month</b></div>
      <div class="an-fc-row"><span>Tested on</span><b>${plural(F.backtest.length, 'month')}</b></div>
      <div class="an-fc-row"><span>History used</span><b>${plural(F.history_months, 'month')}</b></div>
      <p class="an-fc-note">MAPE is the average percentage the forecast was off by when predicting months that had already happened. Lower is better; under 10% is considered good.</p>`;

    // ---- Backtest table ----
    bt.innerHTML = '<thead><tr><th>Month</th><th>Forecast</th><th>Actual</th><th>Error</th><th>Error %</th></tr></thead><tbody>' +
      F.backtest.map((r) => `<tr><td>${esc(r.label)}</td><td>${peso(r.forecast)}</td><td>${peso(r.actual)}</td><td>${r.error >= 0 ? '+' : '−'}${peso(Math.abs(r.error))}</td><td>${r.pct === null ? '—' : r.pct + '%'}</td></tr>`).join('') +
      `<tr class="an-fc-total"><td colspan="3">Average (MAE / MAPE)</td><td>${peso(F.mae)}</td><td>${F.mape === null ? '—' : F.mape + '%'}</td></tr></tbody>`;

    // ---- Window comparison + how it works ----
    win.innerHTML = `
      <div class="table-responsive"><table class="an-table">
        <thead><tr><th>Window</th><th>MAPE</th><th>MAE</th><th>Used</th></tr></thead>
        <tbody>${F.windows.map((w) => `<tr><td>${w.n} months</td><td>${w.mape === null ? '—' : w.mape + '%'}</td><td>${peso(w.mae)}</td><td>${w.used ? '✔' : ''}</td></tr>`).join('')}</tbody>
      </table></div>
      <div class="an-fc-method">
        <b>How it works</b>
        <ol>
          <li>The forecast for a month is the <b>average of the previous ${N} months</b>.</li>
          <li>For later months, earlier forecasts are treated as if they were real, so the line flattens the further ahead you look.</li>
          <li>The shaded range is the forecast ± the average backtest error, and it widens each month ahead.</li>
          <li>A moving average does not detect trend or seasonality, so treat it as a smoothed estimate, not a promise.</li>
        </ol>
      </div>`;
  }

  function draw(d) {
    clearPrintImages();
    kpis(d); insights(d);
    $('anDue').textContent = d.range.due_day;
    drawSales();

    const m = d.methods;
    if (empty('cMethods', hasData(m.amounts))) mk('cMethods', { type: 'doughnut',
      data: { labels: m.labels, datasets: [{ data: m.amounts, backgroundColor: [C.g, C.b, C.o, C.p, C.s, C.r], borderWidth: 3, borderColor: '#fff' }] },
      options: { cutout: '66%', plugins: { legend: { position: 'bottom' }, center: { t1: peso(m.amounts.reduce((a, b) => a + b, 0)), t2: 'received' },
        tooltip: { callbacks: { label: (c) => ` ${c.label}: ${peso(c.parsed)} (${m.counts[c.dataIndex]} payments)` } } } } });

    const w = d.dow, wp = w.amounts.indexOf(Math.max(...w.amounts));
    if (empty('cDow', hasData(w.amounts))) mk('cDow', { type: 'bar',
      data: { labels: w.labels, datasets: [{ data: w.amounts, borderRadius: 8, backgroundColor: w.amounts.map((v, i) => i === wp ? C.o : C.g2), maxBarThickness: 54 }] },
      options: { plugins: { legend: { display: false }, tooltip: { callbacks: { label: (c) => ` ${peso(c.parsed.y)} · ${w.counts[c.dataIndex]} payments` } } },
        scales: { y: { beginAtZero: true, ticks: { callback: (v) => peso(v) } }, x: { grid: { display: false } } } } });

    $('anTimeline').innerHTML = d.timeline.length ? d.timeline.map((t) => `<li style="--d:${t.type === 'payment' ? C.g : C.b}"><b>${esc(t.who)}</b> ${t.type === 'payment' ? 'paid ' + peso(t.amount) : 'booked ' + esc(t.note)}<small>${esc(t.date)}${t.type === 'payment' ? ' · ' + esc(t.note) : ''}</small></li>`).join('') : '<li>No activity yet.</li>';

    const L = d.late, max = Math.max(1, ...L.leaders.map((x) => x.late)), streaks = d.billing.streaks || {};
    $('anLeader').innerHTML = L.leaders.length ? L.leaders.map((x, i) => `<li><span class="an-rank">${i + 1}</span><span class="an-av">${esc(x.name.charAt(0))}</span>
      <a href="homeowner_view.php?id=${x.id}"><span><b>${esc(x.name)}</b><small>${esc(x.street)} · Lot ${esc(x.lot)} · avg ${x.avg_late}d late, worst ${x.max_late}d</small><div class="an-bar"><i style="width:${x.late / max * 100}%"></i></div></span></a>
      <span><span class="an-pill">${x.late}/${x.periods} late</span>${streaks[x.id] ? `<br><span class="an-pill warn">${streaks[x.id]} unpaid now</span>` : ''}</span></li>`).join('')
      : '<li style="display:block;padding:24px;text-align:center;color:#64748b">No late payments in this range.</li>';

    if (empty('cLateDist', hasData(L.bucket_values))) mk('cLateDist', {
      type: 'bar',
      data: {
        labels: L.bucket_labels,
        datasets: [{
          data: L.bucket_values,
          borderRadius: 8,
          backgroundColor: [C.g2, '#facc15', C.o, '#f97316', C.r],
          maxBarThickness: 34
        }]
      },
      options: {
        indexAxis: 'y',
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: (c) => ` ${c.parsed.x} payments` } }
        },
        scales: {
          x: {
            beginAtZero: true,
            grid: { color: 'rgba(226, 232, 240, 0.65)', drawBorder: false },
            ticks: { precision: 0, color: '#64748b' }
          },
          y: {
            grid: { display: false },
            ticks: { color: '#64748b', font: { family: 'Poppins', size: 12, weight: '500' } }
          }
        }
      }
    });

    if (empty('cLateStreet', L.street_labels.length > 0)) mk('cLateStreet', {
      type: 'bar',
      data: {
        labels: L.street_labels,
        datasets: [{
          data: L.street_rates,
          borderRadius: 6,
          backgroundColor: L.street_rates.map((v) => v >= 50 ? C.r : v >= 30 ? C.o : C.g2),
          maxBarThickness: 26
        }]
      },
      options: {
        indexAxis: 'y',
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: (c) => ' ' + c.parsed.x + '% late' } }
        },
        scales: {
          x: {
            beginAtZero: true,
            max: 100,
            grid: { color: 'rgba(226, 232, 240, 0.65)', drawBorder: false },
            ticks: { callback: (v) => v + '%', color: '#64748b' }
          },
          y: {
            grid: { display: false },
            ticks: { color: '#64748b', font: { family: 'Poppins', size: 11, weight: '500' } }
          }
        }
      }
    });

    const ag = d.billing.aging;
    if (empty('cAging', hasData(ag.counts), 'Everyone is up to date.')) mk('cAging', {
      type: 'bar',
      data: {
        labels: ag.labels,
        datasets: [{
          data: ag.counts,
          borderRadius: 8,
          backgroundColor: ['#facc15', C.o, '#f97316', C.r],
          maxBarThickness: 46
        }]
      },
      options: {
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          y: {
            beginAtZero: true,
            grid: { color: 'rgba(226, 232, 240, 0.65)', drawBorder: false },
            ticks: { precision: 0, color: '#64748b' }
          },
          x: {
            grid: { display: false },
            ticks: { color: '#64748b', autoSkip: true, maxRotation: 0, minRotation: 0, font: { family: 'Poppins', size: 11, weight: '500' } }
          }
        }
      }
    });

    const R = d.reservations, rp = R.approved.indexOf(Math.max(...R.approved, 0));
    if (empty('cResWeek', hasData(R.approved) || hasData(R.other), 'No reservations in this range.')) mk('cResWeek', { type: 'bar',
      data: { labels: R.week_labels, datasets: [
        { label: 'Approved', data: R.approved, borderRadius: 4, backgroundColor: R.approved.map((v, i) => i === rp && v > 0 ? C.o : C.g2) },
        { label: 'Pending / rejected', data: R.other, borderRadius: 4, backgroundColor: '#cbd5e1' }] },
      options: { plugins: { legend: { position: 'bottom' } }, scales: { x: { stacked: true, grid: { display: false }, ticks: { maxTicksLimit: 14 } }, y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } } } } });
    if (empty('cResFac', R.facility_values.length > 0)) mk('cResFac', { type: 'doughnut',
      data: { labels: R.facility_labels, datasets: [{ data: R.facility_values, backgroundColor: [C.g, C.b, C.o, C.p, C.s], borderWidth: 3, borderColor: '#fff' }] },
      options: { cutout: '60%', plugins: { legend: { position: 'bottom' }, center: { t1: nf.format(R.facility_values.reduce((a, b) => a + b, 0)), t2: 'bookings' } } } });

    $('anHeatYear').textContent = R.heat_year;
    renderHeat(R);

    renderForecast(d);

    $('anUpdated').textContent = 'Updated ' + new Date(d.updated).toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' });

    schedulePrintImages();
  }

  /* ======================================================================
     PRINT / SAVE PDF
     Charts are canvas elements and print blurry or cut off when resized by
     the browser. Before printing, each chart is rendered once at print size
     and 3x resolution into an <img>; analytics-print.css shows the image
     instead of the canvas. The on-screen charts are not affected.
     ====================================================================== */
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
    Object.values(state.charts).forEach((chart) => {
      const canvas = chart && chart.canvas;
      if (!canvas || !canvas.isConnected || canvas.style.display === 'none') return;
      const box = canvas.parentElement;
      if (!box) return;
      const h = box.classList.contains('short') ? 210 : 250;
      let url = '';
      try {
        chart.options.devicePixelRatio = 3;
        chart.resize(PRINT_CHART_W, h);
        url = chart.toBase64Image('image/png', 1);
      } catch (e) {
        url = '';
      } finally {
        chart.options.devicePixelRatio = undefined;
        try { chart.resize(); } catch (e) { /* ignore */ }
      }
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
    $('anError').hidden = true; $('anUpdated').textContent = 'Loading…';
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
      if (!$('anKpis')) layout();
      draw(json);
      state.loading = false;
      syncActionButtons();
    } catch (e) {
      state.data = null;
      state.filters = null;
      state.loading = false;
      syncActionButtons();
      $('anError').hidden = false; $('anUpdated').textContent = '';
      const msg = $('anError').querySelector('span'); if (msg) msg.textContent = e.message || 'We couldn’t load analytics right now.';
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
      if (s) add(g[2], s.labels, s.values);
    });
    add('Collected by weekday', d.dow.labels, d.dow.amounts);
    add('Payment methods · transaction count', d.methods.labels, d.methods.counts);
    add('Payment methods · amount received', d.methods.labels, d.methods.amounts);

    d.late.leaders.forEach((x) => rows.push(['Late payers', x.name + ' (' + x.street + ' Lot ' + x.lot + ')', x.late + ' late of ' + x.periods + ', average ' + x.avg_late + ' days, worst ' + x.max_late + ' days']));
    add('Late payment distribution', d.late.bucket_labels, d.late.bucket_values);
    add('Late rate by street', d.late.street_labels, d.late.street_rates.map((v) => v + '%'));
    add('Current unpaid accounts', d.billing.aging.labels, d.billing.aging.counts);

    d.reservations.week_labels.forEach((l, i) => {
      rows.push(['Reservations per week · approved', l, d.reservations.approved[i]]);
      rows.push(['Reservations per week · pending or rejected', l, d.reservations.other[i]]);
    });
    add('Approved reservations by facility', d.reservations.facility_labels, d.reservations.facility_values);
    add('Reservation activity · ISO week ' + d.reservations.heat_year, d.reservations.heat.map((x) => 'Week ' + x.w + ' (' + x.start + ')'), d.reservations.heat.map((x) => x.n));

    (d.timeline || []).forEach((x) => rows.push(['Latest system activity (not filtered) · ' + x.type, x.date + ' · ' + x.who, x.type === 'payment' ? x.amount + ' · ' + x.note : x.note + ' · ' + x.amount]));

    rows.push(['Year comparison', d.yoy.year + ' year to date', d.yoy.ytd]);
    rows.push(['Year comparison', (d.yoy.year - 1) + ' same months', d.yoy.ytd_previous]);

    const F = d.forecast;
    if (!F.ok) {
      rows.push(['Forecast', 'Status', F.reason]);
    } else {
      rows.push(['Forecast model', 'Method', F.window + '-month moving average']);
      rows.push(['Forecast model', 'Completed months used', F.history_months + ' (' + F.first_month + ' to ' + F.last_month + ')']);
      rows.push(['Forecast model', 'Average error (MAPE)', F.mape === null ? '' : F.mape + '%']);
      rows.push(['Forecast model', 'Average error (MAE, pesos)', F.mae]);
      F.windows.forEach((w) => rows.push(['Forecast window comparison', w.n + '-month window' + (w.used ? ' (used)' : ''), 'MAPE ' + (w.mape === null ? 'n/a' : w.mape + '%') + ', MAE ' + w.mae]));
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
    syncActionButtons();
  });
  ['anFrom', 'anTo', 'anBlock'].forEach((id) => $(id).addEventListener('change', syncActionButtons));
  $('anFilters').addEventListener('submit', (e) => { e.preventDefault(); load(); });
  $('anRetry').addEventListener('click', load);
  $('anPrint').addEventListener('click', printReport);
  $('anCsv').addEventListener('click', exportCsv);

  load();
})(); 
