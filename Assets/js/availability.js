// Assets/js/availability.js  -  monthly availability calendar with a free-choice time range
(function () {
    var cfg = window.AVAIL_CONFIG;
    if (!cfg) return;

    var $ = function (id) { return document.getElementById(id); };
    var MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July',
                  'August', 'September', 'October', 'November', 'December'];
    var DOW = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    var openH = parseInt(cfg.open, 10);
    var closeH = parseInt(cfg.close, 10);
    var now = new Date();

    // Small styles for the availability controls.
    (function addStyles() {
        var st = document.createElement('style');
        st.textContent =
            '.slot-chip.slot-sel{background:#1b4332;border-color:#1b4332;color:#fff;}' +
            '.slot-chip.slot-free{font-family:inherit;}' +
            '.avail-msg{color:#991b1b;font-size:.8rem;margin-top:8px;}';
        document.head.appendChild(st);
    })();

    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function ymd(y, m, d) { return y + '-' + pad(m + 1) + '-' + pad(d); }
    function toMin(t) { var p = t.split(':'); return parseInt(p[0], 10) * 60 + parseInt(p[1], 10); }
    function fmt(h) { var ap = h >= 12 ? 'PM' : 'AM'; var x = h % 12; if (x === 0) x = 12; return x + ':00 ' + ap; }
    function peso(n) { return '\u20B1' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

    var todayStr = ymd(now.getFullYear(), now.getMonth(), now.getDate());
    var nowMin = now.getHours() * 60 + now.getMinutes();

    var state = {
        facility: cfg.facilities[0],
        year: now.getFullYear(),
        month: now.getMonth(),
        days: {},
        selected: null,   // chosen date
        selStart: null,   // chosen start hour
        selEnd: null,     // chosen end hour (exclusive)
        picking: false,   // true after the first click, waiting for the end click
        msg: '',
        token: 0
    };

    function resetRange() {
        state.selStart = null;
        state.selEnd = null;
        state.picking = false;
        state.msg = '';
    }

    // state of one hour slot: past | booked | mine | free
    function slotState(dateStr, h) {
        if (dateStr < todayStr) return 'past';
        if (dateStr === todayStr && h * 60 <= nowMin) return 'past';
        var busy = state.days[dateStr] || [];
        for (var i = 0; i < busy.length; i++) {
            if (toMin(busy[i].s) < (h + 1) * 60 && toMin(busy[i].e) > h * 60) {
                return busy[i].mine ? 'mine' : 'booked';
            }
        }
        return 'free';
    }

    // Latest end hour reachable from `start` without hitting a taken hour
    function maxEnd(dateStr, start) {
        var h = start;
        while (h < closeH && slotState(dateStr, h) === 'free') h++;
        return h;
    }

    function rangeIsFree(dateStr, start, end) {
        for (var h = start; h < end; h++) {
            if (slotState(dateStr, h) !== 'free') return false;
        }
        return true;
    }

    function dayStatus(dateStr) {
        if (dateStr < todayStr) return 'past';
        var free = 0, booked = 0;
        for (var h = openH; h < closeH; h++) {
            var s = slotState(dateStr, h);
            if (s === 'free') free++;
            else if (s === 'booked' || s === 'mine') booked++;
        }
        if (free === 0) return booked > 0 ? 'full' : 'past';
        return booked === 0 ? 'available' : 'partial';
    }

    function render() {
        $('availTitle').textContent = MONTHS[state.month] + ' ' + state.year;

        var grid = $('availGrid');
        var html = '';
        DOW.forEach(function (d) { html += '<div class="avail-dow">' + d + '</div>'; });

        var firstDow = new Date(state.year, state.month, 1).getDay();
        var daysInMonth = new Date(state.year, state.month + 1, 0).getDate();
        for (var b = 0; b < firstDow; b++) html += '<div></div>';

        for (var d = 1; d <= daysInMonth; d++) {
            var ds = ymd(state.year, state.month, d);
            var st = dayStatus(ds);
            var sel = state.selected === ds ? ' selected' : '';
            var dis = st === 'past' ? ' disabled' : '';
            html += '<button type="button" class="avail-day avail-' + st + sel + '" data-date="' + ds + '"' + dis + '>' + d + '</button>';
        }
        grid.innerHTML = html;

        grid.querySelectorAll('.avail-day').forEach(function (btn) {
            btn.addEventListener('click', function () {
                state.selected = btn.getAttribute('data-date');
                resetRange();
                render();
            });
        });

        $('availPrev').disabled = (state.year === now.getFullYear() && state.month <= now.getMonth());
        renderDetail();
    }

    function renderDetail() {
        var box = $('availDetail');
        if (!state.selected) {
            box.innerHTML = '<div class="text-muted small">Tap a day to see its time slots.</div>';
            return;
        }
        var date = state.selected;
        var parts = date.split('-');
        var label = MONTHS[parseInt(parts[1], 10) - 1] + ' ' + parseInt(parts[2], 10) + ', ' + parts[0];
        var hasSel = state.selStart !== null && state.selEnd !== null;

        // ----- hour chips (visual guide, also clickable) -----
        var html = '<div class="fw-semibold mb-2">' + state.facility + ' &middot; ' + label + '</div><div class="avail-slots">';
        for (var h = openH; h < closeH; h++) {
            var s = slotState(date, h);
            var text = fmt(h) + ' - ' + fmt(h + 1);
            if (s === 'free') {
                var on = hasSel && h >= state.selStart && h < state.selEnd ? ' slot-sel' : '';
                html += '<button type="button" class="slot-chip slot-free' + on + '" data-h="' + h + '">' + text + '</button>';
            } else if (s === 'mine') {
                html += '<span class="slot-chip slot-mine">' + text + '<small>Your booking</small></span>';
            } else if (s === 'booked') {
                html += '<span class="slot-chip slot-booked">' + text + '<small>Reserved</small></span>';
            } else {
                html += '<span class="slot-chip slot-past">' + text + '</span>';
            }
        }
        html += '</div>';
        html += '<div class="small text-muted mt-2">Tap your <strong>first</strong> hour, then your <strong>last</strong> hour. Or choose the times below. Any length is fine.</div>';

        // ----- free-choice start / end -----
        html += '<div class="avail-range">';

        html += '<div><label for="availStart">Start time</label><select id="availStart" class="form-select form-select-sm">';
        html += '<option value=""' + (hasSel ? '' : ' selected') + ' disabled>Select</option>';
        for (var a = openH; a < closeH; a++) {
            if (slotState(date, a) === 'free') {
                html += '<option value="' + a + '"' + (hasSel && a === state.selStart ? ' selected' : '') + '>' + fmt(a) + '</option>';
            }
        }
        html += '</select></div>';

        html += '<div><label for="availEnd">End time</label><select id="availEnd" class="form-select form-select-sm"' + (hasSel ? '' : ' disabled') + '>';
        if (hasSel) {
            var me = maxEnd(date, state.selStart);
            for (var e = state.selStart + 1; e <= me; e++) {
                html += '<option value="' + e + '"' + (e === state.selEnd ? ' selected' : '') + '>' + fmt(e) + '</option>';
            }
        } else {
            html += '<option>Select</option>';
        }
        html += '</select></div>';

        html += '<div class="avail-summary">';
        if (hasSel) {
            var hrs = state.selEnd - state.selStart;
            var rate = (cfg.rates && cfg.rates[state.facility]) || 0;
            html += '<div class="fw-semibold">' + fmt(state.selStart) + ' &ndash; ' + fmt(state.selEnd) + '</div>';
            html += '<div class="text-muted">' + hrs + ' hour' + (hrs !== 1 ? 's' : '') + (rate ? ' &middot; ' + peso(hrs * rate) : '') + '</div>';
        } else {
            html += '<div class="text-muted">No time selected yet.</div>';
        }
        html += '</div>';

        html += '<div><button type="button" id="availBook" class="btn btn-primary btn-sm"' + (hasSel ? '' : ' disabled') + '>' +
                '<i class="bi bi-calendar-plus me-1"></i> Book this schedule</button></div>';
        html += '</div>';

        if (state.msg) html += '<div class="avail-msg">' + state.msg + '</div>';

        box.innerHTML = html;

        // chip clicks: first click = start, second click = end
        box.querySelectorAll('.slot-free').forEach(function (btn) {
            btn.addEventListener('click', function () {
                chipClick(parseInt(btn.getAttribute('data-h'), 10));
            });
        });

        var startSel = $('availStart'), endSel = $('availEnd');
        startSel.addEventListener('change', function () {
            var a = parseInt(startSel.value, 10);
            var keepLen = (state.selEnd !== null && state.selStart !== null) ? state.selEnd - state.selStart : 1;
            var e = Math.min(a + keepLen, maxEnd(date, a));
            if (e <= a) e = a + 1;
            state.selStart = a;
            state.selEnd = e;
            state.picking = false;
            state.msg = '';
            renderDetail();
        });
        endSel.addEventListener('change', function () {
            state.selEnd = parseInt(endSel.value, 10);
            state.picking = false;
            state.msg = '';
            renderDetail();
        });

        var bookBtn = $('availBook');
        if (bookBtn) {
            bookBtn.addEventListener('click', function () {
                if (state.selStart === null || state.selEnd === null) return;
                book(date, state.selStart, state.selEnd);
            });
        }
    }

    function chipClick(h) {
        var date = state.selected;
        state.msg = '';

        if (!state.picking || state.selStart === null) {
            // first click: start a new range
            state.selStart = h;
            state.selEnd = h + 1;
            state.picking = true;
        } else if (h < state.selStart) {
            // clicked earlier than the start: restart from here
            state.selStart = h;
            state.selEnd = h + 1;
            state.picking = true;
        } else if (rangeIsFree(date, state.selStart, h + 1)) {
            // second click: finish the range
            state.selEnd = h + 1;
            state.picking = false;
        } else {
            // something reserved in between
            state.msg = 'That range includes a reserved hour. Please choose a range with no reserved hours in between.';
            state.selStart = h;
            state.selEnd = h + 1;
            state.picking = true;
        }
        renderDetail();
    }

    // Fill the booking form (used by the calendar and after OTP verification)
    function fill(p) {
        var f = $('facility');
        if (p.facility && f) { f.value = p.facility; f.dispatchEvent(new Event('change')); }
        var d = $('res_date');
        if (p.date && d) d.value = p.date;
        var s = $('start_time'), e = $('end_time');
        if (p.start && s && s._flatpickr) s._flatpickr.setDate(p.start, true);
        if (p.end && e && e._flatpickr) e._flatpickr.setDate(p.end, true);
    }

    function book(dateStr, startH, endH) {
        var p = { facility: state.facility, date: dateStr, start: pad(startH) + ':00', end: pad(endH) + ':00' };

        if (cfg.verified) {
            fill(p);
            bootstrap.Modal.getOrCreateInstance($('bookFacilityModal')).show();
        } else {
            // OTP first, then come back with the form pre-filled
            var next = 'reservations.php?open=book&facility=' + encodeURIComponent(p.facility)
                     + '&date=' + p.date + '&start=' + p.start + '&end=' + p.end;
            window.location.href = cfg.verifyUrl + encodeURIComponent(next);
        }
    }

    function load() {
        var ym = state.year + '-' + pad(state.month + 1);
        var my = ++state.token;
        $('availGrid').classList.add('avail-loading');
        $('availGrid').setAttribute('aria-busy', 'true');
        $('availError').classList.add('d-none');

        fetch(cfg.endpoint + '?facility=' + encodeURIComponent(state.facility) + '&month=' + ym, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (my !== state.token) return;   // a newer request replaced this one
                if (!j.ok) throw new Error(j.error || 'Failed');
                state.days = j.days || {};
                $('availGrid').classList.remove('avail-loading');
                $('availGrid').removeAttribute('aria-busy');
                render();
            })
            .catch(function () {
                if (my !== state.token) return;
                $('availGrid').classList.remove('avail-loading');
                $('availGrid').removeAttribute('aria-busy');
                $('availError').classList.remove('d-none');
            });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var sel = $('availFacility');
        cfg.facilities.forEach(function (name) {
            var o = document.createElement('option');
            o.value = name; o.textContent = name;
            sel.appendChild(o);
        });
        sel.addEventListener('change', function () {
            state.facility = sel.value;
            state.selected = null;
            resetRange();
            load();
        });
        $('availPrev').addEventListener('click', function () {
            state.month--; if (state.month < 0) { state.month = 11; state.year--; }
            state.selected = null; resetRange(); load();
        });
        $('availNext').addEventListener('click', function () {
            state.month++; if (state.month > 11) { state.month = 0; state.year++; }
            state.selected = null; resetRange(); load();
        });
        load();
    });

    window.AvailCalendar = { fill: fill };
})();